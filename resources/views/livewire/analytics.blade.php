<div>
    <div class="analytics-toolbar"><div><span class="section-kicker">Shop performance</span><p>{{ $summary['from'] }} — {{ $summary['to'] }}</p><small class="muted">Asia/Shanghai · Updated {{ $summary['updated'] }} · Recorded activity</small></div><div class="analytics-controls"><label>Report period<select wire:model.live="days"><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option></select></label><button wire:click="$refresh" wire:loading.attr="disabled">Refresh</button></div></div>
    <section class="panel report-export-panel" aria-label="Report downloads">
        <div><h2>Take your reports with you</h2><p class="muted">Download a CSV or open a print view to print or save as PDF.</p></div>
        <div class="report-export-controls">
            <label>Report to export<select wire:model.live="reportType">@foreach($reportTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <div class="report-export-actions" wire:loading.class="report-export-busy" wire:loading.attr="inert" wire:target="days,reportType">
                <a class="button" href="{{ route('reports.csv', ['type' => $reportType, 'days' => $days]) }}">Download CSV</a>
                <a class="button secondary" href="{{ route('reports.print', ['type' => $reportType, 'days' => $days]) }}" target="_blank" rel="noopener">Print view <span class="sr-only">(opens a new tab)</span></a>
            </div>
        </div>
        <small class="muted">Uses the report period above. Inventory, expiry and unpaid balances show current records across all dates. Pending offline sales are excluded until synchronized.</small>
    </section>
    <div class="metrics panel-grid">
        <section class="panel"><small>Net sales revenue</small><h2>{{ \App\Support\ShopPreferences::amount($summary['revenue']) }}</h2><span class="muted">Includes credit sales; subtracts returns</span></section>
        <section class="panel"><small>Completed sales</small><h2>{{ $summary['transactions'] }}</h2><span class="muted">Selected period</span></section>
        <section class="panel"><small>Average basket</small><h2>{{ \App\Support\ShopPreferences::amount($summary['average']) }}</h2><span class="muted">Original sales ÷ completed transactions</span></section>
        <section class="panel"><small>Net money collected</small><h2>{{ \App\Support\ShopPreferences::amount($summary['collections']) }}</h2><span class="muted">Paid sales + repayments − refunds</span></section>
    </div>
    <p class="notice">Before discounts: {{ \App\Support\ShopPreferences::amount($summary['salesBeforeDiscount']) }} · Discounts given: {{ \App\Support\ShopPreferences::amount($summary['discounts']) }} · Discount share reversed on returns: {{ \App\Support\ShopPreferences::amount($summary['returnedDiscounts']) }}. Profit uses the discounted selling amounts.</p>
    <p class="muted">Sales after discounts: {{ \App\Support\ShopPreferences::amount($summary['grossSales']) }} · Returns: {{ \App\Support\ShopPreferences::amount($summary['returnValue']) }} across {{ $summary['returnCount'] }} records · Money refunded: {{ \App\Support\ShopPreferences::amount($summary['refunds']) }}. Returns reduce sales on the day they are recorded.</p>
    <div class="section-heading"><div><h2>Profit estimates</h2><p class="muted">Selected period · Recorded batch costs saved on new sales and returns</p></div></div>
    <div class="metrics panel-grid">
        <section class="panel"><small>Estimated cost of goods</small><h2>{{ $summary['uncostedSalesLines'] === 0 ? \App\Support\ShopPreferences::amount($summary['knownSalesCost']) : '—' }}</h2><span class="muted">Cost of sold base units</span></section>
        <section class="panel"><small>Estimated gross profit</small><h2>{{ $summary['grossProfit'] !== null ? \App\Support\ShopPreferences::amount($summary['grossProfit']) : '—' }}</h2><span class="muted">Sales revenue − cost of goods</span></section>
        <section class="panel"><small>Gross margin</small><h2>{{ $summary['grossMargin'] !== null ? number_format($summary['grossMargin'],1).'%' : '—' }}</h2><span class="muted">Gross profit ÷ sales revenue</span></section>
        <section class="panel"><small>Estimated operating profit</small><h2>{{ $summary['operatingProfit'] !== null ? \App\Support\ShopPreferences::amount($summary['operatingProfit']) : '—' }}</h2><span class="muted">Gross profit − paid expenses − stock loss costs</span></section>
    </div>
    <div class="notice">
        <strong>Cost coverage:</strong> Net sales with recorded costs: {{ \App\Support\ShopPreferences::amount($summary['costedRevenue']) }}. Total net sales: {{ \App\Support\ShopPreferences::amount($summary['revenue']) }}.
        @if($summary['uncostedSalesLines'])<br>{{ $summary['uncostedSalesLines'] }} sale/return {{ $summary['uncostedSalesLines'] === 1 ? 'line has' : 'lines have' }} unknown cost. Overall gross profit and margin are withheld. Profit from costed lines only: {{ \App\Support\ShopPreferences::amount($summary['costedProfit']) }}.@endif
        @if($summary['uncosted'])<br>Operating profit is withheld because {{ $summary['uncosted'] }} stock-loss {{ $summary['uncosted'] === 1 ? 'entry has' : 'entries have' }} unknown cost.@endif
        <br>New sales use the acquisition cost of the batches sold. Returns reverse their original allocated cost; losses use the affected batches. Older receipts retain their saved cost basis. Unknown stock costs are not replaced by the current product default. Manual loss estimates are labeled separately. Taxes, unrecorded expenses and cash-session differences are excluded. Loan principal and repayments do not add profit.
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
            <title id="trend-title">Daily sales and money collections</title><desc id="trend-desc">Sales total {{ \App\Support\ShopPreferences::amount($summary['revenue']) }}; collections total {{ \App\Support\ShopPreferences::amount($summary['collections']) }}. Exact daily values are available in the table below.</desc>
            @foreach([0,0.5,1] as $level)
            @php $tick = ($chartMin + ($chartMax - $chartMin) * $level) /100; $tickLabel = abs($tick) >= 1000000 ? number_format($tick/1000000,1).'M' : (abs($tick) >= 1000 ? number_format($tick/1000,1).'K' : number_format($tick,0)); @endphp
            <line x1="58" y1="{{ 198 - $level * 160 }}" x2="662" y2="{{ 198 - $level * 160 }}" class="chart-grid"/><text x="49" y="{{ 202 - $level * 160 }}" text-anchor="end">{{ $tickLabel }}</text>@endforeach
            <polyline points="{{ implode(' ', $series['sales']) }}" class="sales-line"/>
            <polyline points="{{ implode(' ', $series['collections']) }}" class="collections-line"/>
            <text x="58" y="225">{{ $summary['daily'][0]['label'] }}</text><text x="360" y="225" text-anchor="middle">{{ $summary['daily'][(int) floor(count($summary['daily'])/2)]['label'] }}</text><text x="662" y="225" text-anchor="end">{{ $summary['daily'][count($summary['daily'])-1]['label'] }}</text>
        </svg>
        @else<div class="chart-empty"><span class="section-kicker">Your sales story starts here</span><p>Complete a sale or record a repayment to see the daily trend.</p></div>@endif
        <div class="daily-activity-toolbar"><p class="muted">Choose a day to see the records behind its totals.</p><button wire:click="viewDay('{{ now('Asia/Shanghai')->toDateString() }}')" wire:loading.attr="disabled">View today’s activity</button></div>
        @error('selectedDay')<p class="error" role="alert">{{ $message }}</p>@enderror
        <details class="ledger-details"><summary>View daily totals &amp; activity</summary><div class="table-wrap"><table><thead><tr><th>Date</th><th>Sales</th><th>Collections</th><th>Activity</th></tr></thead><tbody>@foreach($summary['daily'] as $day)<tr><td>{{ $day['date'] }}</td><td>{{ \App\Support\ShopPreferences::amount($day['sales']) }}</td><td>{{ \App\Support\ShopPreferences::amount($day['collections']) }}</td><td><button wire:click="viewDay('{{ $day['date'] }}')" wire:loading.attr="disabled" aria-label="View activity for {{ $day['date'] }}" @if($selectedDay === $day['date']) aria-pressed="true" @else aria-pressed="false" @endif>View activity</button></td></tr>@endforeach</tbody></table></div></details>
        @if($dayActivity)
        <div class="daily-activity" wire:key="daily-activity-{{ $dayActivity['date'] }}" aria-live="polite">
            <div class="section-heading"><div><h3>Activity for {{ \Illuminate\Support\Carbon::parse($dayActivity['date'])->format('M j, Y') }}</h3><p class="muted">{{ $dayActivity['count'] }} recorded entries · Asia/Shanghai</p></div><button wire:click="clearDay" wire:loading.attr="disabled">Close day</button></div>
            <div class="daily-activity-totals"><div><small>Net sales</small><strong>{{ \App\Support\ShopPreferences::amount($dayActivity['sales']) }}</strong></div><div><small>Net collections</small><strong>{{ \App\Support\ShopPreferences::amount($dayActivity['collections']) }}</strong></div></div>
            <div class="table-wrap"><table><thead><tr><th>Time</th><th>Activity</th><th>Reference</th><th>Payment / refund</th><th>Net sales</th><th>Net collections</th></tr></thead><tbody>
                @forelse($dayActivity['rows'] as $activity)<tr wire:key="activity-{{ $activity['id'] }}"><td>{{ \Illuminate\Support\Carbon::parse($activity['time'])->format('g:i A') }}</td><td>{{ $activity['type'] }}<small class="backup-id">{{ $activity['description'] }}</small></td><td>{{ $activity['reference'] }}</td><td>{{ $activity['method'] === 'Credit' ? 'Grocery credit' : $activity['method'] }}</td><td>{{ \App\Support\ShopPreferences::amount($activity['sales']) }}</td><td>{{ \App\Support\ShopPreferences::amount($activity['collections']) }}</td></tr>@empty<tr><td colspan="6">No confirmed sales, returns or repayments on this day.</td></tr>@endforelse
            </tbody></table></div>
            <p class="muted">Credit sales add sales revenue when recorded; repayments add collections on their payment date. Returns reduce sales, and only money refunded reduces collections. Cash loans given, expenses and supplier payments are separate from these totals. Pending offline sales are excluded.</p>
            <small class="muted">CSV downloads and print views above still use the full selected report period.</small>
        </div>
        @endif
    </section>
    <div class="columns panel-grid">
        <section class="panel"><h2>Net sales by original payment method</h2><p class="muted">Returns subtract from the original sale method. Refund money may use another method.</p>
            @foreach($summary['payments'] as $method=>$amount)<div class="bar-row"><div class="spread"><span>{{ $method === 'Credit' ? 'Grocery credit' : $method }}</span><strong>{{ \App\Support\ShopPreferences::amount($amount) }} <small class="muted">· {{ $summary['revenue'] > 0 && min($summary['payments']) >= 0 ? round($amount/$summary['revenue']*100).'%' : '—' }}</small></strong></div><div class="bar-track"><div class="bar-fill" style="width:{{ $summary['revenue'] > 0 ? min(100, max(0, $amount/$summary['revenue']*100)) : 0 }}%"></div></div></div>@endforeach
            <p class="muted">Paid sales: {{ \App\Support\ShopPreferences::amount($summary['immediate']) }} · Repayments: {{ \App\Support\ShopPreferences::amount($summary['repaid']) }} · Refunds: {{ \App\Support\ShopPreferences::amount($summary['refunds']) }}</p>
        </section>
        <section class="panel"><h2>Top products</h2><p class="muted">Ranked by net sales revenue · Return units subtracted · Profit is estimated</p><div class="table-wrap"><table><thead><tr><th>Product</th><th>Net units sold</th><th>Net revenue</th><th>Gross profit</th></tr></thead><tbody>
            @forelse($summary['products'] as $product)<tr><td class="wrappable">{{ $product['name'] }}</td><td>{{ $product['units'] }}</td><td>{{ \App\Support\ShopPreferences::amount($product['revenue']) }}</td><td>{{ $product['uncostedLines'] === 0 ? \App\Support\ShopPreferences::amount($product['profit']) : 'Unknown cost' }}</td></tr>@empty<tr><td colspan="4">No product sales in this period.</td></tr>@endforelse
        </tbody></table></div></section>
    </div>
    <div class="metrics panel-grid">
        <section class="panel"><small>Customer balances</small><h2>{{ \App\Support\ShopPreferences::amount($summary['outstanding']) }}</h2><span class="muted">All-time outstanding debts</span></section>
        <section class="panel"><small>Overdue balances</small><h2>{{ \App\Support\ShopPreferences::amount($summary['overdue']) }}</h2><span class="muted">Due before today · All-time</span></section>
        <section class="panel"><small>Cash loans given</small><h2>{{ \App\Support\ShopPreferences::amount($summary['cashLent']) }}</h2><span class="muted">Selected period · Excluded from sales</span></section>
        <section class="panel"><small>Lost stock units</small><h2>{{ $summary['lossUnits'] }}</h2><span class="muted">Selected period · Base units</span></section>
    </div>
    <div class="columns panel-grid">
        <section class="panel"><h2>Customer receivables</h2><p class="muted">Current remaining balances for grocery credit and cash loans</p><div class="table-wrap"><table><thead><tr><th>Customer</th><th>Outstanding</th><th>Overdue</th></tr></thead><tbody>
            @forelse($summary['customers'] as $customer)<tr><td class="wrappable">{{ $customer['name'] }}</td><td>{{ \App\Support\ShopPreferences::amount($customer['balance']) }}</td><td>{{ \App\Support\ShopPreferences::amount($customer['overdue']) }}</td></tr>@empty<tr><td colspan="3">No outstanding customer debt.</td></tr>@endforelse
        </tbody></table></div></section>
        <section class="panel"><h2>Stock losses by reason</h2><p class="muted">Base units lost in the selected period</p>@forelse($summary['reasons'] as $reason=>$units)<div class="bar-row"><div class="spread"><span>{{ $reason }}</span><strong>{{ $units }}</strong></div><div class="bar-track"><div class="bar-fill loss-fill" style="width:{{ $units/$summary['lossUnits']*100 }}%"></div></div></div>@empty<p class="empty">No losses recorded in this period.</p>@endforelse
            <p><strong>Known loss cost: {{ \App\Support\ShopPreferences::amount($summary['lossCost']) }}</strong></p><p class="muted">{{ $summary['uncosted'] }} loss {{ $summary['uncosted']===1 ? 'entry has' : 'entries have' }} unknown cost; any recorded portion is included in this amount.</p>
        </section>
    </div>
    <section class="panel"><h2>Low-stock watch · {{ count($summary['lowStock']) }} products</h2><p class="muted">Current stock at or below each product’s reorder threshold</p><div class="table-wrap"><table><thead><tr><th>Product</th><th>Available</th><th>Reorder at</th></tr></thead><tbody>
        @forelse($summary['lowStock'] as $product)<tr><td class="wrappable">{{ $product['name'] }}</td><td>{{ $product['stock'] }} {{ $product['unit'] }}s</td><td>{{ $product['reorder'] ?? 0 }}</td></tr>@empty<tr><td colspan="3">All products are above their reorder thresholds.</td></tr>@endforelse
    </tbody></table></div></section>
    <div class="columns panel-grid"><section class="panel"><h2>Recorded expenses</h2><p class="muted">Paid operating expenses in the selected period · {{ \App\Support\ShopPreferences::amount($summary['expenses']) }} total</p>
        @forelse($summary['expenseCategories'] as $category=>$amount)<div class="bar-row"><div class="spread"><span>{{ $category }}</span><strong>{{ \App\Support\ShopPreferences::amount($amount) }}</strong></div><div class="bar-track"><div class="bar-fill loss-fill" style="width:{{ $amount/$summary['expenses']*100 }}%"></div></div></div>@empty<p class="empty">No expenses recorded in this period.</p>@endforelse
    </section><section class="panel"><h2>Cash-session differences</h2><p class="muted">Sessions closed in the selected period · Physical drawer counts</p><div class="money-summary"><div><small class="muted">Shortages</small><strong>{{ \App\Support\ShopPreferences::amount($summary['cashShortages']) }}</strong></div><div><small class="muted">Surpluses</small><strong>{{ \App\Support\ShopPreferences::amount($summary['cashSurpluses']) }}</strong></div></div><p class="muted">Drawer differences stay separate from stock losses and sales revenue.</p></section></div>
    <section class="panel"><h2>Supplier payables</h2><div class="metrics cash-metrics"><div><small>Outstanding bills</small><h2>{{ \App\Support\ShopPreferences::amount($summary['supplierOutstanding']) }}</h2><span class="muted">Current · All recorded bills</span></div><div><small>Overdue bills</small><h2>{{ \App\Support\ShopPreferences::amount($summary['supplierOverdue']) }}</h2><span class="muted">Due before today</span></div><div><small>Paid to suppliers</small><h2>{{ \App\Support\ShopPreferences::amount($summary['supplierPaid']) }}</h2><span class="muted">Selected period · All methods</span></div></div><p class="muted">Supplier payments reduce debt and available money. They are kept separate from sales collections and operating expenses so stock cost is not deducted twice.</p><div class="table-wrap"><table><thead><tr><th>Supplier</th><th>Outstanding</th><th>Overdue</th></tr></thead><tbody>@forelse($summary['supplierBalances'] as $supplier)<tr><td class="wrappable">{{ $supplier['name'] }}</td><td>{{ \App\Support\ShopPreferences::amount($supplier['balance']) }}</td><td>{{ \App\Support\ShopPreferences::amount($supplier['overdue']) }}</td></tr>@empty<tr><td colspan="3">No supplier bills recorded.</td></tr>@endforelse</tbody></table></div>@if($summary['unlinkedDeliveries'])<p class="muted">{{ $summary['unlinkedDeliveries'] }} earlier or manual delivery entries have no recorded payment status and are excluded from supplier balances.</p>@endif</section>
    <section class="panel"><h2>Expiry watch</h2><p class="muted">Current remaining stock · Base units across products</p><div class="metrics cash-metrics"><div><small>Expired stock</small><h2>{{ $summary['expiryStock']['expired'] }}</h2></div><div><small>Due within 30 days</small><h2>{{ $summary['expiryStock']['soon'] }}</h2></div><div><small>Expiry unknown</small><h2>{{ $summary['expiryStock']['unknown'] }}</h2></div></div><p class="muted">Known expired stock is excluded from checkout. Undated stock is allowed and needs a physical expiry check. Earlier stock and count additions have unknown dates.</p></section>
    <section class="panel"><h2>Stock acquisition value</h2><p class="muted">Current remaining stock, including expired units. Recorded costs only; this is not a selling-value estimate.</p><div class="metrics cash-metrics"><div><small>Known stock value</small><h2>{{ \App\Support\ShopPreferences::amount($summary['expiryStock']['knownCost']) }}</h2></div><div><small>Units with unknown cost</small><h2>{{ $summary['expiryStock']['unknownCostUnits'] }}</h2></div><div><small>Total stock value</small><h2>{{ $summary['expiryStock']['unknownCostUnits'] === 0 ? \App\Support\ShopPreferences::amount($summary['expiryStock']['knownCost']) : '—' }}</h2></div></div><p class="muted">The total is withheld when any stock cost is unknown. Count increases and legacy returns without cost allocations remain unvalued.</p></section>
    <p class="notice">These reports show recorded shop activity. Amounts are in Philippine pesos (PHP). Tax calculation is unconfigured.</p>
</div>
