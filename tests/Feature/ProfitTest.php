<?php

namespace Tests\Feature;

use App\Support\DemoCatalogue;
use App\Support\PosStore;
use App\Support\SessionAnalytics;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionLosses;
use App\Support\SessionSales;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProfitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SessionCash::open('opening', '1000', 'Test float');
    }

    private function setCost(int $id, ?int $cost): void
    {
        $product = DemoCatalogue::find($id);
        $product['baseCost'] = $cost;
        DemoCatalogue::save($product);
        $state = SessionBatches::state();
        $batchId = 'legacy-'.$id;
        if (($state['batches'][$batchId]['source'] ?? '') === 'Legacy stock') {
            $state['batches'][$batchId]['source'] = 'Opening stock';
            $state['batches'][$batchId]['totalCost'] = $cost === null ? null : $cost * $product['stock'];
            PosStore::put(SessionBatches::changes($state));
        }
    }

    public function test_individual_and_package_sales_use_exact_base_unit_costs(): void
    {
        $this->setCost(1, 1235);
        $sale = SessionSales::complete('mixed', ['drink-pack' => 1, 'drink-one' => 2], 'Cash', '150', false);
        $this->assertSame(1235, $sale['lines'][0]['baseCost']);
        $this->assertSame(7410, $sale['lines'][0]['cost']);
        $this->assertSame(2470, $sale['lines'][1]['cost']);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(9880, $summary['knownSalesCost']);
        $this->assertSame(15000, $summary['costedRevenue']);
        $this->assertSame(5120, $summary['grossProfit']);
        $this->assertSame(34.1, $summary['grossMargin']);
        $this->assertSame(5120, $summary['operatingProfit']);
        $this->assertSame(5120, $summary['products'][0]['profit']);
        $this->get('/reports')->assertOk()->assertSee('98.80')->assertSee('51.20')->assertSee('34.1%');
    }

    public function test_previews_do_not_record_profit_and_default_cost_edits_do_not_revalue_batches(): void
    {
        $this->setCost(1, 1500);
        $draft = SessionSales::draft(['drink-one' => 1], 'Cash', '20', false);
        $this->assertSame(1500, $draft['lines'][0]['cost']);
        $this->assertEmpty(SessionSales::all());
        $this->assertSame(0, SessionAnalytics::summarize(7)['grossProfit']);
        $this->setCost(1, 1800);
        $sale = SessionSales::complete('current', ['drink-one' => 1], 'Cash', '20', false);
        $this->assertSame(1500, $sale['lines'][0]['cost']);
        $this->assertSame(500, SessionAnalytics::summarize(7)['grossProfit']);
    }

    public function test_cost_edits_and_retries_preserve_original_sale_profit(): void
    {
        $this->setCost(1, 1500);
        $first = SessionSales::complete('first', ['drink-one' => 2], 'Cash', '40', false);
        $this->setCost(1, 1800);
        $retry = SessionSales::complete('first', ['drink-one' => 2], 'Cash', '40', false);
        $this->assertSame($first, $retry);
        $this->assertSame(1000, SessionAnalytics::summarize(7)['grossProfit']);
        SessionSales::complete('second', ['drink-one' => 1], 'GCash', '20', true);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(4500, $summary['knownSalesCost']);
        $this->assertSame(1500, $summary['grossProfit']);
        $this->assertSame(25.0, $summary['grossMargin']);
        $this->assertSame(69, DemoCatalogue::find(1)['stock']);
    }

    public function test_missing_and_legacy_costs_withhold_totals_without_rewriting_history(): void
    {
        $this->setCost(1, 1500);
        $sale = SessionSales::complete('mixed-costs', ['drink-one' => 1, 'noodle-one' => 1], 'Cash', '35', false);
        $sales = SessionSales::all();
        unset($sales[$sale['id']]['lines'][1]['cost']);
        session()->put('demo.sales', $sales);
        $this->setCost(2, 1000);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(1, $summary['uncostedSalesLines']);
        $this->assertSame(2000, $summary['costedRevenue']);
        $this->assertSame(500, $summary['costedProfit']);
        $this->assertNull($summary['grossProfit']);
        $this->assertNull($summary['grossMargin']);
        $this->assertNull($summary['operatingProfit']);
        $this->get('/reports')->assertOk()->assertSee('1 sale/return line has unknown cost')->assertSee('Unknown cost');
    }

    public function test_zero_cost_is_known_and_sales_below_cost_show_a_loss(): void
    {
        $this->setCost(1, 2500);
        $this->setCost(2, 0);
        $sale = SessionSales::complete('loss', ['drink-one' => 1, 'noodle-one' => 1], 'Maya', '35', true);
        $this->assertSame(0, $sale['lines'][1]['cost']);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(0, $summary['uncostedSalesLines']);
        $this->assertSame(1000, $summary['grossProfit']);
        $this->assertSame(-500, $summary['products'][0]['profit']);
        SessionSales::complete('more-loss', ['drink-one' => 3], 'Cash', '60', false);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(-500, $summary['grossProfit']);
        $this->assertSame(-5.3, $summary['grossMargin']);
        $this->get('/dashboard')->assertOk()->assertSee('-5.00')->assertSee('-5.3%');
    }

    public function test_credit_profit_excludes_loans_repayments_and_drawer_differences(): void
    {
        $this->setCost(1, 1500);
        SessionCredit::addCustomer('Maria', '');
        $customer = array_key_first(SessionCredit::customers());
        SessionSales::complete('credit', ['drink-pack' => 1], 'Credit', '', false, $customer);
        $groceryDebt = array_key_first(SessionCredit::debts());
        SessionCredit::cashLoan('loan', $customer, '100', 'Cash', '', '');
        SessionCredit::repay('repay', $groceryDebt, '20', 'Cash', '');
        SessionCash::expense('expense', '5', 'Supplies', 'Cash', 'Receipt paper');
        SessionLosses::record('loss', 'drink-one', 1, 'Damage', 'Broken bottle', '15');
        $shift = SessionCash::active();
        SessionCash::close($shift['id'], 'closing', '914', 'One unit short');
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(11000, $summary['revenue']);
        $this->assertSame(2000, $summary['collections']);
        $this->assertSame(2000, $summary['grossProfit']);
        $this->assertSame(0, $summary['operatingProfit']);
        $this->assertSame(100, $summary['cashShortages']);
    }

    public function test_unknown_loss_cost_withholds_operating_profit_only(): void
    {
        $this->setCost(1, 1500);
        SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false);
        SessionLosses::record('loss', 'noodle-one', 1, 'Damage', 'Damaged packet with unknown cost', '');
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(500, $summary['grossProfit']);
        $this->assertSame(25.0, $summary['grossMargin']);
        $this->assertNull($summary['operatingProfit']);
        $this->get('/reports')->assertOk()->assertSee('Operating profit is withheld');
    }

    public function test_profit_uses_the_selected_local_calendar_window(): void
    {
        $this->setCost(1, 1500);
        $this->travelTo(Carbon::parse('2026-10-01 16:30:00', 'UTC'));
        SessionSales::complete('older', ['drink-one' => 1], 'Cash', '20', false);
        $this->travelTo(Carbon::parse('2026-10-09 08:30:00', 'UTC'));
        SessionSales::complete('today', ['drink-one' => 2], 'Cash', '40', false);
        $this->assertSame(1000, SessionAnalytics::summarize(7)['grossProfit']);
        $this->assertSame(1500, SessionAnalytics::summarize(30)['grossProfit']);
        $this->travelBack();
    }
}
