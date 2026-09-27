<?php

use App\Http\Controllers\AffiliationSelectionController;
use App\Http\Controllers\CampusController;
use App\Http\Middleware\RequireActiveAffiliation;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('affiliations/select', [AffiliationSelectionController::class, 'index'])->name('affiliations.select');
    Route::post('affiliations/select', [AffiliationSelectionController::class, 'store'])->name('affiliations.store');

    Route::middleware(RequireActiveAffiliation::class)->group(function (): void {
        Route::post('campuses', [CampusController::class, 'store'])->name('campuses.store');
        Route::put('campuses/{campus}', [CampusController::class, 'update'])->name('campuses.update');
        Route::patch('campuses/{campus}/deactivate', [CampusController::class, 'deactivate'])->name('campuses.deactivate');
        Route::patch('campuses/{campus}/reactivate', [CampusController::class, 'reactivate'])->name('campuses.reactivate');
    });

    Route::view('dashboard', 'dashboard')->middleware(RequireActiveAffiliation::class)->name('dashboard');
});

require __DIR__.'/settings.php';
