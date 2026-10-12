<?php

use App\Modules\Reports\Http\Controllers\Web\DashboardController;
use App\Modules\Reports\Http\Controllers\Web\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'can:view dashboard'])
    ->name('dashboard');

Route::middleware('can:view reports')->group(function () {
    Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
    Route::get('/reports/items', [ReportController::class, 'items'])->name('reports.items');
    Route::get('/reports/profit', [ReportController::class, 'profit'])->name('reports.profit');
});
