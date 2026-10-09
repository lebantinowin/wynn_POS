<?php

namespace Tests\Feature;

use App\Livewire\Purchasing;
use App\Support\DemoCatalogue;
use App\Support\SessionPurchasing;
use Livewire\Livewire;
use Tests\TestCase;

class PurchasingTest extends TestCase
{
    private function supplier(): string
    {
        SessionPurchasing::saveSupplier(null, ['name' => 'Wholesale supplier', 'contact' => '', 'phone' => '', 'email' => '', 'notes' => '']);

        return array_key_first(SessionPurchasing::suppliers());
    }

    private function order(): string
    {
        SessionPurchasing::createOrder($this->supplier(), '', [
            ['optionId' => 'drink-case', 'quantity' => 3, 'cost' => '300.25'],
            ['optionId' => 'noodle-pack', 'quantity' => 4, 'cost' => '50.00'],
        ]);

        return array_key_first(SessionPurchasing::orders());
    }

    public function test_supplier_creation_edit_and_duplicate_validation(): void
    {
        Livewire::test(Purchasing::class)->set('name', 'Wholesale supplier')->set('email', 'supplier@example.com')->call('saveSupplier')->assertHasNoErrors();
        $id = array_key_first(SessionPurchasing::suppliers());
        Livewire::test(Purchasing::class)->set('name', 'WHOLESALE SUPPLIER')->call('saveSupplier')->assertHasErrors('name');
        Livewire::test(Purchasing::class)->call('editSupplier', $id)->set('phone', '12345')->call('saveSupplier')->assertHasNoErrors();
        $this->assertCount(1, SessionPurchasing::suppliers());
        $this->assertSame('12345', SessionPurchasing::suppliers()[$id]['phone']);
        $this->get('/purchasing')->assertOk()->assertSee('Wholesale supplier');
    }

    public function test_order_creation_keeps_stock_unchanged_and_snapshots_expected_cost(): void
    {
        Livewire::test(Purchasing::class)->set('supplierId', $this->supplier())->set('lines', [
            ['optionId' => 'drink-case', 'quantity' => 3, 'cost' => '300.25'],
        ])->call('createOrder')->assertHasNoErrors();
        $order = array_values(SessionPurchasing::orders())[0];
        $this->assertSame('Ordered', $order['status']);
        $this->assertSame(30025, $order['lines'][0]['cost']);
        $this->assertSame(24, $order['lines'][0]['units']);
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
    }

    public function test_partial_then_full_delivery_updates_order_and_inventory(): void
    {
        $id = $this->order();
        Livewire::test(Purchasing::class)->call('startReceiving', $id)
            ->set('deliveries.0.quantity', 1)->set('deliveries.0.cost', '310.50')
            ->set('reference', 'INV-001')->call('receiveOrder')->assertHasNoErrors();
        $this->assertSame(96, DemoCatalogue::find(1)['stock']);
        $this->assertSame(48, DemoCatalogue::find(2)['stock']);
        $this->assertSame('Partially received', SessionPurchasing::orders()[$id]['status']);
        $this->assertSame(31050, session('demo.receiving')[0]['totalCost']);
        Livewire::test(Purchasing::class)->call('startReceiving', $id)
            ->set('deliveries.0.quantity', 2)->set('deliveries.1.quantity', 4)
            ->call('receiveOrder')->assertHasNoErrors();
        $this->assertSame(144, DemoCatalogue::find(1)['stock']);
        $this->assertSame(68, DemoCatalogue::find(2)['stock']);
        $this->assertSame('Received', SessionPurchasing::orders()[$id]['status']);
        $this->assertCount(3, session('demo.receiving'));
    }

    public function test_over_receiving_and_invalid_expiry_leave_all_lines_unchanged(): void
    {
        $id = $this->order();
        Livewire::test(Purchasing::class)->call('startReceiving', $id)
            ->set('deliveries.0.quantity', 1)->set('deliveries.1.quantity', 5)
            ->call('receiveOrder')->assertHasErrors('deliveries.1.quantity');
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertSame(0, SessionPurchasing::orders()[$id]['lines'][0]['received']);
        $product = DemoCatalogue::find(2);
        $product['trackExpiry'] = true;
        DemoCatalogue::save($product);
        Livewire::test(Purchasing::class)->call('startReceiving', $id)
            ->set('deliveries.0.quantity', 1)->set('deliveries.1.quantity', 1)
            ->call('receiveOrder')->assertHasErrors('deliveries.1.expiry');
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(session('demo.receiving', []));
    }

    public function test_duplicate_delivery_event_cannot_add_stock_twice(): void
    {
        $id = $this->order();
        $deliveries = [['quantity' => 1, 'cost' => '300.00', 'expiry' => ''], ['quantity' => 0, 'cost' => '50.00', 'expiry' => '']];
        $this->assertTrue(SessionPurchasing::receive($id, $deliveries, 'INV-001', 'sample-event'));
        $this->assertFalse(SessionPurchasing::receive($id, $deliveries, 'INV-001', 'sample-event'));
        $this->assertSame(96, DemoCatalogue::find(1)['stock']);
        $this->assertCount(1, session('demo.receiving'));
    }

    public function test_order_rejects_missing_supplier_and_duplicate_sale_options(): void
    {
        Livewire::test(Purchasing::class)->set('supplierId', 'missing')->set('lines', [
            ['optionId' => 'drink-case', 'quantity' => 1, 'cost' => '300.00'],
        ])->call('createOrder')->assertHasErrors('supplierId');
        Livewire::test(Purchasing::class)->set('supplierId', $this->supplier())->set('lines', [
            ['optionId' => 'drink-case', 'quantity' => 1, 'cost' => '300.00'],
            ['optionId' => 'drink-case', 'quantity' => 2, 'cost' => '300.00'],
        ])->call('createOrder')->assertHasErrors('lines.0.optionId');
        $this->assertEmpty(SessionPurchasing::orders());
    }
}
