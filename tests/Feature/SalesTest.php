<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Livewire\Sales;
use App\Support\DemoCatalogue;
use App\Support\SessionCash;
use App\Support\SessionSales;
use Livewire\Livewire;
use Tests\TestCase;

class SalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SessionCash::open('test-opening', '1000', 'Test float');
    }

    public function test_completed_sale_deducts_combined_package_units_and_clears_cart(): void
    {
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-pack')->call('add', 'drink-pack')
            ->call('add', 'drink-one')->set('received', '300')->call('complete')->assertHasNoErrors()->assertSet('cart', []);
        $this->assertSame(59, DemoCatalogue::find(1)['stock']);
        $sale = $checkout->get('receipt');
        $this->assertSame('WM-000001', $sale['number']);
        $this->assertSame(24000, $sale['total']);
        $this->assertSame(6000, $sale['change']);
        $this->assertSame(-13, session('demo.saleMovements')[0]['quantity']);
        $this->assertCount(1, SessionSales::all());
    }

    public function test_insufficient_combined_stock_leaves_all_products_and_sales_unchanged(): void
    {
        Livewire::test(Checkout::class)->call('add', 'noodle-one')->call('setQuantity', 'drink-case', 3)
            ->call('add', 'drink-one')->set('received', '2000')->call('complete')->assertHasErrors('cart');
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertSame(48, DemoCatalogue::find(2)['stock']);
        $this->assertEmpty(SessionSales::all());
        $this->assertEmpty(session('demo.saleMovements', []));
    }

    public function test_repeated_completion_event_does_not_repeat_sale_or_stock_deduction(): void
    {
        $first = SessionSales::complete('same-event', ['drink-one' => 1], 'Cash', '20.00', false);
        $retry = SessionSales::complete('same-event', ['drink-one' => 1], 'Cash', '20.00', false);
        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
        $this->assertCount(1, SessionSales::all());
        $this->assertCount(1, session('demo.saleMovements'));
    }

    public function test_payment_validation_and_qr_confirmation_are_required(): void
    {
        Livewire::test(Checkout::class)->call('add', 'drink-one')->set('received', '19.99')
            ->call('complete')->assertHasErrors('received')
            ->set('received', '20.001')->call('complete')->assertHasErrors('received')
            ->set('method', 'Maya')->call('complete')->assertHasErrors('confirmed')
            ->set('received', '20')->set('confirmed', true)->call('complete')->assertHasNoErrors()->assertSet('receipt.method', 'Maya');
        $sale = array_values(SessionSales::all())[0];
        $this->assertTrue($sale['paymentConfirmed']);
        $this->assertNull($sale['cashReceived']);
        $this->assertSame(2000, $sale['paymentReceived']);
    }

    public function test_digital_received_amount_is_required_exact_and_saved_without_affecting_cash(): void
    {
        foreach (['GCash', 'Maya'] as $method) {
            $checkout = Livewire::test(Checkout::class)->call('add', 'drink-one')->set('method', $method);
            $before = [DemoCatalogue::products(), SessionSales::all(), SessionCash::movements()];
            foreach (['', '0', '19.99', '20.01', '-20', '20.001', '1e2', 'Infinity'] as $amount) {
                $checkout->set('received', $amount)->set('confirmed', true)->call('complete')->assertHasErrors('received');
                $this->assertSame($before, [DemoCatalogue::products(), SessionSales::all(), SessionCash::movements()]);
            }
            $checkout->set('received', '20.00')->set('confirmed', true)->call('complete')->assertHasNoErrors();
            $sale = $checkout->get('receipt');
            $this->assertSame(2000, $sale['paymentReceived']);
            $this->assertNull($sale['cashReceived']);
            $this->assertSame(0, $sale['change']);
            $this->assertSame(100000, SessionCash::summary($sale['cashSessionId'])['expected']);
            $this->assertSame(2000, SessionCash::summary($sale['cashSessionId'])['methods'][$method]['in']);
            Livewire::test(Sales::class)->call('viewReceipt', $sale['id'])->assertSee($method.' amount received')
                ->assertSee('Received')->assertSee('20.00');
            $this->assertSame($sale, SessionSales::complete($sale['eventId'], [], $method, '', false));
            $sales = SessionSales::all();
            unset($sales[$sale['id']]['paymentReceived']);
            session()->put('demo.sales', $sales);
            Livewire::test(Sales::class)->call('viewReceipt', $sale['id'])->assertSee('Not recorded');
            $this->assertSame($sales, SessionSales::all());
        }
    }

    public function test_preview_leaves_stock_unchanged_and_history_preserves_original_details(): void
    {
        Livewire::test(Checkout::class)->call('add', 'drink-one')->set('received', '20')->call('preview')->assertHasNoErrors();
        $this->assertEmpty(SessionSales::all());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $sale = SessionSales::complete('snapshot', ['drink-one' => 1], 'Cash', '20', false);
        $product = DemoCatalogue::find(1);
        $product['name'] = 'Renamed drink';
        $product['options'][0]['price'] = 9999;
        DemoCatalogue::save($product);
        Livewire::test(Sales::class)->call('viewReceipt', $sale['id'])->assertSee('COPY')->assertSee('Bottled drink')->assertSee('20.00');
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
        $this->assertCount(1, SessionSales::all());
        $this->get('/sales')->assertOk()->assertSee('WM-000001');
    }

    public function test_receipt_history_filters_by_payment_and_number(): void
    {
        SessionSales::complete('cash-sale', ['drink-one' => 1], 'Cash', '20', false);
        SessionSales::complete('qr-sale', ['drink-one' => 1], 'GCash', '20', true);
        Livewire::test(Sales::class)->set('method', 'GCash')->assertSee('WM-000002')->assertDontSee('WM-000001');
        Livewire::test(Sales::class)->set('search', 'WM-000001')->assertSee('WM-000001')->assertDontSee('WM-000002');
    }

    public function test_unknown_product_and_invalid_quantity_cannot_complete(): void
    {
        Livewire::test(Checkout::class)->set('cart', ['unknown' => 1])->set('received', '20')->call('complete')->assertHasErrors('cart');
        Livewire::test(Checkout::class)->set('cart', ['drink-one' => 0])->set('received', '20')->call('complete')->assertHasErrors('cart.drink-one');
        $this->assertEmpty(SessionSales::all());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
    }
}
