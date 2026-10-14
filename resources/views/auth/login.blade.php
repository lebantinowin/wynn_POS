<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ config('pos.practice') ? 'Practice · ' : '' }}Wynn's Mart · Owner sign in</title>
    <link rel="preload" href="{{ asset('fonts/InterVariable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/grocery.css') }}?v=8">
</head>
<body class="auth-page">
    <main class="auth-layout">
        <section class="auth-intro" aria-label="Wynn's Mart workspace">
            <div class="brand"><span class="brand-mark">W</span><div><strong>Wynn's Mart</strong><span>Business workspace</span></div></div>
            <div class="auth-intro-copy"><span class="section-kicker">YOUR SHOP, IN ONE PLACE</span><h1>A clear view.<br>A smoother day.</h1><p>Keep your counter, stock and shop performance connected.</p></div>
            <div class="auth-features"><span>Checkout &amp; receipts</span><span>Stock &amp; purchasing</span><span>Reports &amp; insights</span></div>
            <p class="auth-owner-note">One shop. One owner workspace.</p>
        </section>
        <section class="auth-card" aria-labelledby="login-heading">
            <span class="section-kicker">{{ config('pos.practice') ? 'PRACTICE OWNER ACCESS' : 'OWNER ACCESS' }}</span>
            @if(config('pos.practice'))<p class="notice">Practice shop · Sample records only. Sign in with the separate practice account created in the launcher.</p>@endif
            <h2 id="login-heading">{{ $ownerConfigured ? 'Welcome back' : 'Welcome to Wynn’s Mart' }}</h2>
            <p class="muted">{{ $ownerConfigured ? 'Sign in to continue to your shop.' : 'Set up your owner account on this computer to get started.' }}</p>
            @if($ownerConfigured)
                <form method="POST" action="{{ route('login.store') }}">
                    @csrf
                    <label for="email">Email address<input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required maxlength="255" autofocus @if($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif></label>
                    @error('email')<p class="error auth-error" id="email-error" role="alert">{{ $message }}</p>@enderror
                    <label for="password">Password<input id="password" name="password" type="password" autocomplete="current-password" required maxlength="4096" @if($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif></label>
                    @error('password')<p class="error auth-error" id="password-error" role="alert">{{ $message }}</p>@enderror
                    <button class="primary" type="submit">Sign in</button>
                </form>
                <details class="auth-help"><summary>Forgot your password?</summary><p>On this computer, run <code>&amp; '{{ base_path(config('pos.practice') ? 'run-practice.ps1' : 'setup-owner.ps1') }}' {{ config('pos.practice') ? '-ResetOwner' : '-Reset' }}</code> in PowerShell. {{ config('pos.practice') ? 'Stop the practice server first. This resets only the practice login.' : 'Enter your new password privately, then sign in again on each device.' }}</p></details>
            @else
                <div class="auth-setup"><strong>First-time setup</strong><p>In PowerShell on this computer, run:</p><code>&amp; '{{ base_path(config('pos.practice') ? 'run-practice.ps1' : 'setup-owner.ps1') }}'</code><p>Enter your name, email and a password at the hidden prompt. Then refresh this page to sign in.</p><a class="button" href="{{ route('login') }}">Refresh sign-in page</a></div>
            @endif
            <p class="auth-session-note">Use the same owner account on your desktop and tablet. Mobile gives you access to analytics.</p>
        </section>
    </main>
</body>
</html>
