<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Grocery · {{ ucfirst($section) }}</title><link rel="stylesheet" href="{{ asset('css/grocery.css') }}">@livewireStyles</head>
<body><div class="app" x-data="{menu:false}"><aside :class="{'open':menu}"><a class="brand" href="/dashboard">Grocery<span>Owner workspace</span></a><nav>
@foreach(['dashboard'=>'Dashboard','checkout'=>'Checkout','products'=>'Products','inventory'=>'Inventory','purchasing'=>'Purchasing & Suppliers','sales'=>'Sales & Returns','cash'=>'Shifts & Expenses','reports'=>'Reports','settings'=>'Settings'] as $key=>$label)
<a href="/{{ $key }}" class="{{ $section===$key?'active':'' }} {{ in_array($key,['dashboard','reports'])?'analytics-link':'operations-link' }}">{{ $label }}</a>
@endforeach
</nav><p>One shop · Counter 1<br>Owner operated</p></aside><main><header><button class="menu" @click="menu=!menu">☰ Menu</button><div><small>YOUR BUSINESS, AT A GLANCE</small><h1>{{ ['cash'=>'Shifts & Expenses','purchasing'=>'Purchasing & Suppliers','sales'=>'Sales & Returns'][$section] ?? ucfirst($section) }}</h1></div><span class="badge">Sample workspace</span></header>
<div class="notice">Development preview · Sample data only. No completed sales are saved. Catalogue edits and stock counts use temporary session storage. Database, owner login, and offline checkout are pending.</div>
<div class="mobile-notice">Mobile access is for analytics. Open Dashboard or Reports; use desktop or tablet for operations.</div>
<div class="{{ in_array($section,['dashboard','reports'])?'analytics':'operations' }}">
@if($section==='checkout')<livewire:checkout />
@elseif($section==='products')<livewire:products />
@elseif($section==='inventory')<livewire:inventory />
@elseif(in_array($section,['dashboard','reports']))<div class="metrics">@foreach(['Net sales'=>'4,800.00','Transactions'=>'32','Gross profit'=>'960.00','Average basket'=>'150.00'] as $label=>$value)<section class="panel"><small>{{ $label }}</small><h2>{{ $value }}</h2><span class="muted">Illustrative figures</span></section>@endforeach</div><div class="columns"><section class="panel"><h2>Payment overview</h2>@foreach(['Cash'=>3000,'GCash'=>1200,'Maya'=>600] as $label=>$value)<p class="spread"><span>{{ $label }}</span><strong>{{ number_format($value,2) }}</strong></p>@endforeach<p class="muted">No live data or synchronization yet.</p></section><section class="panel"><h2>Reports being prepared</h2><p>Sales trends · Product margins · Inventory turnover · Expiry and waste · Supplier balances · Expenses</p><p class="muted">Date filters, drill-downs and exports are future milestones.</p></section></div>
@else<section class="panel"><h2>{{ ucfirst($section) }} workspace</h2><p>This module is planned. Its workflow will be implemented after the catalogue and checkout foundation.</p><a class="button" href="/checkout">Explore sample checkout</a></section>@endif
</div><footer>Laravel + Livewire · MySQL setup deferred · Sample prices have no configured currency</footer></main></div>@livewireScripts</body></html>


