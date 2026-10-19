<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

final class SessionAnalytics
{
    private static function validateDay(string $date, ?int $days): void
    {
        $today = now('Asia/Shanghai')->startOfDay();
        $days = $days === null ? null : (in_array($days, [7, 30, 90], true) ? $days : 7);
        Validator::make(['selectedDay' => $date], ['selectedDay' => ['required', 'date_format:Y-m-d',
            'after_or_equal:'.($days === null ? '1900-01-01' : $today->copy()->subDays($days - 1)->toDateString()), 'before_or_equal:'.$today->toDateString()]])->validate();
    }

    public static function dailyActivity(string $date, ?int $days = null): array
    {
        self::validateDay($date, $days);
        $rows = [];
        foreach (SessionSales::all() as $sale) {
            $time = Carbon::parse($sale['time'])->timezone('Asia/Shanghai');
            if ($time->toDateString() !== $date) {
                continue;
            }
            $rows[] = ['id' => 'sale-'.$sale['id'], 'type' => 'Sale', 'time' => $time->toIso8601String(), 'reference' => $sale['number'],
                'description' => $sale['customer'] ?? 'Counter sale', 'method' => $sale['method'], 'sales' => $sale['total'],
                'collections' => $sale['method'] === 'Credit' ? 0 : $sale['total']];
        }
        foreach (SessionReturns::all() as $return) {
            $time = Carbon::parse($return['time'])->timezone('Asia/Shanghai');
            if ($time->toDateString() !== $date) {
                continue;
            }
            $rows[] = ['id' => 'return-'.$return['id'], 'type' => 'Return', 'time' => $time->toIso8601String(), 'reference' => $return['number'],
                'description' => 'Original sale '.$return['saleNumber'], 'method' => $return['refund'] > 0 ? $return['method'] : 'Credit reduction',
                'sales' => -$return['total'], 'collections' => -$return['refund']];
        }
        $debts = SessionCredit::debts();
        foreach (SessionCredit::repayments() as $repayment) {
            $time = Carbon::parse($repayment['time'])->timezone('Asia/Shanghai');
            if ($time->toDateString() !== $date) {
                continue;
            }
            $debt = $debts[$repayment['debtId']] ?? [];
            $type = match ($debt['type'] ?? '') {
                'Cash loan' => 'Loan repayment', 'Grocery credit' => 'Grocery repayment', default => 'Repayment',
            };
            $rows[] = ['id' => 'repayment-'.$repayment['id'], 'type' => $type,
                'time' => $time->toIso8601String(), 'reference' => $debt['number'] ?? 'Repayment', 'description' => $repayment['customer'],
                'method' => $repayment['method'], 'sales' => 0, 'collections' => $repayment['amount']];
        }
        usort($rows, fn (array $a, array $b): int => [$a['time'], $a['id']] <=> [$b['time'], $b['id']]);

        return ['date' => $date, 'rows' => $rows, 'sales' => array_sum(array_column($rows, 'sales')),
            'collections' => array_sum(array_column($rows, 'collections')), 'count' => count($rows)];
    }

    public static function summarize(int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 7;
        $today = now('Asia/Shanghai')->startOfDay();
        $start = $today->copy()->subDays($days - 1);

        return self::summarizeBetween($start, $today);
    }

    public static function summarizeDay(string $date): array
    {
        self::validateDay($date, null);
        $day = Carbon::parse($date, 'Asia/Shanghai')->startOfDay();

        return self::summarizeBetween($day, $day);
    }

    public static function summarizeAll(): array
    {
        $today = now('Asia/Shanghai')->startOfDay();
        $start = $today->copy();
        foreach ([SessionSales::all(), SessionReturns::all(), SessionCredit::debts(), SessionCredit::repayments(),
            SessionLosses::all(), SessionCash::expenses(), SessionCash::all(), SessionPayables::payments()] as $records) {
            foreach ($records as $record) {
                $time = $record['time'] ?? $record['closedAt'] ?? $record['openedAt'] ?? null;
                if ($time !== null) {
                    $day = Carbon::parse($time)->timezone('Asia/Shanghai')->startOfDay();
                    if ($day->lessThan($start)) {
                        $start = $day;
                    }
                }
            }
        }

        return self::summarizeBetween($start, $today, false);
    }

    public static function calendarMonth(string $month): array
    {
        Validator::make(['month' => $month], ['month' => ['required', 'date_format:Y-m', 'after_or_equal:1900-01',
            'before_or_equal:'.now('Asia/Shanghai')->format('Y-m')]])->validate();
        $start = Carbon::parse($month.'-01', 'Asia/Shanghai')->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $summary = self::summarizeBetween($start, $end);
        $daily = array_column($summary['daily'], null, 'date');
        $cells = [];
        $length = (int) (ceil(($start->dayOfWeek + $start->daysInMonth) / 7) * 7);
        $first = $start->copy()->subDays($start->dayOfWeek);
        for ($index = 0; $index < $length; $index++) {
            $day = $first->copy()->addDays($index);
            $date = $day->toDateString();
            $cells[] = ['date' => $date, 'day' => $day->day, 'inMonth' => isset($daily[$date]),
                'future' => $date > now('Asia/Shanghai')->toDateString(), 'today' => $date === now('Asia/Shanghai')->toDateString(),
                'sales' => $daily[$date]['sales'] ?? 0, 'transactions' => $daily[$date]['transactions'] ?? 0];
        }

        return ['month' => $month, 'label' => $start->format('F Y'), 'cells' => $cells, 'summary' => $summary];
    }

    private static function summarizeBetween(Carbon $start, Carbon $today, bool $includeDaily = true): array
    {
        $daily = [];
        if ($includeDaily) {
            for ($day = $start->copy(); $day->lte($today); $day->addDay()) {
                $daily[$day->toDateString()] = ['date' => $day->toDateString(), 'label' => $day->format('M j'), 'sales' => 0, 'collections' => 0, 'transactions' => 0];
            }
        }
        $from = $start->toDateString();
        $to = $today->toDateString();
        $included = fn (string $date): bool => $date >= $from && $date <= $to;
        $payments = ['Cash' => 0, 'GCash' => 0, 'Maya' => 0, 'Credit' => 0];
        $products = [];
        $transactions = 0;
        $immediate = 0;
        $knownSalesCost = 0;
        $costedRevenue = 0;
        $uncostedSalesLines = 0;
        $grossSales = 0;
        $salesBeforeDiscount = 0;
        $discounts = 0;
        foreach (SessionSales::all() as $sale) {
            $date = Carbon::parse($sale['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if (! $included($date)) {
                continue;
            }
            $transactions++;
            if ($includeDaily) {
                $daily[$date]['transactions']++;
                $daily[$date]['sales'] += $sale['total'];
            }
            $grossSales += $sale['total'];
            $salesBeforeDiscount += $sale['subtotal'] ?? $sale['total'];
            $discounts += $sale['discount'] ?? 0;
            $payments[$sale['method']] += $sale['total'];
            if ($sale['method'] !== 'Credit') {
                if ($includeDaily) {
                    $daily[$date]['collections'] += $sale['total'];
                }
                $immediate += $sale['total'];
            }
            foreach ($sale['lines'] as $line) {
                $id = $line['productId'];
                $products[$id] ??= ['name' => $line['name'], 'units' => 0, 'revenue' => 0, 'profit' => 0, 'uncostedLines' => 0];
                $products[$id]['units'] += $line['baseQuantity'];
                $products[$id]['revenue'] += $line['total'];
                if (($line['cost'] ?? null) === null) {
                    $uncostedSalesLines++;
                    $products[$id]['uncostedLines']++;
                } else {
                    $knownSalesCost += $line['cost'];
                    $costedRevenue += $line['total'];
                    $products[$id]['profit'] += $line['total'] - $line['cost'];
                }
            }
        }
        $returnValue = 0;
        $refunds = 0;
        $returnCount = 0;
        $returnedDiscounts = 0;
        foreach (SessionReturns::all() as $return) {
            $date = Carbon::parse($return['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if (! $included($date)) {
                continue;
            }
            $returnCount++;
            $returnValue += $return['total'];
            $returnedDiscounts += $return['discount'] ?? 0;
            $refunds += $return['refund'];
            if ($includeDaily) {
                $daily[$date]['sales'] -= $return['total'];
                $daily[$date]['collections'] -= $return['refund'];
            }
            $payments[$return['saleMethod']] -= $return['total'];
            foreach ($return['lines'] as $line) {
                $id = $line['productId'];
                $products[$id] ??= ['name' => $line['name'], 'units' => 0, 'revenue' => 0, 'profit' => 0, 'uncostedLines' => 0];
                $products[$id]['units'] -= $line['baseQuantity'];
                $products[$id]['revenue'] -= $line['total'];
                if ($line['cost'] === null) {
                    $uncostedSalesLines++;
                    $products[$id]['uncostedLines']++;
                } else {
                    $knownSalesCost -= $line['cost'];
                    $costedRevenue -= $line['total'];
                    $products[$id]['profit'] -= $line['total'] - $line['cost'];
                }
            }
        }
        $repaid = 0;
        foreach (SessionCredit::repayments() as $repayment) {
            $date = Carbon::parse($repayment['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if ($included($date)) {
                if ($includeDaily) {
                    $daily[$date]['collections'] += $repayment['amount'];
                }
                $repaid += $repayment['amount'];
            }
        }
        $outstanding = 0;
        $overdue = 0;
        $cashLent = 0;
        $customers = [];
        foreach (SessionCredit::debts() as $debt) {
            $outstanding += $debt['balance'];
            $isOverdue = $debt['balance'] > 0 && $debt['dueDate'] && $debt['dueDate'] < now('Asia/Shanghai')->toDateString();
            if ($isOverdue) {
                $overdue += $debt['balance'];
            }
            if ($debt['balance'] > 0) {
                $id = $debt['customerId'];
                $customers[$id] ??= ['name' => $debt['customer'], 'balance' => 0, 'overdue' => 0];
                $customers[$id]['balance'] += $debt['balance'];
                $customers[$id]['overdue'] += $isOverdue ? $debt['balance'] : 0;
            }
            $date = Carbon::parse($debt['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if ($debt['type'] === 'Cash loan' && $included($date)) {
                $cashLent += $debt['principal'];
            }
        }
        $lossUnits = 0;
        $lossCost = 0;
        $uncosted = 0;
        $reasons = [];
        foreach (SessionLosses::all() as $loss) {
            $date = Carbon::parse($loss['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if (! $included($date)) {
                continue;
            }
            $lossUnits += $loss['baseQuantity'];
            $lossCost += $loss['totalCost'] ?? ($loss['knownCost'] ?? 0);
            $uncosted += $loss['totalCost'] === null ? 1 : 0;
            $reasons[$loss['reason']] = ($reasons[$loss['reason']] ?? 0) + $loss['baseQuantity'];
        }
        usort($products, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
        usort($customers, fn (array $a, array $b): int => $b['balance'] <=> $a['balance']);
        arsort($reasons);
        $expenses = 0;
        $expenseCategories = [];
        foreach (SessionCash::expenses() as $expense) {
            $date = Carbon::parse($expense['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if ($included($date)) {
                $expenses += $expense['amount'];
                $expenseCategories[$expense['category']] = ($expenseCategories[$expense['category']] ?? 0) + $expense['amount'];
            }
        }
        arsort($expenseCategories);
        $cashShortages = 0;
        $cashSurpluses = 0;
        foreach (SessionCash::all() as $cashSession) {
            if ($cashSession['status'] !== 'Closed') {
                continue;
            }
            $date = Carbon::parse($cashSession['closedAt'])->setTimezone('Asia/Shanghai')->toDateString();
            if ($included($date)) {
                $cashShortages += max(0, -$cashSession['difference']);
                $cashSurpluses += max(0, $cashSession['difference']);
            }
        }
        $lowStock = array_values(array_filter(DemoCatalogue::products(), fn (array $product): bool => $product['stock'] <= ($product['reorder'] ?? 0)));
        $revenue = array_sum($payments);
        $grossProfit = $uncostedSalesLines === 0 ? $revenue - $knownSalesCost : null;
        $operatingProfit = $grossProfit !== null && $uncosted === 0 ? $grossProfit - $expenses - $lossCost : null;
        $supplierBalances = SessionPayables::balances();
        $supplierPaid = 0;
        foreach (SessionPayables::payments() as $payment) {
            $date = Carbon::parse($payment['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if ($included($date)) {
                $supplierPaid += $payment['amount'];
            }
        }

        return ['daily' => array_values($daily), 'payments' => $payments, 'products' => array_slice($products, 0, 10),
            'customers' => $customers, 'transactions' => $transactions, 'revenue' => $revenue,
            'average' => $transactions ? (int) round($grossSales / $transactions) : 0,
            'immediate' => $immediate, 'repaid' => $repaid, 'collections' => $immediate + $repaid - $refunds,
            'grossSales' => $grossSales, 'returnValue' => $returnValue, 'refunds' => $refunds, 'returnCount' => $returnCount,
            'salesBeforeDiscount' => $salesBeforeDiscount, 'discounts' => $discounts,
            'returnedDiscounts' => $returnedDiscounts, 'netDiscounts' => $discounts - $returnedDiscounts,
            'outstanding' => $outstanding, 'overdue' => $overdue, 'cashLent' => $cashLent, 'lowStock' => $lowStock,
            'lossUnits' => $lossUnits, 'lossCost' => $lossCost, 'uncosted' => $uncosted, 'reasons' => $reasons,
            'expenses' => $expenses, 'expenseCategories' => $expenseCategories, 'cashShortages' => $cashShortages, 'cashSurpluses' => $cashSurpluses,
            'knownSalesCost' => $knownSalesCost, 'costedRevenue' => $costedRevenue, 'uncostedSalesLines' => $uncostedSalesLines,
            'costedProfit' => $costedRevenue - $knownSalesCost, 'grossProfit' => $grossProfit, 'operatingProfit' => $operatingProfit,
            'grossMargin' => $grossProfit !== null && $revenue > 0 ? round($grossProfit / $revenue * 100, 1) : null,
            'supplierBalances' => $supplierBalances, 'supplierOutstanding' => array_sum(array_column($supplierBalances, 'balance')),
            'supplierOverdue' => array_sum(array_column($supplierBalances, 'overdue')), 'supplierPaid' => $supplierPaid,
            'unlinkedDeliveries' => SessionPayables::unlinkedDeliveries(),
            'expiryStock' => SessionBatches::overview()['totals'],
            'from' => $start->format('M j, Y'), 'to' => $today->format('M j, Y'),
            'updated' => now('Asia/Shanghai')->format('M j, Y · g:i A')];
    }
}
