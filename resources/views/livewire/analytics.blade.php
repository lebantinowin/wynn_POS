<div>
    <div class="analytics-toolbar"><div><span class="section-kicker">Shop performance</span><p>{{ $summary['from'] }} — {{ $summary['to'] }}</p><small class="muted">Asia/Shanghai · Updated {{ $summary['updated'] }} · Session data</small></div><div class="analytics-controls"><label>Report period<select wire:model.live="days"><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option></select></label><button wire:click="$refresh" wire:loading.attr="disabled">Refresh</button></div></div>
    <div class="metrics panel-grid">
        <section class="panel"><small>Net sales revenue</small><h2>{{ number_format($summary['revenue']/100,2) }}</h2><span class="muted">Includes credit sales; subtracts returns</span></section>
        <section class="panel"><small>Completed sales</small><h2>{{ $summary['transactions'] }}</h2><span class="muted">Selected period</span></section>
        <section class="panel"><small>Average basket</small><h2>{{ number_format($summary['average']/100,2) }}</h2><span class="muted">Original sales ÷ completed transactions</span></section>
        <section class="panel"><small>Net money collected</small><h2>{{ number_format($summary['collections']/100,2) }}</h2><span class="muted">Paid sales + repayments − refunds</span></section>
    </div>
    <p class="notice">Original sales: {{ number_format($summary['grossSales']/100,2) }} · Returns: {{ number_format($summary['returnValue']/100,2) }} across {{ $summary['returnCount'] }} records · Money refunded: {{ number_format($summary['refunds']/100,2) }}. Returns reduce sales on the day they are recorded.</p>
    <div class="section-heading"><div><h2>Profit estimates</h2><p class="muted">Selected period · Purchase costs saved when each sale was completed</p></div></div>
    <div class="metrics panel-grid">
        <section class="panel"><small>Estimated cost of goods</small><h2>{{ $summary['uncostedSalesLines'] === 0 ? number_format($summary['knownSalesCost']/100,2) : '—' }}</h2><span class="muted">Cost of sold base units</span></section>
        <section class="panel"><small>Estimated gross profit</small><h2>{{ $summary['grossProfit'] !== null ? number_format($summary['grossProfit']/100,2) : '—' }}</h2><span class="muted">Sales revenue − cost of goods</span></section>
        <section class="panel"><small>Gross margin</small><h2>{{ $summary['grossMargin'] !== null ? number_format($summary['grossMargin'],1).'%' : '—' }}</h2><span class="muted">Gross profit ÷ sales revenue</span></section>
        <section class="panel"><small>Estimated operating profit</small><h2>{{ $summary['operatingProfit'] !== null ? number_format($summary['operatingProfit']/100,2) : '—' }}</h2><span class="muted">Gross profit − paid expenses − stock loss costs</span></section>
    </div>
    <div class="notice">
        <strong>Cost coverage:</strong> Net sales with recorded costs: {{ number_format($summary['costedRevenue']/100,2) }}. Total net sales: {{ number_format($summary['revenue']/100,2) }}.
        @if($summary['uncostedSalesLines'])<br>{{ $summary['uncostedSalesLines'] }} sale/return {{ $summary['uncostedSalesLines'] === 1 ? 'line has' : 'lines have' }} unknown cost. Overall gross profit and margin are withheld. Profit from costed lines only: {{ number_format($summary['costedProfit']/100,2) }}.@endif
        @if($summary['uncosted'])<br>Operating profit is withheld because {{ $summary['uncosted'] }} stock-loss {{ $summary['uncosted'] === 1 ? 'entry has' : 'entries have' }} unknown cost.@endif
        <br>Estimates use each sale's saved product purchase cost. Supplier delivery costs do not update this estimate automatically. Set costs before completing new sales; older sales are not recalculated. Taxes, unrecorded expenses and cash-session differences are excluded. Loan principal and repayments do not add profit.
    </div>
    <section class="panel"><div class="section-heading"><div><h2>Sales & collections over time</h2><p class="muted">Daily net totals · Credit adds sales; repayments add collections; returns subtract sales and money refunded.</p></div><div class="chart-legend"><span><i class="sales-dot"></i>Net sales</span><span><i class="collections-dot"></i>Net collections</span></div></div>
        @php
            $chartValues = array_merge(array_column($summary['daily'], 'sales'), array_column($summary['daily'], 'collections'));
            $chartMax = max(100, ...array_map('abs', $chartValues));
            $chartMin = min($chartValues) < 0 ? -$chartMax : 0;
            $series = ['sales'=>[], 'collections'=>[]];
            foreach ($summary['daily'] as $index=>$day) {
                $x = 58 + $index * 604 / (count($summary['daily']) - 1);
                foreach ($series as $key=>$points) { $series[$key][] = round($x,2).','.round(198 - ($day[$key] - $chartMin) / ($chartMax - $chartMin) * 160,2); }
            }
        @endphp
        @if($summary['transactions'] || $summary['returnCount'] || $summary['collections'])
        <svg class="trend-chart" viewBox="0 0 700 236" role="img" aria-labelledby="trend-title trend-desc">
            <title id="trend-title">Daily sales and money collections</title><desc id="trend-desc">Sales total {{ number_format($summary['revenue']/100,2) }}; collections total {{ number_format($summary['collections']/100,2) }}. Exact daily values are available in the table below.</desc>
            @foreach([0,0.5,1] as $level)
            @php $tick = ($chartMin + ($chartMax - $chartMin) * $level) /100; $tickLabel = abs($tick) >= 1000000 ? number_format($tick/1000000,1).'M' : (abs($tick) >= 1000 ? number_format($tick/1000,1).'K' : number_format($tick,0)); @endphp
            <line x1="58" y1="{{ 198 - $level * 160 }}" x2="662" y2="{{ 198 - $level * 160 }}" class="chart-grid"/><text x="49" y="{{ 202 - $level * 160 }}" text-anchor="end">{{ $tickLabel }}</text>@endforeach
            <polyline points="{{ implode(' ', $series['sales']) }}" class="sales-line"/>
            <polyline points="{{ implode(' ', $series['collections']) }}" class="collections-line"/>
            <text x="58" y="225">{{ $summary['daily'][0]['label'] }}</text><text x="360" y="225" text-anchor="middle">{{ $summary['daily'][(int) floor(count($summary['daily'])/2)]['label'] }}</text><text x="662" y="225" text-anchor="end">{{ $summary['daily'][count($summary['daily'])-1]['label'] }}</text>
        </svg>
        @else<div class="chart-empty"><span class="section-kicker">Your sales story starts here</span><p>Complete a sale or record a repayment to see the daily trend.</p></div>@endif
        <details class="ledger-details"><summary>View daily totals</summary><div class="table-wrap"><table><thead><tr><th>Date</th><th>Sales</th><th>Collections</th></tr></thead><tbody>@foreach($summary['daily'] as $day)<tr><td>{{ $day['date'] }}</td><td>{{ number_format($day['sales']/100,2) }}</td><td>{{ number_format($day['collections']/100,2) }}</td></tr>@endforeach</tbody></table></div></details>
    </section>
    <div class="columns panel-grid">
        <section class="panel"><h2>Net sales by original payment method</h2><p class="muted">Returns subtract from the original sale method. Refund money may use another method.</p>
            @foreach($summary['payments'] as $method=>$amount)<div class="bar-row"><div class="spread"><span>{{ $method === 'Credit' ? 'Grocery credit' : $method }}</span><strong>{{ number_format($amount/100,2) }} <small class="muted">· {{ $summary['revenue'] > 0 && min($summary['payments']) >= 0 ? round($amount/$summary['revenue']*100).'%' : '—' }}</small></strong></div><div class="bar-track"><div class="bar-fill" style="width:{{ $summary['revenue'] > 0 ? min(100, max(0, $amount/$summary['revenue']*100)) : 0 }}%"></div></div></div>@endforeach
            <p class="muted">Paid sales: {{ number_format($summary['immediate']/100,2) }} · Repayments: {{ number_format($summary['repaid']/100,2) }} · Refunds: {{ number_format($summary['refunds']/100,2) }}</p>
        </section>
        <section class="panel"><h2>Top products</h2><p class="muted">Ranked by net sales revenue · Return units subtracted · Profit is estimated</p><div class="table-wrap"><table><thead><tr><th>Product</th><th>Net units sold</th><th>Net revenue</th><th>Gross profit</th></tr></thead><tbody>
            @forelse($summary['products'] as $product)<tr><td class="wrappable">{{ $product['name'] }}</td><td>{{ $product['units'] }}</td><td>{{ number_format($product['revenue']/100,2) }}</td><td>{{ $product['uncostedLines'] === 0 ? number_format($product['profit']/100,2) : 'Unknown cost' }}</td></tr>@empty<tr><td colspan="4">No product sales in this period.</td></tr>@endforelse
        </tbody></table></div></section>
    </div>
    <div class="metrics panel-grid">
        <section class="panel"><small>Customer balances</small><h2>{{ number_format($summary['outstanding']/100,2) }}</h2><span class="muted">All-time outstanding debts</span></section>
        <section class="panel"><small>Overdue balances</small><h2>{{ number_format($summary['overdue']/100,2) }}</h2><span class="muted">Due before today · All-time</span></section>
        <section class="panel"><small>Cash loans given</small><h2>{{ number_format($summary['cashLent']/100,2) }}</h2><span class="muted">Selected period · Excluded from sales</span></section>
        <section class="panel"><small>Lost stock units</small><h2>{{ $summary['lossUnits'] }}</h2><span class="muted">Selected period · Base units</span></section>
    </div>
    <div class="columns panel-grid">
        <section class="panel"><h2>Customer receivables</h2><p class="muted">Current remaining balances for grocery credit and cash loans</p><div class="table-wrap"><table><thead><tr><th>Customer</th><th>Outstanding</th><th>Overdue</th></tr></thead><tbody>
            @forelse($summary['customers'] as $customer)<tr><td class="wrappable">{{ $customer['name'] }}</td><td>{{ number_format($customer['balance']/100,2) }}</td><td>{{ number_format($customer['overdue']/100,2) }}</td></tr>@empty<tr><td colspan="3">No outstanding customer debt.</td></tr>@endforelse
        </tbody></table></div></section>
        <section class="panel"><h2>Stock losses by reason</h2><p class="muted">Base units lost in the selected period</p>@forelse($summary['reasons'] as $reason=>$units)<div class="bar-row"><div class="spread"><span>{{ $reason }}</span><strong>{{ $units }}</strong></div><div class="bar-track"><div class="bar-fill loss-fill" style="width:{{ $units/$summary['lossUnits']*100 }}%"></div></div></div>@empty<p class="empty">No losses recorded in this period.</p>@endforelse
            <p><strong>Known loss cost: {{ number_format($summary['lossCost']/100,2) }}</strong></p><p class="muted">{{ $summary['uncosted'] }} loss {{ $summary['uncosted']===1 ? 'entry has' : 'entries have' }} unknown cost and {{ $summary['uncosted']===1 ? 'is' : 'are' }} excluded from this amount.</p>
        </section>
    </div>
    <section class="panel"><h2>Low-stock watch · {{ count($summary['lowStock']) }} products</h2><p class="muted">Current stock at or below each product’s reorder threshold</p><div class="table-wrap"><table><thead><tr><th>Product</th><th>Available</th><th>Reorder at</th></tr></thead><tbody>
        @forelse($summary['lowStock'] as $product)<tr><td class="wrappable">{{ $product['name'] }}</td><td>{{ $product['stock'] }} {{ $product['unit'] }}s</td><td>{{ $product['reorder'] ?? 0 }}</td></tr>@empty<tr><td colspan="3">All products are above their reorder thresholds.</td></tr>@endforelse
    </tbody></table></div></section>
    <div class="columns panel-grid"><section class="panel"><h2>Recorded expenses</h2><p class="muted">Paid operating expenses in the selected period · {{ number_format($summary['expenses']/100,2) }} total</p>
        @forelse($summary['expenseCategories'] as $category=>$amount)<div class="bar-row"><div class="spread"><span>{{ $category }}</span><strong>{{ number_format($amount/100,2) }}</strong></div><div class="bar-track"><div class="bar-fill loss-fill" style="width:{{ $amount/$summary['expenses']*100 }}%"></div></div></div>@empty<p class="empty">No expenses recorded in this period.</p>@endforelse
    </section><section class="panel"><h2>Cash-session differences</h2><p class="muted">Sessions closed in the selected period · Physical drawer counts</p><div class="money-summary"><div><small class="muted">Shortages</small><strong>{{ number_format($summary['cashShortages']/100,2) }}</strong></div><div><small class="muted">Surpluses</small><strong>{{ number_format($summary['cashSurpluses']/100,2) }}</strong></div></div><p class="muted">Drawer differences stay separate from stock losses and sales revenue.</p></section></div>
    <p class="notice">These reports show activity saved in this browser session. Currency and tax settings are pending.</p>
</div>
