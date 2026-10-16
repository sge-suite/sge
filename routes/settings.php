<?php

use App\Http\Controllers\OwnPersonalDataController;
use App\Http\Middleware\RequireActiveAffiliation;
use App\Models\UserPersonalData;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', RequireActiveAffiliation::class, 'can:viewOwn,'.UserPersonalData::class])->group(function (): void {
    Route::livewire('settings/personal-data', 'pages::settings.personal-data')->name('personal-data.edit');
    Route::put('settings/personal-data', [OwnPersonalDataController::class, 'update'])->name('personal-data.update');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});
