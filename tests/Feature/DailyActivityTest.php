<?php

namespace Tests\Feature;

use App\Livewire\Analytics;
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
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class DailyActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Shanghai')->startOfDay()->addHours(12));
        SessionCash::open('opening', '1000', '');
    }

    public function test_mixed_day_reconciles_discounted_sales_refunds_and_both_repayment_types(): void
    {
        SessionCredit::addCustomer('Daily customer', '');
        $customer = array_key_first(SessionCredit::customers());
        $cash = SessionSales::complete('cash', ['drink-one' => 2], 'Cash', '50', false, '', '', null, false, '1.01', 'Owner promotion');
        SessionSales::complete('digital', ['noodle-pack' => 1], 'GCash', '70', true);
        $credit = SessionSales::complete('credit', ['drink-pack' => 1], 'Credit', '', false, $customer);
        $groceryDebt = array_key_first(SessionCredit::debts());
        SessionCredit::repay('grocery-repay', $groceryDebt, '30', 'Cash', '');
        SessionCredit::cashLoan('loan', $customer, '100', 'Cash', '', '');
        SessionCredit::repay('loan-repay', array_key_last(SessionCredit::debts()), '20', 'Maya', '');
        SessionReturns::complete('cash-return', $cash['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'One unopened bottle returned');
        SessionReturns::complete('credit-return', $credit['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Maya', true, 'Full unopened pack returned');
        SessionCash::expense('expense', '10', SessionCash::CATEGORIES[0], 'Cash', 'Sample paid expense');
        SessionLosses::record('loss', 'drink-one', 1, 'Unpaid consumption', 'One bottle consumed', '');
        $before = [SessionSales::all(), SessionReturns::all(), SessionCredit::debts(), SessionCredit::repayments(), SessionCash::all(), DemoCatalogue::products()];
        $day = SessionAnalytics::dailyActivity(now('Asia/Shanghai')->toDateString(), 7);
        $this->assertSame(7, $day['count']);
        $this->assertSame(8950, $day['sales']);
        $this->assertSame(10950, $day['collections']);
        $this->assertContains('Loan repayment', array_column($day['rows'], 'type'));
        $this->assertContains('Grocery repayment', array_column($day['rows'], 'type'));
        $this->assertNotContains('Cash loan', array_column($day['rows'], 'type'));
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame($summary['revenue'], $day['sales']);
        $this->assertSame($summary['collections'], $day['collections']);
        $this->assertSame($before, [SessionSales::all(), SessionReturns::all(), SessionCredit::debts(), SessionCredit::repayments(), SessionCash::all(), DemoCatalogue::products()]);
        Livewire::test(Analytics::class)->call('viewDay', $day['date'])->assertHasNoErrors()->assertSee('Loan repayment')->assertSee('Grocery repayment')
            ->assertSee('Original sale '.$credit['number'])->assertSee('CSV downloads and print views above still use the full selected report period.');
    }

    public function test_return_of_older_sale_appears_on_return_day_with_negative_totals(): void
    {
        $sale = SessionSales::complete('old-sale', ['drink-pack' => 1], 'Cash', '110', false);
        $sales = SessionSales::all();
        $sales[$sale['id']]['time'] = now()->subDays(10)->toIso8601String();
        PosStore::put('demo.sales', $sales);
        $return = SessionReturns::complete('returned', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'GCash', true, 'Unopened old purchase returned');
        $day = SessionAnalytics::dailyActivity(now('Asia/Shanghai')->toDateString(), 7);
        $this->assertSame(1, $day['count']);
        $this->assertSame(-11000, $day['sales']);
        $this->assertSame(-11000, $day['collections']);
        $this->assertSame('GCash', $day['rows'][0]['method']);
        $this->assertSame($return['number'], $day['rows'][0]['reference']);
        $this->assertSame(-11000, SessionAnalytics::summarize(7)['revenue']);
    }

    public function test_utc_records_are_grouped_by_shop_calendar_date(): void
    {
        $today = now('Asia/Shanghai')->startOfDay();
        $first = SessionSales::complete('before-midnight', ['drink-one' => 1], 'Cash', '20', false);
        $second = SessionSales::complete('after-midnight', ['drink-one' => 1], 'Cash', '20', false);
        $sales = SessionSales::all();
        $sales[$first['id']]['time'] = $today->copy()->subMinute()->utc()->toIso8601String();
        $sales[$second['id']]['time'] = $today->copy()->addMinute()->utc()->toIso8601String();
        PosStore::put('demo.sales', $sales);
        $current = SessionAnalytics::dailyActivity($today->toDateString(), 7);
        $previous = SessionAnalytics::dailyActivity($today->copy()->subDay()->toDateString(), 7);
        $this->assertSame($second['number'], $current['rows'][0]['reference']);
        $this->assertSame($first['number'], $previous['rows'][0]['reference']);
        $this->assertSame(2000, $current['sales']);
        $this->assertSame(2000, $previous['sales']);
        foreach (SessionAnalytics::summarize(7)['daily'] as $daily) {
            if (in_array($daily['date'], [$current['date'], $previous['date']], true)) {
                $this->assertSame(2000, $daily['sales']);
            }
        }
    }

    public function test_invalid_future_and_outside_period_dates_keep_the_previous_selection(): void
    {
        $today = now('Asia/Shanghai')->toDateString();
        $component = Livewire::test(Analytics::class)->call('viewDay', $today)->assertHasNoErrors();
        foreach (['not-a-date', now()->addDay()->toDateString(), now()->subDays(7)->toDateString(), '2026-02-30'] as $invalid) {
            $component->call('viewDay', $invalid)->assertHasErrors('selectedDay')->assertSet('selectedDay', $today);
        }
        $component->set('days', 30)->assertSet('selectedDay', '')->call('viewDay', now()->subDays(20)->toDateString())->assertHasNoErrors();
        $component->call('clearDay')->assertSet('selectedDay', '');
    }

    public function test_open_day_shows_saved_amounts_after_product_price_changes(): void
    {
        SessionSales::complete('saved-sale', ['drink-one' => 1], 'Cash', '20', false);
        $product = DemoCatalogue::find(1);
        $product['options'][0]['price'] = 99999;
        DemoCatalogue::save($product);
        $date = now('Asia/Shanghai')->toDateString();
        $this->assertSame(2000, SessionAnalytics::dailyActivity($date, 7)['sales']);
        Livewire::test(Analytics::class)->call('viewDay', $date)->assertHasNoErrors()->assertSee('WM-000001');
        $this->assertCount(1, SessionSales::all());
    }

    public function test_empty_day_and_clock_rollover_clear_stale_selection_without_writes(): void
    {
        $today = now('Asia/Shanghai')->toDateString();
        $component = Livewire::test(Analytics::class)->call('viewDay', $today)->assertSee('No confirmed sales, returns or repayments on this day.');
        $this->assertSame(0, SessionAnalytics::dailyActivity($today, 7)['count']);
        $oldest = now('Asia/Shanghai')->subDays(6)->toDateString();
        $component->call('viewDay', $oldest)->assertHasNoErrors();
        $this->travel(1)->days();
        $component->call('$refresh')->assertSet('selectedDay', '');
        $this->assertSame([], SessionSales::all());
    }

    public function test_customer_labels_are_escaped_and_guests_cannot_access_daily_activity(): void
    {
        SessionCredit::addCustomer('<script>alert(1)</script>', '');
        SessionSales::complete('credit', ['drink-one' => 1], 'Credit', '', false, array_key_first(SessionCredit::customers()));
        Livewire::test(Analytics::class)->call('viewDay', now('Asia/Shanghai')->toDateString())->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        Auth::forgetGuards();
        $this->get('/reports')->assertRedirect('/login');
        $this->postJson(route('default-livewire.update'), ['components' => []])->assertUnauthorized();
    }

    public function test_calendar_day_profit_expenses_and_losses_use_saved_costs_without_writes(): void
    {
        $batches = SessionBatches::state();
        foreach ($batches['batches'] as &$batch) {
            $batch['totalCost'] = $batch['initialQuantity'] * 400;
        }
        unset($batch);
        PosStore::put(SessionBatches::changes($batches));
        SessionSales::complete('calendar-sale', ['drink-one' => 2], 'Cash', '50', false, '', '', null, false, '1.00', 'Owner promotion');
        SessionCash::expense('calendar-expense', '5', SessionCash::CATEGORIES[0], 'Cash', 'Recorded daily expense');
        SessionLosses::record('calendar-loss', 'drink-one', 1, 'Unpaid consumption', 'One bottle consumed', '');
        $date = now('Asia/Shanghai')->toDateString();
        $before = [DemoCatalogue::products(), SessionSales::all(), SessionCash::all()];
        $day = SessionAnalytics::summarizeDay($date);
        $this->assertSame(3900, $day['revenue']);
        $this->assertSame(3100, $day['grossProfit']);
        $this->assertSame(2200, $day['operatingProfit']);
        $this->assertSame(500, $day['expenses']);
        $this->assertSame(400, $day['lossCost']);
        Livewire::test(Analytics::class, ['calendar' => true])->assertSet('selectedDay', $date)->assertSee('Estimated operating profit')->assertSee('₱22.00')
            ->call('viewDay', now('Asia/Shanghai')->subDay()->toDateString())->assertHasNoErrors()->assertSee('No confirmed sales, returns or repayments on this day.');
        $this->assertSame($before, [DemoCatalogue::products(), SessionSales::all(), SessionCash::all()]);
    }

    public function test_month_grid_handles_leap_year_and_six_week_alignment(): void
    {
        $february = SessionAnalytics::calendarMonth('2024-02');
        $this->assertCount(35, $february['cells']);
        $this->assertCount(29, array_filter($february['cells'], fn (array $cell): bool => $cell['inMonth']));
        $this->assertSame('2024-02-01', $february['cells'][4]['date']);
        $august = SessionAnalytics::calendarMonth('2025-08');
        $this->assertCount(42, $august['cells']);
        $this->assertCount(31, array_filter($august['cells'], fn (array $cell): bool => $cell['inMonth']));
        $this->assertSame('2025-08-01', $august['cells'][5]['date']);
    }

    public function test_calendar_month_navigation_clears_day_and_rejects_future_or_other_month_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 12:00:00', 'Asia/Shanghai'));
        $component = Livewire::test(Analytics::class, ['calendar' => true])->assertSet('month', '2026-01')->assertSet('selectedDay', '2026-01-15');
        $component->call('moveMonth', -1)->assertSet('month', '2025-12')->assertSet('selectedDay', '')
            ->call('viewDay', '2025-12-17')->assertHasNoErrors()->assertSet('selectedDay', '2025-12-17');
        $component->call('viewDay', '2026-01-01')->assertHasErrors('selectedDay')->assertSet('selectedDay', '2025-12-17');
        $component->call('moveMonth', 1)->assertSet('month', '2026-01')->assertSet('selectedDay', '');
        $component->call('moveMonth', 1)->assertHasErrors('month')->assertSet('month', '2026-01');
        $component->call('moveMonth', 99)->assertHasErrors('month');
        $component->call('viewDay', '2026-01-31')->assertHasErrors('selectedDay');
        $component->call('currentMonth')->assertSet('selectedDay', '2026-01-15')->assertHasNoErrors();
    }

    public function test_calendar_with_unknown_acquisition_cost_withholds_profit(): void
    {
        SessionSales::complete('unknown-cost', ['drink-one' => 1], 'Cash', '20', false);
        $date = now('Asia/Shanghai')->toDateString();
        $this->assertNull(SessionAnalytics::summarizeDay($date)['grossProfit']);
        $this->assertNull(SessionAnalytics::summarizeAll()['grossProfit']);
        Livewire::test(Analytics::class, ['calendar' => true])->assertSee('Unknown cost')->assertSee('Profit totals needing those costs are withheld.');
    }

    public function test_overall_totals_include_older_years_and_stay_stable_while_browsing_calendar(): void
    {
        $batches = SessionBatches::state();
        foreach ($batches['batches'] as &$batch) {
            $batch['totalCost'] = $batch['initialQuantity'] * 600;
        }
        unset($batch);
        PosStore::put(SessionBatches::changes($batches));
        $old = SessionSales::complete('old-calendar-sale', ['drink-one' => 1], 'Cash', '20', false);
        SessionSales::complete('today-calendar-sale', ['drink-one' => 1], 'Cash', '20', false);
        $oldDate = now('Asia/Shanghai')->subYears(12);
        $sales = SessionSales::all();
        $sales[$old['id']]['time'] = $oldDate->toIso8601String();
        PosStore::put('demo.sales', $sales);
        $all = SessionAnalytics::summarizeAll();
        $this->assertSame(4000, $all['revenue']);
        $this->assertSame(4000, $all['collections']);
        $this->assertSame(2800, $all['grossProfit']);
        $this->assertSame(2, $all['transactions']);
        $this->assertSame([], $all['daily']);
        $this->assertSame(2000, SessionAnalytics::summarizeDay(now('Asia/Shanghai')->toDateString())['revenue']);
        Livewire::test(Analytics::class, ['calendar' => true])->assertSee('Overall performance')->assertViewHas('overall', fn (array $overall): bool => $overall['revenue'] === 4000)
            ->set('calendarYear', $oldDate->year)->call('viewDay', $oldDate->toDateString())->assertHasNoErrors()
            ->assertViewHas('overall', fn (array $overall): bool => $overall['revenue'] === 4000)
            ->assertViewHas('daySummary', fn (array $day): bool => $day['revenue'] === 2000);
        $this->assertSame($sales, SessionSales::all());
    }

    public function test_month_and_year_dropdowns_reject_invalid_values_and_clamp_future_month_on_year_change(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'Asia/Shanghai'));
        $component = Livewire::test(Analytics::class, ['calendar' => true])->assertSet('calendarYear', 2026)->assertSet('calendarMonthNumber', 10)
            ->set('calendarYear', 2025)->set('calendarMonthNumber', 12)->assertSet('month', '2025-12')->assertSet('selectedDay', '')
            ->set('calendarYear', 2026)->assertSet('month', '2026-10')->assertSet('calendarMonthNumber', 10)->assertHasNoErrors();
        $component->set('calendarMonthNumber', 11)->assertHasErrors('month')->assertSet('month', '2026-10')->assertSet('calendarMonthNumber', 10);
        $component->set('calendarMonthNumber', 13)->assertHasErrors('calendarMonthNumber')->assertSet('month', '2026-10');
        $component->set('calendarYear', 1899)->assertHasErrors('calendarYear')->assertSet('calendarYear', 2026);
        $component->set('calendarYear', 2027)->assertHasErrors('calendarYear')->assertSet('month', '2026-10');
    }
}
