<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CheckoutCarts
{
    public static function available(): bool
    {
        return ! PosStore::database() || PosStore::connection()->getSchemaBuilder()->hasTable('checkout_carts');
    }

    public static function held(): array
    {
        if (! self::available()) {
            return [];
        }
        if (! PosStore::database()) {
            return array_filter(session('checkout.carts', []), fn (array $record): bool => $record['status'] === 'Held');
        }

        return PosStore::connection()->table('checkout_carts')->where('shop_id', 1)->where('status', 'Held')
            ->orderBy('created_at')->get()->mapWithKeys(fn (object $row): array => [$row->event_id => json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR)])->all();
    }

    private static function find(string $id): ?array
    {
        if (! Str::isUuid($id) || ! self::available()) {
            return null;
        }
        if (! PosStore::database()) {
            return session('checkout.carts.'.$id);
        }
        $snapshot = PosStore::connection()->table('checkout_carts')->where('shop_id', 1)->where('event_id', $id)->value('snapshot');

        return $snapshot ? json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR) : null;
    }

    private static function save(array $record): void
    {
        if (! PosStore::database()) {
            session()->put('checkout.carts.'.$record['id'], $record);

            return;
        }
        PosStore::connection()->table('checkout_carts')->updateOrInsert(['event_id' => $record['id'], 'shop_id' => 1], [
            'status' => $record['status'], 'snapshot' => json_encode($record, JSON_THROW_ON_ERROR),
            'created_at' => $record['time'], 'updated_at' => now(),
        ]);
    }

    private static function assertWritable(): void
    {
        abort_unless(auth()->id() === User::OWNER_ID, 403);
        app(OfflineCheckout::class)->assertWritable();
        if (! self::available() || ! PosStore::ready()) {
            throw ValidationException::withMessages(['heldCart' => 'Apply the saved-cart migration and initialize the shop before holding carts.']);
        }
    }

    public static function hold(string $id, array $cart, string $label, string $discount = '', string $discountReason = ''): array
    {
        return PosStore::atomic(function () use ($id, $cart, $label, $discount, $discountReason): array {
            self::assertWritable();
            $label = trim($label);
            Validator::make(compact('id', 'cart', 'label'), ['id' => 'required|uuid', 'cart' => 'required|array|min:1|max:200',
                'cart.*' => 'required|integer|min:1|max:9999', 'label' => 'nullable|string|max:80'])->validate();
            $cart = array_map('intval', $cart);
            if ($existing = self::find($id)) {
                if ($existing['status'] !== 'Held' || $existing['cart'] !== $cart || $existing['label'] !== $label
                    || ($existing['discountInput'] ?? '') !== $discount || ($existing['discountReason'] ?? '') !== trim($discountReason)) {
                    throw ValidationException::withMessages(['heldCart' => 'This cart changed in another request. Reload checkout before holding it again.']);
                }

                return $existing;
            }
            foreach (SessionSales::all() as $sale) {
                if ($sale['eventId'] === $id) {
                    throw ValidationException::withMessages(['heldCart' => 'This cart was already sold. Start a new cart.']);
                }
            }
            if (count(self::held()) >= 20) {
                throw ValidationException::withMessages(['heldCart' => 'Resume or discard a held cart first. You can hold up to 20 carts.']);
            }
            $lines = [];
            foreach ($cart as $optionId => $quantity) {
                $option = DemoCatalogue::option((string) $optionId);
                if (! $option) {
                    throw ValidationException::withMessages(['cart' => 'A cart item is no longer available. Remove it before holding the cart.']);
                }
                $lines[] = array_intersect_key($option, array_flip(['id', 'productId', 'name', 'label', 'price', 'units']))
                    + ['quantity' => $quantity, 'total' => $quantity * $option['price']];
            }
            $pricing = SaleDiscount::apply($lines, $discount, $discountReason);
            $record = ['id' => $id, 'status' => 'Held', 'label' => $label, 'cart' => $cart,
                'discountInput' => $discount, 'time' => now()->toIso8601String()] + $pricing;
            self::save($record);

            return $record;
        });
    }

    public static function resume(string $id): array
    {
        return PosStore::atomic(function () use ($id): array {
            self::assertWritable();
            $record = self::find($id);
            if (! $record || $record['status'] !== 'Held') {
                throw ValidationException::withMessages(['heldCart' => 'This cart is no longer on hold. It may have been resumed or discarded in another tab.']);
            }
            $changed = false;
            $requirements = [];
            foreach ($record['lines'] as $line) {
                $option = DemoCatalogue::option($line['id']);
                if (! $option || $option['productId'] !== $line['productId']) {
                    throw ValidationException::withMessages(['heldCart' => 'A saved product or option is no longer available. This cart remains on hold; review its items before discarding or rebuilding it.']);
                }
                $changed = $changed || $option['price'] !== $line['price'] || $option['units'] !== $line['units'];
                $requirements[$option['productId']] = ($requirements[$option['productId']] ?? 0) + $option['units'] * $line['quantity'];
            }
            $stock = SessionBatches::overview()['products'];
            $shortStock = false;
            foreach ($requirements as $productId => $quantity) {
                $shortStock = $shortStock || $quantity > ($stock[$productId]['sellable'] ?? 0);
            }
            $record['status'] = 'Resumed';
            self::save($record);

            return $record + ['pricesChanged' => $changed, 'shortStock' => $shortStock];
        });
    }

    public static function discard(string $id): void
    {
        PosStore::atomic(function () use ($id): void {
            self::assertWritable();
            $record = self::find($id);
            if (! $record || $record['status'] !== 'Held') {
                throw ValidationException::withMessages(['heldCart' => 'This cart is no longer on hold.']);
            }
            $record['status'] = 'Discarded';
            self::save($record);
        });
    }

    public static function assertCanComplete(string $eventId): void
    {
        if (self::find($eventId)) {
            throw ValidationException::withMessages(['heldCart' => 'This cart was put on hold. Resume it from Held carts before completing a sale.']);
        }
    }
}
