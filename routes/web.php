<?php

use App\Http\Controllers\AdministrativeAffiliationController;
use App\Http\Controllers\AffiliationSelectionController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\RequireActiveAffiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('affiliations/select', [AffiliationSelectionController::class, 'index'])->name('affiliations.select');
    Route::post('affiliations/select', [AffiliationSelectionController::class, 'store'])->name('affiliations.store');

    Route::middleware(RequireActiveAffiliation::class)->group(function (): void {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

        Route::livewire('campus', 'pages::campuses.own')
            ->middleware('can:viewOwn,'.Campus::class)->name('campuses.own');

        Route::middleware('can:viewAny,'.Course::class)->group(function (): void {
            Route::livewire('courses', 'pages::courses.index')->name('courses.index');
            Route::livewire('courses/create', 'pages::courses.create')->name('courses.create');
            Route::livewire('courses/{course}/edit', 'pages::courses.edit')->name('courses.edit');
            Route::livewire('courses/{course}', 'pages::courses.show')->name('courses.show');
            Route::post('courses', [CourseController::class, 'store'])->name('courses.store');
            Route::put('courses/{course}', [CourseController::class, 'update'])->name('courses.update');
            Route::patch('courses/{course}/deactivate', [CourseController::class, 'deactivate'])->name('courses.deactivate');
            Route::patch('courses/{course}/reactivate', [CourseController::class, 'reactivate'])->name('courses.reactivate');
        });

        Route::livewire('email-logs', 'pages::email-logs.index')
            ->middleware('can:viewAny,'.EmailDeliveryAttempt::class)->name('email-logs.index');
        Route::livewire('email-logs/{attempt}', 'pages::email-logs.show')
            ->middleware('can:view,attempt')->name('email-logs.show');

        Route::livewire('audit', 'pages::audit.index')
            ->middleware('can:viewAny,'.Activity::class)->name('audit.index');
        Route::livewire('audit/{activity}', 'pages::audit.show')
            ->middleware('can:view,activity')->name('audit.show');

        Route::middleware('can:viewAdministration,'.Campus::class)->group(function (): void {
            Route::livewire('campuses', 'pages::campuses.index')->name('campuses.index');
            Route::livewire('campuses/create', 'pages::campuses.create')->name('campuses.create');
            Route::livewire('campuses/{campus}/edit', 'pages::campuses.edit')->name('campuses.edit');
            Route::livewire('campuses/{campus}', 'pages::campuses.show')->name('campuses.show');
        });

        Route::middleware('can:viewAny,'.User::class)->scopeBindings()->group(function (): void {
            Route::livewire('users', 'pages::users.index')->name('users.index');
            Route::livewire('users/create', 'pages::users.create')->name('users.create');
            Route::livewire('users/{user}/affiliations/create', 'pages::users.affiliations.create')->name('users.affiliations.create');
            Route::livewire('users/{user}/affiliations/{affiliation}/edit', 'pages::users.affiliations.edit')->name('users.affiliations.edit');
            Route::livewire('users/{user}/edit', 'pages::users.edit')->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
            Route::delete('users/{user}/affiliations/{affiliation}', [AdministrativeAffiliationController::class, 'destroy'])->name('users.affiliations.destroy');
            Route::livewire('users/{user}', 'pages::users.show')->name('users.show');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::post('users/{user}/affiliations', [AdministrativeAffiliationController::class, 'store'])->name('users.affiliations.store');
            Route::put('users/{user}/affiliations/{affiliation}', [AdministrativeAffiliationController::class, 'update'])->name('users.affiliations.update');
            Route::patch('users/{user}/affiliations/{affiliation}/deactivate', [AdministrativeAffiliationController::class, 'deactivate'])->name('users.affiliations.deactivate');
            Route::patch('users/{user}/affiliations/{affiliation}/reactivate', [AdministrativeAffiliationController::class, 'reactivate'])->name('users.affiliations.reactivate');
        });

        Route::post('campuses', [CampusController::class, 'store'])->name('campuses.store');
        Route::put('campuses/{campus}', [CampusController::class, 'update'])->name('campuses.update');
        Route::patch('campuses/{campus}/deactivate', [CampusController::class, 'deactivate'])->name('campuses.deactivate');
        Route::patch('campuses/{campus}/reactivate', [CampusController::class, 'reactivate'])->name('campuses.reactivate');
    });

});

require __DIR__.'/settings.php';
