<?php

namespace Tests\Feature;

use App\Livewire\Analytics;
use App\Livewire\Losses;
use App\Support\DemoCatalogue;
use App\Support\SessionAnalytics;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionLosses;
use App\Support\SessionSales;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AnalyticsLossesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SessionCash::open('test-opening', '1000', 'Test float');
    }

    public function test_losses_convert_packages_and_keep_unknown_cost_separate_from_zero_cost(): void
    {
        Livewire::test(Losses::class)->set('optionId', 'drink-pack')->set('quantity', 2)
            ->set('notes', 'Packages damaged during handling')->set('reason', 'Damage')->set('unitCost', '60.25')
            ->call('record')->assertHasNoErrors()->assertSet('optionId', '');
        $loss = SessionLosses::all()[0];
        $this->assertSame(12, $loss['baseQuantity']);
        $this->assertSame(12050, $loss['totalCost']);
        $this->assertSame(72, $loss['before']);
        $this->assertSame(60, $loss['after']);
        SessionLosses::record('unknown', 'drink-one', 1, 'Unpaid consumption', 'Consumed without paying', '');
        SessionLosses::record('free', 'drink-one', 1, 'Other', 'Free sample damaged', '0');
        $this->assertNull(SessionLosses::all()[1]['totalCost']);
        $this->assertSame(0, SessionLosses::all()[2]['totalCost']);
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(14, $summary['lossUnits']);
        $this->assertSame(12050, $summary['lossCost']);
        $this->assertSame(1, $summary['uncosted']);
        $this->assertEmpty(SessionSales::all());
        $this->assertEmpty(SessionCredit::debts());
        $this->get('/losses')->assertOk()->assertSee('Packages damaged during handling')->assertSee('Unknown');
    }

    public function test_invalid_loss_and_stock_overdraw_do_not_deduct_inventory(): void
    {
        Livewire::test(Losses::class)->set('optionId', 'drink-case')->set('quantity', 4)->set('notes', 'Missing stock')
            ->call('record')->assertHasErrors('quantity')->set('quantity', 1)->set('reason', 'Invalid')->call('record')->assertHasErrors('reason')
            ->set('reason', 'Damage')->set('unitCost', '10.001')->call('record')->assertHasErrors('unitCost')
            ->set('unitCost', '')->set('notes', '')->call('record')->assertHasErrors('notes')
            ->set('notes', 'Missing stock')->set('optionId', 'unknown')->call('record')->assertHasErrors('optionId');
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionLosses::all());
    }

    public function test_repeated_loss_event_deducts_only_once(): void
    {
        SessionLosses::record('repeat', 'drink-pack', 1, 'Unpaid consumption', 'Consumed without paying', '');
        SessionLosses::record('repeat', 'drink-pack', 1, 'Unpaid consumption', 'Consumed without paying', '');
        $this->assertSame(66, DemoCatalogue::find(1)['stock']);
        $this->assertCount(1, SessionLosses::all());
    }

    public function test_analytics_separates_sales_collections_loans_receivables_and_losses(): void
    {
        SessionCredit::addCustomer('Maria', '');
        $customer = array_key_first(SessionCredit::customers());
        SessionSales::complete('cash', ['drink-one' => 1], 'Cash', '50', false);
        SessionSales::complete('qr', ['noodle-pack' => 1], 'GCash', '70', true);
        SessionSales::complete('credit', ['drink-pack' => 1], 'Credit', '', false, $customer);
        $groceryDebt = array_key_first(SessionCredit::debts());
        SessionCredit::cashLoan('loan', $customer, '100', 'Cash', '', '');
        $loanDebt = array_key_last(SessionCredit::debts());
        SessionCredit::repay('grocery-repayment', $groceryDebt, '20', 'Cash', '');
        SessionCredit::repay('loan-repayment', $loanDebt, '30', 'Maya', '');
        SessionLosses::record('costed', 'drink-one', 2, 'Damage', 'Broken bottles', '18');
        SessionLosses::record('uncosted', 'noodle-pack', 1, 'Unpaid consumption', 'Consumed without paying', '');
        $product = DemoCatalogue::find(1);
        $product['reorder'] = 70;
        DemoCatalogue::save($product);
        $data = SessionAnalytics::summarize(7);
        $this->assertSame(20000, $data['revenue']);
        $this->assertSame(3, $data['transactions']);
        $this->assertSame(6667, $data['average']);
        $this->assertSame(9000, $data['immediate']);
        $this->assertSame(5000, $data['repaid']);
        $this->assertSame(14000, $data['collections']);
        $this->assertSame(16000, $data['outstanding']);
        $this->assertSame(10000, $data['cashLent']);
        $this->assertSame(0, $data['overdue']);
        $this->assertSame(7, $data['lossUnits']);
        $this->assertSame(3600, $data['lossCost']);
        $this->assertSame(1, $data['uncosted']);
        $this->assertSame(7, $data['products'][0]['units']);
        $this->assertSame(13000, $data['products'][0]['revenue']);
        $this->assertSame(1, $data['lowStock'][0]['id']);
        $this->assertSame(16000, $data['customers'][0]['balance']);
        Livewire::test(Analytics::class)->assertSee('200.00')->assertSee('140.00')->assertSee('Daily sales and money collections')
            ->assertSee('1 loss entry has unknown cost');
    }

    public function test_date_windows_use_local_midnight_and_keep_outstanding_balances_all_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 15:30:00', 'UTC'));
        SessionSales::complete('old', ['drink-one' => 1], 'Cash', '20', false);
        SessionCredit::addCustomer('Maria', '');
        $id = array_key_first(SessionCredit::customers());
        SessionCredit::cashLoan('old-loan', $id, '100', 'Cash', '2026-10-04', '');
        SessionLosses::record('old-loss', 'drink-one', 1, 'Damage', 'Broken bottle', '10');
        $this->travelTo(Carbon::parse('2026-10-03 16:30:00', 'UTC'));
        SessionSales::complete('boundary', ['drink-one' => 1], 'Cash', '20', false);
        $this->travelTo(Carbon::parse('2026-10-09 16:30:00', 'UTC'));
        SessionSales::complete('today', ['drink-one' => 1], 'Cash', '20', false);
        $data = SessionAnalytics::summarize(7);
        $this->assertSame('2026-10-04', $data['daily'][0]['date']);
        $this->assertSame('2026-10-10', $data['daily'][6]['date']);
        $this->assertSame(4000, $data['revenue']);
        $this->assertSame(2000, $data['daily'][0]['sales']);
        $this->assertSame(2000, $data['daily'][6]['sales']);
        $this->assertSame(10000, $data['overdue']);
        $this->assertSame(10000, $data['outstanding']);
        $this->assertSame(0, $data['cashLent']);
        $this->assertSame(0, $data['lossUnits']);
        $month = SessionAnalytics::summarize(30);
        $this->assertSame(6000, $month['revenue']);
        $this->assertSame(10000, $month['cashLent']);
        $this->assertSame(1, $month['lossUnits']);
        Livewire::test(Analytics::class)->set('days', 30)->assertSee('60.00');
        $this->travelBack();
    }

    public function test_empty_dashboard_and_reports_render_without_invented_revenue_or_profit(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee('Your sales story starts here')->assertDontSee('Illustrative figures')->assertDontSee('4,800.00');
        $this->get('/reports')->assertOk()->assertSee('Estimated gross profit')->assertSee('No outstanding customer debt');
        $this->assertSame(0, SessionAnalytics::summarize(90)['revenue']);
        $this->assertCount(7, SessionAnalytics::summarize(999)['daily']);
    }

    public function test_sold_out_sample_products_render_in_low_stock_watch_without_a_configured_threshold(): void
    {
        SessionSales::complete('sold-out', ['drink-case' => 3], 'Cash', '1260', false);
        $this->assertSame(0, DemoCatalogue::find(1)['stock']);
        $this->assertSame(1, SessionAnalytics::summarize(7)['lowStock'][0]['id']);
        $this->get('/dashboard')->assertOk()->assertSee('Low-stock watch')->assertSee('0 bottles');
    }
}
