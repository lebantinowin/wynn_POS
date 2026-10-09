<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Grocery · {{ ucfirst($section) }}</title>
    <link rel="preload" href="{{ asset('fonts/InterVariable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/grocery.css') }}?v=2">
    @livewireStyles
</head>
<body>
@php
    $navigation = ['dashboard'=>'Dashboard','checkout'=>'Checkout','products'=>'Products','inventory'=>'Inventory','purchasing'=>'Purchasing & Suppliers','sales'=>'Sales & Returns','cash'=>'Shifts & Expenses','reports'=>'Reports','settings'=>'Settings'];
    $icons = [
        'dashboard'=>'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
        'checkout'=>'M3 3h2l2.5 12h11L21 6H6 M9 20h.01 M18 20h.01',
        'products'=>'M12 3 3 8v9l9 5 9-5V8z M3 8l9 5 9-5 M12 13v9 M7.5 5.5l9 5',
        'inventory'=>'M3 9V4h18v5 M5 9v12h14V9 M3 9h18 M9 13h6',
        'purchasing'=>'M3 7h11v10H3z M14 10h4l3 4v3h-7 M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4 M18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
        'sales'=>'M6 3h12v18l-3-2-3 2-3-2-3 2z M9 7h6 M9 11h6 M9 15h3',
        'cash'=>'M3 6h18v14H3z M3 10h18 M16 15h2 M7 3h10',
        'reports'=>'M4 3v18h17 M8 16v-5 M13 16V6 M18 16v-8',
        'settings'=>'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z',
    ];
    $descriptions = ['dashboard'=>'A clear view of your shop’s performance.','checkout'=>'Find products, build a cart, and review payments.','products'=>'Manage products, prices, and package options.','inventory'=>'Keep stock counts and incoming deliveries in order.','purchasing'=>'Manage suppliers, plan orders, and receive deliveries.','sales'=>'Review sales, receipts, and returns.','cash'=>'Keep track of cash sessions and business expenses.','reports'=>'Explore sales, profitability, and stock performance.','settings'=>'Configure your shop and checkout preferences.'];
@endphp
<div class="app" x-data="{menu:false}" @keydown.escape.window="menu=false">
    <button class="scrim" x-show="menu" x-cloak @click="menu=false" aria-label="Close navigation"></button>
    <aside :class="{'open':menu}" aria-label="Main navigation">
        <a class="brand" href="/dashboard"><span class="brand-mark">G</span><div><strong>Grocery</strong><span>Business workspace</span></div></a>
        <div class="nav-label">WORKSPACE</div>
        <nav>
            @foreach($navigation as $key=>$label)
            <a href="/{{ $key }}" @if($section===$key) aria-current="page" @endif class="{{ $section===$key?'active':'' }} {{ in_array($key,['dashboard','reports'])?'analytics-link':'operations-link' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icons[$key] }}"/></svg>
                <span>{{ $label }}</span>
            </a>
            @endforeach
        </nav>
        <div class="owner-card"><span class="avatar">OW</span><div><strong>Owner workspace</strong><small>One shop · Counter 1</small></div></div>
    </aside>
    <main>
        <div class="topbar">
            <button class="menu" @click="menu=!menu" :aria-expanded="menu" aria-label="Toggle navigation"><svg class="button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 6h16M4 12h16M4 18h16"/></svg>Menu</button>
            <div class="breadcrumb">Workspace <strong>/ &nbsp; {{ $navigation[$section] }}</strong></div>
            <span class="badge"><span class="badge-dot"></span>Sample workspace</span>
        </div>
        <div class="content">
            <header class="page-heading"><div><h1>{{ $navigation[$section] }}</h1><p>{{ $descriptions[$section] }}</p></div><span class="badge">Counter 1</span></header>
            <div class="notice preview-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/></svg><span><strong>Preview mode.</strong> Sales are not saved. Product and inventory changes stay in this browser session.</span></div>
            <div class="mobile-notice">Mobile access is for analytics. Open Dashboard or Reports; use desktop or tablet for operations.</div>
            <div class="{{ in_array($section,['dashboard','reports'])?'analytics':'operations' }}">
                @if($section==='checkout')<livewire:checkout />
                @elseif($section==='products')<livewire:products />
                @elseif($section==='inventory')<livewire:inventory />
                @elseif($section==='purchasing')<livewire:purchasing />
                @elseif(in_array($section,['dashboard','reports']))
                <div class="metrics">
                    @foreach(['Net sales'=>'4,800.00','Transactions'=>'32','Gross profit'=>'960.00','Average basket'=>'150.00'] as $label=>$value)
                    <section class="panel"><small>{{ $label }}</small><h2>{{ $value }}</h2><span class="muted">Illustrative figures</span></section>
                    @endforeach
                </div>
                <div class="columns"><section class="panel"><h2>Payment overview</h2>@foreach(['Cash'=>3000,'GCash'=>1200,'Maya'=>600] as $label=>$value)<p class="spread product"><span>{{ $label }}</span><strong>{{ number_format($value,2) }}</strong></p>@endforeach<p class="muted">Sample totals · Live reporting coming later.</p></section><section class="panel"><h2>Reports being prepared</h2><p>Sales trends · Product margins · Inventory turnover · Expiry and waste · Supplier balances · Expenses</p><p class="muted">Date filters, drill-downs and exports are future milestones.</p></section></div>
                @else<section class="panel"><div class="empty-state"><h2>{{ $navigation[$section] }}</h2><p>This workspace is planned for an upcoming step.</p><a class="button" href="/checkout">Explore sample checkout</a></div></section>@endif
            </div>
            <footer><details><summary>About this preview</summary><p>Temporary session data is not synchronized or backed up. Owner login, completed sales, offline checkout, database persistence, physical printing and live analytics are pending. Currency and tax settings are not yet configured.</p></details></footer>
        </div>
    </main>
</div>
@livewireScripts
</body>
</html>
