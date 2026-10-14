<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Livewire\Inventory;
use App\Livewire\Products;
use App\Models\User;
use App\Support\CheckoutCarts;
use App\Support\DemoCatalogue;
use App\Support\OfflineCheckout;
use App\Support\PosStore;
use App\Support\ReportExports;
use App\Support\SessionAnalytics;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionLosses;
use App\Support\SessionPayables;
use App\Support\SessionPurchasing;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use App\Support\ShopPreferences;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PostgresPersistenceTest extends TestCase
{
    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('RUN_PGSQL_TESTS')) {
            $this->markTestSkipped('Set RUN_PGSQL_TESTS=1 to run isolated PostgreSQL integration checks.');
        }
        Storage::fake('local');
        $this->schema = 'pos_test_'.bin2hex(random_bytes(8));
        $connection = config('database.connections.pgsql');
        $connection['database'] = getenv('POS_PGSQL_TEST_DATABASE') ?: 'wynn_mart';
        $connection['search_path'] = $this->schema;
        config(['database.connections.pos_test' => $connection, 'database.connections.pos_competing' => $connection,
            'pos.connection' => 'pos_test', 'pos.storage' => 'database']);
        DB::connection('pos_test')->statement('CREATE SCHEMA "'.$this->schema.'"');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pos_test', '--path' => [
            'database/migrations/2026_10_12_003040_create_pos_tables.php',
            'database/migrations/2026_10_12_024957_create_offline_checkout_tables.php',
            'database/migrations/2026_10_13_011002_create_shop_settings_table.php',
            'database/migrations/2026_10_13_062247_create_checkout_carts_table.php',
            'database/migrations/2026_10_14_060702_add_recovery_release_to_offline_checkouts_table.php',
        ], '--force' => true]));
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            DB::disconnect('pos_competing');
            DB::connection('pos_test')->statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            DB::purge('pos_test');
            DB::purge('pos_competing');
        }
        parent::tearDown();
    }

    private function initialize(): void
    {
        PosStore::import(['products' => DemoCatalogue::defaults()], 'test-browser');
    }

    public function test_unavailable_checkout_release_is_persistent_and_stale_requests_preserve_new_hold(): void
    {
        $this->initialize();
        SessionCash::open('recovery-open', '1000', '');
        $offline = app(OfflineCheckout::class);
        $old = $offline->prepare((string) Str::uuid(), (string) Str::uuid());
        $this->travelTo(Carbon::parse($old['validUntil'])->addSecond());
        $eventId = (string) Str::uuid();
        $release = $offline->releaseUnavailable($old['id'], $eventId, 'Browser unavailable', 'RELEASE COUNTER 1', true);
        $new = $offline->prepare((string) Str::uuid(), (string) Str::uuid());
        $stock = DemoCatalogue::products();
        $cash = SessionCash::all();
        DB::purge('pos_test');
        $this->assertEquals($release, $offline->releaseUnavailable($old['id'], $eventId, 'Browser unavailable', 'RELEASE COUNTER 1', true));
        $this->assertSame($new['id'], $offline->active()->id);
        $this->assertSame('Unknown', $offline->releaseHistory()[0]['unconfirmedSales']);
        $this->assertSame($stock, DemoCatalogue::products());
        $this->assertSame($cash, SessionCash::all());
        $this->assertSame([], SessionSales::all());
    }

    public function test_discounted_receipt_costs_and_partial_refunds_survive_postgresql_reload(): void
    {
        $this->initialize();
        $batches = SessionBatches::state();
        foreach ($batches['batches'] as &$batch) {
            $batch['totalCost'] = $batch['initialQuantity'] * 1000;
        }
        unset($batch);
        PosStore::put(SessionBatches::changes($batches));
        SessionCash::open('discount-open', '1000', '');
        $checkout = Livewire::test(Checkout::class)->call('setQuantity', 'drink-one', 3)
            ->set(['discount' => '1.01', 'discountReason' => 'Owner promotion', 'received' => '100'])
            ->call('complete')->assertHasNoErrors();
        $sale = $checkout->get('receipt');
        session()->flush();
        DB::purge('pos_test');
        $this->assertSame(5899, SessionSales::all()[$sale['id']]['total']);
        $this->assertSame(101, SessionSales::all()[$sale['id']]['discount']);
        $this->assertSame('Owner promotion', SessionSales::all()[$sale['id']]['discountReason']);
        $this->assertSame(3000, SessionSales::all()[$sale['id']]['lines'][0]['cost']);
        $this->assertSame(2899, SessionAnalytics::summarize(7)['grossProfit']);
        foreach ([1966, 1966, 1967] as $index => $expectedRefund) {
            $return = SessionReturns::complete('discount-return-'.$index, $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Discounted item returned');
            $this->assertSame($expectedRefund, $return['refund']);
            DB::purge('pos_test');
        }
        $this->assertSame(5899, array_sum(array_column(SessionReturns::all(), 'refund')));
        $this->assertSame(0, SessionAnalytics::summarize(7)['revenue']);
        $this->assertSame(0, SessionAnalytics::summarize(7)['netDiscounts']);
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEquals($sale, SessionSales::all()[$sale['id']]);
    }

    public function test_held_carts_persist_across_browser_sessions_and_resume_with_current_prices(): void
    {
        $this->initialize();
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-pack')->set('holdLabel', 'Waiting customer');
        $id = $checkout->get('saleEventId');
        $checkout->call('holdCart')->assertHasNoErrors()->assertSet('cart', []);
        session()->flush();
        DB::purge('pos_test');
        $this->assertSame(['drink-pack' => 1], CheckoutCarts::held()[$id]['cart']);
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionSales::all());
        $product = DemoCatalogue::find(1);
        $product['options'][1]['price'] = 12000;
        DemoCatalogue::save($product);
        Livewire::test(Checkout::class)->call('resumeCart', $id)->assertHasNoErrors()->assertSet('cart.drink-pack', 1)
            ->assertSee('Prices or package sizes changed')->assertSet('confirmed', false);
        $this->assertEmpty(CheckoutCarts::held());
        $this->assertSame('Resumed', PosStore::connection()->table('checkout_carts')->where('event_id', $id)->value('status'));
    }

    public function test_exports_freeze_database_snapshot_and_remain_readable_during_offline_checkout(): void
    {
        $this->initialize();
        SessionCash::open('report-open', '1000', 'Test float');
        $first = SessionSales::complete('report-first', ['drink-one' => 1], 'Cash', '20', false);
        $response = $this->get('/reports/export/transactions?days=7')->assertOk();
        $second = SessionSales::complete('report-second', ['drink-one' => 1], 'Cash', '20', false);
        $content = $response->streamedContent();
        $this->assertStringContainsString($first['number'], $content);
        $this->assertStringNotContainsString($second['number'], $content);
        app(OfflineCheckout::class)->prepare((string) Str::uuid(), (string) Str::uuid());
        $stock = DemoCatalogue::products();
        foreach (ReportExports::TYPES as $type => $label) {
            $this->get('/reports/print/'.$type)->assertOk()->assertSee('Counter 1 has an active offline session.');
            $csv = $this->get('/reports/export/'.$type)->assertOk()->streamedContent();
            $this->assertStringContainsString('This report may be incomplete', $csv);
        }
        $this->assertSame($stock, DemoCatalogue::products());
        $this->assertCount(2, SessionSales::all());
        $this->assertNotNull(app(OfflineCheckout::class)->active());
    }

    public function test_manual_barcode_shop_day_reconciles_stock_cash_debts_costs_and_reports(): void
    {
        $products = DemoCatalogue::defaults();
        foreach ($products as &$product) {
            $product['stock'] = 0;
        }
        unset($product);
        PosStore::import(['products' => $products], 'acceptance-day');
        SessionPurchasing::saveSupplier(null, ['name' => 'Acceptance supplier', 'contact' => '', 'phone' => '', 'email' => '', 'notes' => '']);
        SessionPurchasing::createOrder(array_key_first(SessionPurchasing::suppliers()), '', [['optionId' => 'drink-case', 'quantity' => 2, 'cost' => '240']]);
        SessionPurchasing::receive(array_key_first(SessionPurchasing::orders()), [['quantity' => 2, 'cost' => '240', 'expiry' => now('Asia/Shanghai')->addDays(10)->toDateString()]], 'Acceptance invoice', 'acceptance-delivery');
        $invoiceId = array_key_first(SessionPayables::invoices());
        $this->assertSame(48, DemoCatalogue::find(1)['stock']);
        $this->assertSame(48000, SessionPayables::invoices()[$invoiceId]['balance']);
        $drawer = SessionCash::open('acceptance-open', '1000', 'Test opening float');

        $checkout = Livewire::test(Checkout::class)
            ->set('search', ' DEMO-001 ')->call('scan')
            ->set('search', 'DEMO-001')->call('scan')
            ->set('search', 'DEMO-006')->call('scan')
            ->assertSet('cart.drink-one', 2)->assertSet('cart.drink-pack', 1)
            ->set('received', '200')->call('preview')->assertHasNoErrors();
        $this->assertSame(48, DemoCatalogue::find(1)['stock']);
        $this->assertCount(0, SessionSales::all());
        $saleEventId = $checkout->get('saleEventId');
        $checkout->call('complete')->assertHasNoErrors()->assertSet('cart', []);
        $cashSale = $checkout->get('receipt');
        $this->assertSame(15000, $cashSale['total']);
        $this->assertSame(5000, $cashSale['change']);
        $this->assertSame(8000, array_sum(array_column($cashSale['lines'], 'cost')));

        $digitalCheckout = Livewire::test(Checkout::class)->call('add', 'drink-pack')->set('method', 'GCash')
            ->set('received', '110')->set('confirmed', true)->call('complete')->assertHasNoErrors();
        $digitalSale = $digitalCheckout->get('receipt');
        SessionCredit::addCustomer('Acceptance customer', '');
        $customerId = array_key_first(SessionCredit::customers());
        $creditCheckout = Livewire::test(Checkout::class)->call('add', 'drink-pack')->set('method', 'Credit')
            ->set('customerId', $customerId)->call('complete')->assertHasNoErrors();
        $creditSale = $creditCheckout->get('receipt');
        $groceryDebtId = array_key_first(SessionCredit::debts());
        SessionCredit::repay('acceptance-grocery-payment', $groceryDebtId, '40', 'Cash', 'Partial grocery payment');
        SessionCredit::cashLoan('acceptance-loan', $customerId, '50', 'Cash', '', 'Test loan principal');
        $loanId = array_key_last(SessionCredit::debts());
        SessionCredit::repay('acceptance-loan-payment', $loanId, '10', 'Cash', 'Partial loan payment');

        $creditReturn = SessionReturns::complete('acceptance-credit-return', $creditSale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Unopened credit purchase returned');
        $this->assertSame(7000, $creditReturn['creditReduction']);
        $this->assertSame(4000, $creditReturn['refund']);
        $cashReturnLines = array_map(fn (array $line): array => ['quantity' => $line['id'] === 'drink-one' ? 1 : 0, 'disposition' => 'Restock'], $cashSale['lines']);
        SessionReturns::complete('acceptance-cash-return', $cashSale['id'], $cashReturnLines, 'Cash', true, 'Unopened individual item returned');
        SessionLosses::record('acceptance-consumed', 'drink-one', 1, 'Unpaid consumption', 'Consumed without paying', '');
        SessionCash::expense('acceptance-expense', '15', 'Supplies', 'Cash', 'Test receipt paper');
        SessionPayables::pay('acceptance-supplier-payment', $invoiceId, '100', 'Cash', true, '', 'Partial invoice payment');

        $this->assertSame(34, DemoCatalogue::find(1)['stock']);
        $this->assertSame(0, SessionCredit::debts()[$groceryDebtId]['balance']);
        $this->assertSame(4000, SessionCredit::debts()[$loanId]['balance']);
        $this->assertSame(38000, SessionPayables::invoices()[$invoiceId]['balance']);
        $this->assertSame(97500, SessionCash::summary($drawer['id'])['expected']);
        $closed = SessionCash::close($drawer['id'], 'acceptance-close', '970', 'Test drawer shortage of 5.00');
        $this->assertSame(-500, $closed['difference']);

        session()->flush();
        DB::purge('pos_test');
        $this->assertSame(11000, SessionSales::all()[$digitalSale['id']]['paymentReceived']);
        $this->assertNull(SessionSales::all()[$digitalSale['id']]['cashReceived']);
        $this->assertEquals($cashSale, SessionSales::complete($saleEventId, [], 'Cash', '', false));
        $this->assertEquals($closed, SessionCash::close($drawer['id'], 'acceptance-close', '0', 'Retry'));
        $summary = SessionAnalytics::summarize(7);
        foreach (['transactions' => 3, 'grossSales' => 37000, 'returnValue' => 13000, 'refunds' => 6000,
            'revenue' => 24000, 'collections' => 25000, 'knownSalesCost' => 13000, 'grossProfit' => 11000,
            'lossUnits' => 1, 'lossCost' => 1000, 'expenses' => 1500, 'operatingProfit' => 8500,
            'outstanding' => 4000, 'cashLent' => 5000, 'supplierOutstanding' => 38000, 'supplierPaid' => 10000,
            'cashShortages' => 500, 'cashSurpluses' => 0] as $key => $expected) {
            $this->assertSame($expected, $summary[$key], $key);
        }
        $this->assertSame(34000, $summary['expiryStock']['knownCost']);
        $this->assertSame(0, $summary['expiryStock']['unknownCostUnits']);
        $this->assertSame(24000, array_sum(array_column($summary['daily'], 'sales')));
        $this->assertSame(25000, array_sum(array_column($summary['daily'], 'collections')));
        $this->assertSame(['Cash' => 13000, 'GCash' => 11000, 'Maya' => 0, 'Credit' => 0], $summary['payments']);
        $this->assertSame(13, $summary['products'][0]['units']);
        SessionBatches::assertBalanced(SessionBatches::state(), DemoCatalogue::products());
        $this->assertSame(3, PosStore::connection()->table('sales')->count());
        $this->assertSame(2, PosStore::connection()->table('sale_returns')->count());
        $this->get('/reports')->assertOk()->assertSee('240.00')->assertSee('250.00')->assertSee('85.00');
    }

    public function test_shop_profile_and_receipt_header_survive_postgresql_reconnect_and_edits(): void
    {
        $this->initialize();
        $preferences = app(ShopPreferences::class);
        $preferences->save(array_replace($preferences->all(), ['shopName' => 'PG shop', 'address' => 'Saved address', 'footer' => 'Saved footer']));
        SessionCash::open('profile-drawer', '100', '');
        $sale = SessionSales::complete('profile-sale', ['drink-one' => 1], 'Cash', '20', false);
        $preferences->save(array_replace($preferences->all(), ['address' => 'Changed address']));
        DB::purge('pos_test');
        app()->forgetScopedInstances();
        $this->assertSame('Changed address', app(ShopPreferences::class)->all()['address']);
        $this->assertEquals($sale, SessionSales::all()[$sale['id']]);
        $this->assertSame('Saved address', $sale['receiptProfile']['address']);
        $this->assertSame('PHP', $sale['receiptProfile']['currency']);
        $this->assertSame('Unconfirmed', $sale['receiptProfile']['taxStatus']);
    }

    public function test_invalid_manual_checkout_and_stale_drawer_cannot_change_postgresql_records(): void
    {
        $this->initialize();
        $drawer = SessionCash::open('acceptance-open', '100', '');
        $checkout = Livewire::test(Checkout::class)->set('search', 'UNKNOWN-CODE')->call('scan')
            ->assertHasErrors('search')->assertSet('cart', [])
            ->set('search', 'DEMO-006')->call('scan')->set('received', '1')->call('complete')->assertHasErrors('received');
        $checkout->set('method', 'Maya')->call('complete')->assertHasErrors('confirmed')
            ->call('setQuantity', 'drink-pack', 13)->set('received', '1430')->set('confirmed', true)->call('complete')->assertHasErrors('cart');
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertSame(10000, SessionCash::summary($drawer['id'])['expected']);
        $this->assertSame(0, PosStore::connection()->table('sales')->count());
        $this->assertSame(0, PosStore::connection()->table('cash_movements')->count());

        $checkout->call('setQuantity', 'drink-pack', 1)->set('method', 'Cash')->set('received', '200');
        SessionCash::close($drawer['id'], 'acceptance-close', '100', '');
        $newDrawer = SessionCash::open('acceptance-new-open', '250', '');
        $checkout->call('complete')->assertHasErrors('cashSession');
        $this->assertSame(25000, SessionCash::summary($newDrawer['id'])['expected']);
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertCount(0, SessionSales::all());
        Livewire::test(Checkout::class)->set('search', 'DEMO-006')->call('scan')->set('received', '200')
            ->call('complete')->assertHasNoErrors();
        $this->assertSame(66, DemoCatalogue::find(1)['stock']);
        $this->assertSame(36000, SessionCash::summary($newDrawer['id'])['expected']);
        $this->assertCount(1, SessionSales::all());
    }

    public function test_owner_login_protects_shared_postgresql_records_across_sessions(): void
    {
        $this->initialize();
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pos_test', '--path' => 'database/migrations/0001_01_01_000000_create_users_table.php', '--force' => true]));
        config(['database.default' => 'pos_test']);
        $owner = User::factory()->create(['id' => User::OWNER_ID, 'email' => 'pg-owner@example.test', 'password' => 'Postgres-Owner42!']);
        Auth::forgetGuards();
        $this->get('/products')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Welcome back');
        $this->post('/login', ['email' => $owner->email, 'password' => 'Postgres-Owner42!'])->assertRedirect('/products');
        Auth::forgetGuards();
        $this->get('/products')->assertOk()->assertSee('Bottled drink');
        $this->post('/logout')->assertRedirect('/login');
        Auth::forgetGuards();
        $this->get('/products')->assertRedirect('/login');
        $this->assertSame(2, PosStore::connection()->table('products')->count());
        $this->assertSame(0, PosStore::connection()->table('sales')->count());
    }

    public function test_import_preserves_products_options_batches_and_shared_reads_without_browser_data(): void
    {
        $products = DemoCatalogue::defaults();
        $products[0]['baseCost'] = 1000;
        PosStore::import(['products' => $products], 'first-browser');
        session()->flush();
        $this->assertEquals($products, DemoCatalogue::products());
        $this->assertSame(5, PosStore::connection()->table('sale_options')->count());
        $this->assertSame(72, SessionBatches::state()['batches']['legacy-1']['remaining']);
        $this->assertNull(SessionBatches::state()['batches']['legacy-1']['totalCost']);
        Storage::disk('local')->assertExists('pos-imports/'.hash('sha256', 'first-browser').'.json');
        $this->get('/products')->assertOk()->assertSee('shared shop database');
        $this->get('/inventory')->assertOk()->assertSee('72 bottle');
        try {
            PosStore::import(['products' => []], 'another-browser');
            $this->fail('A second browser must not replace the database.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('storage', $exception->errors());
        }
        $this->assertEquals($products, DemoCatalogue::products());
    }

    public function test_sales_returns_debts_and_drawer_entries_commit_together_and_retries_survive_new_sessions(): void
    {
        $this->initialize();
        SessionCash::open('open', '500', '');
        SessionCredit::addCustomer('Maria', '');
        $customer = array_key_first(SessionCredit::customers());
        $sale = SessionSales::complete('sale', ['drink-pack' => 2], 'Credit', '', false, $customer);
        $debt = array_key_first(SessionCredit::debts());
        SessionCredit::repay('repay', $debt, '50', 'Cash', '');
        $return = SessionReturns::complete('return', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Unopened package');
        $this->assertSame(66, DemoCatalogue::find(1)['stock']);
        $this->assertSame(6000, SessionCredit::debts()[$debt]['balance']);
        $this->assertSame(55000, SessionCash::summary(SessionCash::active()['id'])['expected']);
        $this->assertSame(6, $return['lines'][0]['baseQuantity']);
        $this->assertSame(1, PosStore::connection()->table('sales')->count());
        $this->assertSame(1, PosStore::connection()->table('sale_lines')->count());
        $this->assertSame(1, PosStore::connection()->table('sale_returns')->count());
        session()->flush();
        $this->assertEquals($sale, SessionSales::complete('sale', ['drink-pack' => 2], 'Credit', '', false, $customer));
        $this->assertEquals($return, SessionReturns::complete('return', $sale['id'], [], 'Cash', true, 'Retry'));
        $this->assertSame(66, DemoCatalogue::find(1)['stock']);
        $this->assertSame(1, PosStore::connection()->table('debt_repayments')->count());
    }

    public function test_livewire_products_receiving_and_counts_use_the_database(): void
    {
        $this->initialize();
        Livewire::test(Products::class)->call('create')->set('name', 'Biscuits')->set('sku', 'BIS-1')
            ->set('category', 'Pantry')->set('unit', 'packet')->set('baseCost', '3.50')->set('openingStock', 5)
            ->set('options.0.price', '10')->call('save')->assertHasNoErrors();
        $product = DemoCatalogue::find(3);
        $this->assertSame(350, $product['baseCost']);
        Livewire::test(Inventory::class)->set('optionId', $product['options'][0]['id'])->set('quantity', 2)
            ->set('cost', '4')->set('supplier', 'Local supplier')->call('receive')->assertHasNoErrors();
        Livewire::test(Products::class)->call('startCount', 3)->set('physicalCount', 6)->set('countReason', 'Counted shelf stock')->call('saveCount')->assertHasNoErrors();
        $this->assertSame(6, DemoCatalogue::find(3)['stock']);
        $this->assertSame(1, PosStore::connection()->table('deliveries')->count());
        $this->assertSame(1, PosStore::connection()->table('stock_counts')->count());
        SessionBatches::assertBalanced(SessionBatches::state(), DemoCatalogue::products());
        $this->assertFalse(session()->has('demo.products'));
    }

    public function test_supplier_bills_payments_losses_and_expenses_keep_relational_links(): void
    {
        $this->initialize();
        SessionCash::open('open', '500', '');
        SessionPurchasing::saveSupplier(null, ['name' => 'Supplier', 'contact' => '', 'phone' => '', 'email' => '', 'notes' => '']);
        $supplier = array_key_first(SessionPurchasing::suppliers());
        SessionPurchasing::createOrder($supplier, '', [['optionId' => 'drink-pack', 'quantity' => 2, 'cost' => '60']]);
        $order = array_key_first(SessionPurchasing::orders());
        SessionPurchasing::receive($order, [['quantity' => 1, 'cost' => '61', 'expiry' => '']], 'Invoice 1', 'delivery');
        $invoice = array_key_first(SessionPayables::invoices());
        SessionPayables::pay('pay', $invoice, '20', 'Cash', true, '', 'Paid part');
        SessionCash::expense('expense', '10', 'Supplies', 'Cash', 'Receipt paper');
        SessionLosses::record('loss', 'drink-one', 1, 'Damage', 'Broken bottle', '10');
        $this->assertSame(4100, SessionPayables::invoices()[$invoice]['balance']);
        $this->assertSame(47000, SessionCash::summary(SessionCash::active()['id'])['expected']);
        $this->assertSame(77, DemoCatalogue::find(1)['stock']);
        $this->assertSame($invoice, PosStore::connection()->table('supplier_payments')->value('invoice_id'));
        $this->assertSame(1, PosStore::connection()->table('supplier_invoice_lines')->count());
        $payment = SessionPayables::payments();
        $this->assertArrayHasKey(array_key_first($payment), $payment);
        $this->assertSame('20', (string) (array_values($payment)[0]['amount'] / 100));
        $this->assertCount(1, SessionCash::expenses());
        SessionPurchasing::receive($order, [], '', 'delivery');
        SessionPayables::pay('pay', $invoice, '20', 'Cash', true, '', '');
        $this->assertSame(1, PosStore::connection()->table('supplier_payments')->count());
    }

    public function test_failed_database_write_rolls_back_preceding_stock_update(): void
    {
        $this->initialize();
        $products = DemoCatalogue::products();
        $products[0]['stock'] = 1;
        try {
            PosStore::put(['demo.products' => $products, 'demo.batches' => ['bad' => ['id' => 'bad', 'productId' => 1, 'remaining' => -1, 'initialQuantity' => 1]]]);
            $this->fail('The database quantity constraint must reject this write.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertSame(2, PosStore::connection()->table('inventory_batches')->count());
    }

    public function test_existing_browser_history_round_trips_without_revaluing_or_merging_records(): void
    {
        config(['pos.storage' => 'session']);
        SessionCash::open('open', '500', '');
        SessionCredit::addCustomer('Maria', '');
        $customer = array_key_first(SessionCredit::customers());
        $sale = SessionSales::complete('sale', ['drink-pack' => 1], 'Cash', '200', false);
        SessionReturns::complete('return', $sale['id'], [['quantity' => 1, 'disposition' => 'Discard']], 'Cash', true, 'Damaged package');
        SessionCredit::cashLoan('loan', $customer, '10', 'Cash', '', '');
        $debt = array_key_first(SessionCredit::debts());
        SessionCredit::repay('repay', $debt, '5', 'Cash', '');
        SessionCash::expense('expense', '1', 'Supplies', 'Cash', 'Paper receipt');
        SessionPurchasing::saveSupplier(null, ['name' => 'Supplier', 'contact' => '', 'phone' => '', 'email' => '', 'notes' => '']);
        SessionPurchasing::createOrder(array_key_first(SessionPurchasing::suppliers()), '', [['optionId' => 'drink-one', 'quantity' => 2, 'cost' => '10']]);
        SessionPurchasing::receive(array_key_first(SessionPurchasing::orders()), [['quantity' => 2, 'cost' => '10', 'expiry' => '']], '', 'delivery');
        SessionPayables::pay('pay', array_key_first(SessionPayables::invoices()), '5', 'Cash', true, '', '');
        $original = session('demo');
        config(['pos.storage' => 'database']);
        PosStore::import($original, 'original-browser');
        session()->flush();
        foreach ($original as $collection => $records) {
            $this->assertEquals($records, PosStore::get('demo.'.$collection), $collection);
        }
        SessionBatches::assertBalanced(SessionBatches::state(), DemoCatalogue::products());
        $this->assertSame(1, PosStore::connection()->table('stock_losses')->count());
        $this->assertSame(1, PosStore::connection()->table('supplier_payments')->count());
    }

    public function test_expiry_and_original_batch_returns_remain_exact_after_database_round_trip(): void
    {
        $products = DemoCatalogue::defaults();
        $products[0]['baseCost'] = 1000;
        PosStore::import(['products' => $products], 'browser');
        $expiry = now('Asia/Shanghai')->addDays(3)->toDateString();
        Livewire::test(Inventory::class)->set('optionId', 'drink-one')->set('quantity', 2)
            ->set('cost', '12')->set('supplier', 'Supplier')->set('expiry', $expiry)->call('receive')->assertHasNoErrors();
        SessionCash::open('open', '500', '');
        $sale = SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false);
        $allocation = $sale['lines'][0]['batchAllocations'][0];
        $this->assertSame($expiry, $allocation['expiry']);
        $this->assertSame(2400, $allocation['originalTotalCost']);
        $product = DemoCatalogue::find(1);
        $product['baseCost'] = 1500;
        DemoCatalogue::save($product);
        $return = SessionReturns::complete('return', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Unopened bottle');
        $this->assertSame(1200, $return['lines'][0]['cost']);
        $this->assertSame(2, SessionBatches::state()['batches'][$allocation['batchId']]['remaining']);
        $this->assertSame($expiry, PosStore::connection()->table('inventory_batches')->where('id', $allocation['batchId'])->value('expiry'));
        $this->assertSame(1200, SessionSales::all()[$sale['id']]['lines'][0]['cost']);
    }

    public function test_fractional_batch_costs_returns_and_resales_persist_without_revaluation(): void
    {
        $products = DemoCatalogue::defaults();
        $products[0]['stock'] = 0;
        PosStore::import(['products' => $products], 'browser');
        Livewire::test(Inventory::class)->set('optionId', 'drink-pack')->set('quantity', 1)
            ->set('cost', '61.25')->set('supplier', 'Supplier')->call('receive')->assertHasNoErrors();
        SessionCash::open('opening', '500', '');
        $sale = SessionSales::complete('sale', ['drink-one' => 3], 'Cash', '60', false);
        $batch = $sale['lines'][0]['batchAllocations'][0]['batchId'];
        $this->assertSame(3062, $sale['lines'][0]['cost']);
        session()->flush();
        $first = SessionReturns::complete('first', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Unopened item');
        $second = SessionReturns::complete('second', $sale['id'], [['quantity' => 1, 'disposition' => 'Discard']], 'Cash', true, 'Damaged item');
        $this->assertSame(1020, $first['lines'][0]['cost']);
        $this->assertSame(1021, $second['lines'][0]['cost']);
        $this->assertSame(4083, SessionBatches::overview()['batches'][$batch]['remainingCost']);
        $this->assertSame(1021, SessionLosses::all()[0]['totalCost']);
        $product = DemoCatalogue::find(1);
        $product['baseCost'] = 0;
        DemoCatalogue::save($product);
        session()->flush();
        $resale = SessionSales::complete('resale', ['drink-one' => 4], 'Cash', '80', false);
        $this->assertSame(4083, $resale['lines'][0]['cost']);
        $this->assertSame(0, SessionBatches::overview()['batches'][$batch]['remainingCost']);
        $this->assertEquals($sale['lines'], SessionSales::all()[$sale['id']]['lines']);
        $this->assertEquals($second, SessionReturns::complete('second', $sale['id'], [], 'Invalid', false, ''));
        $this->assertSame(2, PosStore::connection()->table('sales')->count());
        $this->get('/sales')->assertOk();
        $this->get('/inventory')->assertOk()->assertSee('Remaining value');
        $this->get('/reports')->assertOk()->assertSee('Stock acquisition value');
        SessionBatches::assertBalanced(SessionBatches::state(), DemoCatalogue::products());
    }

    public function test_competing_connection_waits_for_shop_lock_and_stale_stock_cannot_oversell(): void
    {
        $this->initialize();
        $competitor = DB::connection('pos_competing');
        PosStore::atomic(function () use ($competitor): void {
            $competitor->beginTransaction();
            try {
                $competitor->statement("SET LOCAL lock_timeout = '100ms'");
                $competitor->table('pos_workspace')->where('id', 1)->lockForUpdate()->first();
                $this->fail('A competing connection must not obtain the active shop lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            } finally {
                $competitor->rollBack();
            }
        });
        $competitor->transaction(fn () => $this->assertNotNull($competitor->table('pos_workspace')->where('id', 1)->lockForUpdate()->first()));
        SessionCash::open('open', '0', '');
        SessionSales::complete('one', ['drink-case' => 3], 'GCash', '1260', true);
        try {
            SessionSales::complete('two', ['drink-one' => 1], 'GCash', '20', true);
            $this->fail('A stale second checkout must not oversell.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
        $this->assertSame(0, DemoCatalogue::find(1)['stock']);
        $this->assertCount(1, SessionSales::all());
    }

    public function test_import_rejects_inconsistent_batches_and_mutations_before_initialization(): void
    {
        try {
            SessionCash::open('open', '0', '');
            $this->fail('Import is required before recording changes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('storage', $exception->errors());
        }
        try {
            PosStore::import(['products' => DemoCatalogue::defaults(), 'batchProducts' => [1, 2], 'batches' => []], 'bad-browser');
            $this->fail('Unbalanced legacy batches must not be imported.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertFalse(PosStore::ready());
        $this->assertSame(0, PosStore::connection()->table('products')->count());
        $this->assertSame(0, PosStore::connection()->table('cash_sessions')->count());
        $this->get('/dashboard')->assertOk()->assertSee('Import this browser');
    }
}
