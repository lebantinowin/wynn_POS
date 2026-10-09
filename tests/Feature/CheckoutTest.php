<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    public function test_shell_and_catalogue_render_without_database(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('Sample workspace');
        $this->get('/products')->assertOk()->assertSee('Pack of 6');
        $this->get('/checkout')->assertOk()->assertSee('Find products');
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
            ->set('confirmed', true)->call('preview')->assertHasNoErrors()
            ->assertSet('receipt.method', 'GCash');
    }

    public function test_invalid_quantity_is_rejected_and_repeated_scan_increments(): void
    {
        Livewire::test(Checkout::class)->call('add', 'drink-one')
            ->call('setQuantity', 'drink-one', 0)->assertHasErrors('cart')
            ->assertSet('cart.drink-one', 1)
            ->call('setQuantity', 'drink-one', '1.5')->assertHasErrors('cart')
            ->call('add', 'drink-one')->assertSet('cart.drink-one', 2);
    }
}
