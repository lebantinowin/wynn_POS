<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SessionCash
{
    public const CATEGORIES = ['Utilities', 'Transport', 'Supplies', 'Rent', 'Maintenance', 'Other'];

    public static function all(): array
    {
        return session('demo.cashSessions', []);
    }

    public static function movements(): array
    {
        return session('demo.cashMovements', []);
    }

    public static function expenses(): array
    {
        return session('demo.expenses', []);
    }

    public static function active(): ?array
    {
        foreach (self::all() as $cashSession) {
            if ($cashSession['status'] === 'Open') {
                return $cashSession;
            }
        }

        return null;
    }

    public static function requireOpen(?string $expectedId = null): array
    {
        $cashSession = self::active();
        if (! $cashSession) {
            throw ValidationException::withMessages(['cashSession' => 'Open a cash session in Shifts & Expenses first.']);
        }
        if ($expectedId !== null && $expectedId !== $cashSession['id']) {
            throw ValidationException::withMessages(['cashSession' => 'The cash session changed. Reload this screen before recording another transaction.']);
        }

        return $cashSession;
    }

    public static function money(string $amount, string $field): int
    {
        Validator::make([$field => $amount], [$field => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/']])->validate();
        [$whole,$fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function open(string $eventId, string $opening, string $notes): array
    {
        $sessions = self::all();
        foreach ($sessions as $cashSession) {
            if ($cashSession['eventId'] === $eventId) {
                return $cashSession;
            }
        }
        if (self::active()) {
            throw ValidationException::withMessages(['cashSession' => 'A cash session is already open for Counter 1.']);
        }
        $amount = self::money($opening, 'opening');
        $notes = trim($notes);
        Validator::make(compact('notes'), ['notes' => 'nullable|string|max:500'])->validate();
        $id = (string) Str::uuid();
        $cashSession = ['id' => $id, 'eventId' => $eventId, 'number' => 'SHIFT-'.str_pad((string) (count($sessions) + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'Open', 'opening' => $amount, 'notes' => $notes, 'operator' => 'Owner', 'counter' => 'Counter 1',
            'openedAt' => now()->toIso8601String(), 'closedAt' => null, 'closeEventId' => null, 'counted' => null, 'difference' => null,
            'closeNotes' => '', 'summary' => null];
        $sessions[$id] = $cashSession;
        session()->put('demo.cashSessions', $sessions);

        return $cashSession;
    }

    public static function summary(string $id): array
    {
        $cashSession = self::all()[$id] ?? null;
        if (! $cashSession) {
            throw ValidationException::withMessages(['cashSession' => 'Cash session not found.']);
        }
        if ($cashSession['status'] === 'Closed') {
            return $cashSession['summary'];
        }
        $methods = ['Cash' => ['in' => 0, 'out' => 0], 'GCash' => ['in' => 0, 'out' => 0], 'Maya' => ['in' => 0, 'out' => 0]];
        $sources = ['Sale' => ['in' => 0, 'out' => 0], 'Repayment' => ['in' => 0, 'out' => 0], 'Cash loan' => ['in' => 0, 'out' => 0],
            'Expense' => ['in' => 0, 'out' => 0], 'Manual cash' => ['in' => 0, 'out' => 0], 'Return refund' => ['in' => 0, 'out' => 0]];
        foreach (self::movements() as $entry) {
            if ($entry['cashSessionId'] === $id) {
                $methods[$entry['method']][$entry['direction']] += $entry['amount'];
                if ($entry['method'] === 'Cash') {
                    $sources[$entry['source']][$entry['direction']] += $entry['amount'];
                }
            }
        }
        $credit = 0;
        $transactions = 0;
        foreach (SessionSales::all() as $sale) {
            if (($sale['cashSessionId'] ?? null) === $id) {
                $transactions++;
                $credit += $sale['method'] === 'Credit' ? $sale['total'] : 0;
            }
        }
        foreach (SessionReturns::all() as $return) {
            if ($return['cashSessionId'] === $id && $return['saleMethod'] === 'Credit') {
                $credit -= $return['total'];
            }
        }

        return ['methods' => $methods, 'sources' => $sources, 'transactions' => $transactions, 'credit' => $credit,
            'expected' => $cashSession['opening'] + $methods['Cash']['in'] - $methods['Cash']['out']];
    }

    public static function stageMovement(string $eventId, string $source, string $sourceId, int $amount, string $method,
        string $direction, string $reference, string $notes = '', ?string $expectedId = null, string $errorField = 'amount'): array
    {
        $active = self::active();
        if ($method === 'Cash' || $expectedId !== null) {
            $active = self::requireOpen($expectedId);
        }
        if ($method === 'Cash' && $direction === 'out' && $amount > self::summary($active['id'])['expected']) {
            throw ValidationException::withMessages([$errorField => 'Amount exceeds expected drawer cash. Record a cash addition first if more money has been put in the drawer.']);
        }

        return ['id' => (string) Str::uuid(), 'eventId' => $eventId, 'cashSessionId' => $active['id'] ?? null,
            'source' => $source, 'sourceId' => $sourceId, 'amount' => $amount, 'method' => $method, 'direction' => $direction,
            'reference' => $reference, 'notes' => $notes, 'time' => now()->toIso8601String(), 'operator' => 'Owner'];
    }

    public static function recordMovement(string $eventId, string $direction, string $amount, string $reason, ?string $expectedId = null): void
    {
        $movements = self::movements();
        foreach ($movements as $movement) {
            if ($movement['source'] === 'Manual cash' && $movement['eventId'] === $eventId) {
                return;
            }
        }
        $reason = trim($reason);
        Validator::make(compact('direction', 'reason'), ['direction' => 'required|in:in,out', 'reason' => 'required|string|min:3|max:500'])->validate();
        $entry = self::stageMovement($eventId, 'Manual cash', (string) Str::uuid(), SessionCredit::amount($amount), 'Cash',
            $direction, 'Drawer adjustment', $reason, $expectedId);
        $movements[] = $entry;
        session()->put('demo.cashMovements', $movements);
    }

    public static function expense(string $eventId, string $amount, string $category, string $method, string $notes, ?string $expectedId = null): void
    {
        $expenses = self::expenses();
        foreach ($expenses as $expense) {
            if ($expense['eventId'] === $eventId) {
                return;
            }
        }
        $notes = trim($notes);
        Validator::make(compact('category', 'method', 'notes'), [
            'category' => 'required|in:'.implode(',', self::CATEGORIES), 'method' => 'required|in:Cash,GCash,Maya',
            'notes' => 'required|string|min:3|max:500'])->validate();
        $minor = SessionCredit::amount($amount, 'expenseAmount');
        $id = (string) Str::uuid();
        $number = 'EXP-'.str_pad((string) (count($expenses) + 1), 5, '0', STR_PAD_LEFT);
        $movement = self::stageMovement($eventId, 'Expense', $id, $minor, $method, 'out', $number, $notes, $expectedId, 'expenseAmount');
        $expense = ['id' => $id, 'eventId' => $eventId, 'number' => $number, 'amount' => $minor, 'category' => $category,
            'method' => $method, 'notes' => $notes, 'time' => $movement['time'], 'cashSessionId' => $movement['cashSessionId'], 'operator' => 'Owner'];
        $expenses[$id] = $expense;
        $movements = self::movements();
        $movements[] = $movement;
        session()->put(['demo.expenses' => $expenses, 'demo.cashMovements' => $movements]);
    }

    public static function close(string $id, string $eventId, string $counted, string $closeNotes): array
    {
        $sessions = self::all();
        $cashSession = $sessions[$id] ?? null;
        if ($cashSession && $cashSession['closeEventId'] === $eventId) {
            return $cashSession;
        }
        self::requireOpen($id);
        $amount = self::money($counted, 'counted');
        $summary = self::summary($id);
        $difference = $amount - $summary['expected'];
        $closeNotes = trim($closeNotes);
        Validator::make(compact('closeNotes'), ['closeNotes' => $difference !== 0 ? 'required|string|min:3|max:500' : 'nullable|string|max:500'])->validate();
        $cashSession['status'] = 'Closed';
        $cashSession['closedAt'] = now()->toIso8601String();
        $cashSession['closeEventId'] = $eventId;
        $cashSession['counted'] = $amount;
        $cashSession['difference'] = $difference;
        $cashSession['closeNotes'] = $closeNotes;
        $cashSession['summary'] = $summary;
        $sessions[$id] = $cashSession;
        session()->put('demo.cashSessions', $sessions);

        return $cashSession;
    }
}
