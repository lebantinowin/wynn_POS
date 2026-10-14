<?php

namespace Tests\Feature;

use App\Livewire\Sales;
use App\Livewire\ShopSettings;
use App\Support\DemoCatalogue;
use App\Support\OfflineCheckout;
use App\Support\PosStore;
use App\Support\SessionCash;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use App\Support\ShopPreferences;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShopSettingsTest extends TestCase
{
    private const MIGRATIONS = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/2026_10_12_003040_create_pos_tables.php',
        'database/migrations/2026_10_12_024957_create_offline_checkout_tables.php',
        'database/migrations/2026_10_14_060702_add_recovery_release_to_offline_checkouts_table.php',
        'database/migrations/2026_10_13_011002_create_shop_settings_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put('settings-test.sqlite', '');
        config(['database.connections.sqlite.database' => Storage::disk('local')->path('settings-test.sqlite'), 'pos.connection' => 'sqlite', 'pos.storage' => 'database']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--path' => self::MIGRATIONS, '--force' => true]);
        PosStore::import(['products' => DemoCatalogue::defaults()], 'settings-test');
    }

    public function test_defaults_use_pesos_without_treating_unknown_tax_as_zero_or_activating_invoices(): void
    {
        $this->get('/settings')->assertOk()->assertSee('BIR invoice preparation')->assertSee('Invoice issuance is pending.');
        $this->assertSame('Unconfirmed', app(ShopPreferences::class)->all()['taxStatus']);
        $this->assertSame('BIR', app(ShopPreferences::class)->all()['invoiceRequirement']);
        $this->assertSame('₱1,234.56', ShopPreferences::amount(123456));
        $this->assertSame('₱-0.01', ShopPreferences::amount(-1));
        $this->assertSame('₱0.00', ShopPreferences::amount(0));
        $this->assertStringContainsString('Tax calculation is not configured', ShopPreferences::notice());
        $this->assertEmpty(SessionSales::all());
    }

    public function test_settings_persist_after_reconnect_without_touching_stock_cash_or_sales(): void
    {
        $before = DemoCatalogue::products();
        Livewire::test(ShopSettings::class)->set('settings.shopName', '  QA Mart  ')
            ->set('settings.address', "Test address\nTest town")->set('settings.contact', 'QA contact')
            ->set('settings.footer', 'Thank you')->set('settings.registeredName', 'Test proprietor')
            ->set('settings.tin', '123-456-789')->set('settings.branchCode', '00000')
            ->set('settings.rdo', 'Test RDO')->set('settings.permitReference', 'TEST ONLY')
            ->call('save')->assertHasNoErrors()->assertSee('Shop details saved.');
        DB::purge('sqlite');
        app()->forgetScopedInstances();
        $settings = app(ShopPreferences::class)->all();
        $this->assertSame('QA Mart', $settings['shopName']);
        $this->assertSame('00000', $settings['branchCode']);
        $this->assertSame('123-456-789', $settings['tin']);
        $this->assertSame($before, DemoCatalogue::products());
        $this->assertEmpty(SessionSales::all());
        $this->assertEmpty(SessionCash::movements());
        $this->assertSame(1, PosStore::connection()->table('shop_settings')->count());
    }

    public function test_old_receipt_and_return_keep_saved_header_after_shop_details_change(): void
    {
        $preferences = app(ShopPreferences::class);
        $preferences->save(array_replace($preferences->all(), ['shopName' => 'Original shop', 'address' => 'Original address',
            'contact' => 'Original contact', 'footer' => 'Original thanks']));
        SessionCash::open('settings-drawer', '100', '');
        $sale = SessionSales::complete('settings-sale', ['drink-pack' => 1], 'Cash', '200', false);
        $preferences->save(array_replace($preferences->all(), ['shopName' => 'Renamed shop', 'address' => 'New address', 'footer' => 'New thanks']));
        Livewire::test(Sales::class)->call('viewReceipt', $sale['id'])
            ->assertSee('Original shop')->assertSee('Original address')->assertSee('Original thanks')
            ->assertDontSee('Renamed shop')->assertSee('₱110.00')->assertSee('COPY');
        $this->assertEquals($sale, SessionSales::all()[$sale['id']]);
        $return = SessionReturns::complete('settings-return', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Unopened package');
        $this->assertSame($sale['receiptProfile'], $return['receiptProfile']);
        $this->assertSame('Original shop', $return['shop']);
        Livewire::test(Sales::class)->call('viewReturn', $return['id'])->assertSee('Original address')->assertSee('Original thanks')->assertSee('₱110.00');
        $this->assertEquals($sale, SessionSales::all()[$sale['id']]);
    }

    public function test_invalid_currency_registration_and_tax_values_cannot_save(): void
    {
        $component = Livewire::test(ShopSettings::class);
        foreach (['currency' => 'USD', 'taxStatus' => 'Zero tax', 'invoiceRequirement' => 'Certified',
            'tin' => 'invalid', 'branchCode' => 'ABC', 'shopName' => ''] as $field => $value) {
            $component->set('settings', ShopPreferences::defaults())->set('settings.'.$field, $value)
                ->call('save')->assertHasErrors('settings.'.$field);
        }
        $this->assertSame(0, PosStore::connection()->table('shop_settings')->count());
    }

    public function test_recording_registration_status_and_permit_does_not_enable_tax_or_invoice_issuance(): void
    {
        $preferences = app(ShopPreferences::class);
        $preferences->save(array_replace($preferences->all(), ['taxStatus' => 'VAT', 'permitReference' => 'TEST ONLY']));
        SessionCash::open('settings-drawer', '100', '');
        $sale = SessionSales::complete('no-tax-invention', ['drink-one' => 1], 'Cash', '20', false);
        $this->assertSame(2000, $sale['total']);
        $this->assertArrayNotHasKey('vat', $sale);
        Livewire::test(Sales::class)->call('viewReceipt', $sale['id'])->assertSee('NOT A FISCAL RECEIPT')->assertSee('Tax calculation is not configured');
    }

    public function test_prepared_offline_session_blocks_settings_and_keeps_registration_identifiers_out_of_cache(): void
    {
        $preferences = app(ShopPreferences::class);
        $preferences->save(array_replace($preferences->all(), ['shopName' => 'Offline shop', 'footer' => 'Saved thanks', 'tin' => '123456789', 'permitReference' => 'TEST ONLY']));
        SessionCash::open('offline-settings-drawer', '100', '');
        $snapshot = app(OfflineCheckout::class)->prepare((string) Str::uuid(), (string) Str::uuid());
        $this->assertSame('Offline shop', $snapshot['shop']);
        $this->assertSame('Saved thanks', $snapshot['receiptProfile']['footer']);
        $this->assertArrayNotHasKey('tin', $snapshot['receiptProfile']);
        $this->assertArrayNotHasKey('permitReference', $snapshot['receiptProfile']);
        Livewire::test(ShopSettings::class)->set('settings.shopName', 'Changed offline')->call('save')->assertHasErrors('offline');
        app()->forgetScopedInstances();
        $this->assertSame('Offline shop', app(ShopPreferences::class)->all()['shopName']);
        $this->assertEmpty(SessionSales::all());
    }

    public function test_saved_header_is_escaped_in_receipts(): void
    {
        $preferences = app(ShopPreferences::class);
        $preferences->save(array_replace($preferences->all(), ['footer' => '<script>alert(1)</script>']));
        SessionCash::open('settings-drawer', '100', '');
        $sale = SessionSales::complete('escaped-header', ['drink-one' => 1], 'Cash', '20', false);
        Livewire::test(Sales::class)->call('viewReceipt', $sale['id'])
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_guest_cannot_access_or_change_shop_preferences(): void
    {
        Auth::logout();
        $this->get('/settings')->assertRedirect('/login');
        $this->expectException(HttpException::class);
        app(ShopPreferences::class)->save(ShopPreferences::defaults());
    }
}
