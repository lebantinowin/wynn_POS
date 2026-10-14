<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Support\CheckoutCarts;
use App\Support\DemoCatalogue;
use App\Support\OfflineCheckout;
use App\Support\PosStore;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionSales;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CheckoutCartsTest extends TestCase
{
    private function database(): void
    {
        Storage::fake('local');
        $file = Storage::disk('local')->path('held-carts.sqlite');
        touch($file);
        config(['database.connections.sqlite.database' => $file, 'pos.connection' => 'sqlite', 'pos.storage' => 'database']);
        DB::purge('sqlite');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'sqlite', '--path' => [
            'database/migrations/2026_10_12_003040_create_pos_tables.php',
            'database/migrations/2026_10_12_024957_create_offline_checkout_tables.php',
            'database/migrations/2026_10_13_062247_create_checkout_carts_table.php',
            'database/migrations/2026_10_14_060702_add_recovery_release_to_offline_checkouts_table.php',
        ], '--force' => true]));
        PosStore::import(['products' => DemoCatalogue::defaults()], 'held-cart-tests');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_hold_and_resume_preserve_items_without_creating_stock_or_payment_movements(): void
    {
        $before = [DemoCatalogue::products(), SessionSales::all(), SessionCash::all(), SessionBatches::state()];
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-pack')->call('add', 'drink-one')
            ->set('method', 'GCash')->set(['received' => '130', 'confirmed' => true])->set('holdLabel', 'Customer A');
        $id = $checkout->get('saleEventId');
        $checkout->call('holdCart')->assertHasNoErrors()->assertSet('cart', [])->assertSet('received', '')
            ->assertSet('confirmed', false)->assertSee('Customer A')->assertSee('130.00');
        $this->assertCount(1, CheckoutCarts::held());
        $this->assertNotSame($id, $checkout->get('saleEventId'));
        $checkout->call('resumeCart', $id)->assertHasNoErrors()->assertSet('cart.drink-pack', 1)->assertSet('cart.drink-one', 1)
            ->assertSet('method', 'Cash')->assertSet('received', '')->assertSet('confirmed', false)->assertSet('receipt', null);
        $this->assertEmpty(CheckoutCarts::held());
        $this->assertSame($before, [DemoCatalogue::products(), SessionSales::all(), SessionCash::all(), SessionBatches::state()]);
    }

    public function test_resume_refuses_to_overwrite_current_cart_and_repeated_resume_cannot_duplicate_it(): void
    {
        $id = (string) Str::uuid();
        CheckoutCarts::hold($id, ['drink-pack' => 1], 'Waiting');
        $checkout = Livewire::test(Checkout::class)->call('add', 'noodle-one')->call('resumeCart', $id)
            ->assertHasErrors('heldCart')->assertSet('cart.noodle-one', 1);
        $this->assertCount(1, CheckoutCarts::held());
        $checkout->call('remove', 'noodle-one')->call('resumeCart', $id)->assertHasNoErrors()->assertSet('cart.drink-pack', 1)
            ->call('remove', 'drink-pack')->call('resumeCart', $id)->assertHasErrors('heldCart')->assertSet('cart', []);
    }

    public function test_old_checkout_cannot_sell_a_cart_that_was_held_even_after_resuming_or_discarding(): void
    {
        SessionCash::open('open', '100', '');
        $id = (string) Str::uuid();
        CheckoutCarts::hold($id, ['drink-one' => 1], 'Saved');
        $this->assertSame($id, CheckoutCarts::hold($id, ['drink-one' => 1], 'Saved')['id']);
        $this->assertCount(1, CheckoutCarts::held());
        foreach (['Held', 'Resumed'] as $status) {
            if ($status === 'Resumed') {
                CheckoutCarts::resume($id);
            }
            try {
                SessionSales::complete($id, ['drink-one' => 1], 'Cash', '20', false);
                $this->fail('A stale cart must not become a sale.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('heldCart', $exception->errors());
            }
        }
        $other = (string) Str::uuid();
        CheckoutCarts::hold($other, ['drink-one' => 2], 'Other');
        Livewire::test(Checkout::class)->call('discardHeldCart', $other)->assertHasNoErrors();
        $this->assertEmpty(CheckoutCarts::held());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionSales::all());
        $this->assertEmpty(SessionCash::movements());
        Livewire::test(Checkout::class)->call('resumeCart', $other)->assertHasErrors('heldCart');
    }

    public function test_resume_uses_current_prices_warns_about_stock_and_does_not_assume_prior_payment(): void
    {
        SessionCash::open('open', '100', '');
        $id = (string) Str::uuid();
        CheckoutCarts::hold($id, ['drink-pack' => 13], 'Large order');
        $product = DemoCatalogue::find(1);
        $product['options'][1]['price'] = 12000;
        DemoCatalogue::save($product);
        $checkout = Livewire::test(Checkout::class)->call('resumeCart', $id)->assertHasNoErrors()
            ->assertSee('Prices or package sizes changed')->assertSee('Some items exceed current sellable stock')
            ->assertSet('received', '')->set('received', '1560')->call('complete')->assertHasErrors('cart');
        $this->assertSame(12000, $checkout->instance()->lines()[0]['price']);
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionSales::all());
        $checkout->call('setQuantity', 'drink-pack', 1)->set('received', '120')->call('complete')->assertHasNoErrors();
        $this->assertSame(12000, $checkout->get('receipt')['total']);
        $this->assertSame(66, DemoCatalogue::find(1)['stock']);
    }

    public function test_unavailable_products_remain_on_hold_and_invalid_carts_are_rejected(): void
    {
        Livewire::test(Checkout::class)->call('holdCart')->assertHasErrors('cart');
        Livewire::test(Checkout::class)->set('cart', ['unknown' => 1])->call('holdCart')->assertHasErrors('cart');
        Livewire::test(Checkout::class)->set('cart', ['drink-one' => 0])->call('holdCart')->assertHasErrors('cart.drink-one');
        $id = (string) Str::uuid();
        CheckoutCarts::hold($id, ['drink-one' => 1], 'Original');
        $product = DemoCatalogue::find(1);
        $product['options'] = array_slice($product['options'], 1);
        DemoCatalogue::save($product);
        Livewire::test(Checkout::class)->call('resumeCart', $id)->assertHasErrors('heldCart')->assertSet('cart', []);
        $this->assertCount(1, CheckoutCarts::held());
        $this->assertSame(2000, CheckoutCarts::held()[$id]['total']);
    }

    public function test_limit_and_owner_authorization_do_not_lose_existing_carts(): void
    {
        for ($index = 0; $index < 20; $index++) {
            CheckoutCarts::hold((string) Str::uuid(), ['drink-one' => 1], 'Cart '.$index);
        }
        Livewire::test(Checkout::class)->call('add', 'drink-one')->call('holdCart')->assertHasErrors('heldCart')->assertSet('cart.drink-one', 1);
        $this->assertCount(20, CheckoutCarts::held());
        Auth::user()->setRememberToken(null);
        Auth::logout();
        $this->get('/checkout')->assertRedirect('/login');
        $this->expectException(HttpException::class);
        CheckoutCarts::discard(array_key_first(CheckoutCarts::held()));
    }

    public function test_database_cart_survives_session_flush_and_connection_reload(): void
    {
        $this->database();
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-pack')->set('holdLabel', '<script>Reminder</script>');
        $id = $checkout->get('saleEventId');
        $checkout->call('holdCart')->assertHasNoErrors()->assertDontSeeHtml('<script>Reminder</script>');
        session()->flush();
        DB::purge('sqlite');
        $this->assertSame(['drink-pack' => 1], CheckoutCarts::held()[$id]['cart']);
        Livewire::test(Checkout::class)->call('resumeCart', 'invalid-id')->assertHasErrors('heldCart');
        Livewire::test(Checkout::class)->call('resumeCart', $id)->assertHasNoErrors()->assertSet('cart.drink-pack', 1);
        $this->assertSame('Resumed', PosStore::connection()->table('checkout_carts')->where('event_id', $id)->value('status'));
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
    }

    public function test_offline_counter_ownership_blocks_held_cart_mutations(): void
    {
        $this->database();
        $id = (string) Str::uuid();
        CheckoutCarts::hold($id, ['drink-one' => 1], 'Saved');
        SessionCash::open('open', '100', '');
        app(OfflineCheckout::class)->prepare((string) Str::uuid(), (string) Str::uuid());
        Livewire::test(Checkout::class)->call('resumeCart', $id)->assertHasErrors('offline')->assertSet('cart', [])
            ->call('discardHeldCart', $id)->assertHasErrors('offline')->call('add', 'drink-one')->call('holdCart')->assertHasErrors('offline');
        $this->assertCount(1, CheckoutCarts::held());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
    }
}
