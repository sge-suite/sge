<?php

use App\Http\Controllers\AffiliationSelectionController;
use App\Http\Middleware\RequireActiveAffiliation;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('affiliations/select', [AffiliationSelectionController::class, 'index'])->name('affiliations.select');
    Route::post('affiliations/select', [AffiliationSelectionController::class, 'store'])->name('affiliations.store');

    Route::view('dashboard', 'dashboard')->middleware(RequireActiveAffiliation::class)->name('dashboard');
});

require __DIR__.'/settings.php';
