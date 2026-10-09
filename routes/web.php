<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/dashboard'));
Route::get('/{section}', function (string $section) {
    abort_unless(in_array($section, ['dashboard', 'checkout', 'products', 'inventory', 'purchasing', 'sales', 'cash', 'reports', 'settings']), 404);
    return view('shell', compact('section'));
});
