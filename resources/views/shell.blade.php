<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ config('pos.practice') ? 'Practice · ' : '' }}Wynn's Mart · {{ $section === 'sales' ? 'Transactions' : ucfirst($section) }}</title>
    <link rel="preload" href="{{ asset('fonts/InterVariable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/grocery.css') }}?v=8">
    @livewireStyles
</head>
<body>
@php
    $databaseStorage = \App\Support\PosStore::database();
    $storageReady = \App\Support\PosStore::ready();
    $navigation = ['dashboard'=>'Dashboard','checkout'=>'Checkout','products'=>'Products','inventory'=>'Inventory','purchasing'=>'Purchasing & Suppliers','sales'=>'Transactions','credit'=>'Customer Credit & Loans','losses'=>'Stock Losses','cash'=>'Shifts & Expenses','reports'=>'Reports','settings'=>'Settings'];
    $icons = [
        'dashboard'=>'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
        'checkout'=>'M3 3h2l2.5 12h11L21 6H6 M9 20h.01 M18 20h.01',
        'products'=>'M12 3 3 8v9l9 5 9-5V8z M3 8l9 5 9-5 M12 13v9 M7.5 5.5l9 5',
        'inventory'=>'M3 9V4h18v5 M5 9v12h14V9 M3 9h18 M9 13h6',
        'purchasing'=>'M3 7h11v10H3z M14 10h4l3 4v3h-7 M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4 M18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
        'sales'=>'M6 3h12v18l-3-2-3 2-3-2-3 2z M9 7h6 M9 11h6 M9 15h3',
        'credit'=>'M3 10h18v11H3z M3 14h18 M8 10V6a4 4 0 0 1 8 0v4 M7 18h3 M15 18h2',
        'losses'=>'M12 3 2 21h20z M12 9v5 M12 17h.01',
        'cash'=>'M3 6h18v14H3z M3 10h18 M16 15h2 M7 3h10',
        'reports'=>'M4 3v18h17 M8 16v-5 M13 16V6 M18 16v-8',
        'settings'=>'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z',
    ];
    $descriptions = ['dashboard'=>'A clear view of your shop’s performance.','checkout'=>'Find products, build a cart, and review payments.','products'=>'Manage products, prices, and package options.','inventory'=>'Keep stock counts and incoming deliveries in order.','purchasing'=>'Manage suppliers, plan orders, and receive deliveries.','sales'=>'Search transaction history, reprint receipts, and record returns.','credit'=>'Track grocery credit, money lent, and customer repayments.','losses'=>'Record missing, consumed, damaged, or expired stock.','cash'=>'Keep track of cash sessions and business expenses.','reports'=>'Explore sales, collections, customer balances, and stock losses.','settings'=>'Configure your shop and checkout preferences.'];
@endphp
<div class="app" x-data="{menu:false, compact:window.innerWidth <= 900}" @resize.window.debounce.100ms="compact=window.innerWidth <= 900; if(!compact) menu=false" @keydown.escape.window="menu=false" x-effect="document.body.style.overflow = menu && compact ? 'hidden' : ''">
    <button class="scrim" x-show="menu && compact" x-transition.opacity.duration.300ms x-cloak @click="menu=false" aria-label="Close navigation"></button>
    <aside :class="{'open':menu}" :inert="compact && !menu" :aria-hidden="compact && !menu" aria-label="Main navigation">
        <a class="brand" href="/dashboard" wire:navigate><span class="brand-mark">W</span><div><strong>Wynn's Mart</strong><span>Business workspace</span></div></a>
        <div class="nav-label">WORKSPACE</div>
        <nav>
            @foreach($navigation as $key=>$label)
            <a href="/{{ $key }}" wire:navigate @if($section===$key) aria-current="page" @endif class="{{ $section===$key?'active':'' }} {{ in_array($key,['dashboard','reports'])?'analytics-link':'operations-link' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icons[$key] }}"/></svg>
                <span>{{ $label }}</span>
            </a>
            @endforeach
        </nav>
        <div class="owner-card"><span class="avatar">OW</span><div><strong>{{ auth()->user()->name }}</strong><small>Owner · Counter 1</small></div></div>
        <form class="owner-logout" method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
    </aside>
    <main>
        <div class="topbar">
            <button class="menu" @click="menu=!menu" :aria-expanded="menu" aria-label="Toggle navigation"><svg class="button-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 6h16M4 12h16M4 18h16"/></svg>Menu</button>
            <div class="breadcrumb">Workspace <strong>/ &nbsp; {{ $navigation[$section] }}</strong></div>
            <span class="badge"><span class="badge-dot"></span>{{ config('pos.practice') ? 'Practice shop' : ($databaseStorage && $storageReady ? 'Shared workspace' : 'Sample workspace') }}</span>
        </div>
        <div class="content" wire:transition.navigate>
            <header class="page-heading"><div><h1>{{ $navigation[$section] }}</h1><p>{{ $descriptions[$section] }}</p></div><span class="badge">Counter 1</span></header>
            <div class="notice preview-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/></svg><span>@if(config('pos.practice'))<strong>Practice shop · Sample records only.</strong> Your practice sales, stock and cash are saved in a separate database. Main shop records are untouched.@elseif($databaseStorage && $storageReady)<strong>Shared shop records.</strong> Changes are saved across browser sessions. Refresh to see updates from another device.@else<strong>Preview mode.</strong> Sales, credit, repayments and stock changes stay in this browser session only.@endif</span></div>
            @if(config('pos.practice'))<x-practice-guide :section="$section" />@endif
            @if($databaseStorage && (!$storageReady || $section === 'settings'))<livewire:store-setup />@endif
            <div class="mobile-notice">Mobile access is for analytics. Open Dashboard or Reports; use desktop or tablet for operations.</div>
            @if($databaseStorage && app(\App\Support\OfflineCheckout::class)->active())<div class="notice"><strong>Counter 1 is using offline checkout.</strong> Stock and cash changes are paused. Reports show confirmed records only; pending offline sales are not included. <span class="operations-link"><a href="/offline-checkout.html">Synchronize and finish in the prepared browser</a> · <a href="{{ route('workspace', ['section' => 'settings']) }}#offline-recovery">Unavailable browser? Review recovery</a>.</span></div>@endif
            <div class="{{ in_array($section,['dashboard','reports'])?'analytics':'operations' }}">
                @if($section === 'settings')<section class="panel"><h2>Owner account</h2><p><strong>{{ auth()->user()->name }}</strong><br>{{ auth()->user()->email }}</p><p class="muted">One owner account protects this workspace. Sign out when you finish using a shared device. Sessions expire after two hours of inactivity.</p><details><summary>Change or recover your password</summary><p class="muted">Run <code>&amp; '{{ base_path(config('pos.practice') ? 'run-practice.ps1' : 'setup-owner.ps1') }}' {{ config('pos.practice') ? '-ResetOwner' : '-Reset' }}</code> in PowerShell on this computer. {{ config('pos.practice') ? 'This resets only the practice login. Stop the practice server before rerunning its launcher.' : 'Each device will need to sign in again.' }}</p></details></section>@endif
                @if($section === 'settings' && (!$databaseStorage || $storageReady))<livewire:shop-settings /><livewire:offline-recovery />@endif
                @if($section === 'settings' && !config('pos.practice'))<livewire:backups />@elseif($section === 'settings')<p class="notice">Main shop backup controls are unavailable in practice. Open Settings in your main shop to manage its backups.</p>@endif
                @if(!$databaseStorage || $storageReady)
                @if($section==='checkout')<livewire:checkout />
                @elseif($section==='products')<livewire:products />
                @elseif($section==='inventory')<livewire:inventory />
                @elseif($section==='purchasing')<livewire:purchasing />
                @elseif($section==='sales')<livewire:sales />
                @elseif($section==='credit')<livewire:credit />
                @elseif($section==='losses')<livewire:losses />
                @elseif($section==='cash')<livewire:cash />
                @elseif(in_array($section,['dashboard','reports']))
                <livewire:analytics />
                @elseif($section !== 'settings')<section class="panel"><div class="empty-state"><h2>{{ $navigation[$section] }}</h2><p>This workspace is planned for an upcoming step.</p><a class="button" href="/checkout" wire:navigate>Explore sample checkout</a></div></section>@endif
                @endif
            </div>
            <footer><details><summary>About this workspace</summary><p>@if($databaseStorage && $storageReady)Shop records are stored in PostgreSQL and protected by owner login. Backup status and recovery checks are available in Settings. Prepared offline checkout is available from Checkout. Physical printer integration is pending.@else Temporary session data is not synchronized or backed up. Offline checkout, database persistence and physical printer integration are pending.@endif Philippine pesos (PHP). Tax calculation and BIR invoice issuance are pending.</p></details></footer>
        </div>
    </main>
</div>
<script>
document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 401) {
                preventDefault();
                window.location.assign(@json(route('login')));
            }
        });
    });
});
document.addEventListener('submit', event => {
    if (event.target.matches('.owner-logout')) localStorage.setItem('wynn-offline-signed-out', '1');
});
</script>
@livewireScripts
</body>
</html>
