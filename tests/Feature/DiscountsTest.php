<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Livewire\Sales;
use App\Support\CheckoutCarts;
use App\Support\DemoCatalogue;
use App\Support\PosStore;
use App\Support\ReportExports;
use App\Support\SaleDiscount;
use App\Support\SessionAnalytics;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use Livewire\Livewire;
use Tests\TestCase;

class DiscountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SessionCash::open('test-open', '1000', '');
    }

    private function returns(array $sale, int $quantity): array
    {
        return array_map(fn (array $line): array => ['quantity' => $quantity, 'disposition' => 'Restock'], $sale['lines']);
    }

    public function test_discounted_cash_sale_tracks_subtotal_cost_profit_and_immutable_receipt(): void
    {
        $state = SessionBatches::state();
        foreach ($state['batches'] as &$batch) {
            $batch['totalCost'] = $batch['initialQuantity'] * ($batch['productId'] === 1 ? 1000 : 500);
        }
        unset($batch);
        PosStore::put(SessionBatches::changes($state));
        $checkout = Livewire::test(Checkout::class)->call('setQuantity', 'drink-one', 2)->call('add', 'noodle-one')
            ->set(['discount' => '5', 'discountReason' => 'Owner promotion', 'received' => '100'])->call('preview')->assertHasNoErrors()
            ->assertSet('receipt.subtotal', 5500)->assertSet('receipt.discount', 500)->assertSet('receipt.total', 5000)
            ->assertSee('Subtotal before discount')->assertSee('Owner promotion');
        $this->assertEmpty(SessionSales::all());
        $checkout->call('complete')->assertHasNoErrors()->assertSet('discount', '')->assertSet('discountReason', '');
        $sale = $checkout->get('receipt');
        $this->assertSame([363, 137], array_column($sale['lines'], 'discount'));
        $this->assertSame(5000, array_sum(array_column($sale['lines'], 'total')));
        $this->assertSame(5000, $sale['change']);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(5500, $summary['salesBeforeDiscount']);
        $this->assertSame(500, $summary['discounts']);
        $this->assertSame(5000, $summary['revenue']);
        $this->assertSame(2500, $summary['knownSalesCost']);
        $this->assertSame(2500, $summary['grossProfit']);
        $this->assertSame(105000, SessionCash::summary($sale['cashSessionId'])['expected']);
        $product = DemoCatalogue::find(1);
        $product['options'][0]['price'] = 9999;
        DemoCatalogue::save($product);
        $this->assertSame($sale, SessionSales::complete($sale['eventId'], [], 'Cash', '', false, discount: '999'));
        Livewire::test(Sales::class)->call('viewReceipt', $sale['id'])->assertSee('COPY')->assertSee('Owner promotion')->assertSee('50.00');
    }

    public function test_digital_payments_match_discounted_total_and_discount_changes_clear_confirmation(): void
    {
        foreach (['GCash', 'Maya'] as $method) {
            $checkout = Livewire::test(Checkout::class)->call('add', 'drink-one')->set('method', $method)
                ->set(['discount' => '5', 'discountReason' => 'Promotion', 'received' => '20', 'confirmed' => true])
                ->call('complete')->assertHasErrors('received');
            $checkout->set(['received' => '15', 'confirmed' => true])->call('preview')->assertHasNoErrors()
                ->set('discount', '6')->assertSet('confirmed', false)->assertSet('receipt', null)
                ->set(['received' => '14', 'confirmed' => true])->call('complete')->assertHasNoErrors()->assertSet('receipt.paymentReceived', 1400);
        }
        $this->assertCount(2, SessionSales::all());
        $this->assertSame(100000, SessionCash::summary(SessionCash::active()['id'])['expected']);
    }

    public function test_invalid_discounts_and_missing_reasons_leave_stock_and_money_unchanged(): void
    {
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-one')->set('received', '20')->set('discountReason', 'Promotion');
        $before = [DemoCatalogue::products(), SessionBatches::state(), SessionSales::all(), SessionCash::movements()];
        foreach (['-1', '20', '20.01', '1.001', '1e2', 'Infinity'] as $value) {
            $checkout->set('discount', $value)->call('complete')->assertHasErrors('discount');
            $this->assertSame($before, [DemoCatalogue::products(), SessionBatches::state(), SessionSales::all(), SessionCash::movements()]);
        }
        foreach (['', 'ab', str_repeat('x', 201)] as $reason) {
            $checkout->set(['discount' => '1', 'discountReason' => $reason])->call('complete')->assertHasErrors('discountReason');
        }
        $checkout->set(['discount' => '0', 'discountReason' => ''])->call('complete')->assertHasNoErrors()->assertSet('receipt.discount', 0);
    }

    public function test_repeated_partial_returns_refund_exact_discounted_cents_including_zero_value_items(): void
    {
        $product = DemoCatalogue::find(1);
        $product['options'][0]['price'] = 33;
        DemoCatalogue::save($product);
        $sale = SessionSales::complete('rounded', ['drink-one' => 3], 'Cash', '0.01', false, discount: '0.98', discountReason: 'Owner promotion');
        $this->assertSame(1, $sale['total']);
        $refunds = [];
        foreach ([0, 0, 1] as $index => $expected) {
            $return = SessionReturns::complete('return-'.$index, $sale['id'], $this->returns($sale, 1), 'Cash', true, 'Original discounted return');
            $this->assertSame($expected, $return['refund']);
            $refunds[] = $return['refund'];
            Livewire::test(Sales::class)->set('status', $index === 2 ? 'Returned' : 'Partially returned')->assertSee($sale['number']);
        }
        $this->assertSame(1, array_sum($refunds));
        $this->assertSame(98, array_sum(array_column(SessionReturns::all(), 'discount')));
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(0, $summary['revenue']);
        $this->assertSame(0, $summary['netDiscounts']);
        $this->assertSame(100000, SessionCash::summary(SessionCash::active()['id'])['expected']);
    }

    public function test_discounted_credit_debt_and_paid_return_use_the_actual_discounted_value(): void
    {
        SessionCredit::addCustomer('Customer', '');
        $customer = array_key_first(SessionCredit::customers());
        $sale = SessionSales::complete('credit-discount', ['drink-one' => 1], 'Credit', '', false, $customer,
            discount: '5', discountReason: 'Owner promotion');
        $debt = array_key_first(SessionCredit::debts());
        $this->assertSame(1500, SessionCredit::debts()[$debt]['principal']);
        SessionCredit::repay('repayment', $debt, '10', 'Cash', '');
        $return = SessionReturns::complete('credit-return', $sale['id'], $this->returns($sale, 1), 'Cash', true, 'Return discounted item');
        $this->assertSame(500, $return['creditReduction']);
        $this->assertSame(1000, $return['refund']);
        $this->assertSame(0, SessionCredit::debts()[$debt]['balance']);
        $this->assertSame(0, SessionAnalytics::summarize(7)['revenue']);
    }

    public function test_held_cart_preserves_discount_and_resets_it_after_sale(): void
    {
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-one')->set(['discount' => '5', 'discountReason' => 'Promotion']);
        $id = $checkout->get('saleEventId');
        $checkout->call('holdCart')->assertHasNoErrors()->assertSet('discount', '');
        $this->assertSame(1500, CheckoutCarts::held()[$id]['total']);
        $checkout->call('resumeCart', $id)->assertHasNoErrors()->assertSet('discount', '5')->assertSet('discountReason', 'Promotion')
            ->set('received', '15')->call('complete')->assertHasNoErrors()->assertSet('receipt.total', 1500)->assertSet('discount', '');
    }

    public function test_large_proportional_discount_never_uses_float_rounding(): void
    {
        $pricing = SaleDiscount::apply([['total' => 4000000000000000], ['total' => 4000000000000000]], '999999999999.99', 'Large test discount');
        $this->assertSame([49999999999999, 50000000000000], array_column($pricing['lines'], 'discount'));
        $this->assertSame(99999999999999, array_sum(array_column($pricing['lines'], 'discount')));
        $this->assertSame($pricing['total'], array_sum(array_column($pricing['lines'], 'total')));
    }

    public function test_discount_shares_are_stable_when_server_and_browser_order_items_differently(): void
    {
        $lines = [['id' => 'practice-pack', 'total' => 2800], ['id' => 'practice-one', 'total' => 1000]];
        $forward = SaleDiscount::apply($lines, '3', 'Owner promotion');
        $reverse = SaleDiscount::apply(array_reverse($lines), '3', 'Owner promotion');
        $this->assertSame(2578, array_column($forward['lines'], 'total', 'id')['practice-pack']);
        $this->assertSame(922, array_column($forward['lines'], 'total', 'id')['practice-one']);
        $this->assertEquals(array_column($forward['lines'], 'total', 'id'), array_column($reverse['lines'], 'total', 'id'));
        $this->assertSame(['practice-pack', 'practice-one'], array_column($forward['lines'], 'id'));
    }

    public function test_old_undiscounted_receipts_keep_their_values_and_returns(): void
    {
        $sale = SessionSales::complete('legacy', ['drink-one' => 2], 'Cash', '40', false);
        unset($sale['subtotal'], $sale['discount'], $sale['discountReason'], $sale['lines'][0]['subtotal'], $sale['lines'][0]['discount']);
        PosStore::put('demo.sales', [$sale['id'] => $sale]);
        $this->assertSame(4000, SessionAnalytics::summarize(7)['salesBeforeDiscount']);
        $return = SessionReturns::complete('legacy-return', $sale['id'], $this->returns($sale, 1), 'Cash', true, 'Original undiscounted item');
        $this->assertSame(2000, $return['refund']);
        $this->assertSame($sale, SessionSales::all()[$sale['id']]);
    }

    public function test_exports_show_discount_and_escaped_reason_without_double_deducting_profit(): void
    {
        SessionSales::complete('export', ['drink-one' => 1], 'Cash', '15', false, discount: '5', discountReason: '=SUM(1,2)');
        $report = ReportExports::build('transactions', 7);
        $row = array_combine($report['columns'], $report['rows'][0]);
        $this->assertSame('15.00', ReportExports::formatted($row['Net sales (PHP)'], true));
        $this->assertSame('20.00', ReportExports::formatted($row['Subtotal before discount (PHP)'], true));
        $this->assertSame('5.00', ReportExports::formatted($row['Discount (PHP)'], true));
        $this->assertSame("'=SUM(1,2)", ReportExports::formatted($row['Discount reason'], true));
        $this->get('/reports/print/transactions')->assertOk()->assertSee('Discount (PHP)')->assertSee('=SUM(1,2)');
    }
}
