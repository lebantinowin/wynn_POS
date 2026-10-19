<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Support\DemoCatalogue;
use App\Support\SessionCash;
use App\Support\SessionSales;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    public function test_opening_a_session_in_checkout_keeps_cart_and_enables_digital_sale(): void
    {
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-one')->set('method', 'GCash')
            ->set(['received' => '20.00', 'confirmed' => true])
            ->assertSee('Open a cash session to complete this sale.')
            ->assertSee('Open cash session');
        $this->assertEmpty(SessionCash::all());
        $checkout->set('opening', '1000')->call('openCashSession')->assertHasNoErrors()
            ->assertSet('cart.drink-one', 1)->assertSet('method', 'GCash')->assertSet('received', '20.00')
            ->assertSet('confirmed', true)->assertDontSee('Open cash session');
        $this->assertSame(SessionCash::active()['id'], $checkout->get('cashSessionId'));
        $this->assertSame(100000, SessionCash::active()['opening']);
        $this->assertEmpty(SessionSales::all());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $checkout->call('complete')->assertHasNoErrors()->assertSet('cart', [])->assertSet('receipt.paymentReceived', 2000);
        $this->assertCount(1, SessionSales::all());
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
    }

    public function test_invalid_opening_amount_does_not_create_a_session_or_clear_cart(): void
    {
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-one');
        foreach (['', '-1', '0.001', '1e3'] as $amount) {
            $checkout->set('opening', $amount)->call('openCashSession')->assertHasErrors('opening')->assertSet('cart.drink-one', 1);
            $this->assertEmpty(SessionCash::all());
            $this->assertEmpty(SessionSales::all());
        }
        $checkout->set('opening', '0')->call('openCashSession')->assertHasNoErrors();
        $this->assertSame(0, SessionCash::active()['opening']);
        $checkout->set('opening', '20')->call('openCashSession')->assertHasErrors('cashSession');
        $this->assertCount(1, SessionCash::all());
    }

    public function test_empty_cart_still_requires_an_item_and_closed_session_cannot_complete(): void
    {
        $checkout = Livewire::test(Checkout::class)->set('opening', '0')->call('openCashSession')->assertHasNoErrors()
            ->assertSee('Add a product to start.');
        $cashSessionId = $checkout->get('cashSessionId');
        $checkout->call('add', 'drink-one')->set('received', '20');
        SessionCash::close($cashSessionId, 'close-from-other-screen', '0', '');
        $checkout->call('complete')->assertHasErrors('cashSession');
        $this->assertEmpty(SessionSales::all());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $checkout->assertSet('cart.drink-one', 1)->assertSee('Open a cash session to complete this sale.');
    }

    public function test_shell_and_catalogue_render_without_database(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('Sample workspace');
        $this->get('/products')->assertOk()->assertSee('Pack of 6');
        $this->get('/checkout')->assertOk()->assertSee('Receipt Overview');
        $this->get('/unknown')->assertNotFound();
    }

    public function test_scan_package_and_individual_quantities_match_receipt(): void
    {
        $component = Livewire::test(Checkout::class)
            ->set('search', 'DEMO-006')->call('scan')
            ->call('add', 'drink-pack')->call('add', 'drink-one')
            ->set('received', '300')->call('preview')->assertHasNoErrors();
        $receipt = $component->get('receipt');
        $this->assertSame(24000, $receipt['total']);
        $this->assertSame(6000, $receipt['change']);
        $this->assertSame(13, array_sum(array_column($receipt['lines'], 'baseQuantity')));
    }

    public function test_preview_requires_cash_coverage_and_qr_confirmation(): void
    {
        Livewire::test(Checkout::class)->call('add', 'drink-one')
            ->set('received', '1')->call('preview')->assertHasErrors('received')
            ->set('method', 'GCash')->call('preview')->assertHasErrors('confirmed')
            ->set('received', '20')->set('confirmed', true)->call('preview')->assertHasNoErrors()
            ->assertSet('receipt.method', 'GCash');
    }

    public function test_changing_amount_or_method_requires_payment_confirmation_again(): void
    {
        Livewire::test(Checkout::class)->call('add', 'drink-one')->set('received', '100')
            ->set('method', 'GCash')->assertSet('received', '')->assertSee('GCash amount received (PHP)')
            ->set('received', '20')->set('confirmed', true)->call('preview')->assertHasNoErrors()
            ->assertSet('receipt.paymentReceived', 2000)->assertSee('GCash amount received')
            ->set('received', '20.00')->assertSet('confirmed', false)->assertSet('receipt', null)
            ->call('preview')->assertHasErrors('confirmed')
            ->set('method', 'Maya')->assertSet('received', '')->assertSee('Maya amount received (PHP)');
    }

    public function test_entering_digital_amount_and_confirming_in_one_request_previews_the_receipt(): void
    {
        Livewire::test(Checkout::class)->call('add', 'drink-one')->set('method', 'GCash')
            ->set(['received' => '20.00', 'confirmed' => true])->call('preview')->assertHasNoErrors()
            ->assertSet('receipt.paymentReceived', 2000)->assertSet('receipt.paymentConfirmed', true);
    }

    public function test_invalid_quantity_is_rejected_and_repeated_scan_increments(): void
    {
        Livewire::test(Checkout::class)->call('add', 'drink-one')
            ->call('setQuantity', 'drink-one', 0)->assertHasErrors('cart')
            ->assertSet('cart.drink-one', 1)
            ->call('setQuantity', 'drink-one', '1.5')->assertHasErrors('cart')
            ->call('add', 'drink-one')->assertSet('cart.drink-one', 2);
    }

    public function test_live_receipt_updates_without_payment_or_writing_a_sale(): void
    {
        $before = [DemoCatalogue::products(), SessionSales::all(), SessionCash::movements()];
        $checkout = Livewire::test(Checkout::class)->assertViewHas('liveReceipt', null)
            ->call('add', 'drink-one')->assertHasNoErrors()->assertSet('receipt', null)
            ->assertSee('PREVIEW · NOT A COMPLETED SALE')->assertDontSee('Preview receipt')->assertDontSee('Receipt history')
            ->assertDontSee('Print receipt')->assertDontSee('Print / Save PDF')
            ->assertViewHas('liveReceipt', fn (array $receipt): bool => $receipt['total'] === 2000 && $receipt['cashReceived'] === null && $receipt['change'] === null)
            ->set('received', '1')->assertHasNoErrors()->assertSee('Cash received is below the total.')
            ->set(['discount' => '5', 'discountReason' => 'Owner promotion', 'received' => '100'])
            ->assertViewHas('liveReceipt', fn (array $receipt): bool => $receipt['total'] === 1500 && $receipt['change'] === 8500)
            ->call('setQuantity', 'drink-one', 2)
            ->assertViewHas('liveReceipt', fn (array $receipt): bool => $receipt['total'] === 3500 && $receipt['change'] === 6500);
        foreach (['invalid', '1e3', '0.001', '-1'] as $received) {
            $checkout->set('received', $received)->assertHasNoErrors()
                ->assertViewHas('liveReceipt', fn (array $receipt): bool => $receipt['cashReceived'] === null && $receipt['change'] === null);
        }
        $checkout->set('discountReason', '')->assertViewHas('liveReceipt', null)->assertDontSee('Print receipt');
        $this->assertSame($before, [DemoCatalogue::products(), SessionSales::all(), SessionCash::movements()]);
    }

    public function test_live_digital_and_credit_previews_do_not_bypass_completion_checks(): void
    {
        SessionCash::open('live-preview-open', '1000', '');
        foreach (['GCash', 'Maya'] as $method) {
            $checkout = Livewire::test(Checkout::class)->call('add', 'drink-one')->set('method', $method)
                ->assertHasNoErrors()->assertSee('Awaiting app verification.')
                ->set('received', '20')->set('confirmed', true)->assertSee('App payment verified by owner.')
                ->set('received', '19')->assertSet('confirmed', false)->assertSee('Awaiting app verification.')
                ->set('confirmed', true)->assertSee('Received amount must match the total.')
                ->call('complete')->assertHasErrors('received')->assertDontSee('Print receipt');
            $checkout->set('method', 'Credit')->assertViewHas('liveReceipt', fn (array $receipt): bool => $receipt['customer'] === 'Not selected')
                ->call('complete')->assertHasErrors('customerId');
        }
        $this->assertEmpty(SessionSales::all());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
    }

    public function test_checkout_only_offers_printing_for_a_saved_sale(): void
    {
        SessionCash::open('print-after-save', '1000', '');
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-one')->set('received', '20')
            ->assertDontSee('Print receipt')->call('complete')->assertHasNoErrors()
            ->assertSee('Latest saved receipt')->assertSee('SAVED SALE · NOT A FISCAL RECEIPT')->assertSee('Print receipt');
        $saved = $checkout->get('receipt');
        $checkout->call('add', 'drink-one')->assertSet('receipt', null)->assertSee('PREVIEW · NOT A COMPLETED SALE')->assertDontSee('Print receipt');
        $this->assertSame($saved, SessionSales::all()[$saved['id']]);
        $this->assertCount(1, SessionSales::all());
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
    }
}
