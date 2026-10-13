<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class BatchCost
{
    /** @return array<int, array{quantity: int, offset: int, basisQuantity: int, basisCost: ?int}> */
    public static function segments(array $batch): array
    {
        $segments = $batch['costSegments'] ?? ($batch['remaining'] === 0 ? [] : [[
            'quantity' => $batch['remaining'], 'offset' => $batch['initialQuantity'] - $batch['remaining'],
            'basisQuantity' => $batch['initialQuantity'], 'basisCost' => $batch['totalCost'],
        ]]);
        foreach ($segments as $segment) {
            if ($segment['quantity'] < 1 || $segment['offset'] < 0 || $segment['basisQuantity'] < 1
                || $segment['basisQuantity'] < $segment['offset'] + $segment['quantity']
                || ($segment['basisCost'] !== null && $segment['basisCost'] < 0)) {
                throw ValidationException::withMessages(['batches' => 'A batch cost allocation is invalid. Review Inventory before recording changes.']);
            }
        }

        return $segments;
    }

    public static function portion(int $cost, int $units, int $quantity): int
    {
        return intdiv($cost, $units) * $quantity + intdiv(($cost % $units) * $quantity, $units);
    }

    public static function total(array $segments): ?int
    {
        $total = 0;
        foreach ($segments as $segment) {
            if ($segment['basisCost'] === null) {
                return null;
            }
            $total += self::portion($segment['basisCost'], $segment['basisQuantity'], $segment['offset'] + $segment['quantity'])
                - self::portion($segment['basisCost'], $segment['basisQuantity'], $segment['offset']);
        }

        return $total;
    }

    public static function slice(array $segments, int $offset, int $quantity): array
    {
        $result = [];
        foreach ($segments as $segment) {
            $skip = min($offset, $segment['quantity']);
            $offset -= $skip;
            $take = min($quantity, $segment['quantity'] - $skip);
            if ($take > 0) {
                $segment['offset'] += $skip;
                $segment['quantity'] = $take;
                $result[] = $segment;
                $quantity -= $take;
            }
        }
        if ($quantity !== 0 || $offset !== 0) {
            throw ValidationException::withMessages(['batches' => 'Recorded batch costs do not cover the stock quantity. Reload and review Inventory.']);
        }

        return $result;
    }

    public static function take(array &$batch, int $quantity): array
    {
        $segments = self::segments($batch);
        if (array_sum(array_column($segments, 'quantity')) !== $batch['remaining']) {
            throw ValidationException::withMessages(['batches' => 'Batch cost quantities do not match remaining stock.']);
        }
        $taken = self::slice($segments, 0, $quantity);
        $batch['costSegments'] = self::slice($segments, $quantity, $batch['remaining'] - $quantity);

        return $taken;
    }

    public static function restore(array &$batch, array $allocation): void
    {
        $segments = $allocation['costSegments'] ?? [[
            'quantity' => $allocation['quantity'], 'offset' => 0,
            'basisQuantity' => $allocation['quantity'], 'basisCost' => null,
        ]];
        $batch['costSegments'] = [...self::segments($batch), ...$segments];
    }

    /** @return array{cost: ?int, knownCost: int, unknownUnits: int} */
    public static function summarize(array $allocations): array
    {
        $knownCost = 0;
        $unknownUnits = 0;
        foreach ($allocations as $allocation) {
            if (isset($allocation['costSegments']) && array_sum(array_column($allocation['costSegments'], 'quantity')) !== $allocation['quantity']) {
                throw ValidationException::withMessages(['batches' => 'Saved cost quantities do not match the allocated stock.']);
            }
            foreach ($allocation['costSegments'] ?? [['quantity' => $allocation['quantity'], 'basisCost' => null]] as $segment) {
                if ($segment['basisCost'] === null) {
                    $unknownUnits += $segment['quantity'];
                } else {
                    $knownCost += self::total([$segment]);
                }
            }
        }

        return ['cost' => $unknownUnits === 0 ? $knownCost : null, 'knownCost' => $knownCost, 'unknownUnits' => $unknownUnits];
    }
}
