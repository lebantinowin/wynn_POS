<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Livewire\Inventory;
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

    public function test_base_purchase_cost_is_saved_exactly_and_reloaded_for_editing(): void
    {
        $this->newProduct()->set('baseCost', ' 8.35 ')->call('save')->assertHasNoErrors();
        $this->assertSame(835, DemoCatalogue::find(3)['baseCost']);
        $this->assertSame(1235, DemoCatalogue::find(3)['options'][0]['price']);

        Livewire::test(Products::class)->call('edit', 3)->assertSet('baseCost', '8.35')
            ->set('baseCost', '9.10')->call('save')->assertHasNoErrors();
        $this->assertSame(910, DemoCatalogue::find(3)['baseCost']);
        $this->assertSame(30, DemoCatalogue::find(3)['stock']);
        $this->assertSame(10, DemoCatalogue::find(3)['options'][1]['units']);

        Livewire::test(Products::class)->call('edit', 3)->call('create')->assertSet('baseCost', '');
    }

    public function test_unknown_purchase_cost_is_distinct_from_zero(): void
    {
        $this->newProduct()->call('save')->assertHasNoErrors();
        $this->assertNull(DemoCatalogue::find(3)['baseCost']);
        Livewire::test(Products::class)->call('edit', 3)->assertSet('baseCost', '')
            ->set('baseCost', '0.00')->call('save')->assertHasNoErrors();
        $this->assertSame(0, DemoCatalogue::find(3)['baseCost']);
        Livewire::test(Products::class)->call('edit', 3)->assertSet('baseCost', '0.00');
    }

    public function test_invalid_purchase_cost_does_not_change_the_catalogue(): void
    {
        foreach (['-1', '8.355', '1e3', '10000000', 'cost'] as $invalid) {
            $this->newProduct()->set('baseCost', $invalid)->call('save')->assertHasErrors('baseCost');
            $this->assertNull(DemoCatalogue::find(3));
        }
    }

    public function test_expiry_tracking_requires_a_date_for_opening_stock(): void
    {
        $expiry = now('Asia/Shanghai')->addMonths(3)->toDateString();
        $this->newProduct()->set('trackExpiry', true)->call('save')->assertHasErrors('openingExpiry')
            ->set('openingExpiry', $expiry)->call('save')->assertHasNoErrors();
        $product = DemoCatalogue::find(3);
        $this->assertTrue($product['trackExpiry']);
        $this->assertSame($expiry, $product['openingExpiry']);
        $this->assertSame(30, $product['openingQuantity']);
        $this->get('/products')->assertOk()->assertSee('Opening stock expiry:')->assertSee($expiry);
    }

    public function test_opening_expiry_enables_tracking_and_preserves_a_separate_delivery_date(): void
    {
        $openingExpiry = now('Asia/Shanghai')->addMonth()->toDateString();
        $deliveryExpiry = now('Asia/Shanghai')->addMonths(4)->toDateString();
        $this->newProduct()->set('openingExpiry', $openingExpiry)->call('save')->assertHasNoErrors();
        $this->assertTrue(DemoCatalogue::find(3)['trackExpiry']);
        $optionId = DemoCatalogue::find(3)['options'][1]['id'];
        Livewire::test(Inventory::class)->set('optionId', $optionId)->set('quantity', 2)
            ->set('cost', '80')->set('supplier', 'Supplier')->set('expiry', $deliveryExpiry)
            ->call('receive')->assertHasNoErrors();
        $this->assertSame($deliveryExpiry, session('demo.receiving')[0]['expiry']);
        Livewire::test(Products::class)->call('edit', 3)->assertSet('openingExpiry', $openingExpiry)
            ->set('openingExpiry', $deliveryExpiry)->set('openingStock', 999)->call('save')->assertHasNoErrors();
        $product = DemoCatalogue::find(3);
        $this->assertSame($openingExpiry, $product['openingExpiry']);
        $this->assertSame(30, $product['openingQuantity']);
        $this->assertSame(50, $product['stock']);
        Livewire::test(Products::class)->call('edit', 3)->call('create')->assertSet('openingExpiry', '');
    }

    public function test_zero_opening_stock_needs_no_date_and_invalid_dates_are_rejected(): void
    {
        foreach (['2026-02-30', 'tomorrow', '10/09/2026'] as $invalid) {
            $this->newProduct()->set('openingExpiry', $invalid)->call('save')->assertHasErrors('openingExpiry');
            $this->assertNull(DemoCatalogue::find(3));
        }
        $this->newProduct()->set('openingStock', 0)->set('openingExpiry', now()->addMonth()->toDateString())
            ->call('save')->assertHasErrors('openingExpiry');
        $this->assertNull(DemoCatalogue::find(3));
        $this->newProduct()->set('trackExpiry', true)->set('openingStock', 0)->call('save')->assertHasNoErrors();
        $this->assertNull(DemoCatalogue::find(3)['openingExpiry']);
        $this->assertSame(0, DemoCatalogue::find(3)['openingQuantity']);
    }

    public function test_already_expired_opening_stock_is_recorded_with_an_expired_label(): void
    {
        $expiry = now('Asia/Shanghai')->subDay()->toDateString();
        $this->newProduct()->set('openingExpiry', $expiry)->call('save')->assertHasNoErrors();
        $this->assertSame($expiry, DemoCatalogue::find(3)['openingExpiry']);
        $this->get('/products')->assertOk()->assertSee('Expired');
    }
}
