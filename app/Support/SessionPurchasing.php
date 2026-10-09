<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SessionPurchasing
{
    public static function suppliers(): array
    {
        return session('demo.suppliers', []);
    }

    public static function orders(): array
    {
        return session('demo.orders', []);
    }

    public static function money(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function saveSupplier(?string $id, array $data): void
    {
        $data = array_map(fn ($value) => trim((string) $value), $data);
        Validator::make($data, ['name' => 'required|string|max:120', 'contact' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:120', 'notes' => 'nullable|string|max:500'])->validate();
        $suppliers = self::suppliers();
        if ($id !== null && ! isset($suppliers[$id])) {
            throw ValidationException::withMessages(['name' => 'Supplier no longer available.']);
        }
        foreach ($suppliers as $key => $supplier) {
            if ($key !== $id && strtolower($supplier['name']) === strtolower($data['name'])) {
                throw ValidationException::withMessages(['name' => 'Supplier name is already in use.']);
            }
        }
        $id ??= (string) Str::uuid();
        $suppliers[$id] = $data + ['id' => $id];
        session()->put('demo.suppliers', $suppliers);
    }

    public static function createOrder(string $supplierId, string $notes, array $lines): void
    {
        Validator::make(['supplierId' => $supplierId, 'notes' => $notes, 'lines' => $lines], [
            'supplierId' => 'required|string', 'notes' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1|max:30', 'lines.*.optionId' => 'required|string|distinct',
            'lines.*.quantity' => 'required|integer|min:1|max:100000',
            'lines.*.cost' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
        ])->validate();
        $supplier = self::suppliers()[$supplierId] ?? null;
        if (! $supplier) {
            throw ValidationException::withMessages(['supplierId' => 'Select a saved supplier.']);
        }
        $snapshots = [];
        foreach ($lines as $index => $line) {
            $option = DemoCatalogue::option($line['optionId']);
            if (! $option) {
                throw ValidationException::withMessages(["lines.$index.optionId" => 'Select an available product option.']);
            }
            $cost = self::money($line['cost']);
            if ($cost < 1) {
                throw ValidationException::withMessages(["lines.$index.cost" => 'Cost must be greater than zero.']);
            }
            $snapshots[] = ['optionId' => $line['optionId'], 'productId' => $option['productId'], 'name' => $option['name'],
                'label' => $option['label'], 'units' => $option['units'], 'quantity' => (int) $line['quantity'], 'received' => 0, 'cost' => $cost];
        }
        $orders = self::orders();
        $id = (string) Str::uuid();
        $orders[$id] = ['id' => $id, 'number' => 'PO-'.str_pad((string) (count($orders) + 1), 5, '0', STR_PAD_LEFT),
            'supplierId' => $supplierId, 'supplier' => $supplier['name'], 'notes' => trim($notes),
            'lines' => $snapshots, 'status' => 'Ordered', 'createdAt' => now()->toIso8601String()];
        session()->put('demo.orders', $orders);
    }

    public static function receive(string $orderId, array $deliveries, string $reference, string $eventId): bool
    {
        $processed = session('demo.receivingEvents', []);
        if (in_array($eventId, $processed, true)) {
            return false;
        }
        $orders = self::orders();
        $order = $orders[$orderId] ?? null;
        if (! $order || $order['status'] === 'Received') {
            throw ValidationException::withMessages(['delivery' => 'Order is unavailable or already fully received.']);
        }
        Validator::make(['deliveries' => $deliveries, 'reference' => $reference], [
            'deliveries' => 'required|array', 'deliveries.*.quantity' => 'required|integer|min:0|max:100000',
            'deliveries.*.cost' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'deliveries.*.expiry' => 'nullable|date_format:Y-m-d|after_or_equal:today',
            'reference' => 'nullable|string|max:100',
        ])->validate();
        if (array_keys($deliveries) !== array_keys($order['lines'])) {
            throw ValidationException::withMessages(['delivery' => 'Delivery lines must match the order.']);
        }
        $products = DemoCatalogue::products();
        $receipts = session('demo.receiving', []);
        $added = 0;
        foreach ($order['lines'] as $index => &$line) {
            $input = $deliveries[$index];
            $quantity = (int) $input['quantity'];
            if ($quantity > $line['quantity'] - $line['received']) {
                throw ValidationException::withMessages(["deliveries.$index.quantity" => 'Quantity exceeds the remaining order quantity.']);
            }
            if ($quantity === 0) {
                continue;
            }
            $productIndex = array_search($line['productId'], array_column($products, 'id'), true);
            if ($productIndex === false) {
                throw ValidationException::withMessages(['delivery' => 'Product no longer available.']);
            }
            $product = &$products[$productIndex];
            if (($product['trackExpiry'] ?? false) && empty($input['expiry'])) {
                throw ValidationException::withMessages(["deliveries.$index.expiry" => 'Expiry date is required for this product.']);
            }
            $cost = self::money($input['cost']);
            if ($cost < 1) {
                throw ValidationException::withMessages(["deliveries.$index.cost" => 'Actual cost must be greater than zero.']);
            }
            $baseQuantity = $quantity * $line['units'];
            if ($product['stock'] + $baseQuantity > 100000000) {
                throw ValidationException::withMessages(["deliveries.$index.quantity" => 'Resulting stock exceeds the supported limit.']);
            }
            $before = $product['stock'];
            $product['stock'] += $baseQuantity;
            $line['received'] += $quantity;
            $added += $baseQuantity;
            $receipts[] = ['id' => (string) Str::uuid(), 'orderId' => $orderId, 'eventId' => $eventId,
                'productId' => $line['productId'], 'name' => $line['name'], 'option' => $line['label'],
                'unitsPerOption' => $line['units'], 'quantity' => $quantity, 'baseQuantity' => $baseQuantity,
                'before' => $before, 'after' => $product['stock'], 'costPerOption' => $cost, 'totalCost' => $cost * $quantity,
                'supplier' => $order['supplier'], 'reference' => trim($reference), 'expiry' => $input['expiry'] ?? '',
                'actor' => 'Owner preview', 'time' => now()->toIso8601String()];
            unset($product);
        }
        unset($line);
        if ($added === 0) {
            throw ValidationException::withMessages(['delivery' => 'Enter a received quantity on at least one line.']);
        }
        $complete = true;
        foreach ($order['lines'] as $line) {
            if ($line['received'] < $line['quantity']) {
                $complete = false;
            }
        }
        $order['status'] = $complete ? 'Received' : 'Partially received';
        $orders[$orderId] = $order;
        $processed[] = $eventId;
        session()->put(['demo.products' => $products, 'demo.orders' => $orders, 'demo.receiving' => $receipts, 'demo.receivingEvents' => $processed]);

        return true;
    }
}
