<?php

namespace Tests\Feature;

use App\Livewire\Analytics;
use App\Models\User;
use App\Support\DemoCatalogue;
use App\Support\PosStore;
use App\Support\ReportExports;
use App\Support\SessionBatches;
use App\Support\SessionCash;
use App\Support\SessionCredit;
use App\Support\SessionLosses;
use App\Support\SessionPayables;
use App\Support\SessionPurchasing;
use App\Support\SessionReturns;
use App\Support\SessionSales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class ReportExportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-13 12:00:00', 'Asia/Shanghai'));
        SessionCash::open('opening', '1000', 'Test float');
    }

    private function csv(string $type, int $days = 7): array
    {
        $response = $this->get('/reports/export/'.$type.'?days='.$days)->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $metadata = [];
        while (($line = fgetcsv($stream, escape: '')) !== false && $line !== [null]) {
            $metadata[] = $line;
        }
        $columns = fgetcsv($stream, escape: '');
        $rows = [];
        while (($line = fgetcsv($stream, escape: '')) !== false) {
            $this->assertCount(count($columns), $line);
            $rows[] = array_combine($columns, $line);
        }
        fclose($stream);

        return compact('metadata', 'columns', 'rows');
    }

    private function costStock(int $cost): void
    {
        $state = SessionBatches::state();
        foreach ($state['batches'] as &$batch) {
            $batch['totalCost'] = $cost * $batch['initialQuantity'];
        }
        unset($batch);
        PosStore::put(SessionBatches::changes($state));
    }

    public function test_transaction_exports_preserve_received_amounts_and_unknown_older_digital_payments(): void
    {
        SessionSales::complete('cash', ['drink-one' => 1], 'Cash', '100', false);
        SessionSales::complete('digital', ['drink-one' => 1], 'GCash', '20', true);
        $legacy = SessionSales::complete('legacy', ['drink-one' => 1], 'Maya', '20', true);
        $sales = SessionSales::all();
        unset($sales[$legacy['id']]['paymentReceived']);
        session()->put('demo.sales', $sales);
        $rows = $this->csv('transactions')['rows'];
        $this->assertSame('100.00', $rows[0]['Amount received (PHP)']);
        $this->assertSame('20.00', $rows[1]['Amount received (PHP)']);
        $this->assertSame('Unknown', $rows[2]['Amount received (PHP)']);
        $this->assertSame('20.00', $rows[0]['Paid sale / refund (PHP)']);
        $this->get('/reports/print/transactions')->assertOk()->assertSee('Amount received (PHP)')->assertSee('100.00');
        $this->assertSame($sales, SessionSales::all());
    }

    public function test_all_report_types_download_and_preview_without_changing_shop_records(): void
    {
        $before = [DemoCatalogue::products(), SessionSales::all(), SessionCash::all(), SessionBatches::state()];
        foreach (ReportExports::TYPES as $type => $title) {
            $csv = $this->csv($type);
            $this->assertContains(['Report', $title], $csv['metadata']);
            $this->assertContains(['Currency', 'PHP'], $csv['metadata']);
            $this->assertContains(['Selected period', '2026-10-07 to 2026-10-13'], $csv['metadata']);
            $this->get('/reports/print/'.$type.'?days=7')->assertOk()->assertSee($title)->assertSee('Print / Save PDF')
                ->assertSee('Pending offline sales are excluded until synchronized.')
                ->assertDontSee('grocery.css');
        }
        $this->assertSame($before, [DemoCatalogue::products(), SessionSales::all(), SessionCash::all(), SessionBatches::state()]);
        $this->get('/reports/print/expenses')->assertSee('No matching records.');
    }

    public function test_local_date_boundaries_separate_sales_returns_and_debt_collections(): void
    {
        $this->costStock(1000);
        $this->travelTo(Carbon::parse('2026-10-06 23:59:59', 'Asia/Shanghai'));
        SessionSales::complete('before', ['drink-one' => 1], 'Cash', '20', false);
        $this->travelTo(Carbon::parse('2026-10-07 00:00:00', 'Asia/Shanghai'));
        $sale = SessionSales::complete('boundary', ['drink-one' => 2], 'Cash', '40', false);
        SessionCredit::addCustomer('Maria', '');
        $customer = array_key_first(SessionCredit::customers());
        SessionSales::complete('credit', ['drink-one' => 1], 'Credit', '', false, $customer);
        $debt = array_key_first(SessionCredit::debts());
        $this->travelTo(Carbon::parse('2026-10-13 12:00:00', 'Asia/Shanghai'));
        SessionReturns::complete('return', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Unopened bottle returned');
        SessionCredit::repay('repay', $debt, '10', 'Maya', '');
        SessionCredit::cashLoan('loan', $customer, '100', 'Cash', '', '');
        $daily = $this->csv('daily')['rows'];
        $this->assertCount(7, $daily);
        $this->assertSame('60.00', $daily[0]['Net sales (PHP)']);
        $this->assertSame('40.00', $daily[0]['Net collections (PHP)']);
        $this->assertSame('-20.00', $daily[6]['Net sales (PHP)']);
        $this->assertSame('-10.00', $daily[6]['Net collections (PHP)']);
        $transactions = $this->csv('transactions')['rows'];
        $this->assertCount(3, $transactions);
        $this->assertSame('-20.00', $transactions[2]['Net sales (PHP)']);
        $this->assertSame('-10.00', $transactions[2]['Cost (PHP)']);
        $this->assertSame('-10.00', $transactions[2]['Gross profit (PHP)']);
        $this->assertSame('0.00', $transactions[1]['Paid sale / refund (PHP)']);
        $summary = array_column($this->csv('summary')['rows'], 'Value', 'Metric');
        $this->assertSame('40.00', $summary['Net sales']);
        $this->assertSame('30.00', $summary['Net collections']);
        $this->assertSame('20.00', $summary['Estimated gross profit']);
        $this->assertSame('100.00', $summary['Cash loans issued (principal)']);
        $this->assertCount(4, $this->csv('transactions', 30)['rows']);
    }

    public function test_credit_return_reduces_balance_and_is_not_a_money_refund(): void
    {
        SessionCredit::addCustomer('Maria', '');
        $customer = array_key_first(SessionCredit::customers());
        $sale = SessionSales::complete('credit', ['drink-one' => 2], 'Credit', '', false, $customer);
        SessionReturns::complete('return-credit', $sale['id'], [['quantity' => 1, 'disposition' => 'Restock']], 'Cash', true, 'Unopened credit item');
        $rows = $this->csv('transactions')['rows'];
        $this->assertSame('20.00', $rows[1]['Credit reduction (PHP)']);
        $this->assertSame('0.00', $rows[1]['Paid sale / refund (PHP)']);
        $this->assertSame('20.00', $this->csv('customers')['rows'][0]['Outstanding (PHP)']);
    }

    public function test_unknown_costs_and_real_zero_costs_remain_distinct(): void
    {
        SessionSales::complete('unknown', ['drink-one' => 1], 'Cash', '20', false);
        SessionLosses::record('unknown-loss', 'drink-one', 1, 'Unpaid consumption', 'Consumed without paying', '');
        SessionLosses::record('free-loss', 'drink-one', 1, 'Damage', 'Free item damaged', '0');
        $summary = array_column($this->csv('summary')['rows'], 'Value', 'Metric');
        $this->assertSame('Unknown', $summary['Estimated gross profit']);
        $this->assertSame('Unknown', $summary['Estimated total sales cost']);
        $this->assertSame('Unknown', $this->csv('transactions')['rows'][0]['Cost (PHP)']);
        $losses = $this->csv('losses')['rows'];
        $this->assertSame('Unknown', $losses[0]['Loss cost (PHP)']);
        $this->assertSame('0.00', $losses[1]['Loss cost (PHP)']);
        $this->costStock(0);
        SessionSales::complete('free-stock', ['noodle-one' => 1], 'Cash', '15', false);
        $transactions = $this->csv('transactions')['rows'];
        $this->assertSame('0.00', $transactions[1]['Cost (PHP)']);
        $this->assertSame('15.00', $transactions[1]['Gross profit (PHP)']);
    }

    public function test_current_customer_and_supplier_balances_ignore_period_and_keep_overdue_amounts(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01', 'Asia/Shanghai'));
        SessionCredit::addCustomer('Old customer', '');
        $customer = array_key_first(SessionCredit::customers());
        SessionSales::complete('old-credit', ['drink-one' => 1], 'Credit', '', false, $customer, '2026-08-02');
        SessionCredit::cashLoan('old-loan', $customer, '100', 'Cash', '2026-08-02', '');
        SessionPurchasing::saveSupplier(null, ['name' => 'Old supplier', 'contact' => '', 'phone' => '', 'email' => '', 'notes' => '']);
        SessionPurchasing::createOrder(array_key_first(SessionPurchasing::suppliers()), '', [['optionId' => 'drink-pack', 'quantity' => 1, 'cost' => '60']]);
        SessionPurchasing::receive(array_key_first(SessionPurchasing::orders()), [['quantity' => 1, 'cost' => '60', 'expiry' => '2027-01-01']], 'Old delivery', 'delivery', '2026-08-02');
        $this->travelTo(Carbon::parse('2026-10-13 12:00:00', 'Asia/Shanghai'));
        SessionCredit::repay('partial', array_key_last(SessionCredit::debts()), '30', 'Cash', '');
        SessionPayables::pay('supplier-partial', array_key_first(SessionPayables::invoices()), '10', 'Cash', true, '', '');
        $customers = $this->csv('customers');
        $this->assertCount(2, $customers['rows']);
        $this->assertSame(['20.00', '70.00'], array_column($customers['rows'], 'Outstanding (PHP)'));
        $this->assertSame(['Yes', 'Yes'], array_column($customers['rows'], 'Overdue'));
        $this->assertStringContainsString('Current snapshot', $customers['metadata'][5][1]);
        $supplier = $this->csv('suppliers')['rows'][0];
        $this->assertSame('60.00', $supplier['Billed (PHP)']);
        $this->assertSame('50.00', $supplier['Outstanding (PHP)']);
        $this->assertSame('50.00', $supplier['Overdue (PHP)']);
    }

    public function test_stock_exports_count_base_units_expired_batches_and_unknown_valuation(): void
    {
        $product = DemoCatalogue::find(1);
        $product['reorder'] = 100;
        DemoCatalogue::save($product);
        $state = SessionBatches::state();
        $state['batches']['legacy-1']['expiry'] = '2026-10-12';
        $state['batches']['legacy-2']['expiry'] = '2026-10-23';
        $state['batches']['legacy-2']['totalCost'] = 0;
        PosStore::put(SessionBatches::changes($state));
        $stock = $this->csv('inventory')['rows'];
        $this->assertSame('72', $stock[0]['Recorded stock']);
        $this->assertSame('0', $stock[0]['Sellable stock']);
        $this->assertSame('72', $stock[0]['Expired units']);
        $this->assertSame('Yes', $stock[0]['Low stock']);
        $this->assertSame('48', $stock[1]['Due within 30 days']);
        $batches = $this->csv('expiry')['rows'];
        $this->assertSame('Unknown', $batches[0]['Stock value (PHP)']);
        $this->assertSame('72', $batches[0]['Units with unknown cost']);
        $this->assertSame('0.00', $batches[1]['Stock value (PHP)']);
    }

    public function test_closed_cash_snapshot_and_open_uncounted_drawer_are_reported_separately(): void
    {
        SessionSales::complete('cash-sale', ['drink-one' => 1], 'Cash', '20', false);
        SessionCash::expense('expense', '5', SessionCash::CATEGORIES[0], 'Cash', 'Operating expense');
        $closed = SessionCash::close(SessionCash::active()['id'], 'close', '1010', 'Five peso shortage');
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'Asia/Shanghai'));
        SessionCash::open('new-session', '500', 'New float');
        SessionSales::complete('next-sale', ['drink-one' => 1], 'Cash', '20', false);
        $cash = $this->csv('cash')['rows'];
        $this->assertSame('1015.00', $cash[0]['Expected (PHP)']);
        $this->assertSame('-5.00', $cash[0]['Difference (PHP)']);
        $this->assertSame('520.00', $cash[1]['Expected (PHP)']);
        $this->assertSame('', $cash[1]['Counted (PHP)']);
        $this->assertSame($closed['summary'], SessionCash::summary($closed['id']));
        $this->assertSame('5.00', $this->csv('expenses')['rows'][0]['Amount (PHP)']);
        $this->travelTo(Carbon::parse('2026-11-01', 'Asia/Shanghai'));
        $this->assertCount(1, $this->csv('cash')['rows']);
        $this->assertSame('Open', $this->csv('cash')['rows'][0]['Status']);
        $this->assertCount(0, $this->csv('expenses')['rows']);
    }

    public function test_csv_quotes_unicode_multiline_and_neutralizes_formulas_without_changing_values(): void
    {
        $name = '  =HYPERLINK("https://example.test")';
        SessionCredit::addCustomer($name, 'PRIVATE-PHONE');
        $notes = "Customer said \"hello\", on credit\nSecond line ₱";
        SessionCredit::cashLoan('loan', array_key_first(SessionCredit::customers()), '20', 'Cash', '', $notes);
        $rows = $this->csv('customers')['rows'];
        $this->assertSame("'".trim($name), $rows[0]['Customer']);
        $this->assertSame($notes, $rows[0]['Notes']);
        $this->assertSame(trim($name), SessionCredit::debts()[array_key_first(SessionCredit::debts())]['customer']);
        foreach (['=1+1', '+cmd', '-cmd', '@SUM(1)', "\tplain", "\rplain", "\nplain", " \u{00a0}=1+1"] as $text) {
            $this->assertSame("'".$text, ReportExports::formatted(['value' => $text, 'kind' => 'text'], true));
        }
        $this->assertSame('-20.00', ReportExports::formatted(['value' => -2000, 'kind' => 'money'], true));
        $this->get('/reports/print/customers')->assertSee('=HYPERLINK(&quot;https://example.test&quot;)', false)
            ->assertDontSee('PRIVATE-PHONE')->assertSee('Second line ₱');
    }

    public function test_report_routes_validate_scope_and_reject_guests_and_non_owners(): void
    {
        foreach (['export', 'print'] as $action) {
            $this->get('/reports/'.$action.'/unknown')->assertNotFound();
            foreach (['0', '8', '7.5', 'all', ''] as $days) {
                $this->getJson('/reports/'.$action.'/summary?days='.$days)->assertUnprocessable()->assertJsonValidationErrors('days');
            }
        }
        Auth::user()->setRememberToken(null);
        Auth::logout();
        $this->get('/reports/export/summary')->assertRedirect('/login');
        $this->get('/reports/print/customers')->assertRedirect('/login');
        $this->actingAs(User::factory()->make(['id' => 2, 'remember_token' => null]));
        $this->get('/reports/export/customers')->assertRedirect('/login');
    }

    public function test_export_controls_follow_the_live_selected_report_and_period(): void
    {
        Livewire::test(Analytics::class)->set('days', 30)->set('reportType', 'expenses')
            ->assertSee('/reports/export/expenses?days=30', false)
            ->assertSee('/reports/print/expenses?days=30', false);
    }
}
