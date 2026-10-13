<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SessionPayables
{
    public static function invoices(): array
    {
        return PosStore::get('demo.supplierInvoices', []);
    }

    public static function payments(): array
    {
        return PosStore::get('demo.supplierPayments', []);
    }

    public static function stageInvoice(array $order, string $eventId, array $receipts, string $reference, string $dueDate): array
    {
        $id = (string) Str::uuid();
        $total = array_sum(array_column($receipts, 'totalCost'));

        return ['id' => $id, 'eventId' => $eventId,
            'number' => 'BILL-'.str_pad((string) (count(self::invoices()) + 1), 5, '0', STR_PAD_LEFT),
            'orderId' => $order['id'], 'orderNumber' => $order['number'], 'supplierId' => $order['supplierId'],
            'supplier' => $order['supplier'], 'reference' => trim($reference), 'dueDate' => $dueDate,
            'total' => $total, 'balance' => $total, 'lines' => $receipts, 'time' => now()->toIso8601String()];
    }

    public static function balances(): array
    {
        $suppliers = [];
        $today = now('Asia/Shanghai')->toDateString();
        foreach (self::invoices() as $invoice) {
            $id = $invoice['supplierId'];
            $suppliers[$id] ??= ['id' => $id, 'name' => SessionPurchasing::suppliers()[$id]['name'] ?? $invoice['supplier'],
                'total' => 0, 'paid' => 0, 'balance' => 0, 'overdue' => 0];
            $suppliers[$id]['total'] += $invoice['total'];
            $suppliers[$id]['paid'] += $invoice['total'] - $invoice['balance'];
            $suppliers[$id]['balance'] += $invoice['balance'];
            if ($invoice['dueDate'] && $invoice['dueDate'] < $today) {
                $suppliers[$id]['overdue'] += $invoice['balance'];
            }
        }

        return $suppliers;
    }

    public static function unlinkedDeliveries(): int
    {
        $linked = [];
        foreach (self::invoices() as $invoice) {
            foreach ($invoice['lines'] as $line) {
                $linked[] = $line['id'];
            }
        }

        return count(array_filter(PosStore::get('demo.receiving', []), fn (array $entry): bool => ! in_array($entry['id'], $linked, true)));
    }

    public static function pay(string $eventId, string $invoiceId, string $amount, string $method, bool $confirmed,
        string $reference, string $notes, ?string $cashSessionId = null): array
    {
        if (PosStore::needsTransaction()) {
            return PosStore::atomic(fn () => self::pay($eventId, $invoiceId, $amount, $method, $confirmed, $reference, $notes, $cashSessionId));
        }

        $payments = self::payments();
        foreach ($payments as $payment) {
            if ($payment['eventId'] === $eventId) {
                return $payment;
            }
        }
        $reference = trim($reference);
        $notes = trim($notes);
        Validator::make(compact('method', 'confirmed', 'reference', 'notes'), [
            'method' => 'required|in:Cash,GCash,Maya', 'confirmed' => 'accepted',
            'reference' => $method === 'Cash' ? 'nullable|string|max:100' : 'required|string|max:100',
            'notes' => 'nullable|string|max:500',
        ])->validate();
        $invoices = self::invoices();
        $invoice = $invoices[$invoiceId] ?? null;
        if (! $invoice || $invoice['balance'] === 0) {
            throw ValidationException::withMessages(['invoiceId' => 'Select an unpaid supplier bill.']);
        }
        $minor = SessionCash::money($amount, 'paymentAmount');
        if ($minor < 1 || $minor > $invoice['balance']) {
            throw ValidationException::withMessages(['paymentAmount' => 'Enter a positive amount no greater than the remaining balance.']);
        }
        $id = (string) Str::uuid();
        $number = 'SPAY-'.str_pad((string) (count($payments) + 1), 5, '0', STR_PAD_LEFT);
        $movement = SessionCash::stageMovement($eventId, 'Supplier payment', $id, $minor, $method, 'out',
            $number.' · '.$invoice['number'], $notes, $cashSessionId, 'paymentAmount');
        $invoice['balance'] -= $minor;
        $invoices[$invoiceId] = $invoice;
        $payment = ['id' => $id, 'eventId' => $eventId, 'number' => $number, 'invoiceId' => $invoiceId,
            'invoiceNumber' => $invoice['number'], 'orderNumber' => $invoice['orderNumber'],
            'supplierId' => $invoice['supplierId'], 'supplier' => $invoice['supplier'], 'amount' => $minor,
            'balanceAfter' => $invoice['balance'], 'method' => $method, 'reference' => $reference, 'notes' => $notes,
            'time' => $movement['time'], 'cashSessionId' => $movement['cashSessionId'], 'operator' => 'Owner'];
        $payments[$id] = $payment;
        $movements = SessionCash::movements();
        $movements[] = $movement;
        PosStore::put(['demo.supplierInvoices' => $invoices, 'demo.supplierPayments' => $payments, 'demo.cashMovements' => $movements]);

        return $payment;
    }
}
