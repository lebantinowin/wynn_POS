<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class SessionAnalytics
{
    public static function summarize(int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 7;
        $today = now('Asia/Shanghai')->startOfDay();
        $start = $today->copy()->subDays($days - 1);
        $daily = [];
        for ($day = $start->copy(); $day->lte($today); $day->addDay()) {
            $daily[$day->toDateString()] = ['date' => $day->toDateString(), 'label' => $day->format('M j'), 'sales' => 0, 'collections' => 0];
        }
        $payments = ['Cash' => 0, 'GCash' => 0, 'Maya' => 0, 'Credit' => 0];
        $products = [];
        $transactions = 0;
        $immediate = 0;
        $knownSalesCost = 0;
        $costedRevenue = 0;
        $uncostedSalesLines = 0;
        $grossSales = 0;
        foreach (SessionSales::all() as $sale) {
            $date = Carbon::parse($sale['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if (! isset($daily[$date])) {
                continue;
            }
            $transactions++;
            $grossSales += $sale['total'];
            $daily[$date]['sales'] += $sale['total'];
            $payments[$sale['method']] += $sale['total'];
            if ($sale['method'] !== 'Credit') {
                $daily[$date]['collections'] += $sale['total'];
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
        foreach (SessionReturns::all() as $return) {
            $date = Carbon::parse($return['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if (! isset($daily[$date])) {
                continue;
            }
            $returnCount++;
            $returnValue += $return['total'];
            $refunds += $return['refund'];
            $daily[$date]['sales'] -= $return['total'];
            $daily[$date]['collections'] -= $return['refund'];
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
            if (isset($daily[$date])) {
                $daily[$date]['collections'] += $repayment['amount'];
                $repaid += $repayment['amount'];
            }
        }
        $outstanding = 0;
        $overdue = 0;
        $cashLent = 0;
        $customers = [];
        foreach (SessionCredit::debts() as $debt) {
            $outstanding += $debt['balance'];
            $isOverdue = $debt['balance'] > 0 && $debt['dueDate'] && $debt['dueDate'] < $today->toDateString();
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
            if ($debt['type'] === 'Cash loan' && isset($daily[$date])) {
                $cashLent += $debt['principal'];
            }
        }
        $lossUnits = 0;
        $lossCost = 0;
        $uncosted = 0;
        $reasons = [];
        foreach (SessionLosses::all() as $loss) {
            $date = Carbon::parse($loss['time'])->setTimezone('Asia/Shanghai')->toDateString();
            if (! isset($daily[$date])) {
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
            if (isset($daily[$date])) {
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
            if (isset($daily[$date])) {
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
            if (isset($daily[$date])) {
                $supplierPaid += $payment['amount'];
            }
        }

        return ['daily' => array_values($daily), 'payments' => $payments, 'products' => array_slice($products, 0, 10),
            'customers' => $customers, 'transactions' => $transactions, 'revenue' => $revenue,
            'average' => $transactions ? (int) round($grossSales / $transactions) : 0,
            'immediate' => $immediate, 'repaid' => $repaid, 'collections' => $immediate + $repaid - $refunds,
            'grossSales' => $grossSales, 'returnValue' => $returnValue, 'refunds' => $refunds, 'returnCount' => $returnCount,
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
