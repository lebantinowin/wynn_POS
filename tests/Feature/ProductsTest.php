<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Livewire\Products;
use App\Support\DemoCatalogue;
use Livewire\Livewire;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    private function newProduct()
    {
        return Livewire::test(Products::class)->call('create')
            ->set('name', 'Crackers')->set('sku', 'CRACK-001')->set('category', 'Pantry')
            ->set('unit', 'packet')->set('openingStock', 30)->set('reorder', 5)
            ->set('options', [
                ['label' => 'Individual', 'units' => 1, 'price' => '12.35', 'barcode' => 'CRACK-ONE'],
                ['label' => 'Pack of 10', 'units' => 10, 'price' => '115.00', 'barcode' => 'CRACK-TEN'],
            ]);
    }

    public function test_created_package_can_be_scanned_in_checkout(): void
    {
        $this->newProduct()->call('save')->assertHasNoErrors()->assertSet('showForm', false);
        $product = DemoCatalogue::find(3);
        $this->assertSame(1235, $product['options'][0]['price']);
        $this->assertSame(30, $product['stock']);
        $checkout = Livewire::test(Checkout::class)->set('search', 'CRACK-TEN')->call('scan')->set('received', '200')->call('preview')->assertHasNoErrors();
        $this->assertSame(10, $checkout->get('receipt')['lines'][0]['baseQuantity']);
        $this->assertSame(11500, $checkout->get('receipt')['total']);
    }

    public function test_duplicate_codes_and_invalid_packages_are_rejected(): void
    {
        $this->newProduct()->set('sku', 'DRINK-001')->set('options.0.barcode', 'DEMO-001')
            ->call('save')->assertHasErrors(['sku', 'options.0.barcode']);
        $this->newProduct()->set('options.0.units', 2)->call('save')->assertHasErrors('options');
        $this->newProduct()->set('options.0.price', '1.001')->call('save')->assertHasErrors('options.0.price');
        $this->newProduct()->set('options.1.barcode', 'CRACK-ONE')->call('save')->assertHasErrors('options.1.barcode');
        $this->assertNull(DemoCatalogue::find(3));
    }

    public function test_edit_preserves_stock_and_existing_conversions(): void
    {
        Livewire::test(Products::class)->call('edit', 1)->set('options.1.units', 8)->call('save')->assertHasErrors('options.1.units');
        Livewire::test(Products::class)->call('edit', 1)->set('name', 'Updated drink')->set('openingStock', 999)->call('save')->assertHasNoErrors();
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertSame('Updated drink', DemoCatalogue::find(1)['name']);
    }

    public function test_manual_count_requires_reason_and_records_difference(): void
    {
        Livewire::test(Products::class)->call('startCount', 1)->set('physicalCount', 70)->call('saveCount')->assertHasErrors('countReason')
            ->set('countReason', 'Physical count discrepancy')->call('saveCount')->assertHasNoErrors();
        $this->assertSame(70, DemoCatalogue::find(1)['stock']);
        $entry = session('demo.counts')[0];
        $this->assertSame(72, $entry['before']);
        $this->assertSame(-2, $entry['difference']);
        $this->assertSame('Owner preview', $entry['actor']);
        Livewire::test(Products::class)->call('startCount', 1)->set('physicalCount', -1)->set('countReason', 'Invalid count')->call('saveCount')->assertHasErrors('physicalCount');
        $this->assertSame(70, DemoCatalogue::find(1)['stock']);
    }
}
