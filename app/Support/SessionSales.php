<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SessionSales
{
    public static function all(): array
    {
        return PosStore::get('demo.sales', []);
    }

    public static function draft(array $cart, string $method, string $received, bool $confirmed, string $customerId = '', string $dueDate = ''): array
    {
        Validator::make(['cart' => $cart, 'method' => $method], ['cart' => 'required|array|min:1|max:200',
            'cart.*' => 'required|integer|min:1|max:9999', 'method' => 'required|in:Cash,GCash,Maya,Credit'])->validate();
        $lines = [];
        foreach ($cart as $id => $quantity) {
            $option = DemoCatalogue::option((string) $id);
            if (! $option) {
                throw ValidationException::withMessages(['cart' => 'A cart item is no longer available. Remove it before completing the sale.']);
            }
            $product = DemoCatalogue::find($option['productId']);
            $lines[] = ['id' => $option['id'], 'productId' => $product['id'], 'name' => $product['name'], 'sku' => $product['sku'],
                'category' => $product['category'], 'label' => $option['label'], 'units' => $option['units'], 'price' => $option['price'],
                'quantity' => (int) $quantity, 'baseQuantity' => $option['units'] * (int) $quantity,
                'total' => $option['price'] * (int) $quantity, 'baseCost' => $product['baseCost'] ?? null,
                'cost' => isset($product['baseCost']) ? $product['baseCost'] * $option['units'] * (int) $quantity : null];
        }
        $total = array_sum(array_column($lines, 'total'));
        $cash = null;
        if ($method === 'Cash') {
            if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $received)) {
                throw ValidationException::withMessages(['received' => 'Enter a valid cash amount with at most two decimal places.']);
            }
            [$whole, $fraction] = array_pad(explode('.', $received, 2), 2, '');
            $cash = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            if ($cash < $total) {
                throw ValidationException::withMessages(['received' => 'Cash received must cover the total.']);
            }
        } elseif ($method === 'Credit') {
            SessionCredit::customer($customerId);
            Validator::make(['dueDate' => $dueDate], ['dueDate' => 'nullable|date_format:Y-m-d|after_or_equal:'.app(OfflineCheckout::class)->time()->timezone('Asia/Shanghai')->toDateString()])->validate();
        } elseif (! $confirmed) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm payment in the payment app first.']);
        }

        $batches = SessionBatches::state();
        foreach ($lines as &$line) {
            $line['batchAllocations'] = SessionBatches::consume($batches, $line['productId'], $line['baseQuantity'], 'Preview', '', 'cart', true);
            self::applyBatchCost($line);
        }
        unset($line);

        return ['shop' => config('app.name'), 'lines' => $lines, 'total' => $total, 'method' => $method,
            'costBasis' => 'Recorded batch acquisition costs (FEFO)',
            'cashReceived' => $cash, 'change' => $cash !== null ? $cash - $total : 0,
            'paymentConfirmed' => in_array($method, ['GCash', 'Maya']) && $confirmed,
            'customer' => $method === 'Credit' ? SessionCredit::customer($customerId)['name'] : null,
            'customerId' => $method === 'Credit' ? $customerId : null,
            'dueDate' => $method === 'Credit' ? $dueDate : null];
    }

    public static function complete(string $eventId, array $cart, string $method, string $received, bool $confirmed, string $customerId = '', string $dueDate = '', ?string $cashSessionId = null): array
    {
        if (PosStore::needsTransaction()) {
            return PosStore::atomic(fn () => self::complete($eventId, $cart, $method, $received, $confirmed, $customerId, $dueDate, $cashSessionId));
        }

        $sales = self::all();
        foreach ($sales as $sale) {
            if ($sale['eventId'] === $eventId) {
                return $sale;
            }
        }
        $draft = self::draft($cart, $method, $received, $confirmed, $customerId, $dueDate);
        $cashSession = SessionCash::requireOpen($cashSessionId);
        $requirements = [];
        foreach ($draft['lines'] as $line) {
            $requirements[$line['productId']] = ($requirements[$line['productId']] ?? 0) + $line['baseQuantity'];
        }
        $products = DemoCatalogue::products();
        $batches = SessionBatches::state();
        $movements = PosStore::get('demo.saleMovements', []);
        $saleId = (string) Str::uuid();
        foreach ($products as &$product) {
            $deduction = $requirements[$product['id']] ?? 0;
            if ($deduction === 0) {
                continue;
            }
            if ($deduction > $product['stock']) {
                throw ValidationException::withMessages(['cart' => "Insufficient stock for {$product['name']}: {$product['stock']} available, $deduction required."]);
            }
            $before = $product['stock'];
            $product['stock'] -= $deduction;
            $movements[] = ['saleId' => $saleId, 'productId' => $product['id'], 'quantity' => -$deduction,
                'before' => $before, 'after' => $product['stock'], 'reason' => 'Sale', 'time' => app(OfflineCheckout::class)->time()->toIso8601String()];
        }
        unset($product);
        foreach ($draft['lines'] as &$line) {
            $line['batchAllocations'] = SessionBatches::consume($batches, $line['productId'], $line['baseQuantity'], 'Sale', $saleId, 'cart', true);
            self::applyBatchCost($line);
        }
        unset($line);
        SessionBatches::assertBalanced($batches, $products);
        $sale = $draft + ['id' => $saleId, 'eventId' => $eventId, 'number' => 'WM-'.str_pad((string) (count($sales) + 1), 6, '0', STR_PAD_LEFT),
            'status' => 'Completed', 'time' => app(OfflineCheckout::class)->time()->toIso8601String(), 'operator' => 'Owner', 'counter' => 'Counter 1', 'cashSessionId' => $cashSession['id']];
        $sales[$saleId] = $sale;
        $changes = ['demo.products' => $products, 'demo.sales' => $sales, 'demo.saleMovements' => $movements] + SessionBatches::changes($batches);
        if ($method === 'Credit') {
            $debts = SessionCredit::debts();
            $debt = SessionCredit::makeDebt($eventId, $customerId, 'Grocery credit', $sale['total'], $dueDate, '', $saleId);
            $debts[$debt['id']] = $debt;
            $changes['demo.debts'] = $debts;
        } else {
            $movements = SessionCash::movements();
            $movements[] = SessionCash::stageMovement($eventId, 'Sale', $saleId, $sale['total'], $method, 'in', $sale['number'], '', $cashSession['id']);
            $changes['demo.cashMovements'] = $movements;
        }
        PosStore::put($changes);

        return $sale;
    }

    private static function applyBatchCost(array &$line): void
    {
        $line = array_replace($line, BatchCost::summarize($line['batchAllocations']));
        $line['costMethod'] = 'batch-v1';
    }
}
