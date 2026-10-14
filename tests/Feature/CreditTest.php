<?php

namespace Tests\Feature;

use App\Livewire\Checkout;
use App\Livewire\Credit;
use App\Support\DemoCatalogue;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionSales;
use Livewire\Livewire;
use Tests\TestCase;

class CreditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SessionCash::open('test-opening', '1000', 'Test float');
    }

    private function customer(): string
    {
        SessionCredit::addCustomer('Maria Santos', '09123456789');

        return array_key_first(SessionCredit::customers());
    }

    public function test_customer_creation_trims_values_and_rejects_duplicate_names(): void
    {
        Livewire::test(Credit::class)->set('name', '  Maria Santos  ')->set('phone', ' 09123456789 ')
            ->call('addCustomer')->assertHasNoErrors()->assertSet('name', '');
        $this->assertSame('Maria Santos', array_values(SessionCredit::customers())[0]['name']);
        $this->assertSame('09123456789', array_values(SessionCredit::customers())[0]['phone']);
        Livewire::test(Credit::class)->set('name', 'maria santos')->call('addCustomer')->assertHasErrors('name');
        Livewire::test(Credit::class)->set('name', '  ')->call('addCustomer')->assertHasErrors('name');
        $this->assertCount(1, SessionCredit::customers());
    }

    public function test_grocery_credit_preview_and_completion_create_one_linked_debt(): void
    {
        $id = $this->customer();
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-pack')->set('method', 'Credit')
            ->set('customerId', $id)->set('dueDate', now('Asia/Shanghai')->addDay()->toDateString())
            ->call('preview')->assertHasNoErrors()->assertSee('Amount owed: ₱110.00');
        $this->assertEmpty(SessionCredit::debts());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $checkout->call('complete')->assertHasNoErrors()->assertSet('cart', []);
        $debt = array_values(SessionCredit::debts())[0];
        $this->assertSame(66, DemoCatalogue::find(1)['stock']);
        $this->assertSame('Grocery credit', $debt['type']);
        $this->assertSame(11000, $debt['balance']);
        $this->assertSame($checkout->get('receipt')['id'], $debt['saleId']);
        $this->assertFalse($checkout->get('receipt')['paymentConfirmed']);
        $this->get('/credit')->assertOk()->assertSee('Maria Santos')->assertSee('DEBT-00001');
    }

    public function test_invalid_customer_due_date_and_insufficient_stock_do_not_write_credit_records(): void
    {
        $checkout = Livewire::test(Checkout::class)->call('add', 'drink-one')->set('method', 'Credit');
        $checkout->call('complete')->assertHasErrors('customerId');
        $id = $this->customer();
        $checkout->set('customerId', $id)->set('dueDate', 'bad-date')->call('complete')->assertHasErrors('dueDate');
        $checkout->set('dueDate', now('Asia/Shanghai')->subDay()->toDateString())->call('complete')->assertHasErrors('dueDate');
        $checkout->set('dueDate', '')->call('setQuantity', 'drink-case', 4)->call('complete')->assertHasErrors('cart');
        $this->assertEmpty(SessionCredit::debts());
        $this->assertEmpty(SessionSales::all());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
    }

    public function test_cash_loans_are_exact_principal_records_without_merchandise_or_stock_changes(): void
    {
        $id = $this->customer();
        Livewire::test(Credit::class)->set('customerId', $id)->set('amount', '250.05')->set('method', 'GCash')
            ->set('notes', 'Emergency expense')->call('lend')->assertHasNoErrors()->assertSet('amount', '');
        $debt = array_values(SessionCredit::debts())[0];
        $this->assertSame(25005, $debt['principal']);
        $this->assertSame(25005, $debt['balance']);
        $this->assertSame('Cash loan', $debt['type']);
        $this->assertSame('GCash', $debt['method']);
        $this->assertNull($debt['saleId']);
        $this->assertEmpty(SessionSales::all());
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        Livewire::test(Credit::class)->set('customerId', $id)->set('amount', '0')->call('lend')->assertHasErrors('amount')
            ->set('amount', '10.001')->call('lend')->assertHasErrors('amount')
            ->set('amount', '-10')->call('lend')->assertHasErrors('amount');
        $this->assertCount(1, SessionCredit::debts());
    }

    public function test_partial_and_full_repayment_reduce_balance_without_new_sales_or_stock_changes(): void
    {
        $id = $this->customer();
        SessionSales::complete('credit-sale', ['drink-one' => 1], 'Credit', '', false, $id);
        $debtId = array_key_first(SessionCredit::debts());
        Livewire::test(Credit::class)->set('debtId', $debtId)->set('repaymentAmount', '5.25')
            ->set('repaymentMethod', 'Maya')->set('reference', 'verified-123')->call('repay')->assertHasNoErrors();
        $this->assertSame(1475, SessionCredit::debts()[$debtId]['balance']);
        $this->assertSame(525, SessionCredit::repayments()[0]['amount']);
        Livewire::test(Credit::class)->call('selectDebt', $debtId)->assertSet('repaymentAmount', '14.75')
            ->call('repay')->assertHasNoErrors()->assertSee('Paid');
        $this->assertSame(0, SessionCredit::debts()[$debtId]['balance']);
        $this->assertCount(1, SessionSales::all());
        $this->assertCount(2, SessionCredit::repayments());
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
    }

    public function test_invalid_and_excessive_repayment_leave_balances_unchanged(): void
    {
        $id = $this->customer();
        SessionCredit::cashLoan('loan', $id, '100', 'Cash', '', '');
        $debtId = array_key_first(SessionCredit::debts());
        Livewire::test(Credit::class)->set('debtId', $debtId)->set('repaymentAmount', '100.01')->call('repay')->assertHasErrors('repaymentAmount')
            ->set('repaymentAmount', '0')->call('repay')->assertHasErrors('repaymentAmount')
            ->set('repaymentAmount', '10')->set('repaymentMethod', 'Unknown')->call('repay')->assertHasErrors('method')
            ->set('repaymentMethod', 'Cash')->set('debtId', 'missing')->call('repay')->assertHasErrors('debtId');
        $this->assertSame(10000, SessionCredit::debts()[$debtId]['balance']);
        $this->assertEmpty(SessionCredit::repayments());
    }

    public function test_retry_events_do_not_duplicate_loans_credit_sales_or_repayments(): void
    {
        $id = $this->customer();
        SessionCredit::cashLoan('loan', $id, '100', 'Cash', '', '');
        SessionCredit::cashLoan('loan', $id, '100', 'Cash', '', '');
        $debtId = array_key_first(SessionCredit::debts());
        SessionCredit::repay('repay', $debtId, '20', 'Cash', '');
        SessionCredit::repay('repay', $debtId, '20', 'Cash', '');
        SessionSales::complete('credit', ['drink-one' => 1], 'Credit', '', false, $id);
        SessionSales::complete('credit', ['drink-one' => 1], 'Credit', '', false, $id);
        $this->assertSame(8000, SessionCredit::debts()[$debtId]['balance']);
        $this->assertCount(2, SessionCredit::debts());
        $this->assertCount(1, SessionCredit::repayments());
        $this->assertCount(1, SessionSales::all());
        $this->assertSame(71, DemoCatalogue::find(1)['stock']);
    }
}
