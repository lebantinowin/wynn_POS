<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DatabaseBackups;
use App\Support\DemoCatalogue;
use App\Support\PracticeShop;
use App\Support\SessionAnalytics;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionLosses;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PracticeShopTest extends TestCase
{
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        Storage::fake('local');
        $root = Storage::disk('local')->path('practice-test-storage-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($root.'/app/practice');
        $this->app->useStoragePath($root);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $root.'/app/practice/shop.sqlite',
            'database.connections.sqlite.url' => null, 'pos.connection' => 'sqlite', 'pos.storage' => 'database',
            'pos.practice' => true, 'session.cookie' => 'wynn_practice_session']);
        DB::purge('sqlite');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        $this->app->useStoragePath($this->originalStorage);
        parent::tearDown();
    }

    private function prepare(): void
    {
        $status = Artisan::call('practice:prepare', ['--no-interaction' => true]);
        $this->assertSame(0, $status, Artisan::output());
    }

    public function test_practice_preparation_has_known_stock_costs_and_does_not_create_default_credentials(): void
    {
        $this->prepare();
        PracticeShop::assertPrepared();
        $this->assertSame(12, DemoCatalogue::find(1)['stock']);
        $this->assertSame('PRAC003', DemoCatalogue::find(1)['options'][1]['barcode']);
        $this->assertSame(4800, SessionBatches::overview()['totals']['knownCost']);
        $this->assertSame(0, SessionBatches::overview()['totals']['unknownCostUnits']);
        $this->assertCount(0, SessionCash::all());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Artisan::call('practice:prepare', ['--check-owner' => true]));
        $this->assertStringContainsString('needs-setup', Artisan::output());
        $this->get('/dashboard')->assertOk()->assertSee('Practice shop')->assertSee('Owner practice walkthrough')->assertSee('₱1,023');
        foreach (['checkout', 'products', 'inventory', 'purchasing', 'sales', 'credit', 'losses', 'cash', 'reports', 'settings'] as $page) {
            $this->get('/'.$page)->assertOk()->assertSee('Practice shop');
        }
    }

    public function test_relaunch_preserves_sales_stock_login_and_settings(): void
    {
        $this->prepare();
        $owner = User::factory()->create(['id' => User::OWNER_ID, 'email' => 'practice@example.test']);
        SessionCash::open('opening', '1000', '');
        SessionSales::complete('sale', ['practice-one' => 1], 'Cash', '10', false);
        $product = DemoCatalogue::find(1);
        unset($product['openingQuantity']);
        DemoCatalogue::save($product);
        $settings = DB::table('shop_settings')->value('snapshot');
        $hash = $owner->getAuthPassword();
        $this->prepare();
        $this->assertSame(11, DemoCatalogue::find(1)['stock']);
        $this->assertSame(12, DemoCatalogue::find(1)['openingQuantity']);
        $this->get('/products')->assertOk()->assertSee('12 base units originally recorded');
        $this->assertCount(1, SessionSales::all());
        $this->assertSame($hash, User::query()->find(User::OWNER_ID)->getAuthPassword());
        $this->assertSame($settings, DB::table('shop_settings')->value('snapshot'));
        $this->assertSame(0, Artisan::call('practice:prepare', ['--check-owner' => true]));
        $this->assertStringContainsString('configured', Artisan::output());
    }

    public function test_practice_refuses_postgresql_urls_outside_files_and_mismatched_login_database(): void
    {
        config(['pos.connection' => 'pgsql']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->assertSame(1, Artisan::call('practice:prepare'));
        $this->assertStringContainsString('refuses PostgreSQL', Artisan::output());
        $this->assertEmpty($queries);
        $this->get('/login')->assertStatus(503);
        config(['pos.connection' => 'sqlite', 'database.connections.sqlite.url' => 'sqlite:///outside.sqlite']);
        $this->assertSame(1, Artisan::call('practice:prepare'));
        config(['database.connections.sqlite.url' => null, 'database.connections.sqlite.database' => $this->originalStorage.'/app/outside.sqlite']);
        $this->assertSame(1, Artisan::call('practice:prepare'));
        $this->assertStringContainsString('inside storage/app/practice', Artisan::output());
        $this->assertFileDoesNotExist($this->originalStorage.'/app/outside.sqlite');
        $inside = storage_path('app/practice/shop.sqlite');
        config(['database.connections.sqlite.database' => $inside]);
        $this->prepare();
        config(['database.connections.practice_login' => array_replace(config('database.connections.sqlite'), ['database' => storage_path('app/practice/login.sqlite')]), 'database.default' => 'practice_login']);
        $this->assertSame(1, Artisan::call('practice:prepare'));
        $this->assertStringContainsString('same isolated database', Artisan::output());
    }

    public function test_practice_rejects_wrong_cookie_and_an_existing_unrelated_database(): void
    {
        config(['session.cookie' => 'real_shop_cookie']);
        $this->assertSame(1, Artisan::call('practice:prepare'));
        $this->assertStringContainsString('own session cookie', Artisan::output());
        config(['session.cookie' => 'wynn_practice_session']);
        $path = config('database.connections.sqlite.database');
        File::put($path, '');
        $this->get('/login')->assertStatus(503);
        DB::connection('sqlite')->statement('CREATE TABLE unrelated (value TEXT)');
        DB::connection('sqlite')->table('unrelated')->insert(['value' => 'keep']);
        $this->assertSame(1, Artisan::call('practice:prepare'));
        $this->assertSame('keep', DB::table('unrelated')->value('value'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('users'));
    }

    public function test_practice_does_not_relabel_another_initialized_shop_or_change_backup_preferences(): void
    {
        $this->prepare();
        DB::table('pos_workspace')->where('id', 1)->update(['import_source' => hash('sha256', 'another-shop')]);
        $before = DemoCatalogue::products();
        $this->assertSame(1, Artisan::call('practice:prepare'));
        $this->get('/dashboard')->assertStatus(503);
        $this->assertSame($before, DemoCatalogue::products());
        Storage::disk('backups')->put('settings.json', '{"enabled":true}');
        try {
            app(DatabaseBackups::class)->saveSettings(false, 1, 7);
            $this->fail('Practice must not change main backup settings.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unavailable', $exception->getMessage());
        }
        $this->assertSame('{"enabled":true}', Storage::disk('backups')->get('settings.json'));
    }

    public function test_practice_login_settings_and_exports_identify_the_separate_shop(): void
    {
        $this->prepare();
        $this->get('/settings')->assertOk()->assertSee('run-practice.ps1')->assertSee('-ResetOwner')
            ->assertSee('Main shop backup controls are unavailable')->assertDontSee('Back up now');
        $csv = $this->get('/reports/export/summary')->assertOk()->streamedContent();
        $this->assertStringContainsString('Practice shop: sample records only', $csv);
        $this->get('/reports/print/summary')->assertOk()->assertSee('Practice shop: sample records only');
        User::factory()->create(['id' => User::OWNER_ID]);
        auth()->user()->setRememberToken(null);
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('PRACTICE OWNER ACCESS')
            ->assertSee('run-practice.ps1')->assertSee('-ResetOwner')->assertDontSee('setup-owner.ps1');
    }

    public function test_normal_shop_has_no_practice_instructions_and_has_a_working_absolute_reset_path(): void
    {
        config(['pos.practice' => false, 'pos.storage' => 'session']);
        $this->get('/dashboard')->assertOk()->assertDontSee('Owner practice walkthrough');
        $this->get('/settings')->assertOk()->assertSee(base_path('setup-owner.ps1'))->assertDontSee('run-practice.ps1');
    }

    public function test_the_exact_walkthrough_reconciles_cash_stock_profit_and_both_debt_types(): void
    {
        $this->prepare();
        if (getenv('PRACTICE_BROWSER_QA')) {
            User::factory()->create(['id' => User::OWNER_ID, 'name' => 'Practice QA Owner', 'email' => 'practice-qa@example.test', 'password' => 'Practice-QA-Only42!']);
            $artifactRoot = $this->originalStorage.'/app/practice';
            File::ensureDirectoryExists($artifactRoot);
            $artifact = $artifactRoot.'/ui-qa-'.bin2hex(random_bytes(8)).'.sqlite';
            File::copy(config('database.connections.sqlite.database'), $artifact);
            fwrite(STDOUT, "\nPractice browser fixture: ".$artifact."\n");
        }
        SessionCash::open('opening', '1000', '');
        $sale = SessionSales::complete('sale', ['practice-pack' => 2, 'practice-one' => 1], 'Cash', '100', false);
        $this->assertSame(3400, $sale['change']);
        $this->assertSame(5, DemoCatalogue::find(1)['stock']);
        SessionReturns::complete('return', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock'], ['quantity' => 0, 'disposition' => 'Restock']], 'Cash', true, 'Practice unopened pack return');
        $this->assertSame(8, DemoCatalogue::find(1)['stock']);
        SessionLosses::record('loss', 'practice-one', 1, 'Unpaid consumption', 'Practice snack consumed', '');
        SessionCash::expense('expense', '5', SessionCash::CATEGORIES[0], 'Cash', 'Practice expense');
        $this->assertSame(103300, SessionCash::summary(SessionCash::active()['id'])['expected']);
        SessionCredit::addCustomer('Practice customer', '');
        $customer = array_key_first(SessionCredit::customers());
        SessionSales::complete('credit', ['practice-one' => 1], 'Credit', '', false, $customer);
        $groceryDebt = array_key_first(SessionCredit::debts());
        SessionCredit::cashLoan('loan', $customer, '30', 'Cash', '', '');
        $loanDebt = array_key_last(SessionCredit::debts());
        SessionCredit::repay('grocery-repaid', $groceryDebt, '10', 'Cash', '');
        SessionCredit::repay('loan-repaid', $loanDebt, '10', 'Cash', '');
        $this->assertSame(0, SessionCredit::debts()[$groceryDebt]['balance']);
        $this->assertSame(2000, SessionCredit::debts()[$loanDebt]['balance']);
        $closed = SessionCash::close(SessionCash::active()['id'], 'close', '1023', '');
        $this->assertSame(102300, $closed['summary']['expected']);
        $this->assertSame(0, $closed['difference']);
        $this->assertSame(6, DemoCatalogue::find(1)['stock']);
        $summary = SessionAnalytics::summarize(7);
        foreach (['revenue' => 4800, 'collections' => 5800, 'knownSalesCost' => 2000, 'grossProfit' => 2800, 'operatingProfit' => 1900, 'outstanding' => 2000] as $key => $expected) {
            $this->assertSame($expected, $summary[$key], $key);
        }
        $this->assertSame(2400, $summary['expiryStock']['knownCost']);
        $this->assertSame(0, $summary['expiryStock']['unknownCostUnits']);
        if (getenv('DAILY_ACTIVITY_BROWSER_QA')) {
            User::factory()->create(['id' => User::OWNER_ID, 'name' => 'Calendar QA Owner', 'email' => 'practice-qa@example.test', 'password' => 'Practice-QA-Only42!']);
            $artifactRoot = $this->originalStorage.'/app/practice';
            File::ensureDirectoryExists($artifactRoot);
            $artifact = $artifactRoot.'/calendar-ui-qa-'.bin2hex(random_bytes(8)).'.sqlite';
            File::copy(config('database.connections.sqlite.database'), $artifact);
            fwrite(STDOUT, "\nCalendar browser fixture: ".$artifact."\n");
        }
    }
}
