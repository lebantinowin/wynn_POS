<?php

namespace Tests\Feature;

use App\Livewire\Inventory;
use App\Support\DemoCatalogue;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    public function test_case_receiving_updates_base_stock_and_exact_total_cost(): void
    {
        Livewire::test(Inventory::class)->set('optionId', 'drink-case')->set('quantity', 3)
            ->set('cost', '300.25')->set('supplier', 'Sample supplier')->call('receive')->assertHasNoErrors();
        $this->assertSame(144, DemoCatalogue::find(1)['stock']);
        $entry = session('demo.receiving')[0];
        $this->assertSame(72, $entry['baseQuantity']);
        $this->assertSame(30025, $entry['costPerOption']);
        $this->assertSame(90075, $entry['totalCost']);
        $this->get('/inventory')->assertOk()->assertSee('Sample supplier');
    }

    public function test_invalid_receiving_does_not_change_stock(): void
    {
        Livewire::test(Inventory::class)->set('optionId', 'drink-case')->set('quantity', 0)
            ->set('cost', '300')->set('supplier', 'Supplier')->call('receive')->assertHasErrors('quantity');
        Livewire::test(Inventory::class)->set('optionId', 'drink-case')->set('cost', '0')
            ->set('supplier', 'Supplier')->call('receive')->assertHasErrors('cost');
        Livewire::test(Inventory::class)->set('optionId', 'missing')->set('cost', '1')
            ->set('supplier', 'Supplier')->call('receive')->assertHasErrors('optionId');
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(session('demo.receiving', []));
    }

    public function test_expiry_tracked_product_requires_valid_expiry(): void
    {
        $product = DemoCatalogue::find(1);
        $product['trackExpiry'] = true;
        DemoCatalogue::save($product);
        Livewire::test(Inventory::class)->set('optionId', 'drink-one')->set('cost', '10')
            ->set('supplier', 'Supplier')->call('receive')->assertHasErrors('expiry')
            ->set('expiry', now()->subDay()->format('Y-m-d'))->call('receive')->assertHasErrors('expiry')
            ->set('expiry', now()->addMonth()->format('Y-m-d'))->call('receive')->assertHasNoErrors();
        $this->assertSame(73, DemoCatalogue::find(1)['stock']);
    }
}
