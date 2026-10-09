<?php

namespace Tests\Feature;

use App\Livewire\Cash;
use App\Livewire\Checkout;
use App\Support\DemoCatalogue;
use App\Support\SessionAnalytics;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionSales;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CashTest extends TestCase
{
    private function customer(): string
    {
        SessionCredit::addCustomer('Maria', '');

        return array_key_first(SessionCredit::customers());
    }

    public function test_opening_counts_are_exact_allow_zero_and_only_one_session_can_be_open(): void
    {
        Livewire::test(Cash::class)->set('opening', '')->call('open')->assertHasErrors('opening')
            ->set('opening', '0.001')->call('open')->assertHasErrors('opening')
            ->set('opening', '0')->call('open')->assertHasNoErrors()->assertSee('SHIFT-0001');
        $first = SessionCash::active();
        $this->assertSame(0, $first['opening']);
        Livewire::test(Cash::class)->set('opening', '20')->call('open')->assertHasErrors('cashSession');
        $this->assertCount(1, SessionCash::all());
    }

    public function test_checkout_needs_an_open_session_but_receipt_preview_does_not(): void
    {
        Livewire::test(Checkout::class)->call('add', 'drink-one')->set('received', '20')->call('preview')->assertHasNoErrors()
            ->call('complete')->assertHasErrors('cashSession');
        Livewire::test(Checkout::class)->call('add', 'drink-one')->set('method', 'GCash')->set('confirmed', true)
            ->call('complete')->assertHasErrors('cashSession');
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionSales::all());
        $this->assertEmpty(SessionCash::movements());
        $this->get('/checkout')->assertOk()->assertSee('Open a cash session');
    }

    public function test_automatic_and_manual_movements_reconcile_cash_and_keep_digital_and_credit_separate(): void
    {
        $cashSession = SessionCash::open('open', '500', 'Starting float');
        $customer = $this->customer();
        $sale = SessionSales::complete('cash', ['drink-one' => 1], 'Cash', '100', false);
        SessionSales::complete('qr', ['noodle-pack' => 1], 'GCash', '', true);
        SessionSales::complete('credit', ['drink-pack' => 1], 'Credit', '', false, $customer);
        SessionCredit::cashLoan('loan', $customer, '100', 'Cash', '', '');
        $loanId = array_key_last(SessionCredit::debts());
        SessionCredit::repay('repay', $loanId, '25', 'Cash', '');
        SessionCash::recordMovement('top-up', 'in', '50', 'Owner float top-up');
        SessionCash::recordMovement('withdraw', 'out', '10', 'Owner withdrawal');
        SessionCash::expense('cash-expense', '12.50', 'Supplies', 'Cash', 'Receipt paper');
        SessionCash::expense('qr-expense', '10', 'Transport', 'GCash', 'Supplier trip');
        $summary = SessionCash::summary($cashSession['id']);
        $this->assertSame(8000, $sale['change']);
        $this->assertSame($cashSession['id'], $sale['cashSessionId']);
        $this->assertSame(9500, $summary['methods']['Cash']['in']);
        $this->assertSame(12250, $summary['methods']['Cash']['out']);
        $this->assertSame(47250, $summary['expected']);
        $this->assertSame(7000, $summary['methods']['GCash']['in']);
        $this->assertSame(1000, $summary['methods']['GCash']['out']);
        $this->assertSame(11000, $summary['credit']);
        $this->assertSame(3, $summary['transactions']);
        $this->assertSame(1250, $summary['sources']['Expense']['out']);
        $this->assertSame(8, count(SessionCash::movements()));
        $this->assertCount(2, SessionCash::expenses());
        $this->get('/cash')->assertOk()->assertSee('472.50')->assertSee('Drawer breakdown');
    }

    public function test_expenses_and_shortages_appear_in_analytics_without_changing_sales_revenue(): void
    {
        $cashSession = SessionCash::open('open', '100', '');
        SessionCash::expense('expense', '10', 'Utilities', 'Cash', 'Electricity paid');
        SessionCash::close($cashSession['id'], 'close', '88', 'Short by two');
        $second = SessionCash::open('second', '50', '');
        SessionCash::close($second['id'], 'second-close', '53', 'Extra three counted');
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(1000, $summary['expenses']);
        $this->assertSame(['Utilities' => 1000], $summary['expenseCategories']);
        $this->assertSame(200, $summary['cashShortages']);
        $this->assertSame(300, $summary['cashSurpluses']);
        $this->assertSame(0, $summary['revenue']);
        $this->assertSame(0, $summary['collections']);
        $this->assertSame(0, $summary['lossUnits']);
        $this->get('/reports')->assertOk()->assertSee('Recorded expenses')->assertSee('Cash-session differences');
    }

    public function test_closing_requires_a_reason_for_difference_and_preserves_signed_difference(): void
    {
        SessionCash::open('open', '100.05', '');
        $component = Livewire::test(Cash::class)->set('counted', '99.05')->call('close')->assertHasErrors('closeNotes');
        $this->assertSame('Open', SessionCash::active()['status']);
        $component->set('closeNotes', '  ')->call('close')->assertHasErrors('closeNotes')
            ->set('closeNotes', 'One unit short')->call('close')->assertHasNoErrors()->assertSee('Shortage');
        $closed = array_values(SessionCash::all())[0];
        $this->assertSame(-100, $closed['difference']);
        $this->assertSame(9905, $closed['counted']);
        $this->assertNull(SessionCash::active());
        $this->assertSame(10005, $closed['summary']['expected']);
    }

    public function test_balanced_zero_closing_is_valid_and_invalid_counts_do_not_close(): void
    {
        SessionCash::open('open', '0', '');
        Livewire::test(Cash::class)->set('counted', '-1')->call('close')->assertHasErrors('counted')
            ->set('counted', '1.001')->call('close')->assertHasErrors('counted')
            ->set('counted', '0')->call('close')->assertHasNoErrors()->assertSee('Balanced');
        $this->assertSame(0, array_values(SessionCash::all())[0]['difference']);
    }

    public function test_cash_overdraw_and_invalid_movements_leave_all_source_records_unchanged(): void
    {
        $cashSession = SessionCash::open('open', '10', '');
        $customer = $this->customer();
        Livewire::test(Cash::class)->set('direction', 'out')->set('amount', '10.01')->set('reason', 'Owner withdrawal')
            ->call('recordMovement')->assertHasErrors('amount')
            ->set('amount', '0')->call('recordMovement')->assertHasErrors('amount')
            ->set('amount', '1')->set('reason', ' ')->call('recordMovement')->assertHasErrors('reason')
            ->set('expenseAmount', '20')->set('expenseNotes', 'Receipt paper')->call('recordExpense')->assertHasErrors('expenseAmount');
        try {
            SessionCredit::cashLoan('loan', $customer, '20', 'Cash', '', '');
            $this->fail('An overdrawn cash loan should be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('amount', $error->errors());
        }
        $this->assertEmpty(SessionCredit::debts());
        $this->assertEmpty(SessionCash::expenses());
        $this->assertEmpty(SessionCash::movements());
        $this->assertSame(1000, SessionCash::summary($cashSession['id'])['expected']);
    }

    public function test_cash_loan_and_repayment_without_open_drawer_do_not_change_customer_debt(): void
    {
        $customer = $this->customer();
        SessionCredit::cashLoan('digital-loan', $customer, '20', 'Maya', '', '');
        $debtId = array_key_first(SessionCredit::debts());
        try {
            SessionCredit::repay('repay', $debtId, '5', 'Cash', '');
            $this->fail('Cash repayment needs an open drawer.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('cashSession', $error->errors());
        }
        try {
            SessionCredit::cashLoan('cash-loan', $customer, '10', 'Cash', '', '');
            $this->fail('Cash loan needs an open drawer.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('cashSession', $error->errors());
        }
        $this->assertSame(2000, SessionCredit::debts()[$debtId]['balance']);
        $this->assertCount(1, SessionCredit::debts());
        $this->assertEmpty(SessionCredit::repayments());
        $this->assertCount(1, SessionCash::movements());
        $this->assertNull(SessionCash::movements()[0]['cashSessionId']);
    }

    public function test_repeat_events_do_not_duplicate_opening_movements_expenses_or_closing(): void
    {
        $cashSession = SessionCash::open('open', '100', '');
        $retry = SessionCash::open('open', '100', '');
        $this->assertSame($cashSession['id'], $retry['id']);
        SessionCash::recordMovement('move', 'in', '5', 'Float top-up');
        SessionCash::recordMovement('move', 'in', '5', 'Float top-up');
        SessionCash::expense('expense', '10', 'Supplies', 'Cash', 'Receipt paper');
        SessionCash::expense('expense', '10', 'Supplies', 'Cash', 'Receipt paper');
        SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false);
        SessionSales::complete('sale', ['drink-one' => 1], 'Cash', '20', false);
        $closed = SessionCash::close($cashSession['id'], 'close', '115', '');
        $next = SessionCash::open('next', '115', '');
        $retryClosed = SessionCash::close($cashSession['id'], 'close', '115', '');
        $this->assertSame($closed, $retryClosed);
        $this->assertSame($next['id'], SessionCash::active()['id']);
        $this->assertCount(3, SessionCash::movements());
        $this->assertCount(1, SessionCash::expenses());
        $this->assertCount(2, SessionCash::all());
        $this->assertSame(11500, $closed['summary']['expected']);
        $this->assertSame(11500, SessionCash::summary($next['id'])['expected']);
    }

    public function test_old_tabs_cannot_close_or_record_against_a_new_session(): void
    {
        $first = SessionCash::open('first', '100', '');
        $staleCash = Livewire::test(Cash::class);
        $staleCheckout = Livewire::test(Checkout::class)->call('add', 'drink-one')->set('received', '20');
        SessionCash::close($first['id'], 'close', '100', '');
        $second = SessionCash::open('second', '50', '');
        $staleCash->set('counted', '50')->call('close')->assertHasErrors('cashSession')
            ->set('amount', '5')->set('reason', 'Owner top-up')->call('recordMovement')->assertHasErrors('cashSession');
        $staleCheckout->call('complete')->assertHasErrors('cashSession');
        $this->assertSame($second['id'], SessionCash::active()['id']);
        $this->assertEmpty(SessionSales::all());
        $this->assertEmpty(SessionCash::movements());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
    }

    public function test_closed_summary_is_unchanged_by_a_later_session_and_old_preview_sales_are_excluded(): void
    {
        session()->put('demo.sales', ['old' => ['id' => 'old', 'eventId' => 'old', 'method' => 'Cash', 'total' => 99999, 'time' => now()->toIso8601String()]]);
        $first = SessionCash::open('first', '20', '');
        $this->assertSame(2000, SessionCash::summary($first['id'])['expected']);
        $this->assertSame(0, SessionCash::summary($first['id'])['transactions']);
        $closed = SessionCash::close($first['id'], 'first-close', '20', '');
        SessionCash::open('second', '50', '');
        SessionSales::complete('new-sale', ['drink-one' => 1], 'Cash', '20', false);
        $this->assertSame($closed['summary'], SessionCash::summary($first['id']));
        Livewire::test(Cash::class)->call('viewSession', $first['id'])->assertSee('SHIFT-0001 · Closing summary');
    }

    public function test_digital_expense_can_be_recorded_without_a_drawer_and_is_not_reassigned_later(): void
    {
        Livewire::test(Cash::class)->set('expenseAmount', '30.25')->set('method', 'Maya')
            ->set('expenseNotes', 'Shop transport expense')->call('recordExpense')->assertHasNoErrors();
        $expense = array_values(SessionCash::expenses())[0];
        $this->assertSame(3025, $expense['amount']);
        $this->assertNull($expense['cashSessionId']);
        $active = SessionCash::open('open', '0', '');
        $this->assertSame(0, SessionCash::summary($active['id'])['methods']['Maya']['out']);
        $this->assertSame(3025, SessionAnalytics::summarize(7)['expenses']);
    }
}
