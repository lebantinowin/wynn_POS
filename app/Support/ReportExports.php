<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class ReportExports
{
    public const TYPES = [
        'summary' => 'Performance summary', 'daily' => 'Daily sales & collections',
        'transactions' => 'Sales & returns', 'inventory' => 'Inventory & low stock',
        'expiry' => 'Stock batches & expiry', 'customers' => 'Customer credit & loans',
        'suppliers' => 'Supplier balances', 'expenses' => 'Operating expenses',
        'cash' => 'Cash reconciliation', 'losses' => 'Stock losses',
    ];

    /** @return array{value: mixed, kind: string} */
    private static function cell(mixed $value, string $kind = 'text'): array
    {
        return compact('value', 'kind');
    }

    private static function row(array $values, array $money = [], array $numbers = []): array
    {
        $row = [];
        foreach ($values as $index => $value) {
            $row[] = self::cell($value, in_array($index, $money, true) ? 'money' : (in_array($index, $numbers, true) ? 'number' : 'text'));
        }

        return $row;
    }

    public static function formatted(array $cell, bool $csv = false): string
    {
        if ($cell['value'] === null) {
            return 'Unknown';
        }
        if ($cell['value'] === '') {
            return '';
        }
        if ($cell['kind'] === 'money') {
            return $csv ? number_format($cell['value'] / 100, 2, '.', '') : ShopPreferences::amount($cell['value']);
        }
        if ($cell['kind'] === 'percent') {
            return number_format($cell['value'], 1, '.', '').($csv ? '' : '%');
        }
        $text = (string) $cell['value'];
        if ($csv && $cell['kind'] === 'text' && preg_match('/^(?:[\t\r\n]|[\s\p{Z}\x{FEFF}]*[=+\-@])/u', $text)) {
            return "'".$text;
        }

        return $text;
    }

    private static function localTime(?string $time): string
    {
        return $time ? Carbon::parse($time)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '';
    }

    private static function inPeriod(string $time, string $from, string $to): bool
    {
        $date = Carbon::parse($time)->timezone('Asia/Shanghai')->toDateString();

        return $date >= $from && $date <= $to;
    }

    /**
     * Freeze all values before the HTTP stream leaves the workspace transaction.
     *
     * @return array{title: string, shop: string, generated: string, period: string, scope: string, notes: array, columns: array, rows: array}
     */
    public static function build(string $type, int $days): array
    {
        abort_unless(isset(self::TYPES[$type]) && in_array($days, [7, 30, 90], true), 404);
        $today = now('Asia/Shanghai');
        $to = $today->toDateString();
        $from = $today->copy()->subDays($days - 1)->toDateString();
        $current = in_array($type, ['inventory', 'expiry', 'customers', 'suppliers'], true);
        $report = ['title' => self::TYPES[$type], 'shop' => app(ShopPreferences::class)->all()['shopName'],
            'generated' => $today->format('Y-m-d H:i:s').' (UTC+08:00)', 'period' => $from.' to '.$to,
            'scope' => $current ? 'Current snapshot across all recorded dates; the selected period does not filter these balances.' : 'Activity recorded within the selected period, inclusive, UTC+08:00.',
            'notes' => ['Amounts are PHP. Unknown costs are not zero. Management report; tax calculation is unconfigured.',
                'Includes records saved on the server. Pending offline sales are excluded until synchronized.'],
            'columns' => [], 'rows' => []];
        if (app(OfflineCheckout::class)->active()) {
            $report['notes'][] = 'Counter 1 has an active offline session. This report may be incomplete until that session synchronizes and finishes.';
        }
        if (config('pos.practice')) {
            $report['notes'][] = 'Practice shop: sample records only. This report does not contain real shop transactions.';
        }
        $rows = [];
        switch ($type) {
            case 'summary':
                $summary = SessionAnalytics::summarize($days);
                $report['columns'] = ['Metric', 'Value', 'Unit / coverage'];
                $metrics = ['Sales before discounts' => 'salesBeforeDiscount', 'Discounts given' => 'discounts',
                    'Discounts reversed on returns' => 'returnedDiscounts', 'Net discounts' => 'netDiscounts',
                    'Original sales' => 'grossSales', 'Returned sales value' => 'returnValue', 'Net sales' => 'revenue',
                    'Immediate paid sales' => 'immediate', 'Debt repayments collected' => 'repaid', 'Refunds paid' => 'refunds',
                    'Net collections' => 'collections', 'Average original basket' => 'average', 'Known portion of sales cost' => 'knownSalesCost',
                    'Net revenue with recorded costs' => 'costedRevenue', 'Profit from costed lines only' => 'costedProfit',
                    'Estimated gross profit' => 'grossProfit', 'Paid operating expenses' => 'expenses', 'Known stock loss cost' => 'lossCost',
                    'Estimated operating profit' => 'operatingProfit', 'Cash shortages' => 'cashShortages', 'Cash surpluses' => 'cashSurpluses',
                    'Cash loans issued (principal)' => 'cashLent', 'Supplier payments' => 'supplierPaid',
                    'Current customer balance' => 'outstanding', 'Current overdue customer balance' => 'overdue',
                    'Current supplier balance' => 'supplierOutstanding', 'Current overdue supplier balance' => 'supplierOverdue'];
                foreach ($metrics as $label => $key) {
                    $rows[] = self::row([$label, $summary[$key], str_starts_with($label, 'Current') ? 'PHP; current, all dates' : 'PHP'], [1]);
                }
                $rows[] = self::row(['Estimated total sales cost', $summary['uncostedSalesLines'] ? null : $summary['knownSalesCost'], 'PHP; withheld if cost is incomplete'], [1]);
                foreach (['Completed sales' => 'transactions', 'Return records' => 'returnCount', 'Sale / return lines with unknown cost' => 'uncostedSalesLines', 'Loss entries with unknown cost' => 'uncosted', 'Lost base units' => 'lossUnits', 'Unlinked supplier deliveries' => 'unlinkedDeliveries'] as $label => $key) {
                    $rows[] = self::row([$label, $summary[$key], $key === 'unlinkedDeliveries' ? 'Current, all dates' : 'Count'], [], [1]);
                }
                $rows[] = [self::cell('Gross margin'), self::cell($summary['grossMargin'], 'percent'), self::cell('Percent; unknown if profit unavailable or net revenue is not positive')];
                $report['notes'][] = 'Profit estimates exclude tax, unrecorded expenses and drawer differences. Loan principal and repayments do not add profit. Known portions are not complete totals.';
                break;
            case 'daily':
                $report['columns'] = ['Date', 'Net sales (PHP)', 'Net collections (PHP)'];
                foreach (SessionAnalytics::summarize($days)['daily'] as $day) {
                    $rows[] = self::row([$day['date'], $day['sales'], $day['collections']], [1, 2]);
                }
                $report['notes'][] = 'Credit sales add revenue; repayments add collections. Returns reduce revenue on their recorded date; refunds reduce collections.';
                break;
            case 'transactions':
                $report['columns'] = ['Recorded at', 'Type', 'Reference', 'Original sale', 'Sale payment', 'Net sales (PHP)', 'Cost (PHP)', 'Gross profit (PHP)', 'Paid sale / refund (PHP)', 'Credit reduction (PHP)', 'Amount received (PHP)', 'Subtotal before discount (PHP)', 'Discount (PHP)', 'Discount reason'];
                foreach (['Sale' => SessionSales::all(), 'Return' => SessionReturns::all()] as $kind => $entries) {
                    foreach ($entries as $entry) {
                        if (! self::inPeriod($entry['time'], $from, $to)) {
                            continue;
                        }
                        $sign = $kind === 'Sale' ? 1 : -1;
                        $cost = 0;
                        foreach ($entry['lines'] as $line) {
                            if (($line['cost'] ?? null) === null) {
                                $cost = null;
                                break;
                            }
                            $cost += $sign * $line['cost'];
                        }
                        $net = $sign * $entry['total'];
                        $payment = $kind === 'Sale' ? ($entry['method'] === 'Credit' ? 0 : $entry['total']) : -$entry['refund'];
                        $rows[] = self::row([self::localTime($entry['time']), $kind, $entry['number'], $entry['saleNumber'] ?? '',
                            $entry['saleMethod'] ?? $entry['method'], $net, $cost, $cost === null ? null : $net - $cost,
                            $payment, $entry['creditReduction'] ?? 0,
                            $kind === 'Sale' && $entry['method'] !== 'Credit' ? ($entry['paymentReceived'] ?? $entry['cashReceived'] ?? null) : '',
                            $sign * ($entry['subtotal'] ?? $entry['total']), $sign * ($entry['discount'] ?? 0), $entry['discountReason'] ?? ''], [5, 6, 7, 8, 9, 10, 11, 12]);
                    }
                }
                usort($rows, fn (array $a, array $b): int => $a[0]['value'] <=> $b[0]['value']);
                $report['notes'][] = 'Original sales retain their full saved values. Returns are separate negative entries on their recorded date. Repayments are excluded; this payment column is not total collections.';
                $report['notes'][] = 'Amount received is the recorded tender or app payment before cash change. Older digital sales without a recorded amount show Unknown; credit sales and returns leave this column blank.';
                $report['notes'][] = 'Discounts are included in net sales and profit, not deducted again as expenses. Return values use the saved discounted amounts; negative discounts reverse the returned share.';
                break;
            case 'inventory':
                $report['columns'] = ['SKU', 'Product', 'Base unit', 'Recorded stock', 'Sellable stock', 'Reorder level', 'Low stock', 'Expired units', 'Due within 30 days', 'Unknown expiry units'];
                $overview = SessionBatches::overview();
                foreach (DemoCatalogue::products() as $product) {
                    $stock = $overview['products'][$product['id']] ?? ['sellable' => 0, 'expired' => 0, 'soon' => 0, 'unknown' => 0];
                    $rows[] = self::row([$product['sku'], $product['name'], $product['unit'], $product['stock'], $stock['sellable'], $product['reorder'] ?? 0,
                        $product['stock'] <= ($product['reorder'] ?? 0) ? 'Yes' : 'No', $stock['expired'], $stock['soon'], $stock['unknown']], [], [3, 4, 5, 7, 8, 9]);
                }
                $report['notes'][] = 'Stock counts use base units. Expired stock is not sellable. Low-stock flags use recorded stock, matching the dashboard.';
                break;
            case 'expiry':
                $report['columns'] = ['Batch ID', 'Product', 'Base unit', 'Source', 'Reference', 'Received at', 'Expiry date', 'Status', 'Remaining units', 'Stock value (PHP)', 'Known value portion (PHP)', 'Units with unknown cost'];
                foreach (SessionBatches::overview()['batches'] as $batch) {
                    if ($batch['remaining'] === 0) {
                        continue;
                    }
                    $valuation = BatchCost::summarize([['quantity' => $batch['remaining'], 'costSegments' => BatchCost::segments($batch)]]);
                    $rows[] = self::row([$batch['id'], $batch['name'], $batch['unit'], $batch['source'], $batch['reference'], self::localTime($batch['time']),
                        $batch['expiry'] ?: 'Unknown', $batch['status'], $batch['remaining'], $batch['remainingCost'], $valuation['knownCost'], $valuation['unknownUnits']], [9, 10], [8, 11]);
                }
                break;
            case 'customers':
                $report['columns'] = ['Debt', 'Customer', 'Type', 'Issued at', 'Due date', 'Principal (PHP)', 'Outstanding (PHP)', 'Overdue', 'Notes'];
                foreach (SessionCredit::debts() as $debt) {
                    if ($debt['balance'] <= 0) {
                        continue;
                    }
                    $rows[] = self::row([$debt['number'], $debt['customer'], $debt['type'], self::localTime($debt['time']), $debt['dueDate'], $debt['principal'], $debt['balance'],
                        $debt['dueDate'] !== '' && $debt['dueDate'] < $to ? 'Yes' : 'No', $debt['notes']], [5, 6]);
                }
                $report['notes'][] = 'Outstanding debts only, including grocery credit and cash loans. Principal is the original amount; outstanding reflects repayments and credit returns.';
                break;
            case 'suppliers':
                $report['columns'] = ['Supplier', 'Billed (PHP)', 'Paid (PHP)', 'Outstanding (PHP)', 'Overdue (PHP)'];
                foreach (SessionPayables::balances() as $balance) {
                    $rows[] = self::row([$balance['name'], $balance['total'], $balance['paid'], $balance['balance'], $balance['overdue']], [1, 2, 3, 4]);
                }
                $report['notes'][] = SessionPayables::unlinkedDeliveries().' unlinked deliveries are excluded from supplier balances.';
                break;
            case 'expenses':
                $report['columns'] = ['Recorded at', 'Expense', 'Category', 'Payment method', 'Amount (PHP)', 'Cash session', 'Notes'];
                foreach (SessionCash::expenses() as $expense) {
                    if (self::inPeriod($expense['time'], $from, $to)) {
                        $rows[] = self::row([self::localTime($expense['time']), $expense['number'], $expense['category'], $expense['method'], $expense['amount'], $expense['cashSessionId'] ?? '', $expense['notes']], [4]);
                    }
                }
                break;
            case 'cash':
                $report['columns'] = ['Session', 'Status', 'Opened at', 'Closed at', 'Opening float (PHP)', 'Cash in (PHP)', 'Cash out (PHP)', 'Expected (PHP)', 'Counted (PHP)', 'Difference (PHP)', 'Close notes'];
                foreach (SessionCash::all() as $session) {
                    if ($session['status'] === 'Closed' && ! self::inPeriod($session['closedAt'], $from, $to)) {
                        continue;
                    }
                    $summary = SessionCash::summary($session['id']);
                    $rows[] = self::row([$session['number'], $session['status'], self::localTime($session['openedAt']), self::localTime($session['closedAt']), $session['opening'],
                        $summary['methods']['Cash']['in'], $summary['methods']['Cash']['out'], $summary['expected'],
                        $session['counted'] ?? '', $session['difference'] ?? '', $session['closeNotes']], [4, 5, 6, 7, 8, 9]);
                }
                $report['scope'] = 'Sessions closed within the selected period, plus the currently open session regardless of opening date.';
                $report['notes'][] = 'Closed sessions use their saved reconciliation. Open counts and differences are blank until closing. Negative differences are shortages; positive differences are surpluses. Digital payments are excluded from drawer cash.';
                break;
            case 'losses':
                $report['columns'] = ['Recorded at', 'Product', 'Option', 'Quantity', 'Lost base units', 'Reason', 'Loss cost (PHP)', 'Known cost portion (PHP)', 'Cost basis', 'Notes'];
                foreach (SessionLosses::all() as $loss) {
                    if (self::inPeriod($loss['time'], $from, $to)) {
                        $rows[] = self::row([self::localTime($loss['time']), $loss['name'], $loss['option'], $loss['quantity'], $loss['baseQuantity'], $loss['reason'],
                            $loss['totalCost'], $loss['totalCost'] ?? ($loss['knownCost'] ?? 0), $loss['costBasis'], $loss['notes']], [6, 7], [3, 4]);
                    }
                }
                break;
        }
        $report['rows'] = $rows;

        return $report;
    }
}
