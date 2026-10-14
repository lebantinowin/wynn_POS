<?php

namespace Tests\Feature;

use App\Livewire\OfflineRecovery;
use App\Models\User;
use App\Support\DemoCatalogue;
use App\Support\OfflineCheckout;
use App\Support\PosStore;
use App\Support\SessionCash;
use App\Support\SessionSales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OfflineRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['database.connections.sqlite.database' => ':memory:', 'pos.connection' => 'sqlite', 'pos.storage' => 'database']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--path' => [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_10_12_003040_create_pos_tables.php',
            'database/migrations/2026_10_12_024957_create_offline_checkout_tables.php',
            'database/migrations/2026_10_14_060702_add_recovery_release_to_offline_checkouts_table.php',
        ], '--force' => true]);
        PosStore::import(['products' => DemoCatalogue::defaults()], 'recovery-test');
    }

    private function prepared(): array
    {
        SessionCash::open('recovery-cash', '1000', '');

        return app(OfflineCheckout::class)->prepare((string) Str::uuid(), (string) Str::uuid());
    }

    private function expired(array $snapshot): void
    {
        $this->travelTo(Carbon::parse($snapshot['validUntil'])->addSecond());
    }

    private function release(array $snapshot, ?string $eventId = null): array
    {
        return app(OfflineCheckout::class)->releaseUnavailable($snapshot['id'], $eventId ?? (string) Str::uuid(), 'Original browser profile lost', 'RELEASE COUNTER 1', true);
    }

    private function rejected(callable $action): void
    {
        try {
            $action();
            $this->fail('The unsafe recovery action must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_expiry_does_not_automatically_release_and_owner_cannot_release_early(): void
    {
        $snapshot = $this->prepared();
        $component = Livewire::test(OfflineRecovery::class)->set('reason', 'Original browser profile lost')->set('confirmation', 'RELEASE COUNTER 1')->set('acknowledged', true);
        $component->call('release')->assertHasErrors('offline')->assertSee('Wait until');
        $this->expired($snapshot);
        $this->assertNotNull(app(OfflineCheckout::class)->active());
        $this->rejected(fn () => SessionSales::complete('still-paused', ['drink-one' => 1], 'Cash', '20', false));
        $this->assertSame([], app(OfflineCheckout::class)->releaseHistory());
    }

    public function test_release_requires_reason_phrase_acknowledgement_and_owner(): void
    {
        $snapshot = $this->prepared();
        $this->expired($snapshot);
        Livewire::test(OfflineRecovery::class)->call('release')->assertHasErrors(['reason', 'confirmation', 'acknowledged']);
        Livewire::test(OfflineRecovery::class)->set('reason', 'Profile lost')->set('confirmation', 'release counter 1')->set('acknowledged', true)->call('release')->assertHasErrors('confirmation');
        Auth::forgetGuards();
        $this->rejected(fn () => $this->release($snapshot));
        $this->get('/settings')->assertRedirect('/login');
        $this->assertNotNull(app(OfflineCheckout::class)->active());
    }

    public function test_release_records_unknown_pending_sales_without_changing_financial_records(): void
    {
        $this->travel(-3)->hours();
        $snapshot = $this->prepared();
        $this->expired($snapshot);
        if (getenv('OFFLINE_RECOVERY_BROWSER_QA')) {
            User::factory()->create(['id' => User::OWNER_ID, 'name' => 'Recovery QA Owner', 'email' => 'practice-qa@example.test', 'password' => 'Practice-QA-Only42!']);
            $folder = storage_path('app/practice');
            File::ensureDirectoryExists($folder);
            $artifact = $folder.'/recovery-ui-qa-'.bin2hex(random_bytes(8)).'.sqlite';
            DB::connection('sqlite')->getPdo()->exec('VACUUM INTO '.DB::connection('sqlite')->getPdo()->quote($artifact));
            fwrite(STDOUT, "\nOffline recovery browser fixture: ".$artifact."\n");
        }
        $before = [DemoCatalogue::products(), SessionCash::all(), SessionSales::all()];
        Livewire::test(OfflineRecovery::class)->set('reason', 'Original browser profile lost')->set('confirmation', 'RELEASE COUNTER 1')->set('acknowledged', true)
            ->call('release')->assertHasNoErrors()->assertRedirect('/settings');
        $this->assertNull(app(OfflineCheckout::class)->active());
        $this->assertSame($before, [DemoCatalogue::products(), SessionCash::all(), SessionSales::all()]);
        $history = app(OfflineCheckout::class)->releaseHistory();
        $this->assertCount(1, $history);
        $this->assertSame(0, $history[0]['confirmedSales']);
        $this->assertSame('Unknown', $history[0]['unconfirmedSales']);
        $this->assertSame(1, $history[0]['ownerId']);
        $this->assertSame($snapshot['id'], $history[0]['leaseId']);
        $this->get('/settings')->assertOk()->assertSee('Counter 1 reopened.')->assertSee('Original browser profile lost');
        $sale = SessionSales::complete('new-connected-sale', ['drink-one' => 1], 'Cash', '20', false);
        $this->assertSame(2000, $sale['total']);
    }

    public function test_retry_is_idempotent_and_stale_release_cannot_free_a_new_browser(): void
    {
        $snapshot = $this->prepared();
        $this->expired($snapshot);
        $eventId = (string) Str::uuid();
        $saved = $this->release($snapshot, $eventId);
        $new = app(OfflineCheckout::class)->prepare((string) Str::uuid(), (string) Str::uuid());
        $this->assertSame($saved, $this->release($snapshot, $eventId));
        $this->rejected(fn () => $this->release($snapshot));
        $this->rejected(fn () => app(OfflineCheckout::class)->releaseUnavailable($snapshot['id'], $eventId, 'Changed reason', 'RELEASE COUNTER 1', true));
        $this->assertSame($new['id'], app(OfflineCheckout::class)->active()->id);
        $this->assertCount(1, app(OfflineCheckout::class)->releaseHistory());
    }

    public function test_confirmed_upload_retry_survives_release_but_unconfirmed_uploads_are_blocked(): void
    {
        $snapshot = $this->prepared();
        $event = ['leaseId' => $snapshot['id'], 'deviceId' => $snapshot['deviceId'], 'eventId' => (string) Str::uuid(), 'sequence' => 1,
            'occurredAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'cart' => ['drink-one' => 1], 'method' => 'Cash', 'received' => '20',
            'confirmed' => false, 'customerId' => '', 'dueDate' => '', 'total' => 2000];
        $sale = $this->postJson('/offline/sync', $event)->assertOk()->json();
        $this->expired($snapshot);
        $release = $this->release($snapshot);
        $this->assertSame(1, $release['confirmedSales']);
        $this->assertEquals($sale, $this->postJson('/offline/sync', $event)->assertOk()->json());
        $event['eventId'] = (string) Str::uuid();
        $event['sequence'] = 2;
        $this->postJson('/offline/sync', $event)->assertUnprocessable()->assertSee('New uploads are blocked');
        $this->assertCount(1, SessionSales::all());
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
        $this->assertSame(102000, SessionCash::summary($snapshot['cashSessionId'])['expected']);
        $this->assertSame(1, PosStore::connection()->table('offline_sale_events')->count());
    }

    public function test_finished_sessions_cannot_be_relabelled_as_lost_and_reasons_are_escaped(): void
    {
        $snapshot = $this->prepared();
        app(OfflineCheckout::class)->finish($snapshot['id'], $snapshot['deviceId'], 0);
        $this->expired($snapshot);
        $this->rejected(fn () => $this->release($snapshot));
        $this->assertSame([], app(OfflineCheckout::class)->releaseHistory());
        $new = app(OfflineCheckout::class)->prepare((string) Str::uuid(), (string) Str::uuid());
        $this->expired($new);
        app(OfflineCheckout::class)->releaseUnavailable($new['id'], (string) Str::uuid(), '<script>alert(1)</script>', 'RELEASE COUNTER 1', true);
        $this->get('/settings')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_invalid_or_missing_identifier_keeps_the_current_hold(): void
    {
        $snapshot = $this->prepared();
        $this->expired($snapshot);
        $this->rejected(fn () => app(OfflineCheckout::class)->releaseUnavailable('../.env', (string) Str::uuid(), 'Lost profile', 'RELEASE COUNTER 1', true));
        $this->rejected(fn () => $this->release(['id' => (string) Str::uuid()]));
        $this->assertSame($snapshot['id'], app(OfflineCheckout::class)->active()->id);
    }

    public function test_preview_mode_cannot_claim_to_have_released_a_counter(): void
    {
        config(['pos.storage' => 'session']);
        Livewire::test(OfflineRecovery::class)->set('reason', 'Lost profile')->set('confirmation', 'RELEASE COUNTER 1')->set('acknowledged', true)
            ->call('release')->assertHasErrors('offline');
        $this->assertSame([], app(OfflineCheckout::class)->releaseHistory());
    }
}
