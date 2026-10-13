<?php

namespace Tests\Feature;

use App\Livewire\Sales;
use App\Support\DemoCatalogue;
use App\Support\PosStore;
use App\Support\SessionAnalytics;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionLosses;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SessionCash::open('opening', '1000', 'Test float');
        foreach ([1 => 1500, 2 => 1000] as $id => $cost) {
            $product = DemoCatalogue::find($id);
            $product['baseCost'] = $cost;
            DemoCatalogue::save($product);
        }
        $state = SessionBatches::state();
        foreach ($state['batches'] as &$batch) {
            $batch['totalCost'] = DemoCatalogue::find($batch['productId'])['baseCost'] * $batch['initialQuantity'];
            $batch['source'] = 'Opening stock';
        }
        unset($batch);
        PosStore::put(SessionBatches::changes($state));
    }

    private function inputs(string $saleId, array $quantities, string $disposition = 'Restock'): array
    {
        $inputs = [];
        foreach (SessionReturns::remaining($saleId) as $index => $line) {
            $inputs[$index] = ['quantity' => $quantities[$index] ?? 0, 'disposition' => $disposition];
        }

        return $inputs;
    }

    public function test_partial_package_returns_preserve_receipts_prices_and_original_costs(): void
    {
        $sale = SessionSales::complete('sale', ['drink-pack' => 2, 'drink-one' => 2], 'Cash', '260', false);
        $product = DemoCatalogue::find(1);
        $product['baseCost'] = 9999;
        $product['options'][1]['price'] = 99999;
        DemoCatalogue::save($product);
        $return = SessionReturns::complete('return', $sale['id'], $this->inputs($sale['id'], [0 => 1]), 'Cash', true, 'Customer changed their purchase');
        $this->assertSame(11000, $return['total']);
        $this->assertSame(9000, $return['lines'][0]['cost']);
        $this->assertSame(6, $return['lines'][0]['baseQuantity']);
        $this->assertSame(64, DemoCatalogue::find(1)['stock']);
        $this->assertSame($sale, SessionSales::all()[$sale['id']]);
        $this->assertSame(1, SessionReturns::remaining($sale['id'])[0]['remaining']);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(26000, $summary['grossSales']);
        $this->assertSame(15000, $summary['revenue']);
        $this->assertSame(12000, $summary['knownSalesCost']);
        $this->assertSame(3000, $summary['grossProfit']);
        $this->assertSame(15000, $summary['collections']);
        $this->assertSame(115000, SessionCash::summary(SessionCash::active()['id'])['expected']);
        $this->get('/sales')->assertOk()->assertSee('Transaction history')->assertSee('Partially returned')->assertSee('RET-00001');
    }

    public function test_discarded_return_records_one_loss_without_restoring_or_deducting_stock_again(): void
    {
        $sale = SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false);
        SessionReturns::complete('discard', $sale['id'], $this->inputs($sale['id'], [1], 'Discard'), 'Cash', true, 'Bottle damaged when returned');
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
        $this->assertCount(1, SessionLosses::all());
        $loss = SessionLosses::all()[0];
        $this->assertSame(71, $loss['before']);
        $this->assertSame(71, $loss['after']);
        $this->assertSame(1500, $loss['totalCost']);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(0, $summary['grossProfit']);
        $this->assertSame(-1500, $summary['operatingProfit']);
        $this->assertSame(1, $summary['lossUnits']);
        $this->assertSame(0, $summary['knownSalesCost']);
        $this->get('/losses')->assertOk()->assertSee('Returned goods discarded');
    }

    public function test_unpaid_credit_returns_reduce_debt_without_paying_out_money(): void
    {
        SessionCredit::addCustomer('Maria', '');
        $customer = array_key_first(SessionCredit::customers());
        $sale = SessionSales::complete('sale', ['drink-pack' => 1], 'Credit', '', false, $customer);
        $return = SessionReturns::complete('credit-return', $sale['id'], $this->inputs($sale['id'], [1]), 'Cash', false, 'Customer returned unopened pack');
        $this->assertSame(11000, $return['creditReduction']);
        $this->assertSame(0, $return['refund']);
        $this->assertNull($return['method']);
        $debt = array_values(SessionCredit::debts())[0];
        $this->assertSame(11000, $debt['principal']);
        $this->assertSame(0, $debt['balance']);
        $this->assertSame(11000, $debt['returnCredit']);
        $this->assertEmpty(SessionCash::movements());
        $this->assertSame(0, SessionCash::summary(SessionCash::active()['id'])['credit']);
        $this->get('/credit')->assertOk()->assertSee('Settled')->assertSee('Return credit:');
    }

    public function test_repaid_credit_refunds_only_the_excess_after_debt_reduction(): void
    {
        SessionCredit::addCustomer('Maria', '');
        $customer = array_key_first(SessionCredit::customers());
        $sale = SessionSales::complete('sale', ['drink-pack' => 1], 'Credit', '', false, $customer);
        $debtId = array_key_first(SessionCredit::debts());
        SessionCredit::repay('repay', $debtId, '80', 'Cash', '');
        $return = SessionReturns::complete('credit-return', $sale['id'], $this->inputs($sale['id'], [1]), 'Cash', true, 'Returned unopened pack');
        $this->assertSame(3000, $return['creditReduction']);
        $this->assertSame(8000, $return['refund']);
        $this->assertSame(0, SessionCredit::debts()[$debtId]['balance']);
        $this->assertSame(100000, SessionCash::summary(SessionCash::active()['id'])['expected']);
        $this->assertSame(0, SessionAnalytics::summarize(7)['collections']);
        $this->assertSame(0, SessionAnalytics::summarize(7)['revenue']);
        $this->assertSame('Return refund', SessionCash::movements()[1]['source']);
    }

    public function test_multiple_credit_returns_never_overrefund_or_create_negative_debt(): void
    {
        SessionCredit::addCustomer('Maria', '');
        $sale = SessionSales::complete('sale', ['drink-one' => 2], 'Credit', '', false, array_key_first(SessionCredit::customers()));
        $debtId = array_key_first(SessionCredit::debts());
        SessionCredit::repay('repay', $debtId, '15', 'Cash', '');
        $first = SessionReturns::complete('first', $sale['id'], $this->inputs($sale['id'], [1]), 'Cash', false, 'First item returned');
        $this->assertSame(2000, $first['creditReduction']);
        $this->assertSame(0, $first['refund']);
        $second = SessionReturns::complete('second', $sale['id'], $this->inputs($sale['id'], [1]), 'Cash', true, 'Second item returned');
        $this->assertSame(500, $second['creditReduction']);
        $this->assertSame(1500, $second['refund']);
        $this->assertSame(0, SessionCredit::debts()[$debtId]['balance']);
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        Livewire::test(Sales::class)->call('startReturn', $sale['id'])->set('returnLines.0.quantity', 1)
            ->set('returnReason', 'Attempt to return twice')->set('refundConfirmed', true)->call('completeReturn')
            ->assertHasErrors('returnLines.0.quantity');
        $this->assertCount(2, SessionReturns::all());
    }

    public function test_cash_refund_of_a_digital_sale_uses_the_selected_drawer_and_original_sales_method(): void
    {
        $sale = SessionSales::complete('sale', ['drink-one' => 1], 'GCash', '', true);
        SessionReturns::complete('return', $sale['id'], $this->inputs($sale['id'], [1]), 'Cash', true, 'Customer requested cash refund');
        $summary = SessionCash::summary(SessionCash::active()['id']);
        $this->assertSame(98000, $summary['expected']);
        $this->assertSame(2000, $summary['methods']['GCash']['in']);
        $this->assertSame(2000, $summary['sources']['Return refund']['out']);
        $this->assertSame(0, SessionAnalytics::summarize(7)['payments']['GCash']);
        $this->assertSame(0, SessionAnalytics::summarize(7)['collections']);
    }

    public function test_cash_overdraft_and_stock_overflow_leave_all_return_records_unchanged(): void
    {
        $sale = SessionSales::complete('sale', ['drink-one' => 1, 'noodle-one' => 1], 'GCash', '', true);
        SessionCash::recordMovement('withdraw', 'out', '1000', 'Bank deposit');
        Livewire::test(Sales::class)->call('startReturn', $sale['id'])->set('returnLines.0.quantity', 1)
            ->set('refundMethod', 'Cash')->set('returnReason', 'Customer changed purchase')->set('refundConfirmed', true)->call('completeReturn')->assertHasErrors('refund');
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionReturns::all());
        $this->assertEmpty(session('demo.returnMovements', []));
        $this->assertEmpty(SessionLosses::all());
        SessionCash::recordMovement('topup', 'in', '50', 'Refund float top-up');
        $product = DemoCatalogue::find(2);
        $product['stock'] = 100000000;
        DemoCatalogue::save($product);
        Livewire::test(Sales::class)->call('startReturn', $sale['id'])->set('returnLines.0.quantity', 1)->set('returnLines.1.quantity', 1)
            ->set('returnReason', 'Customer returned both')->set('refundConfirmed', true)->call('completeReturn')->assertHasErrors('return');
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
        $this->assertSame(100000000, DemoCatalogue::find(2)['stock']);
        $this->assertEmpty(SessionReturns::all());
    }

    public function test_retries_and_stale_cash_sessions_cannot_repeat_or_misassign_refunds(): void
    {
        $sale = SessionSales::complete('sale', ['drink-one' => 2], 'Cash', '40', false);
        $form = Livewire::test(Sales::class)->call('startReturn', $sale['id'])->set('returnLines.0.quantity', 1)
            ->set('returnReason', 'Customer returned one')->set('refundConfirmed', true);
        $old = SessionCash::active();
        SessionCash::close($old['id'], 'close', '1040', '');
        SessionCash::open('new-opening', '100', 'New session');
        $form->call('completeReturn')->assertHasErrors('cashSession');
        $this->assertEmpty(SessionReturns::all());
        $first = SessionReturns::complete('return', $sale['id'], $this->inputs($sale['id'], [1]), 'Cash', true, 'Customer returned one');
        SessionCash::close(SessionCash::active()['id'], 'close-new', '80', '');
        $retry = SessionReturns::complete('return', $sale['id'], [], 'Invalid', false, '');
        $this->assertSame($first, $retry);
        $this->assertCount(1, SessionReturns::all());
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
        $this->assertSame(104000, SessionCash::all()[$old['id']]['summary']['expected']);
    }

    public function test_invalid_quantities_dispositions_and_unconfirmed_refunds_are_rejected(): void
    {
        $sale = SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false);
        $form = Livewire::test(Sales::class)->call('startReturn', $sale['id'])->set('returnReason', 'Customer returned item');
        $form->call('completeReturn')->assertHasErrors('return');
        $form->set('returnLines.0.quantity', '1.5')->call('completeReturn')->assertHasErrors('returnLines.0.quantity');
        $form->set('returnLines.0.quantity', 2)->call('completeReturn')->assertHasErrors('returnLines.0.quantity');
        $form->set('returnLines.0.quantity', 1)->set('returnLines.0.disposition', 'Invalid')->call('completeReturn')->assertHasErrors('returnLines.0.disposition');
        $form->set('returnLines.0.disposition', 'Restock')->call('completeReturn')->assertHasErrors('refundConfirmed');
        $form->set('refundConfirmed', true)->set('returnReason', '')->call('completeReturn')->assertHasErrors('reason');
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionReturns::all());
        $this->assertSame(102000, SessionCash::summary(SessionCash::active()['id'])['expected']);
    }

    public function test_history_filters_and_reprints_preserve_original_and_return_records(): void
    {
        SessionCredit::addCustomer('Maria Santos', '');
        $sale = SessionSales::complete('sale', ['drink-one' => 2], 'Credit', '', false, array_key_first(SessionCredit::customers()));
        $return = SessionReturns::complete('return', $sale['id'], $this->inputs($sale['id'], [1]), 'Cash', false, 'Unopened item returned');
        $view = Livewire::test(Sales::class)->set('search', 'Maria Santos')->set('period', '7')->set('status', 'Partially returned')
            ->assertSee($sale['number'])->assertSee($return['number'])->assertSee('Grocery credit');
        $view->call('viewReceipt', $sale['id'])->assertSee('COPY')->assertSet('selectedReturnId', null);
        $view->call('viewReturn', $return['id'])->assertSet('selectedId', null)->assertSee('SESSION RETURN');
        $view->set('search', now('Asia/Shanghai')->toDateString())->assertSee($sale['number'])->assertSee($return['number']);
        $view->set('status', 'Returned')->assertSee('No matching sales');
        $this->assertSame($sale, SessionSales::all()[$sale['id']]);
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
    }

    public function test_return_only_periods_have_negative_net_totals_and_chart_values_stay_in_bounds(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
        $sale = SessionSales::complete('old', ['drink-one' => 1], 'Cash', '20', false);
        $this->travelTo(Carbon::parse('2026-10-09 08:00:00', 'UTC'));
        SessionReturns::complete('return', $sale['id'], $this->inputs($sale['id'], [1]), 'Cash', true, 'Older sale returned');
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(0, $summary['transactions']);
        $this->assertSame(-2000, $summary['revenue']);
        $this->assertSame(-2000, $summary['collections']);
        $this->assertSame(-1500, $summary['knownSalesCost']);
        $this->assertSame(-500, $summary['grossProfit']);
        $this->assertSame(-1, $summary['products'][0]['units']);
        $this->assertSame(0, SessionAnalytics::summarize(30)['revenue']);
        $html = $this->get('/reports')->assertOk()->assertSee('-20.00')->assertSee('Daily sales and money collections')->getContent();
        preg_match_all('/<polyline points="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $points) {
            foreach (explode(' ', $points) as $point) {
                $y = (float) explode(',', $point)[1];
                $this->assertGreaterThanOrEqual(38, $y);
                $this->assertLessThanOrEqual(198, $y);
            }
        }
        Livewire::test(Sales::class)->set('period', '7')->assertSee('No matching sales')->assertSee('RET-00001');
        $this->travelBack();
    }

    public function test_missing_or_zero_original_cost_is_preserved_on_discarded_returns(): void
    {
        $product = DemoCatalogue::find(1);
        $product['baseCost'] = null;
        DemoCatalogue::save($product);
        $sale = SessionSales::complete('unknown', ['drink-one' => 1], 'Cash', '20', false);
        $sales = SessionSales::all();
        $sales[$sale['id']]['lines'][0]['cost'] = null;
        unset($sales[$sale['id']]['lines'][0]['costMethod']);
        PosStore::put('demo.sales', $sales);
        SessionReturns::complete('unknown-return', $sale['id'], $this->inputs($sale['id'], [1], 'Discard'), 'Cash', true, 'Returned damaged stock');
        $this->assertNull(SessionLosses::all()[0]['totalCost']);
        $this->assertNull(SessionAnalytics::summarize(7)['operatingProfit']);
        $product['baseCost'] = 0;
        $product['stock'] = DemoCatalogue::find(1)['stock'];
        DemoCatalogue::save($product);
        $free = SessionSales::complete('free', ['drink-one' => 1], 'Cash', '20', false);
        $sales = SessionSales::all();
        $sales[$free['id']]['lines'][0]['cost'] = 0;
        unset($sales[$free['id']]['lines'][0]['costMethod']);
        PosStore::put('demo.sales', $sales);
        $return = SessionReturns::complete('free-return', $free['id'], $this->inputs($free['id'], [1], 'Discard'), 'Cash', true, 'Free stock returned damaged');
        $this->assertSame(0, $return['lines'][0]['cost']);
        $this->assertSame(0, SessionLosses::all()[1]['totalCost']);
    }

    public function test_legacy_receipts_without_customer_or_cost_fields_can_be_reviewed_and_returned(): void
    {
        $sale = SessionSales::complete('legacy', ['drink-one' => 1], 'Cash', '20', false);
        $sales = SessionSales::all();
        unset($sales[$sale['id']]['customer'], $sales[$sale['id']]['lines'][0]['cost'], $sales[$sale['id']]['lines'][0]['costMethod']);
        session()->put('demo.sales', $sales);
        $this->get('/sales')->assertOk()->assertSee('Transaction history')->assertSee($sale['number']);
        Livewire::test(Sales::class)->call('viewReceipt', $sale['id'])->assertSee('COPY')
            ->call('startReturn', $sale['id'])->set('returnLines.0.quantity', 1)->set('returnReason', 'Older receipt return')
            ->set('refundConfirmed', true)->call('completeReturn')->assertHasNoErrors()->assertSee('RET-00001');
        $this->assertNull(array_values(SessionReturns::all())[0]['lines'][0]['cost']);
        $this->assertSame($sales[$sale['id']], SessionSales::all()[$sale['id']]);
    }

    public function test_mixed_negative_payment_totals_do_not_display_misleading_percentages(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
        $sale = SessionSales::complete('older', ['drink-one' => 1], 'Cash', '20', false);
        $this->travelTo(Carbon::parse('2026-10-09 08:00:00', 'UTC'));
        SessionSales::complete('today', ['noodle-one' => 2], 'GCash', '', true);
        SessionReturns::complete('refund', $sale['id'], $this->inputs($sale['id'], [1]), 'Cash', true, 'Earlier cash sale returned');
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(1000, $summary['revenue']);
        $this->assertSame(-2000, $summary['payments']['Cash']);
        $this->assertSame(3000, $summary['payments']['GCash']);
        $this->get('/reports')->assertOk()->assertSee('-20.00')->assertDontSee('300%');
        $this->travelBack();
    }
}
