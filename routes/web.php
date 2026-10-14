<?php

use App\Http\Controllers\OfflineCheckoutController;
use App\Http\Controllers\OwnerSessionController;
use App\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [OwnerSessionController::class, 'create'])->name('login');
Route::post('/login', [OwnerSessionController::class, 'store'])->name('login.store');
Route::post('/logout', [OwnerSessionController::class, 'destroy'])->name('logout');
Route::get('/offline/status', [OfflineCheckoutController::class, 'status'])->name('offline.status');
Route::post('/offline/prepare', [OfflineCheckoutController::class, 'prepare'])->name('offline.prepare');
Route::post('/offline/sync', [OfflineCheckoutController::class, 'sync'])->name('offline.sync');
Route::post('/offline/finish', [OfflineCheckoutController::class, 'finish'])->name('offline.finish');
Route::get('/reports/export/{type}', [ReportExportController::class, 'csv'])->name('reports.csv');
Route::get('/reports/print/{type}', [ReportExportController::class, 'preview'])->name('reports.print');

Route::get('/', fn () => redirect()->route('workspace', ['section' => 'dashboard']))->name('home');
Route::get('/{section}', function (string $section) {
    abort_unless(in_array($section, ['dashboard', 'checkout', 'products', 'inventory', 'purchasing', 'sales', 'credit', 'losses', 'cash', 'reports', 'settings']), 404);

    return view('shell', compact('section'));
})->name('workspace');
