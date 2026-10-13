<?php

namespace Tests\Feature;

use App\Livewire\Backups;
use App\Support\DatabaseBackups;
use App\Support\DemoCatalogue;
use App\Support\PosStore;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class BackupsTest extends TestCase
{
    private ?string $schema = null;

    private function database(): DatabaseBackups
    {
        if (! getenv('RUN_PGSQL_TESTS')) {
            $this->markTestSkipped('Set RUN_PGSQL_TESTS=1 for real PostgreSQL backup/recovery checks.');
        }
        Storage::fake('local');
        $this->schema = 'backup_test_'.bin2hex(random_bytes(8));
        $config = config('database.connections.pgsql');
        $config['database'] = getenv('POS_PGSQL_TEST_DATABASE') ?: 'wynn_mart';
        $config['search_path'] = $this->schema;
        config(['database.connections.backup_test' => $config, 'pos.storage' => 'database',
            'pos.connection' => 'backup_test', 'backups.schema' => $this->schema]);
        DB::connection('backup_test')->statement('CREATE SCHEMA "'.$this->schema.'"');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'backup_test', '--path' => [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_10_12_003040_create_pos_tables.php',
        ], '--force' => true]));
        PosStore::import(['products' => DemoCatalogue::defaults()], 'backup-test');
        SessionCash::open('backup-cash', '1000', '');
        SessionCredit::addCustomer('Recovery customer', '');
        $customer = array_key_first(SessionCredit::customers());
        $sale = SessionSales::complete('backup-sale', ['drink-pack' => 1, 'noodle-one' => 2], 'Credit', '', false, $customer);
        SessionReturns::complete('backup-return', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock'], ['quantity' => 0, 'disposition' => 'Restock']], 'Cash', true, 'Unopened package');
        SessionCredit::cashLoan('backup-loan', $customer, '50', 'Cash', '', '');

        return app(DatabaseBackups::class);
    }

    protected function tearDown(): void
    {
        if ($this->schema) {
            DB::connection('backup_test')->statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
            DB::purge('backup_test');
        }
        parent::tearDown();
    }

    private function saved(string $status = 'Succeeded', int $ageDays = 0): array
    {
        $backups = app(DatabaseBackups::class);
        $id = (string) Str::uuid();
        $record = ['id' => $id, 'createdAt' => now()->subDays($ageDays)->toIso8601String(),
            'completedAt' => now()->subDays($ageDays)->toIso8601String(), 'source' => 'Manual',
            'status' => $status, 'verification' => 'Not checked', 'bytes' => 6, 'checksum' => hash('sha256', 'PGDMP!'),
            'schema' => 'public', 'tables' => []];
        File::ensureDirectoryExists($backups->path('records'));
        File::put($backups->path('records/'.$id.'.json'), json_encode($record));
        File::put($backups->path($id.'.dump'), 'PGDMP!');

        return $record;
    }

    public function test_settings_screen_and_default_preferences_follow_owner_workspace(): void
    {
        $this->get('/settings')->assertOk()->assertSee('Backups &amp; recovery', false)->assertSee('Back up now')->assertSee('14 days')->assertSee('No backup saved yet');
        $this->assertSame(['enabled' => true, 'intervalHours' => 24, 'retentionDays' => 14], app(DatabaseBackups::class)->settings());
    }

    public function test_preferences_persist_and_reject_unsupported_values(): void
    {
        Livewire::test(Backups::class)->set('enabled', false)->set('intervalHours', 6)->set('retentionDays', 30)
            ->call('saveSettings')->assertHasNoErrors()->assertSee('Backup preferences saved.');
        $this->assertSame(['enabled' => false, 'intervalHours' => 6, 'retentionDays' => 30], app(DatabaseBackups::class)->settings());
        Livewire::test(Backups::class)->set('intervalHours', 2)->set('retentionDays', 0)->call('saveSettings')->assertHasErrors(['intervalHours', 'retentionDays']);
        $this->assertSame(30, app(DatabaseBackups::class)->settings()['retentionDays']);
    }

    public function test_disabled_or_not_due_schedule_does_not_launch_tools(): void
    {
        $backups = app(DatabaseBackups::class);
        Process::fake()->preventStrayProcesses();
        $backups->saveSettings(false, 24, 14);
        $this->assertNull($backups->create(true));
        $backups->saveSettings(true, 24, 14);
        $this->saved();
        $this->assertNull($backups->create(true));
        $this->assertArrayHasKey('lastCheckedAt', $backups->scheduler());
        Process::assertNothingRan();
    }

    public function test_preview_mode_cannot_claim_to_have_backed_up_a_database(): void
    {
        Livewire::test(Backups::class)->call('backupNow')->assertHasErrors('backup')->assertSee('Backups require the shared PostgreSQL workspace.');
        $this->assertSame([], app(DatabaseBackups::class)->history());
    }

    public function test_interrupted_operations_are_reported_on_next_schedule_check(): void
    {
        $backups = app(DatabaseBackups::class);
        $interrupted = $this->saved('Running', 1);
        File::put($backups->path($interrupted['id'].'.dump.partial'), 'partial');
        $good = $this->saved();
        $good['verification'] = 'Running';
        File::put($backups->path('records/'.$good['id'].'.json'), json_encode($good));
        $this->assertNull($backups->create(true));
        $history = collect($backups->history());
        $this->assertSame('Failed', $history->firstWhere('id', $interrupted['id'])['status']);
        $this->assertSame('Failed', $history->firstWhere('id', $good['id'])['verification']);
        $this->assertFileDoesNotExist($backups->path($interrupted['id'].'.dump.partial'));
        $this->assertFileDoesNotExist($backups->path($interrupted['id'].'.dump'));
        $this->assertFileExists($backups->path($good['id'].'.dump'));
    }

    public function test_overlap_lock_preserves_preferences_and_history(): void
    {
        $backups = app(DatabaseBackups::class);
        $backups->saveSettings(true, 24, 14);
        $handle = fopen($backups->path('operation.lock'), 'c');
        flock($handle, LOCK_EX);
        try {
            Livewire::test(Backups::class)->set('retentionDays', 7)->call('saveSettings')->assertHasErrors('backup')->assertSee('Another backup or recovery check is already running.');
            $this->assertSame(14, $backups->settings()['retentionDays']);
            $this->assertSame([], $backups->history());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function test_missing_or_modified_archive_fails_before_any_restore_tool_runs(): void
    {
        $record = $this->saved();
        Process::fake()->preventStrayProcesses();
        File::put(app(DatabaseBackups::class)->path($record['id'].'.dump'), 'modified');
        Livewire::test(Backups::class)->call('verify', $record['id'])->assertHasErrors('backup');
        $this->assertSame('Failed', app(DatabaseBackups::class)->history()[0]['verification']);
        Process::assertNothingRan();
    }

    public function test_invalid_backup_identifier_cannot_reach_outside_private_storage(): void
    {
        Process::fake()->preventStrayProcesses();
        Livewire::test(Backups::class)->call('verify', '../.env')->assertHasErrors('backup');
        $this->assertSame([], app(DatabaseBackups::class)->history());
        Process::assertNothingRan();
    }

    public function test_guests_cannot_use_settings_or_livewire_backup_actions(): void
    {
        Auth::forgetGuards();
        $this->get('/settings')->assertRedirect('/login');
        $this->postJson(route('default-livewire.update'), ['components' => []])->assertUnauthorized();
        $this->assertSame([], app(DatabaseBackups::class)->history());
    }

    public function test_backup_archives_have_no_public_filesystem_route(): void
    {
        $record = $this->saved();
        $this->get('/storage/backups/'.$record['id'].'.dump')->assertForbidden();
        $this->assertFalse(config('filesystems.disks.backups.serve'));
        $url = Storage::disk('local')->temporaryUrl('backups/'.$record['id'].'.dump', now()->addMinute());
        $this->get($url)->assertNotFound();
    }

    public function test_real_backup_restores_exact_historical_records_in_a_separate_server(): void
    {
        $backups = $this->database();
        $record = $backups->create();
        $this->assertSame('Succeeded', $record['status']);
        $this->assertGreaterThan(5, $record['bytes']);
        $this->assertSame('PGDMP', substr(File::get($backups->path($record['id'].'.dump')), 0, 5));
        $this->assertSame(hash_file('sha256', $backups->path($record['id'].'.dump')), $record['checksum']);
        $this->assertSame(1, $record['tables']['sales']['rows']);
        $this->assertSame(1, $record['tables']['sale_returns']['rows']);
        $this->assertSame(2, $record['tables']['customer_debts']['rows']);
        SessionCredit::addCustomer('Added after the backup', '');
        $before = $backups->fingerprints(PosStore::connection(), $this->schema);
        $verified = $backups->verify($record['id']);
        $this->assertSame('Passed', $verified['verification']);
        $this->assertSame(count($record['tables']), $verified['verifiedTables']);
        $this->assertSame(array_sum(array_column($record['tables'], 'rows')), $verified['verifiedRows']);
        $this->assertSame($before, $backups->fingerprints(PosStore::connection(), $this->schema));
        $this->assertSame([], File::directories($backups->path('recovery-checks')));
        $this->assertSame([], File::glob($backups->path('*.partial')));
    }

    public function test_failed_dump_keeps_last_successful_archive_and_redacts_tool_errors(): void
    {
        $backups = $this->database();
        $old = $this->saved('Succeeded', 20);
        Process::fake(fn () => Process::result(errorOutput: 'SecretPassword=must-not-appear', exitCode: 1));
        Livewire::test(Backups::class)->call('backupNow')->assertHasErrors('backup');
        $this->assertFileExists($backups->path($old['id'].'.dump'));
        $failed = collect($backups->history())->firstWhere('status', 'Failed');
        $this->assertNotNull($failed);
        $this->assertStringNotContainsString('must-not-appear', json_encode($failed));
        $this->assertFileDoesNotExist($backups->path($failed['id'].'.dump'));
        $this->assertSame([], File::glob($backups->path('*.partial')));
    }

    public function test_successful_backup_prunes_only_expired_registered_archives(): void
    {
        $backups = $this->database();
        $old = $this->saved('Succeeded', 20);
        $recent = $this->saved('Succeeded', 5);
        $failed = $this->saved('Failed', 20);
        File::put($backups->path('unregistered.dump'), 'keep');
        $new = $backups->create();
        $this->assertFileDoesNotExist($backups->path($old['id'].'.dump'));
        $this->assertFileExists($backups->path($recent['id'].'.dump'));
        $this->assertFileExists($backups->path($new['id'].'.dump'));
        $this->assertFileExists($backups->path($failed['id'].'.dump'));
        $this->assertFileExists($backups->path('unregistered.dump'));
        $this->assertSame('Pruned', collect($backups->history())->firstWhere('id', $old['id'])['status']);
    }

    public function test_automatic_backup_runs_once_when_due_and_manual_backup_can_run_immediately(): void
    {
        $backups = $this->database();
        $automatic = $backups->create(true);
        $this->assertSame('Automatic', $automatic['source']);
        $this->assertNull($backups->create(true));
        $manual = $backups->create();
        $this->assertSame('Manual', $manual['source']);
        $this->assertCount(2, $backups->history());
    }

    public function test_browser_transaction_can_create_and_verify_a_real_backup(): void
    {
        $backups = $this->database();
        $server = $_SERVER;
        $environment = $_ENV;
        try {
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'PATH' => getenv('PATH')];
            $_ENV = [];
            $record = PosStore::atomic(fn (): array => $backups->create());
            $this->assertSame('Succeeded', $record['status']);
            $this->assertSame('Passed', PosStore::atomic(fn (): array => $backups->verify($record['id']))['verification']);
        } finally {
            $_SERVER = $server;
            $_ENV = $environment;
        }
    }

    public function test_recovery_detects_record_mismatch_and_cleans_up_temporary_server(): void
    {
        $backups = $this->database();
        $record = $backups->create();
        $record['tables']['products']['rows']++;
        File::put($backups->path('records/'.$record['id'].'.json'), json_encode($record));
        $before = $backups->fingerprints(PosStore::connection(), $this->schema);
        try {
            $backups->verify($record['id']);
            $this->fail('Mismatched restored data must fail verification.');
        } catch (RuntimeException) {
            $this->assertSame('Failed', $backups->history()[0]['verification']);
        }
        $this->assertSame($before, $backups->fingerprints(PosStore::connection(), $this->schema));
        $this->assertSame([], File::directories($backups->path('recovery-checks')));
    }

    public function test_cli_commands_use_same_backup_and_recovery_history(): void
    {
        $backups = $this->database();
        $this->artisan('pos:backup')->assertSuccessful()->run();
        $record = $backups->history()[0];
        $this->artisan('pos:verify-backup', ['id' => $record['id']])->assertSuccessful()->run();
        $this->assertSame('Passed', $backups->history()[0]['verification']);
        $backups->saveSettings(false, 24, 14);
        $this->artisan('pos:backup', ['--scheduled' => true])->assertSuccessful()->run();
        $this->assertCount(1, $backups->history());
    }
}
