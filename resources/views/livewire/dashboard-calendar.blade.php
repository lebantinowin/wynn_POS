<div x-data x-on:calendar-day-selected.window="$nextTick(() => $el.querySelector('.calendar-day-details')?.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',block:'start'}))">
    <div class="section-heading"><div><h2>Overall performance</h2><p class="muted">All confirmed records · {{ $overall['from'] }} — {{ $overall['to'] }}</p></div><button wire:click="$refresh" wire:loading.attr="disabled">Refresh</button></div>
    <div class="metrics panel-grid">
        <section class="panel"><small>Overall net sales</small><h2>{{ \App\Support\ShopPreferences::amount($overall['revenue']) }}</h2><span class="muted">{{ $overall['transactions'] }} sales · {{ $overall['returnCount'] }} returns</span></section>
        <section class="panel"><small>Overall net collections</small><h2>{{ \App\Support\ShopPreferences::amount($overall['collections']) }}</h2><span class="muted">Paid sales + repayments − refunds</span></section>
        <section class="panel"><small>Overall gross profit</small><h2>{{ $overall['grossProfit'] !== null ? \App\Support\ShopPreferences::amount($overall['grossProfit']) : '—' }}</h2><span class="muted">{{ $overall['grossProfit'] !== null ? 'Estimated from saved acquisition costs' : 'Unknown acquisition costs' }}</span></section>
        <section class="panel"><small>Overall operating profit</small><h2>{{ $overall['operatingProfit'] !== null ? \App\Support\ShopPreferences::amount($overall['operatingProfit']) : '—' }}</h2><span class="muted">{{ $overall['operatingProfit'] !== null ? 'After paid expenses and stock loss costs' : 'Unknown acquisition or loss costs' }}</span></section>
    </div>
    <p class="muted">Overall paid expenses: {{ \App\Support\ShopPreferences::amount($overall['expenses']) }} · Known stock loss costs: {{ \App\Support\ShopPreferences::amount($overall['lossCost']) }} · Changing the calendar selection keeps these overall totals.</p>
    <p class="muted">Current customer balances: {{ \App\Support\ShopPreferences::amount($overall['outstanding']) }} · Supplier payables: {{ \App\Support\ShopPreferences::amount($overall['supplierOutstanding']) }} · Low-stock products: {{ count($overall['lowStock']) }}.</p>
    @if($overall['uncostedSalesLines'] || $overall['uncosted'])<p class="notice">Overall cost coverage: {{ $overall['uncostedSalesLines'] }} sale/return lines and {{ $overall['uncosted'] }} stock-loss records have unknown cost. Profit totals needing those costs are withheld.</p>@endif
    <section class="panel calendar-panel">
        <div class="section-heading calendar-heading"><div><span class="section-kicker">Your shop, day by day</span><h2>{{ $calendarMonth['label'] }}</h2><p class="muted">Select a day to review sales, profit and recorded activity.</p></div><div class="calendar-navigation">
            <label>Month<select wire:model.live="calendarMonthNumber" wire:loading.attr="disabled">@foreach(range(1,12) as $monthNumber)<option value="{{ $monthNumber }}" @disabled($calendarYear === now('Asia/Shanghai')->year && $monthNumber > now('Asia/Shanghai')->month)>{{ \Illuminate\Support\Carbon::create(2000, $monthNumber, 1)->format('F') }}</option>@endforeach</select></label>
            <label>Year<select wire:model.live="calendarYear" wire:loading.attr="disabled">@foreach(range(now('Asia/Shanghai')->year,1900) as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach</select></label>
        </div></div>
        @error('month')<p class="error" role="alert">{{ $message }}</p>@enderror
        @error('calendarYear')<p class="error" role="alert">{{ $message }}</p>@enderror
        @error('calendarMonthNumber')<p class="error" role="alert">{{ $message }}</p>@enderror
        @error('selectedDay')<p class="error" role="alert">{{ $message }}</p>@enderror
        <div class="calendar-month-summary"><span>Month net sales <strong>{{ \App\Support\ShopPreferences::amount($calendarMonth['summary']['revenue']) }}</strong></span><span>Completed sales <strong>{{ $calendarMonth['summary']['transactions'] }}</strong></span><span class="muted">Asia/Shanghai · Confirmed records</span></div>
        <div class="calendar-weekdays" aria-hidden="true">@foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $weekday)<span>{{ $weekday }}</span>@endforeach</div>
        <div class="calendar-grid" role="group" aria-label="{{ $calendarMonth['label'] }} sales calendar" wire:loading.class="calendar-busy" wire:target="viewDay,moveMonth,currentMonth">
            @foreach($calendarMonth['cells'] as $cell)
                @if(!$cell['inMonth'])<div class="calendar-padding" aria-hidden="true"></div>
                @else<button type="button" class="calendar-date {{ $cell['today'] ? 'is-today' : '' }} {{ $selectedDay === $cell['date'] ? 'is-selected' : '' }}" wire:key="calendar-day-{{ $cell['date'] }}" wire:click="viewDay('{{ $cell['date'] }}')" wire:loading.attr="disabled" @disabled($cell['future']) aria-pressed="{{ $selectedDay === $cell['date'] ? 'true' : 'false' }}" aria-label="View {{ \Illuminate\Support\Carbon::parse($cell['date'])->format('F j, Y') }}{{ $cell['future'] ? ', future day' : ', net sales '.\App\Support\ShopPreferences::amount($cell['sales']) }}" title="{{ $cell['date'] }}{{ $cell['future'] ? ' · Future day' : ' · Net sales '.\App\Support\ShopPreferences::amount($cell['sales']) }}">
                    <span class="calendar-day-number">{{ $cell['day'] }}@if($cell['today'])<span class="sr-only"> · Today</span>@endif</span>
                    @if(!$cell['future'])<span class="calendar-day-sales">{{ \App\Support\ShopPreferences::amount($cell['sales']) }}</span><small class="calendar-day-count">{{ $cell['transactions'] }} {{ $cell['transactions'] === 1 ? 'sale' : 'sales' }}</small>@else<small class="calendar-day-count">—</small>@endif
                </button>@endif
            @endforeach
        </div>
        <small class="muted calendar-help">Calendar totals are net sales after discounts and returns. Future days are unavailable. Use Reports for period charts, balances and exports.</small>
    </section>
    @if($daySummary)
    <section class="panel calendar-day-details" aria-live="polite" wire:key="calendar-details-{{ $selectedDay }}">
        <div class="section-heading"><div><span class="section-kicker">Selected day</span><h2>{{ \Illuminate\Support\Carbon::parse($selectedDay)->format('l, F j, Y') }}</h2><p class="muted">Recorded on this day · {{ $daySummary['transactions'] }} sales · {{ $daySummary['returnCount'] }} returns</p></div><span class="badge">{{ $selectedDay === now('Asia/Shanghai')->toDateString() ? 'Today' : 'Historical day' }}</span></div>
        <div class="calendar-day-metrics">
            <div><small>Net sales</small><strong>{{ \App\Support\ShopPreferences::amount($daySummary['revenue']) }}</strong></div>
            <div><small>Net collections</small><strong>{{ \App\Support\ShopPreferences::amount($daySummary['collections']) }}</strong></div>
            <div><small>Estimated gross profit</small><strong>{{ $daySummary['grossProfit'] !== null ? \App\Support\ShopPreferences::amount($daySummary['grossProfit']) : 'Unknown cost' }}</strong></div>
            <div><small>Estimated operating profit</small><strong>{{ $daySummary['operatingProfit'] !== null ? \App\Support\ShopPreferences::amount($daySummary['operatingProfit']) : 'Unknown cost' }}</strong></div>
            <div><small>Cost of goods</small><strong>{{ $daySummary['uncostedSalesLines'] === 0 ? \App\Support\ShopPreferences::amount($daySummary['knownSalesCost']) : 'Unknown cost' }}</strong></div>
            <div><small>Paid expenses</small><strong>{{ \App\Support\ShopPreferences::amount($daySummary['expenses']) }}</strong></div>
            <div><small>Stock loss cost</small><strong>{{ $daySummary['uncosted'] === 0 ? \App\Support\ShopPreferences::amount($daySummary['lossCost']) : 'Unknown cost' }}</strong></div>
            <div><small>Money refunded</small><strong>{{ \App\Support\ShopPreferences::amount($daySummary['refunds']) }}</strong></div>
        </div>
        <p class="muted">Gross margin: {{ $daySummary['grossMargin'] !== null ? number_format($daySummary['grossMargin'],1).'%' : '—' }} · Discounts given: {{ \App\Support\ShopPreferences::amount($daySummary['discounts']) }} · Returns: {{ \App\Support\ShopPreferences::amount($daySummary['returnValue']) }} · Repayments collected: {{ \App\Support\ShopPreferences::amount($daySummary['repaid']) }} · Lost stock: {{ $daySummary['lossUnits'] }} base units.</p>
        @if($daySummary['uncostedSalesLines'] || $daySummary['uncosted'])<p class="notice">{{ $daySummary['uncostedSalesLines'] }} sale/return lines and {{ $daySummary['uncosted'] }} stock-loss records have unknown cost. Profit totals needing those costs are withheld. Known stock loss cost: {{ \App\Support\ShopPreferences::amount($daySummary['lossCost']) }}.</p>@endif
        <p class="muted">Gross profit uses discounted sales less their saved acquisition costs. Operating profit also subtracts this day’s paid expenses and stock loss costs. Tax, unrecorded expenses and drawer differences are excluded.</p>
        <h3>Sales, returns &amp; repayments</h3>
        <div class="table-wrap"><table><thead><tr><th>Time</th><th>Activity</th><th>Reference</th><th>Payment / refund</th><th>Net sales</th><th>Net collections</th></tr></thead><tbody>
            @forelse($dayActivity['rows'] as $activity)<tr wire:key="calendar-activity-{{ $activity['id'] }}"><td>{{ \Illuminate\Support\Carbon::parse($activity['time'])->format('g:i A') }}</td><td>{{ $activity['type'] }}<small class="backup-id">{{ $activity['description'] }}</small></td><td>{{ $activity['reference'] }}</td><td>{{ $activity['method'] === 'Credit' ? 'Grocery credit' : $activity['method'] }}</td><td>{{ \App\Support\ShopPreferences::amount($activity['sales']) }}</td><td>{{ \App\Support\ShopPreferences::amount($activity['collections']) }}</td></tr>@empty<tr><td colspan="6">No confirmed sales, returns or repayments on this day.</td></tr>@endforelse
        </tbody></table></div>
        <p class="muted">Credit sales add revenue; repayments add collections. Returns belong to their recorded return day, and refunds reduce collections. Cash loans given, expenses and supplier payments are separate money movements. Pending offline sales are excluded until synchronized.</p>
    </section>
    @else<section class="panel"><div class="empty-state"><h3>Choose a day on the calendar</h3><p>Its sales, profit, expenses and recorded activity will appear here.</p></div></section>@endif
</div>
