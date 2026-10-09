<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SessionLosses
{
    public const REASONS = ['Unpaid consumption', 'Damage', 'Expired', 'Missing stock', 'Other'];

    public static function all(): array
    {
        return session('demo.losses', []);
    }

    public static function record(string $eventId, string $optionId, mixed $quantity, string $reason, string $notes, string $unitCost): void
    {
        $losses = self::all();
        foreach ($losses as $loss) {
            if ($loss['eventId'] === $eventId) {
                return;
            }
        }
        $notes = trim($notes);
        Validator::make(compact('optionId', 'quantity', 'reason', 'notes', 'unitCost'), [
            'optionId' => 'required|string', 'quantity' => 'required|integer|min:1|max:100000',
            'reason' => 'required|in:'.implode(',', self::REASONS), 'notes' => 'required|string|min:3|max:500',
            'unitCost' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ])->validate();
        $option = DemoCatalogue::option($optionId);
        if (! $option) {
            throw ValidationException::withMessages(['optionId' => 'Select an available product option.']);
        }
        $product = DemoCatalogue::find($option['productId']);
        $baseQuantity = (int) $quantity * $option['units'];
        if ($baseQuantity > $product['stock']) {
            throw ValidationException::withMessages(['quantity' => 'Loss exceeds the recorded stock.']);
        }
        $cost = null;
        if ($unitCost !== '') {
            [$whole,$fraction] = array_pad(explode('.', $unitCost, 2), 2, '');
            $cost = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        }
        $before = $product['stock'];
        $product['stock'] -= $baseQuantity;
        $products = DemoCatalogue::products();
        $index = array_search($product['id'], array_column($products, 'id'), true);
        $products[$index] = $product;
        $losses[] = ['id' => (string) Str::uuid(), 'eventId' => $eventId, 'productId' => $product['id'], 'name' => $product['name'],
            'option' => $option['label'], 'quantity' => (int) $quantity, 'baseQuantity' => $baseQuantity, 'reason' => $reason,
            'notes' => trim($notes), 'unitCost' => $cost, 'totalCost' => $cost !== null ? $cost * (int) $quantity : null,
            'before' => $before, 'after' => $product['stock'], 'actor' => 'Owner', 'time' => now()->toIso8601String()];
        session()->put(['demo.products' => $products, 'demo.losses' => $losses]);
    }
}
