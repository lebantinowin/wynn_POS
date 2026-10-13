<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SessionBatches
{
    public static function state(): array
    {
        $state = ['batches' => PosStore::get('demo.batches', []), 'products' => PosStore::get('demo.batchProducts', []),
            'movements' => PosStore::get('demo.batchMovements', [])];

        return self::stateFor(DemoCatalogue::products(), $state);
    }

    public static function stateFor(array $products, array $state): array
    {
        foreach ($products as $product) {
            if (in_array($product['id'], $state['products'], true)) {
                continue;
            }
            $state['products'][] = $product['id'];
            if ($product['stock'] > 0) {
                $id = 'legacy-'.$product['id'];
                $state['batches'][$id] = ['id' => $id, 'productId' => $product['id'], 'name' => $product['name'], 'unit' => $product['unit'],
                    'source' => 'Legacy stock', 'sourceId' => $id, 'reference' => 'Stock before batch tracking', 'expiry' => '',
                    'initialQuantity' => $product['stock'], 'remaining' => $product['stock'], 'totalCost' => null, 'time' => null];
            }
        }

        return $state;
    }

    public static function changes(array $state): array
    {
        return ['demo.batches' => $state['batches'], 'demo.batchProducts' => $state['products'], 'demo.batchMovements' => $state['movements']];
    }

    public static function add(array &$state, array $product, int $quantity, string $source, string $sourceId,
        string $reference, string $expiry = '', ?int $totalCost = null): ?string
    {
        if (! in_array($product['id'], $state['products'], true)) {
            $state['products'][] = $product['id'];
        }
        if ($quantity === 0) {
            return null;
        }
        $id = (string) Str::uuid();
        $state['batches'][$id] = ['id' => $id, 'productId' => $product['id'], 'name' => $product['name'], 'unit' => $product['unit'],
            'source' => $source, 'sourceId' => $sourceId, 'reference' => $reference, 'expiry' => $expiry,
            'initialQuantity' => $quantity, 'remaining' => $quantity, 'totalCost' => $totalCost, 'time' => app(OfflineCheckout::class)->time()->toIso8601String()];
        self::movement($state, $id, $quantity, 0, $quantity, $source, $sourceId);

        return $id;
    }

    private static function movement(array &$state, string $batchId, int $quantity, int $before, int $after,
        string $source, string $sourceId): void
    {
        $state['movements'][] = ['batchId' => $batchId, 'productId' => $state['batches'][$batchId]['productId'],
            'quantity' => $quantity, 'before' => $before, 'after' => $after, 'source' => $source, 'sourceId' => $sourceId,
            'time' => app(OfflineCheckout::class)->time()->toIso8601String()];
    }

    public static function consume(array &$state, int $productId, int $quantity, string $source, string $sourceId,
        string $field, bool $forSale = false, string $batchId = ''): array
    {
        $today = app(OfflineCheckout::class)->time()->timezone('Asia/Shanghai')->toDateString();
        $candidates = array_filter($state['batches'], fn (array $batch): bool => $batch['productId'] === $productId
            && $batch['remaining'] > 0 && ($batchId === '' || $batch['id'] === $batchId)
            && (! $forSale || $batch['expiry'] === '' || $batch['expiry'] >= $today));
        uasort($candidates, fn (array $a, array $b): int => [$a['expiry'] ?: '9999-12-31', $a['time'] ?? '']
            <=> [$b['expiry'] ?: '9999-12-31', $b['time'] ?? '']);
        if ($quantity > array_sum(array_column($candidates, 'remaining'))) {
            throw ValidationException::withMessages([$field => $forSale
                ? 'Not enough sellable batch stock. Known expired batches are excluded; review Inventory.'
                : 'Quantity exceeds the remaining stock in the selected batches.']);
        }
        $allocations = [];
        foreach ($candidates as $id => $batch) {
            if ($quantity === 0) {
                break;
            }
            $taken = min($quantity, $batch['remaining']);
            $costSegments = BatchCost::take($state['batches'][$id], $taken);
            $state['batches'][$id]['remaining'] -= $taken;
            self::movement($state, $id, -$taken, $batch['remaining'], $state['batches'][$id]['remaining'], $source, $sourceId);
            $allocations[] = ['batchId' => $id, 'quantity' => $taken, 'expiry' => $batch['expiry'], 'source' => $batch['source'],
                'sourceId' => $batch['sourceId'], 'reference' => $batch['reference'],
                'originalQuantity' => $batch['initialQuantity'], 'originalTotalCost' => $batch['totalCost'],
                'costSegments' => $costSegments, 'cost' => BatchCost::total($costSegments)];
            $quantity -= $taken;
        }

        return $allocations;
    }

    public static function returnAllocations(array $saleLine, int $alreadyReturned, int $quantity): array
    {
        $allocations = [];
        foreach ($saleLine['batchAllocations'] ?? [] as $allocation) {
            $skip = min($alreadyReturned, $allocation['quantity']);
            $alreadyReturned -= $skip;
            $take = min($quantity, $allocation['quantity'] - $skip);
            if ($take > 0) {
                if (isset($allocation['costSegments'])) {
                    $allocation['costSegments'] = BatchCost::slice($allocation['costSegments'], $skip, $take);
                    $allocation['cost'] = BatchCost::total($allocation['costSegments']);
                }
                $allocation['quantity'] = $take;
                $allocations[] = $allocation;
                $quantity -= $take;
            }
        }
        if (isset($saleLine['batchAllocations']) && $quantity !== 0) {
            throw ValidationException::withMessages(['return' => 'The original batch allocation does not cover this return.']);
        }

        return $allocations;
    }

    public static function restore(array &$state, array $product, array $allocations, int $quantity, bool $restock, string $returnId): array
    {
        if ($allocations === []) {
            if ($restock) {
                $id = self::add($state, $product, $quantity, 'Legacy return', $returnId, 'Older receipt · expiry unknown');

                return [['batchId' => $id, 'quantity' => $quantity, 'expiry' => '']];
            }

            return [];
        }
        foreach ($allocations as $allocation) {
            $id = $allocation['batchId'];
            if (! isset($state['batches'][$id]) || $state['batches'][$id]['productId'] !== $product['id']) {
                throw ValidationException::withMessages(['return' => 'The original inventory batch is missing.']);
            }
            $before = $state['batches'][$id]['remaining'];
            $added = $restock ? $allocation['quantity'] : 0;
            if ($restock) {
                BatchCost::restore($state['batches'][$id], $allocation);
            }
            $state['batches'][$id]['remaining'] += $added;
            self::movement($state, $id, $added, $before, $before + $added, $restock ? 'Return restock' : 'Return discard', $returnId);
        }

        return $allocations;
    }

    public static function assertBalanced(array $state, array $products): void
    {
        foreach ($state['batches'] as $batch) {
            if (array_sum(array_column(BatchCost::segments($batch), 'quantity')) !== $batch['remaining']) {
                throw ValidationException::withMessages(['batches' => 'Batch cost quantities do not match remaining stock.']);
            }
        }
        foreach ($products as $product) {
            $total = array_sum(array_column(array_filter($state['batches'], fn (array $batch): bool => $batch['productId'] === $product['id']), 'remaining'));
            if ($total !== $product['stock']) {
                throw ValidationException::withMessages(['batches' => 'Batch balances do not match product stock. Reload and review Inventory before recording another movement.']);
            }
        }
    }

    public static function overview(): array
    {
        $state = self::state();
        $today = app(OfflineCheckout::class)->time()->timezone('Asia/Shanghai')->toDateString();
        $soon = now('Asia/Shanghai')->addDays(30)->toDateString();
        $totals = ['expired' => 0, 'soon' => 0, 'unknown' => 0, 'available' => 0, 'knownCost' => 0, 'unknownCostUnits' => 0];
        $products = [];
        $batches = $state['batches'];
        foreach ($batches as &$batch) {
            $batch['remainingCost'] = BatchCost::total(BatchCost::segments($batch));
            $valuation = BatchCost::summarize([['quantity' => $batch['remaining'], 'costSegments' => BatchCost::segments($batch)]]);
            $totals['knownCost'] += $valuation['knownCost'];
            $totals['unknownCostUnits'] += $valuation['unknownUnits'];
            $batch['status'] = $batch['remaining'] === 0 ? 'Depleted' : ($batch['expiry'] === '' ? 'Expiry unknown'
                : ($batch['expiry'] < $today ? 'Expired' : ($batch['expiry'] <= $soon ? 'Due within 30 days' : 'In date')));
            $products[$batch['productId']] ??= ['total' => 0, 'sellable' => 0, 'expired' => 0, 'soon' => 0, 'unknown' => 0];
            $products[$batch['productId']]['total'] += $batch['remaining'];
            $key = match ($batch['status']) {
                'Expired' => 'expired', 'Due within 30 days' => 'soon', 'Expiry unknown' => 'unknown', default => null,
            };
            if ($key !== null) {
                $totals[$key] += $batch['remaining'];
                $products[$batch['productId']][$key] += $batch['remaining'];
            }
            if ($batch['status'] !== 'Expired') {
                $totals['available'] += $batch['remaining'];
                $products[$batch['productId']]['sellable'] += $batch['remaining'];
            }
        }
        unset($batch);
        uasort($batches, fn (array $a, array $b): int => [$a['expiry'] ?: '9999-12-31', $a['name'], $a['id']]
            <=> [$b['expiry'] ?: '9999-12-31', $b['name'], $b['id']]);

        $references = [];
        foreach (SessionSales::all() as $sale) {
            $references[$sale['id']] = $sale['number'];
        }
        foreach (SessionReturns::all() as $return) {
            $references[$return['id']] = $return['number'];
        }
        foreach (SessionLosses::all() as $loss) {
            $references[$loss['id']] = $loss['reason'].' · '.$loss['notes'];
        }
        foreach (PosStore::get('demo.counts', []) as $count) {
            if (isset($count['id'])) {
                $references[$count['id']] = $count['reason'];
            }
        }
        $movements = $state['movements'];
        foreach ($movements as &$movement) {
            $movement['reference'] = $references[$movement['sourceId']] ?? ($batches[$movement['batchId']]['reference'] ?? $movement['sourceId']);
        }
        unset($movement);

        return ['batches' => $batches, 'products' => $products, 'totals' => $totals, 'movements' => $movements];
    }
}
