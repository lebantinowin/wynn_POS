<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SessionReturns
{
    public static function all(): array
    {
        return PosStore::get('demo.returns', []);
    }

    public static function saleTotals(string $saleId): array
    {
        $total = 0;
        $quantities = [];
        foreach (self::all() as $return) {
            if ($return['saleId'] !== $saleId) {
                continue;
            }
            $total += $return['total'];
            foreach ($return['lines'] as $line) {
                $quantities[$line['lineIndex']] = ($quantities[$line['lineIndex']] ?? 0) + $line['quantity'];
            }
        }

        return compact('total', 'quantities');
    }

    public static function remaining(string $saleId): array
    {
        $sale = SessionSales::all()[$saleId] ?? null;
        if (! $sale) {
            throw ValidationException::withMessages(['return' => 'Select an available receipt.']);
        }
        $totals = self::saleTotals($saleId);
        $lines = [];
        foreach ($sale['lines'] as $index => $line) {
            $lines[$index] = $line + ['remaining' => $line['quantity'] - ($totals['quantities'][$index] ?? 0)];
        }

        return $lines;
    }

    public static function preview(string $saleId, array $inputs): array
    {
        $remaining = self::remaining($saleId);
        Validator::make(['returnLines' => $inputs], [
            'returnLines' => 'required|array', 'returnLines.*.quantity' => 'required|integer|min:0|max:9999',
            'returnLines.*.disposition' => 'required|in:Restock,Discard',
        ])->validate();
        if (array_keys($inputs) !== array_keys($remaining)) {
            throw ValidationException::withMessages(['return' => 'Return lines must match the original receipt.']);
        }
        $lines = [];
        foreach ($remaining as $index => $line) {
            $quantity = (int) $inputs[$index]['quantity'];
            if ($quantity > $line['remaining']) {
                throw ValidationException::withMessages(["returnLines.$index.quantity" => 'Quantity exceeds what remains returnable on this receipt.']);
            }
            if ($quantity === 0) {
                continue;
            }
            $alreadyReturned = $line['quantity'] - $line['remaining'];
            $allocations = SessionBatches::returnAllocations($line, $alreadyReturned * $line['units'], $line['units'] * $quantity);
            if (($line['costMethod'] ?? '') === 'batch-v1' && array_sum(array_column($allocations, 'quantity')) !== $line['units'] * $quantity) {
                throw ValidationException::withMessages(['return' => 'The saved cost allocation is missing. Review the original receipt before returning stock.']);
            }
            $cost = ($line['costMethod'] ?? '') === 'batch-v1' ? BatchCost::summarize($allocations)
                : ['cost' => ($line['cost'] ?? null) !== null
                    ? BatchCost::portion($line['cost'], $line['quantity'], $alreadyReturned + $quantity)
                        - BatchCost::portion($line['cost'], $line['quantity'], $alreadyReturned)
                    : null];
            $lines[] = ['lineIndex' => $index, 'productId' => $line['productId'], 'name' => $line['name'],
                'label' => $line['label'], 'units' => $line['units'], 'quantity' => $quantity, 'baseQuantity' => $line['units'] * $quantity,
                'price' => $line['price'], 'total' => $line['price'] * $quantity,
                'costMethod' => $line['costMethod'] ?? 'legacy', 'batchAllocations' => $allocations,
                'disposition' => $inputs[$index]['disposition']] + $cost;
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['return' => 'Enter a return quantity on at least one line.']);
        }
        $total = array_sum(array_column($lines, 'total'));
        $sale = SessionSales::all()[$saleId];
        $debtId = null;
        $creditReduction = 0;
        if ($sale['method'] === 'Credit') {
            foreach (SessionCredit::debts() as $debt) {
                if (($debt['saleId'] ?? null) === $saleId && $debt['type'] === 'Grocery credit') {
                    $debtId = $debt['id'];
                    $creditReduction = min($debt['balance'], $total);
                    break;
                }
            }
            if ($debtId === null) {
                throw ValidationException::withMessages(['return' => 'The linked grocery debt is missing. This return cannot be recorded.']);
            }
        }

        return ['saleId' => $saleId, 'saleNumber' => $sale['number'], 'saleMethod' => $sale['method'],
            'customer' => $sale['customer'] ?? null, 'lines' => $lines, 'total' => $total,
            'debtId' => $debtId, 'creditReduction' => $creditReduction, 'refund' => $total - $creditReduction];
    }

    public static function complete(string $eventId, string $saleId, array $inputs, string $method, bool $confirmed, string $reason, ?string $cashSessionId = null): array
    {
        if (PosStore::needsTransaction()) {
            return PosStore::atomic(fn () => self::complete($eventId, $saleId, $inputs, $method, $confirmed, $reason, $cashSessionId));
        }

        $returns = self::all();
        foreach ($returns as $return) {
            if ($return['eventId'] === $eventId) {
                return $return;
            }
        }
        $reason = trim($reason);
        Validator::make(compact('method', 'reason'), ['method' => 'required|in:Cash,GCash,Maya', 'reason' => 'required|string|min:3|max:500'])->validate();
        $preview = self::preview($saleId, $inputs);
        $cashSession = SessionCash::requireOpen($cashSessionId);
        if ($preview['refund'] > 0 && ! $confirmed) {
            throw ValidationException::withMessages(['refundConfirmed' => 'Confirm that the refund has been handed or sent to the customer.']);
        }
        $id = (string) Str::uuid();
        $number = 'RET-'.str_pad((string) (count($returns) + 1), 5, '0', STR_PAD_LEFT);
        $time = now()->toIso8601String();
        $products = DemoCatalogue::products();
        $batches = SessionBatches::state();
        $stockMovements = PosStore::get('demo.returnMovements', []);
        $losses = SessionLosses::all();
        foreach ($preview['lines'] as &$line) {
            $index = array_search($line['productId'], array_column($products, 'id'), true);
            if ($index === false) {
                throw ValidationException::withMessages(['return' => 'A returned product is no longer in the catalogue.']);
            }
            $product = &$products[$index];
            $before = $product['stock'];
            if ($line['disposition'] === 'Restock') {
                if ($before + $line['baseQuantity'] > 100000000) {
                    throw ValidationException::withMessages(['return' => 'Restocking would exceed the supported stock limit.']);
                }
                $product['stock'] += $line['baseQuantity'];
            } else {
                $losses[] = ['id' => (string) Str::uuid(), 'eventId' => $eventId.':'.$line['lineIndex'], 'returnId' => $id,
                    'productId' => $line['productId'], 'name' => $line['name'], 'option' => $line['label'], 'quantity' => $line['quantity'],
                    'baseQuantity' => $line['baseQuantity'], 'reason' => 'Other', 'notes' => "Returned goods discarded · $number · $reason",
                    'unitCost' => null, 'costBasis' => 'Original returned sale cost',
                    'knownCost' => $line['knownCost'] ?? ($line['cost'] ?? 0),
                    'totalCost' => $line['cost'], 'before' => $before, 'after' => $before, 'actor' => 'Owner', 'time' => $time];
            }
            $line['batchAllocations'] = SessionBatches::restore($batches, $product, $line['batchAllocations'],
                $line['baseQuantity'], $line['disposition'] === 'Restock', $id);
            if ($line['disposition'] === 'Discard') {
                $losses[array_key_last($losses)]['batchAllocations'] = $line['batchAllocations'];
            }
            $stockMovements[] = ['returnId' => $id, 'productId' => $line['productId'],
                'quantity' => $line['disposition'] === 'Restock' ? $line['baseQuantity'] : 0,
                'before' => $before, 'after' => $product['stock'], 'disposition' => $line['disposition'], 'time' => $time];
            unset($product);
        }
        unset($line);
        SessionBatches::assertBalanced($batches, $products);
        $record = $preview + ['id' => $id, 'eventId' => $eventId, 'number' => $number, 'shop' => config('app.name'),
            'method' => $preview['refund'] > 0 ? $method : null, 'reason' => $reason, 'time' => $time,
            'operator' => 'Owner', 'counter' => 'Counter 1', 'cashSessionId' => $cashSession['id']];
        $returns[$id] = $record;
        $changes = ['demo.products' => $products, 'demo.returns' => $returns, 'demo.returnMovements' => $stockMovements, 'demo.losses' => $losses]
            + SessionBatches::changes($batches);
        if ($preview['debtId'] !== null) {
            $debts = SessionCredit::debts();
            $debts[$preview['debtId']]['balance'] -= $preview['creditReduction'];
            $debts[$preview['debtId']]['returnCredit'] = ($debts[$preview['debtId']]['returnCredit'] ?? 0) + $preview['creditReduction'];
            $changes['demo.debts'] = $debts;
        }
        if ($preview['refund'] > 0) {
            $movements = SessionCash::movements();
            $movements[] = SessionCash::stageMovement($eventId, 'Return refund', $id, $preview['refund'], $method, 'out',
                $number.' · '.$preview['saleNumber'], $reason, $cashSession['id'], 'refund');
            $changes['demo.cashMovements'] = $movements;
        }
        PosStore::put($changes);

        return $record;
    }
}
