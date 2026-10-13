<?php

namespace Tests\Feature;

use App\Support\DemoCatalogue;
use App\Support\OfflineCheckout;
use App\Support\PosStore;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionSales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfflineCheckoutTest extends TestCase
{
    private ?string $schema = null;

    private const MIGRATIONS = [
        'database/migrations/2026_10_12_003040_create_pos_tables.php',
        'database/migrations/2026_10_12_024957_create_offline_checkout_tables.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['database.connections.sqlite.database' => ':memory:', 'pos.connection' => 'sqlite', 'pos.storage' => 'database']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--path' => self::MIGRATIONS, '--force' => true]);
        PosStore::import(['products' => DemoCatalogue::defaults()], 'offline-test');
    }

    protected function tearDown(): void
    {
        if ($this->schema) {
            DB::connection('offline_test')->statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            DB::purge('offline_test');
        }
        parent::tearDown();
    }

    private function prepared(): array
    {
        SessionCash::open('offline-cash', '1000', '');

        return app(OfflineCheckout::class)->prepare((string) Str::uuid(), (string) Str::uuid());
    }

    private function event(array $snapshot, int $sequence = 1): array
    {
        return ['leaseId' => $snapshot['id'], 'deviceId' => $snapshot['deviceId'], 'eventId' => (string) Str::uuid(),
            'sequence' => $sequence, 'occurredAt' => now()->utc()->format('Y-m-d\\TH:i:s.v\\Z'),
            'cart' => ['drink-one' => 2, 'drink-pack' => 1], 'method' => 'Cash', 'received' => '200',
            'confirmed' => false, 'customerId' => '', 'dueDate' => '', 'total' => 15000];
    }

    private function rejected(callable $action): void
    {
        try {
            $action();
            $this->fail('The operation should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_guest_requests_cannot_prepare_upload_or_release_checkout(): void
    {
        Auth::forgetGuards();
        $this->getJson('/offline/status')->assertUnauthorized();
        foreach (['prepare', 'sync', 'finish'] as $action) {
            $this->postJson('/offline/'.$action, [])->assertUnauthorized();
        }
        $this->assertNull(app(OfflineCheckout::class)->active());
    }

    public function test_preparation_requires_shared_initialized_storage_and_open_cash(): void
    {
        $this->postJson('/offline/prepare', ['id' => (string) Str::uuid(), 'deviceId' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('cashSession');
        config(['pos.storage' => 'session']);
        $this->postJson('/offline/prepare', ['id' => (string) Str::uuid(), 'deviceId' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('offline');
    }

    public function test_one_browser_holds_counter_and_preparation_retry_keeps_original_snapshot(): void
    {
        $snapshot = $this->prepared();
        $this->assertSame($snapshot, app(OfflineCheckout::class)->prepare($snapshot['deviceId'], $snapshot['id']));
        $this->assertSame(72, $snapshot['products'][0]['available']);
        $this->assertArrayNotHasKey('baseCost', $snapshot['products'][0]);
        $this->rejected(fn () => app(OfflineCheckout::class)->prepare((string) Str::uuid(), (string) Str::uuid()));
        $this->assertSame(1, PosStore::connection()->table('offline_checkouts')->count());
    }

    public function test_connected_stock_and_cash_changes_pause_until_original_browser_finishes(): void
    {
        $snapshot = $this->prepared();
        $this->rejected(fn () => SessionSales::complete('connected-sale', ['drink-one' => 1], 'Cash', '20', false));
        $this->rejected(fn () => SessionCash::close($snapshot['cashSessionId'], 'close', '1000', ''));
        $this->rejected(fn () => SessionCredit::addCustomer('New customer', ''));
        $this->assertSame(72, DemoCatalogue::products()[0]['stock']);
        $this->assertSame('Open', SessionCash::active()['status']);
        app(OfflineCheckout::class)->finish($snapshot['id'], $snapshot['deviceId'], 0);
        $sale = SessionSales::complete('connected-sale', ['drink-one' => 1], 'Cash', '20', false);
        $this->assertSame(2000, $sale['total']);
    }

    public function test_ordered_upload_commits_stock_cash_receipt_and_acknowledgment_once(): void
    {
        $snapshot = $this->prepared();
        $event = $this->event($snapshot);
        $this->assertSame(15000, SessionSales::draft($event['cart'], 'Cash', '200', false)['total']);
        $receipt = $this->postJson('/offline/sync', $event)->assertOk()->json();
        $this->assertSame('WM-000001', $receipt['number']);
        $this->assertSame(15000, $receipt['total']);
        $this->assertSame(5000, $receipt['change']);
        $this->assertSame(64, DemoCatalogue::products()[0]['stock']);
        $this->assertSame(115000, SessionCash::summary($snapshot['cashSessionId'])['expected']);
        $this->assertSame($receipt, $this->postJson('/offline/sync', $event)->assertOk()->json());
        $this->assertCount(1, SessionSales::all());
        $this->assertCount(1, SessionCash::movements());
        $this->assertSame(1, PosStore::connection()->table('offline_sale_events')->count());
        $this->assertSame(2, (int) app(OfflineCheckout::class)->active()->next_sequence);
        $this->assertNotNull($receipt['offline']['localNumber']);
    }

    public function test_changed_duplicate_payload_or_total_is_rejected_without_any_mutation(): void
    {
        $snapshot = $this->prepared();
        $event = $this->event($snapshot);
        $wrong = $event;
        $wrong['total']++;
        $this->postJson('/offline/sync', $wrong)->assertUnprocessable();
        $this->assertCount(0, SessionSales::all());
        $this->assertSame(72, DemoCatalogue::products()[0]['stock']);
        $this->postJson('/offline/sync', $event)->assertOk();
        $wrong = $event;
        $wrong['received'] = '300';
        $this->postJson('/offline/sync', $wrong)->assertUnprocessable();
        $this->assertCount(1, SessionSales::all());
        $this->assertSame(64, DemoCatalogue::products()[0]['stock']);
    }

    public function test_out_of_order_sales_and_early_release_keep_the_session_and_records(): void
    {
        $snapshot = $this->prepared();
        $event = $this->event($snapshot, 2);
        $this->postJson('/offline/sync', $event)->assertUnprocessable();
        $this->postJson('/offline/finish', ['id' => $snapshot['id'], 'deviceId' => $snapshot['deviceId'], 'lastSequence' => 2])->assertUnprocessable();
        $event['sequence'] = 1;
        $this->postJson('/offline/sync', $event)->assertOk();
        $this->postJson('/offline/finish', ['id' => $snapshot['id'], 'deviceId' => $snapshot['deviceId'], 'lastSequence' => 1])->assertOk();
        $this->postJson('/offline/finish', ['id' => $snapshot['id'], 'deviceId' => $snapshot['deviceId'], 'lastSequence' => 1])->assertOk();
        $this->assertNull(app(OfflineCheckout::class)->active());
        $this->postJson('/offline/sync', $event)->assertOk();
        $event['eventId'] = (string) Str::uuid();
        $event['sequence'] = 2;
        $this->postJson('/offline/sync', $event)->assertUnprocessable();
        $this->assertCount(1, SessionSales::all());
    }

    public function test_unknown_browser_and_invalid_cart_cannot_change_the_prepared_shop(): void
    {
        $snapshot = $this->prepared();
        $event = $this->event($snapshot);
        $event['deviceId'] = (string) Str::uuid();
        $this->postJson('/offline/sync', $event)->assertUnprocessable();
        $event['deviceId'] = $snapshot['deviceId'];
        $event['cart'] = ['drink-one' => 9999];
        $this->postJson('/offline/sync', $event)->assertUnprocessable();
        $this->assertCount(0, SessionSales::all());
        $this->assertSame(72, DemoCatalogue::products()[0]['stock']);
        $this->assertSame(1, (int) app(OfflineCheckout::class)->active()->next_sequence);
    }

    public function test_sale_time_and_expiry_use_original_local_day_even_when_uploaded_later(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12T23:50:00+08:00'));
        $products = DemoCatalogue::products();
        $state = SessionBatches::state();
        foreach ($state['batches'] as &$batch) {
            if ($batch['productId'] === 1) {
                $batch['expiry'] = '2026-10-12';
            }
        } unset($batch);
        PosStore::put(SessionBatches::changes($state));
        $snapshot = $this->prepared();
        $this->assertSame('2026-10-13', Carbon::parse($snapshot['validUntil'])->timezone('Asia/Shanghai')->toDateString());
        $event = $this->event($snapshot);
        $this->travelTo(Carbon::parse('2026-10-13T08:00:00+08:00'));
        $receipt = $this->postJson('/offline/sync', $event)->assertOk()->json();
        $this->assertSame('2026-10-12', Carbon::parse($receipt['time'])->timezone('Asia/Shanghai')->toDateString());
        $this->assertSame('2026-10-13', Carbon::parse($receipt['offline']['syncedAt'])->timezone('Asia/Shanghai')->toDateString());
        $event = $this->event($snapshot, 2);
        $this->postJson('/offline/sync', $event)->assertUnprocessable();
        $this->assertCount(1, SessionSales::all());
        $this->assertSame('2026-10-13', app(OfflineCheckout::class)->time()->timezone('Asia/Shanghai')->toDateString());
    }

    public function test_credit_and_confirmed_digital_sales_keep_their_payment_and_debt_meaning(): void
    {
        SessionCredit::addCustomer('Offline customer', '');
        $customer = array_key_first(SessionCredit::customers());
        $snapshot = $this->prepared();
        $event = $this->event($snapshot);
        $event['method'] = 'Credit';
        $event['received'] = '';
        $event['customerId'] = $customer;
        $this->postJson('/offline/sync', $event)->assertOk();
        $this->assertCount(1, SessionCredit::debts());
        $this->assertCount(0, SessionCash::movements());
        $event = $this->event($snapshot, 2);
        $event['method'] = 'GCash';
        $event['received'] = '';
        $this->postJson('/offline/sync', $event)->assertUnprocessable();
        $event['confirmed'] = true;
        $this->postJson('/offline/sync', $event)->assertOk();
        $this->assertSame(15000, SessionCash::summary($snapshot['cashSessionId'])['methods']['GCash']['in']);
        $this->assertSame(100000, SessionCash::summary($snapshot['cashSessionId'])['expected']);
    }

    public function test_postgresql_offline_events_survive_connection_reload_and_retry(): void
    {
        if (! getenv('RUN_PGSQL_TESTS')) {
            $this->markTestSkipped('Set RUN_PGSQL_TESTS=1 for PostgreSQL offline durability.');
        }
        $this->schema = 'offline_test_'.bin2hex(random_bytes(8));
        $config = config('database.connections.pgsql');
        $config['database'] = getenv('POS_PGSQL_TEST_DATABASE') ?: 'wynn_mart';
        $config['search_path'] = $this->schema;
        config(['database.connections.offline_test' => $config, 'pos.connection' => 'offline_test']);
        DB::connection('offline_test')->statement('CREATE SCHEMA "'.$this->schema.'"');
        Artisan::call('migrate', ['--database' => 'offline_test', '--path' => self::MIGRATIONS, '--force' => true]);
        PosStore::import(['products' => DemoCatalogue::defaults()], 'offline-pg');
        $snapshot = $this->prepared();
        $event = $this->event($snapshot);
        $receipt = app(OfflineCheckout::class)->sync($event);
        DB::purge('offline_test');
        $this->assertEquals($receipt, app(OfflineCheckout::class)->sync($event));
        $this->assertSame(64, DemoCatalogue::products()[0]['stock']);
        $this->assertSame(1, PosStore::connection()->table('offline_sale_events')->count());
    }
}
