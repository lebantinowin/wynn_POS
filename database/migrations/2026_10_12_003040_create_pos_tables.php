<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
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
        'supplierPayments' => ['table' => 'supplier_payments', 'map' => false, 'fields' => ['event_id' => 'eventId', 'number' => 'number', 'invoice_id' => 'invoiceId', 'cash_session_id' => 'cashSessionId', 'amount' => 'amount', 'method' => 'method']],
        'debts' => ['table' => 'customer_debts', 'map' => true, 'fields' => ['event_id' => 'eventId', 'number' => 'number', 'customer_id' => 'customerId', 'sale_id' => 'saleId', 'principal' => 'principal', 'balance' => 'balance']],
        'repayments' => ['table' => 'debt_repayments', 'map' => false, 'fields' => ['event_id' => 'eventId', 'debt_id' => 'debtId', 'cash_session_id' => 'cashSessionId', 'amount' => 'amount', 'method' => 'method']],
        'expenses' => ['table' => 'operating_expenses', 'map' => false, 'fields' => ['event_id' => 'eventId', 'cash_session_id' => 'cashSessionId', 'amount' => 'amount', 'method' => 'method']],
        'cashMovements' => ['table' => 'cash_movements', 'map' => false, 'fields' => ['event_id' => 'eventId', 'cash_session_id' => 'cashSessionId', 'source' => 'source', 'amount' => 'amount', 'method' => 'method', 'direction' => 'direction']],
        'losses' => ['table' => 'stock_losses', 'map' => false, 'fields' => ['event_id' => 'eventId', 'product_id' => 'productId', 'base_quantity' => 'baseQuantity', 'total_cost' => 'totalCost']],
        'counts' => ['table' => 'stock_counts', 'map' => false, 'fields' => ['product_id' => 'productId', 'difference' => 'difference']],
        'saleMovements' => ['table' => 'sale_stock_movements', 'map' => false, 'fields' => ['product_id' => 'productId', 'sale_id' => 'saleId', 'quantity' => 'quantity']],
        'returnMovements' => ['table' => 'return_stock_movements', 'map' => false, 'fields' => ['product_id' => 'productId', 'return_id' => 'returnId', 'quantity' => 'quantity']],
        'batchMovements' => ['table' => 'batch_movements', 'map' => false, 'fields' => ['product_id' => 'productId', 'batch_id' => 'batchId', 'quantity' => 'quantity', 'source' => 'source']],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_workspace', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->boolean('initialized')->default(false);
            $table->string('import_source', 64)->nullable();
            $table->string('import_archive')->nullable();
            $table->timestampTz('imported_at')->nullable();
        });
        DB::table('pos_workspace')->insert(['id' => 1, 'initialized' => false]);
        $definitions = self::TABLES;
        $integers = ['stock', 'base_cost', 'remaining', 'original_quantity', 'original_cost', 'opening', 'total', 'refund', 'base_quantity', 'total_cost', 'balance', 'amount', 'principal', 'difference', 'quantity'];
        $references = ['product_id' => 'products', 'supplier_id' => 'suppliers', 'customer_id' => 'customers', 'cash_session_id' => 'cash_sessions', 'order_id' => 'purchase_orders', 'sale_id' => 'sales', 'invoice_id' => 'supplier_invoices', 'debt_id' => 'customer_debts', 'return_id' => 'sale_returns', 'batch_id' => 'inventory_batches'];
        foreach ($definitions as $definition) {
            Schema::create($definition['table'], function (Blueprint $table) use ($definition, $integers, $references): void {
                $table->string('id', 120)->primary();
                $table->integer('position');
                $table->string('record_key', 120);
                $table->jsonb('snapshot');
                $table->timestampTz('recorded_at')->nullable()->index();
                foreach (array_keys($definition['fields']) as $column) {
                    if (in_array($column, $integers, true)) {
                        $table->bigInteger($column)->nullable();
                    } elseif ($column === 'expiry') {
                        $table->date($column)->nullable()->index();
                    } else {
                        $table->string($column, 120)->nullable();
                    }
                    if (isset($references[$column])) {
                        $table->foreign($column)->references('id')->on($references[$column])->restrictOnDelete();
                    }
                }
                foreach (['number', 'sku'] as $unique) {
                    if (isset($definition['fields'][$unique])) {
                        $table->unique($unique);
                    }
                }
                if (isset($definition['fields']['event_id']) && ! in_array($definition['table'], ['deliveries', 'cash_movements'], true)) {
                    $table->unique('event_id');
                }
                if ($definition['table'] === 'cash_movements') {
                    $table->unique(['source', 'event_id']);
                }
            });
            if (isset($definition['lines'])) {
                Schema::create($definition['lines'], function (Blueprint $table) use ($definition): void {
                    $table->string('parent_id', 120);
                    $table->integer('position');
                    $table->string('product_id', 120);
                    $table->bigInteger('quantity');
                    $table->jsonb('snapshot');
                    $table->primary(['parent_id', 'position']);
                    $table->foreign('parent_id')->references('id')->on($definition['table'])->restrictOnDelete();
                    $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
                });
            }
        }
        Schema::create('sale_options', function (Blueprint $table): void {
            $table->string('id', 120)->primary();
            $table->string('product_id', 120);
            $table->integer('position');
            $table->string('label', 60);
            $table->bigInteger('base_units');
            $table->bigInteger('price');
            $table->string('barcode', 100)->nullable()->unique();
            $table->jsonb('snapshot');
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });
        Schema::create('batch_products', function (Blueprint $table): void {
            $table->string('id', 120)->primary();
            $table->integer('position');
            $table->foreign('id')->references('id')->on('products')->restrictOnDelete();
        });
        Schema::create('receiving_events', function (Blueprint $table): void {
            $table->string('id', 120)->primary();
            $table->integer('position');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_nonnegative CHECK (stock >= 0)');
            DB::statement('ALTER TABLE inventory_batches ADD CONSTRAINT batches_quantity_valid CHECK (remaining >= 0 AND remaining <= original_quantity)');
            DB::statement('ALTER TABLE sale_options ADD CONSTRAINT options_units_price_valid CHECK (base_units >= 1 AND price >= 1)');
            DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open ON cash_sessions (status) WHERE status = 'Open'");
            DB::statement('CREATE UNIQUE INDEX products_sku_case_unique ON products (lower(sku))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receiving_events');
        Schema::dropIfExists('batch_products');
        Schema::dropIfExists('sale_options');
        foreach (array_reverse(self::TABLES) as $definition) {
            if (isset($definition['lines'])) {
                Schema::dropIfExists($definition['lines']);
            }
            Schema::dropIfExists($definition['table']);
        }
        Schema::dropIfExists('pos_workspace');
    }
};
