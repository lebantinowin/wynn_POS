<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class PosStore
{
    /** @var array<string, array{table: string, map: bool, fields: array<string, string>, lines?: string}> */
    public const COLLECTIONS = [
        'products' => ['table' => 'products', 'map' => false, 'fields' => ['name' => 'name', 'sku' => 'sku', 'stock' => 'stock', 'unit' => 'unit', 'base_cost' => 'baseCost']],
        'batches' => ['table' => 'inventory_batches', 'map' => true, 'fields' => ['product_id' => 'productId', 'expiry' => 'expiry', 'remaining' => 'remaining', 'original_quantity' => 'initialQuantity', 'original_cost' => 'totalCost']],
        'suppliers' => ['table' => 'suppliers', 'map' => true, 'fields' => ['name' => 'name']],
        'customers' => ['table' => 'customers', 'map' => true, 'fields' => ['name' => 'name']],
        'cashSessions' => ['table' => 'cash_sessions', 'map' => true, 'fields' => ['event_id' => 'eventId', 'number' => 'number', 'status' => 'status', 'opening' => 'opening']],
        'orders' => ['table' => 'purchase_orders', 'map' => true, 'fields' => ['supplier_id' => 'supplierId', 'number' => 'number', 'status' => 'status'], 'lines' => 'purchase_order_lines'],
        'sales' => ['table' => 'sales', 'map' => true, 'fields' => ['event_id' => 'eventId', 'number' => 'number', 'customer_id' => 'customerId', 'cash_session_id' => 'cashSessionId', 'total' => 'total', 'method' => 'method'], 'lines' => 'sale_lines'],
        'returns' => ['table' => 'sale_returns', 'map' => true, 'fields' => ['event_id' => 'eventId', 'number' => 'number', 'sale_id' => 'saleId', 'cash_session_id' => 'cashSessionId', 'total' => 'total', 'refund' => 'refund'], 'lines' => 'return_lines'],
        'receiving' => ['table' => 'deliveries', 'map' => false, 'fields' => ['event_id' => 'eventId', 'product_id' => 'productId', 'order_id' => 'orderId', 'base_quantity' => 'baseQuantity', 'total_cost' => 'totalCost']],
        'supplierInvoices' => ['table' => 'supplier_invoices', 'map' => true, 'fields' => ['event_id' => 'eventId', 'number' => 'number', 'supplier_id' => 'supplierId', 'order_id' => 'orderId', 'total' => 'total', 'balance' => 'balance'], 'lines' => 'supplier_invoice_lines'],
        'supplierPayments' => ['table' => 'supplier_payments', 'map' => true, 'fields' => ['event_id' => 'eventId', 'number' => 'number', 'invoice_id' => 'invoiceId', 'cash_session_id' => 'cashSessionId', 'amount' => 'amount', 'method' => 'method']],
        'debts' => ['table' => 'customer_debts', 'map' => true, 'fields' => ['event_id' => 'eventId', 'number' => 'number', 'customer_id' => 'customerId', 'sale_id' => 'saleId', 'principal' => 'principal', 'balance' => 'balance']],
        'repayments' => ['table' => 'debt_repayments', 'map' => false, 'fields' => ['event_id' => 'eventId', 'debt_id' => 'debtId', 'cash_session_id' => 'cashSessionId', 'amount' => 'amount', 'method' => 'method']],
        'expenses' => ['table' => 'operating_expenses', 'map' => true, 'fields' => ['event_id' => 'eventId', 'cash_session_id' => 'cashSessionId', 'amount' => 'amount', 'method' => 'method']],
        'cashMovements' => ['table' => 'cash_movements', 'map' => false, 'fields' => ['event_id' => 'eventId', 'cash_session_id' => 'cashSessionId', 'source' => 'source', 'amount' => 'amount', 'method' => 'method', 'direction' => 'direction']],
        'losses' => ['table' => 'stock_losses', 'map' => false, 'fields' => ['event_id' => 'eventId', 'product_id' => 'productId', 'base_quantity' => 'baseQuantity', 'total_cost' => 'totalCost']],
        'counts' => ['table' => 'stock_counts', 'map' => false, 'fields' => ['product_id' => 'productId', 'difference' => 'difference']],
        'saleMovements' => ['table' => 'sale_stock_movements', 'map' => false, 'fields' => ['product_id' => 'productId', 'sale_id' => 'saleId', 'quantity' => 'quantity']],
        'returnMovements' => ['table' => 'return_stock_movements', 'map' => false, 'fields' => ['product_id' => 'productId', 'return_id' => 'returnId', 'quantity' => 'quantity']],
        'batchMovements' => ['table' => 'batch_movements', 'map' => false, 'fields' => ['product_id' => 'productId', 'batch_id' => 'batchId', 'quantity' => 'quantity', 'source' => 'source']],
    ];

    private int $depth = 0;

    private bool $importing = false;

    /** @var array<string, array> */
    private array $cache = [];

    public static function database(): bool
    {
        return config('pos.storage') === 'database';
    }

    public static function connection(): Connection
    {
        return DB::connection(config('pos.connection'));
    }

    public static function ready(): bool
    {
        return ! self::database() || (bool) self::connection()->table('pos_workspace')->where('id', 1)->value('initialized');
    }

    public static function needsTransaction(): bool
    {
        return self::database() && app(self::class)->depth === 0;
    }

    public static function atomic(callable $operation): mixed
    {
        if (! self::needsTransaction()) {
            return $operation();
        }

        $store = app(self::class);

        return self::connection()->transaction(function () use ($store, $operation): mixed {
            self::connection()->table('pos_workspace')->where('id', 1)->lockForUpdate()->firstOrFail();
            $store->cache = [];
            $store->depth++;
            try {
                return $operation();
            } finally {
                $store->depth--;
                $store->cache = [];
            }
        });
    }

    public static function get(string $key, array $default = []): array
    {
        if (! self::database() || (! self::ready() && ! app(self::class)->importing)) {
            return session()->get($key, $default);
        }
        $name = substr($key, 5);
        $store = app(self::class);
        if (isset($store->cache[$name])) {
            return $store->cache[$name];
        }
        if (in_array($name, ['batchProducts', 'receivingEvents'], true)) {
            $table = $name === 'batchProducts' ? 'batch_products' : 'receiving_events';
            $values = self::connection()->table($table)->orderBy('position')->pluck('id')->all();

            return $store->cache[$name] = $name === 'batchProducts' ? array_map('intval', $values) : $values;
        }
        $definition = self::COLLECTIONS[$name] ?? throw new \InvalidArgumentException('Unknown POS collection.');
        $values = [];
        $lines = isset($definition['lines']) ? self::connection()->table($definition['lines'])->orderBy('position')->get()->groupBy('parent_id') : null;
        $options = $name === 'products' ? self::connection()->table('sale_options')->orderBy('position')->get()->groupBy('product_id') : null;
        foreach (self::connection()->table($definition['table'])->orderBy('position')->get() as $row) {
            $value = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            if ($lines !== null) {
                $value['lines'] = ($lines[$row->id] ?? collect())->map(fn (object $line): array => json_decode($line->snapshot, true, flags: JSON_THROW_ON_ERROR))->all();
            }
            if ($options !== null) {
                $value['options'] = ($options[$row->id] ?? collect())->map(fn (object $option): array => json_decode($option->snapshot, true, flags: JSON_THROW_ON_ERROR))->all();
            }
            if ($definition['map']) {
                $values[$row->record_key] = $value;
            } else {
                $values[] = $value;
            }
        }

        return $store->cache[$name] = $values;
    }

    public static function put(string|array $key, ?array $value = null): void
    {
        if (! self::database()) {
            session()->put($key, $value);

            return;
        }
        if (self::needsTransaction()) {
            self::atomic(fn () => self::put($key, $value));

            return;
        }
        if (! self::ready() && ! app(self::class)->importing) {
            throw ValidationException::withMessages(['storage' => 'Import your browser records before recording changes.']);
        }
        app(OfflineCheckout::class)->assertWritable();
        foreach (is_array($key) ? $key : [$key => $value] as $name => $records) {
            self::write(substr($name, 5), $records);
        }
        app(self::class)->cache = [];
    }

    private static function write(string $name, array $records): void
    {
        $connection = self::connection();
        if (in_array($name, ['batchProducts', 'receivingEvents'], true)) {
            $table = $name === 'batchProducts' ? 'batch_products' : 'receiving_events';
            foreach (array_values($records) as $position => $id) {
                $connection->table($table)->updateOrInsert(['id' => $id], ['position' => $position]);
            }

            return;
        }
        $definition = self::COLLECTIONS[$name] ?? throw new \InvalidArgumentException('Unknown POS collection.');
        $position = 0;
        foreach ($records as $key => $record) {
            $id = (string) ($record['id'] ?? $name.'-'.$position);
            $snapshot = $record;
            $attributes = ['position' => $position++, 'record_key' => (string) $key,
                'recorded_at' => $record['time'] ?? $record['createdAt'] ?? $record['openedAt'] ?? null];
            foreach ($definition['fields'] as $column => $field) {
                $attributes[$column] = ($record[$field] ?? null) === '' ? null : ($record[$field] ?? null);
            }
            if (isset($definition['lines'])) {
                unset($snapshot['lines']);
            }
            if ($name === 'products') {
                unset($snapshot['options']);
            }
            $attributes['snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);
            $connection->table($definition['table'])->updateOrInsert(['id' => $id], $attributes);
            if (isset($definition['lines'])) {
                foreach ($record['lines'] as $linePosition => $line) {
                    $connection->table($definition['lines'])->updateOrInsert(['parent_id' => $id, 'position' => $linePosition], [
                        'product_id' => $line['productId'], 'quantity' => $line['quantity'],
                        'snapshot' => json_encode($line, JSON_THROW_ON_ERROR),
                    ]);
                }
            }
            if ($name === 'products') {
                foreach ($record['options'] as $optionPosition => $option) {
                    $connection->table('sale_options')->updateOrInsert(['id' => $option['id']], [
                        'product_id' => $id, 'position' => $optionPosition, 'label' => $option['label'],
                        'base_units' => $option['units'], 'price' => $option['price'], 'barcode' => $option['barcode'] ?: null,
                        'snapshot' => json_encode($option, JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        }
    }

    public static function import(array $data, string $source): void
    {
        self::atomic(function () use ($data, $source): void {
            if (self::ready()) {
                throw ValidationException::withMessages(['storage' => 'The database is already initialized. Existing shop records were not overwritten.']);
            }
            $products = $data['products'] ?? DemoCatalogue::defaults();
            $batches = SessionBatches::stateFor($products, ['batches' => $data['batches'] ?? [], 'products' => $data['batchProducts'] ?? [], 'movements' => $data['batchMovements'] ?? []]);
            SessionBatches::assertBalanced($batches, $products);
            $archive = 'pos-imports/'.hash('sha256', $source).'.json';
            if (! Storage::disk('local')->put($archive, json_encode(['version' => 1, 'time' => now()->toIso8601String(), 'data' => $data + ['products' => $products]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))) {
                throw new \RuntimeException('The browser archive could not be saved. Import was stopped.');
            }
            $store = app(self::class);
            $store->importing = true;
            try {
                foreach (array_keys(self::COLLECTIONS) as $name) {
                    self::write($name, $name === 'products' ? $products : ($data[$name] ?? []));
                }
                self::write('batches', $batches['batches']);
                self::write('batchProducts', $batches['products']);
                self::write('batchMovements', $batches['movements']);
                self::write('receivingEvents', $data['receivingEvents'] ?? []);
                self::connection()->table('pos_workspace')->where('id', 1)->update(['initialized' => true, 'import_source' => hash('sha256', $source), 'import_archive' => $archive, 'imported_at' => now()]);
            } finally {
                $store->importing = false;
                $store->cache = [];
            }
        });
    }
}
