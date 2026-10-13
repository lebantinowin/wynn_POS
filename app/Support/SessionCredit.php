<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SessionCredit
{
    public static function customers(): array
    {
        return PosStore::get('demo.customers', []);
    }

    public static function debts(): array
    {
        return PosStore::get('demo.debts', []);
    }

    public static function repayments(): array
    {
        return PosStore::get('demo.repayments', []);
    }

    public static function addCustomer(string $name, string $phone): void
    {
        if (PosStore::needsTransaction()) {
            PosStore::atomic(fn () => self::addCustomer($name, $phone));

            return;
        }

        $name = trim($name);
        $phone = trim($phone);
        Validator::make(compact('name', 'phone'), ['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:40'])->validate();
        foreach (self::customers() as $customer) {
            if (strtolower($customer['name']) === strtolower($name)) {
                throw ValidationException::withMessages(['name' => 'Customer name is already in use. Add a distinguishing name if necessary.']);
            }
        }
        $id = (string) Str::uuid();
        $customers = self::customers();
        $customers[$id] = compact('id', 'name', 'phone');
        PosStore::put('demo.customers', $customers);
    }

    public static function customer(string $id): array
    {
        $customer = self::customers()[$id] ?? null;
        if (! $customer) {
            throw ValidationException::withMessages(['customerId' => 'Select a saved customer.']);
        }

        return $customer;
    }

    public static function amount(string $amount, string $field = 'amount'): int
    {
        Validator::make([$field => $amount], [$field => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/']])->validate();
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($minor < 1) {
            throw ValidationException::withMessages([$field => 'Amount must be greater than zero.']);
        }

        return $minor;
    }

    public static function makeDebt(string $eventId, string $customerId, string $type, int $principal, string $dueDate, string $notes, ?string $saleId = null, ?string $method = null): array
    {
        $customer = self::customer($customerId);
        Validator::make(compact('dueDate', 'notes'), ['dueDate' => 'nullable|date_format:Y-m-d|after_or_equal:'.app(OfflineCheckout::class)->time()->timezone('Asia/Shanghai')->toDateString(), 'notes' => 'nullable|string|max:500'])->validate();
        $id = (string) Str::uuid();

        return ['id' => $id, 'number' => 'DEBT-'.str_pad((string) (count(self::debts()) + 1), 5, '0', STR_PAD_LEFT),
            'eventId' => $eventId, 'customerId' => $customerId, 'customer' => $customer['name'], 'type' => $type,
            'principal' => $principal, 'balance' => $principal, 'dueDate' => $dueDate, 'notes' => trim($notes), 'saleId' => $saleId,
            'method' => $method, 'time' => app(OfflineCheckout::class)->time()->toIso8601String()];
    }

    public static function cashLoan(string $eventId, string $customerId, string $amount, string $method, string $dueDate, string $notes, ?string $cashSessionId = null): void
    {
        if (PosStore::needsTransaction()) {
            PosStore::atomic(fn () => self::cashLoan($eventId, $customerId, $amount, $method, $dueDate, $notes, $cashSessionId));

            return;
        }

        $debts = self::debts();
        foreach ($debts as $debt) {
            if ($debt['eventId'] === $eventId) {
                return;
            }
        }
        Validator::make(compact('method'), ['method' => 'required|in:Cash,GCash,Maya'])->validate();
        $debt = self::makeDebt($eventId, $customerId, 'Cash loan', self::amount($amount), $dueDate, $notes, null, $method);
        $movement = SessionCash::stageMovement($eventId, 'Cash loan', $debt['id'], $debt['principal'], $method, 'out', $debt['number'], $debt['notes'], $cashSessionId);
        $debt['cashSessionId'] = $movement['cashSessionId'];
        $debts[$debt['id']] = $debt;
        $movements = SessionCash::movements();
        $movements[] = $movement;
        PosStore::put(['demo.debts' => $debts, 'demo.cashMovements' => $movements]);
    }

    public static function repay(string $eventId, string $debtId, string $amount, string $method, string $reference, ?string $cashSessionId = null): void
    {
        if (PosStore::needsTransaction()) {
            PosStore::atomic(fn () => self::repay($eventId, $debtId, $amount, $method, $reference, $cashSessionId));

            return;
        }

        $repayments = self::repayments();
        foreach ($repayments as $repayment) {
            if ($repayment['eventId'] === $eventId) {
                return;
            }
        }
        Validator::make(compact('method', 'reference'), ['method' => 'required|in:Cash,GCash,Maya', 'reference' => 'nullable|string|max:100'])->validate();
        $debts = self::debts();
        $debt = $debts[$debtId] ?? null;
        if (! $debt) {
            throw ValidationException::withMessages(['debtId' => 'Select an available debt.']);
        }
        $paid = self::amount($amount, 'repaymentAmount');
        if ($paid > $debt['balance']) {
            throw ValidationException::withMessages(['repaymentAmount' => 'Repayment exceeds the outstanding balance.']);
        }
        $repaymentId = (string) Str::uuid();
        $movement = SessionCash::stageMovement($eventId, 'Repayment', $repaymentId, $paid, $method, 'in', $debt['number'], trim($reference), $cashSessionId, 'repaymentAmount');
        $debt['balance'] -= $paid;
        $debts[$debtId] = $debt;
        $repayments[] = ['id' => $repaymentId, 'eventId' => $eventId, 'debtId' => $debtId, 'customerId' => $debt['customerId'], 'cashSessionId' => $movement['cashSessionId'],
            'customer' => $debt['customer'], 'amount' => $paid, 'method' => $method, 'reference' => trim($reference), 'time' => app(OfflineCheckout::class)->time()->toIso8601String()];
        $movements = SessionCash::movements();
        $movements[] = $movement;
        PosStore::put(['demo.debts' => $debts, 'demo.repayments' => $repayments, 'demo.cashMovements' => $movements]);
    }
}
