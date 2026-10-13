<?php

namespace Tests\Feature;

use App\Livewire\Payables;
use App\Livewire\Purchasing;
use App\Support\DemoCatalogue;
use App\Support\SessionAnalytics;
use App\Support\SessionCash;
use App\Support\SessionPayables;
use App\Support\SessionPurchasing;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PayablesTest extends TestCase
{
    private function order(): string
    {
        SessionPurchasing::saveSupplier(null, ['name' => 'Central Wholesale', 'contact' => '', 'phone' => '', 'email' => '', 'notes' => '']);
        SessionPurchasing::createOrder(array_key_first(SessionPurchasing::suppliers()), '', [
            ['optionId' => 'drink-case', 'quantity' => 3, 'cost' => '300.25'],
            ['optionId' => 'noodle-pack', 'quantity' => 4, 'cost' => '50.00'],
        ]);

        return array_key_first(SessionPurchasing::orders());
    }

    private function invoice(string $dueDate = ''): array
    {
        SessionPurchasing::receive($this->order(), [
            ['quantity' => 1, 'cost' => '310.50', 'expiry' => ''],
            ['quantity' => 2, 'cost' => '55.25', 'expiry' => ''],
        ], 'INV-001', 'delivery-1', $dueDate);

        return array_values(SessionPayables::invoices())[0];
    }

    private function reject(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_actual_received_goods_create_bills_and_retries_do_not_duplicate_them(): void
    {
        $order = $this->order();
        $this->assertEmpty(SessionPayables::invoices());
        $delivery = [['quantity' => 1, 'cost' => '310.50', 'expiry' => ''], ['quantity' => 0, 'cost' => '50.00', 'expiry' => '']];
        SessionPurchasing::receive($order, $delivery, 'INV-001', 'first');
        SessionPurchasing::receive($order, $delivery, 'INV-001', 'first');
        $this->assertCount(1, SessionPayables::invoices());
        $first = array_values(SessionPayables::invoices())[0];
        $this->assertSame(31050, $first['total']);
        $this->assertSame(31050, $first['balance']);
        $this->assertCount(1, $first['lines']);
        $this->assertSame(session('demo.receiving')[0], $first['lines'][0]);
        $this->assertSame(96, DemoCatalogue::find(1)['stock']);
        SessionPurchasing::receive($order, [['quantity' => 2, 'cost' => '300.25', 'expiry' => ''], ['quantity' => 4, 'cost' => '50.00', 'expiry' => '']], 'INV-001', 'second');
        $this->assertCount(2, SessionPayables::invoices());
        $this->assertSame(80050, array_values(SessionPayables::invoices())[1]['total']);
        $this->assertSame($first, SessionPayables::invoices()[$first['id']]);
        $this->assertSame(111100, array_values(SessionPayables::balances())[0]['balance']);
        $this->assertSame(0, SessionPayables::unlinkedDeliveries());
    }

    public function test_invalid_deliveries_and_due_dates_leave_stock_and_debt_unchanged(): void
    {
        $component = Livewire::test(Purchasing::class)->call('startReceiving', $this->order())
            ->set('deliveries.0.quantity', 1)->set('deliveries.1.quantity', 5)
            ->call('receiveOrder')->assertHasErrors('deliveries.1.quantity');
        $this->assertSame(72, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionPayables::invoices());
        $component->set('deliveries.1.quantity', 1)->set('dueDate', '2026-02-30')->call('receiveOrder')->assertHasErrors('dueDate');
        $this->assertEmpty(SessionPayables::invoices());
        $this->assertEmpty(session('demo.receiving', []));
        $component->set('dueDate', '2026-10-09')->call('receiveOrder')->assertHasNoErrors()->assertDispatched('supplier-delivery-recorded');
        $this->assertSame('2026-10-09', array_values(SessionPayables::invoices())[0]['dueDate']);
    }

    public function test_partial_cash_then_full_digital_payment_preserve_snapshots_and_deduplicate(): void
    {
        $invoice = $this->invoice();
        $shift = SessionCash::open('open', '500', '');
        $first = SessionPayables::pay('pay-1', $invoice['id'], '100.01', 'Cash', true, 'ACK-1', 'Part payment', $shift['id']);
        $this->assertSame(10001, $first['amount']);
        $this->assertSame(32099, $first['balanceAfter']);
        $this->assertSame(39999, SessionCash::summary($shift['id'])['expected']);
        $this->assertSame(10001, SessionCash::summary($shift['id'])['sources']['Supplier payment']['out']);
        $this->assertSame($invoice['lines'], SessionPayables::invoices()[$invoice['id']]['lines']);
        $second = SessionPayables::pay('pay-2', $invoice['id'], '320.99', 'Maya', true, 'MAYA-001', '', $shift['id']);
        $this->assertSame(0, $second['balanceAfter']);
        $this->assertSame(0, SessionPayables::invoices()[$invoice['id']]['balance']);
        $this->assertSame($first, SessionPayables::payments()[$first['id']]);
        $this->assertSame(39999, SessionCash::summary($shift['id'])['expected']);
        $this->assertSame(32099, SessionCash::summary($shift['id'])['methods']['Maya']['out']);
        $this->assertCount(2, SessionCash::movements());
        $this->assertSame(96, DemoCatalogue::find(1)['stock']);
        $this->assertEmpty(SessionCash::expenses());
        $this->assertSame($first, SessionPayables::pay('pay-1', 'missing', '0', 'invalid', false, '', '', 'closed'));
        $this->assertCount(2, SessionPayables::payments());
    }

    public function test_invalid_payments_and_overpayments_are_rejected_atomically(): void
    {
        $invoice = $this->invoice();
        $cases = [['421.01', 'GCash', true, 'REF', 'paymentAmount'], ['0', 'GCash', true, 'REF', 'paymentAmount'],
            ['1.001', 'GCash', true, 'REF', 'paymentAmount'], ['-1', 'GCash', true, 'REF', 'paymentAmount'],
            ['1', 'Credit', true, 'REF', 'method'], ['1', 'GCash', false, 'REF', 'confirmed'], ['1', 'Maya', true, ' ', 'reference']];
        foreach ($cases as [$amount, $method, $confirmed, $reference, $field]) {
            $this->reject(fn () => SessionPayables::pay('bad', $invoice['id'], $amount, $method, $confirmed, $reference, ''), $field);
            $this->assertSame($invoice, SessionPayables::invoices()[$invoice['id']]);
            $this->assertEmpty(SessionPayables::payments());
            $this->assertEmpty(SessionCash::movements());
        }
        $this->reject(fn () => SessionPayables::pay('missing', 'missing', '1', 'Maya', true, 'REF', ''), 'invoiceId');
    }

    public function test_cash_needs_open_drawer_and_cannot_overdraw_it(): void
    {
        $invoice = $this->invoice();
        $this->reject(fn () => SessionPayables::pay('pay', $invoice['id'], '100', 'Cash', true, '', ''), 'cashSession');
        $shift = SessionCash::open('open', '20', '');
        Livewire::test(Payables::class)->call('startPayment', $invoice['id'])->set('paymentAmount', '20.01')
            ->set('confirmed', true)->call('pay')->assertHasErrors('paymentAmount');
        $this->assertSame($invoice, SessionPayables::invoices()[$invoice['id']]);
        $this->assertEmpty(SessionPayables::payments());
        $this->assertEmpty(SessionCash::movements());
        Livewire::test(Payables::class)->call('startPayment', $invoice['id'])->set('paymentAmount', '20')
            ->set('confirmed', true)->call('pay')->assertHasNoErrors()->assertSee('SPAY-00001');
        $this->assertSame(0, SessionCash::summary($shift['id'])['expected']);
    }

    public function test_digital_outside_shift_stays_unassigned_and_stale_forms_preserve_closed_summary(): void
    {
        $invoice = $this->invoice();
        $payment = SessionPayables::pay('digital', $invoice['id'], '10', 'GCash', true, 'GC-1', '');
        $this->assertNull($payment['cashSessionId']);
        $first = SessionCash::open('open-1', '100', '');
        $form = Livewire::test(Payables::class)->call('startPayment', $invoice['id'])->set('paymentAmount', '10')->set('confirmed', true);
        $closed = SessionCash::close($first['id'], 'close', '100', '');
        $second = SessionCash::open('open-2', '200', '');
        $form->call('pay')->assertHasErrors('cashSession');
        $form->set('method', 'Maya')->set('reference', 'M-2')->set('confirmed', true)->call('pay')->assertHasErrors('cashSession');
        $this->assertSame(41100, SessionPayables::invoices()[$invoice['id']]['balance']);
        $this->assertSame($closed['summary'], SessionCash::summary($first['id']));
        $this->assertSame(20000, SessionCash::summary($second['id'])['expected']);
        $this->assertSame(0, SessionCash::summary($second['id'])['methods']['GCash']['out']);
        $this->assertSame($payment, SessionPayables::pay('digital', $invoice['id'], '10', 'GCash', true, 'GC-1', ''));
    }

    public function test_payments_affect_payables_but_not_sales_collections_or_profit(): void
    {
        $this->travelTo(now('Asia/Shanghai')->setDate(2026, 10, 9)->setTime(12, 0));
        $invoice = $this->invoice('2026-10-08');
        SessionPayables::pay('old', $invoice['id'], '100', 'GCash', true, 'GC-1', '');
        $this->travelTo(now()->addDays(10));
        SessionPayables::pay('recent', $invoice['id'], '20.01', 'Maya', true, 'M-1', '');
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(30099, $summary['supplierOutstanding']);
        $this->assertSame(30099, $summary['supplierOverdue']);
        $this->assertSame(2001, $summary['supplierPaid']);
        $this->assertSame(0, $summary['collections']);
        $this->assertSame(0, $summary['revenue']);
        $this->assertSame(0, $summary['expenses']);
        $this->assertSame(0, $summary['operatingProfit']);
        $this->get('/dashboard')->assertOk()->assertSee('Supplier payables')->assertSee('300.99');
    }

    public function test_history_filters_and_details_preserve_supplier_snapshots(): void
    {
        $this->travelTo(now('Asia/Shanghai')->setDate(2026, 10, 9)->setTime(12, 0));
        $invoice = $this->invoice('2026-10-09');
        $component = Livewire::test(Payables::class)->set('status', 'overdue')->assertDontSee('BILL-00001')
            ->set('status', 'outstanding')->assertSee('BILL-00001')->set('search', 'inv-001')->assertSee('BILL-00001')
            ->set('search', 'missing')->assertDontSee('BILL-00001')->set('search', 'central')->assertSee('BILL-00001')
            ->call('viewInvoice', $invoice['id'])->assertSee('Received product / package')->call('closeInvoice')->assertSet('selectedId', null);
        $supplierId = $invoice['supplierId'];
        SessionPurchasing::saveSupplier($supplierId, ['name' => 'Renamed Supplier', 'contact' => '', 'phone' => '', 'email' => '', 'notes' => '']);
        $this->assertSame('Central Wholesale', SessionPayables::invoices()[$invoice['id']]['supplier']);
        $this->assertSame('Renamed Supplier', SessionPayables::balances()[$supplierId]['name']);
        $this->travelTo(now()->addDay());
        $component->set('status', 'overdue')->assertSee('Overdue');
        SessionPayables::pay('pay', $invoice['id'], '421', 'Maya', true, 'REF', '');
        Livewire::test(Payables::class)->set('status', 'paid')->assertSee('BILL-00001')->assertSee('SPAY-00001')->assertSee('Outside session');
        $this->reject(fn () => SessionPayables::pay('overpaid', $invoice['id'], '1', 'Maya', true, 'REF', ''), 'invoiceId');
        $this->get('/purchasing')->assertOk()->assertSee('Supplier balances')->assertSee('Supplier payment history');
    }

    public function test_legacy_and_manual_deliveries_are_disclosed_without_inventing_debt(): void
    {
        session()->put('demo.receiving', [['id' => 'old', 'orderId' => 'old-order', 'totalCost' => 10000],
            ['id' => 'manual', 'supplier' => 'Cash wholesaler', 'totalCost' => 20000]]);
        Livewire::test(Payables::class)->assertSee('2 earlier or manual inventory delivery');
        $this->assertEmpty(SessionPayables::invoices());
        $summary = SessionAnalytics::summarize(7);
        $this->assertSame(0, $summary['supplierOutstanding']);
        $this->assertSame(2, $summary['unlinkedDeliveries']);
    }

    public function test_changing_payment_details_resets_confirmation_and_cancel_writes_nothing(): void
    {
        $invoice = $this->invoice();
        Livewire::test(Payables::class)->call('startPayment', $invoice['id'])->set('confirmed', true)
            ->set('paymentAmount', '10')->assertSet('confirmed', false)->set('confirmed', true)
            ->set('method', 'Maya')->assertSet('confirmed', false)->set('confirmed', true)
            ->set('reference', 'REF')->assertSet('confirmed', false)->call('cancelPayment')->assertSet('invoiceId', null);
        $this->assertSame($invoice, SessionPayables::invoices()[$invoice['id']]);
        $this->assertEmpty(SessionPayables::payments());
        $this->assertEmpty(SessionCash::movements());
    }
}
