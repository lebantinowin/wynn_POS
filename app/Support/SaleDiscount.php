<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaleDiscount
{
    /** @return array{lines: array, subtotal: int, discount: int, discountReason: string, total: int} */
    public static function apply(array $lines, string $amount = '', string $reason = ''): array
    {
        $subtotal = array_sum(array_column($lines, 'total'));
        if (! is_int($subtotal) || $subtotal < 0 || $subtotal > 9007199254740991) {
            throw ValidationException::withMessages(['cart' => 'The sale total exceeds the supported amount.']);
        }
        $discount = 0;
        if ($amount !== '') {
            if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount)) {
                throw ValidationException::withMessages(['discount' => 'Enter a discount in pesos with at most two decimal places.']);
            }
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
            $discount = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        }
        if ($discount > 0 && $discount >= $subtotal) {
            throw ValidationException::withMessages(['discount' => 'Discount must be less than the subtotal. The final total must be at least ₱0.01.']);
        }
        $reason = trim($reason);
        Validator::make(['discountReason' => $reason], ['discountReason' => $discount > 0 ? 'required|string|min:3|max:200' : 'nullable|string|max:200'])->validate();
        $cumulative = 0;
        $allocated = 0;
        $allocationOrder = array_keys($lines);
        usort($allocationOrder, fn (int $left, int $right): int => isset($lines[$left]['id'], $lines[$right]['id'])
            ? strcmp((string) $lines[$left]['id'], (string) $lines[$right]['id']) : $left <=> $right);
        foreach ($allocationOrder as $index) {
            $line = &$lines[$index];
            $line['subtotal'] = $line['total'];
            $cumulative += $line['subtotal'];
            $share = $discount === 0 ? 0 : self::share($discount, $subtotal, $cumulative);
            $line['discount'] = $share - $allocated;
            $line['total'] -= $line['discount'];
            $allocated = $share;
        }
        unset($line);

        return ['lines' => $lines, 'subtotal' => $subtotal, 'discount' => $discount,
            'discountReason' => $discount > 0 ? $reason : '', 'total' => $subtotal - $discount];
    }

    /** Calculate floor(amount * part / whole) without overflowing the integer product. */
    private static function share(int $amount, int $whole, int $part): int
    {
        $quotient = 0;
        $remainder = 0;
        foreach (str_split(decbin($part)) as $bit) {
            $quotient *= 2;
            $remainder = $remainder * 2 + ($bit === '1' ? $amount : 0);
            $quotient += intdiv($remainder, $whole);
            $remainder %= $whole;
        }

        return $quotient;
    }
}
