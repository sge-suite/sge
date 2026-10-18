<?php

use App\Http\Controllers\AdministrativeAffiliationController;
use App\Http\Controllers\AffiliationSelectionController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\GrantingPartyController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\HolidayImportController;
use App\Http\Controllers\HolidayRecurrenceController;
use App\Http\Controllers\InternshipRequestController;
use App\Http\Controllers\InternshipTypeController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\RequireActiveAffiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\EmailDeliveryAttempt;
use App\Models\GrantingParty;
use App\Models\Holiday;
use App\Models\HolidayRecurrence;
use App\Models\HolidaySource;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('affiliations/select', [AffiliationSelectionController::class, 'index'])->name('affiliations.select');
    Route::post('affiliations/select', [AffiliationSelectionController::class, 'store'])->name('affiliations.store');
    Route::post('affiliations/switch', [AffiliationSelectionController::class, 'switch'])->name('affiliations.switch');

    Route::middleware(RequireActiveAffiliation::class)->group(function (): void {
        Route::livewire('internships', 'pages::internships.index')
            ->middleware('can:viewAny,'.Internship::class)->name('internships.index');
        Route::livewire('internships/{internship}', 'pages::internships.show')
            ->middleware('can:view,internship')->name('internships.show');
        Route::livewire('internships/{internship}/edit', 'pages::internships.edit')
            ->middleware('can:update,internship')->name('internships.edit');
        Route::livewire('internship-review', 'pages::internship-requests.review-index')
            ->middleware('can:viewReviewList,'.InternshipRequest::class)->name('internship-review.index');
        Route::livewire('internship-review/{internshipRequest}', 'pages::internship-requests.review')
            ->middleware('can:review,internshipRequest')->name('internship-review.show');
        Route::middleware('can:viewAny,'.InternshipRequest::class)->group(function (): void {
            Route::livewire('internship-requests', 'pages::internship-requests.index')->name('internship-requests.index');
            Route::livewire('internship-requests/{internshipRequest}', 'pages::internship-requests.form')
                ->middleware('can:view,internshipRequest')->name('internship-requests.show');
            Route::put('internship-requests/{internshipRequest}', [InternshipRequestController::class, 'update'])->name('internship-requests.update');
            Route::post('internship-requests/{internshipRequest}/submit', [InternshipRequestController::class, 'update'])->name('internship-requests.submit');
            Route::post('internship-requests/{internshipRequest}/withdraw', [InternshipRequestController::class, 'update'])->name('internship-requests.withdraw');
        });
        Route::get('internship-evidences/{evidence}', [InternshipRequestController::class, 'evidence'])
            ->middleware('can:view,evidence')->name('internship-requests.evidence');

        Route::middleware('can:viewAny,'.Holiday::class)->group(function (): void {
            Route::livewire('holidays', 'pages::holidays.index')->name('holidays.index');
            Route::livewire('holidays/trash', 'pages::holidays.index')->defaults('trash', true)->name('holidays.trash');
            Route::livewire('holidays/create', 'pages::holidays.form')->middleware('can:create,'.Holiday::class)->name('holidays.create');
            Route::livewire('holidays/{holiday}/edit', 'pages::holidays.form')->middleware('can:update,holiday')->name('holidays.edit');
            Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
            Route::put('holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
            Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
            Route::patch('holidays/{holiday}/restore', [HolidayController::class, 'restore'])->withTrashed()->name('holidays.restore');
        });
        Route::middleware('can:viewAny,'.HolidayRecurrence::class)->group(function (): void {
            Route::livewire('holiday-recurrences', 'pages::holiday-recurrences.index')->name('holiday-recurrences.index');
            Route::livewire('holiday-recurrences/trash', 'pages::holiday-recurrences.index')->defaults('trash', true)->name('holiday-recurrences.trash');
            Route::livewire('holiday-recurrences/create', 'pages::holiday-recurrences.form')->middleware('can:create,'.HolidayRecurrence::class)->name('holiday-recurrences.create');
            Route::livewire('holiday-recurrences/{holidayRecurrence}/edit', 'pages::holiday-recurrences.form')->middleware('can:update,holidayRecurrence')->name('holiday-recurrences.edit');
            Route::post('holiday-recurrences', [HolidayRecurrenceController::class, 'store'])->name('holiday-recurrences.store');
            Route::put('holiday-recurrences/{holidayRecurrence}', [HolidayRecurrenceController::class, 'update'])->name('holiday-recurrences.update');
            Route::delete('holiday-recurrences/{holidayRecurrence}', [HolidayRecurrenceController::class, 'destroy'])->name('holiday-recurrences.destroy');
            Route::patch('holiday-recurrences/{holidayRecurrence}/restore', [HolidayRecurrenceController::class, 'restore'])->withTrashed()->name('holiday-recurrences.restore');
        });
        Route::middleware('can:viewAny,'.HolidaySource::class)->group(function (): void {
            Route::livewire('holiday-imports/{holidaySource}/resolve', 'pages::holiday-imports.resolve')->middleware('can:view,holidaySource')->name('holiday-imports.resolve');
            Route::post('holiday-imports/{holidaySource}/resolve', [HolidayImportController::class, 'resolve'])->middleware('can:update,holidaySource')->name('holiday-imports.resolve.store');
        });

        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

        Route::livewire('campus/edit', 'pages::campuses.own-edit')
            ->middleware('can:viewOwn,'.Campus::class)->name('campuses.own.edit');

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

        Route::middleware('can:viewAny,'.GrantingParty::class)->group(function (): void {
            Route::livewire('granting-parties', 'pages::granting-parties.index')->name('granting-parties.index');
            Route::livewire('granting-parties/create', 'pages::granting-parties.create')->name('granting-parties.create');
            Route::livewire('granting-parties/import', 'pages::granting-parties.import')->name('granting-parties.import');
            Route::livewire('granting-parties/{grantingParty}/edit', 'pages::granting-parties.edit')->name('granting-parties.edit');
            Route::livewire('granting-parties/{grantingParty}', 'pages::granting-parties.show')->name('granting-parties.show');
            Route::post('granting-parties', [GrantingPartyController::class, 'store'])->name('granting-parties.store');
            Route::put('granting-parties/{grantingParty}', [GrantingPartyController::class, 'update'])->name('granting-parties.update');
            Route::patch('granting-parties/{grantingParty}/deactivate', [GrantingPartyController::class, 'deactivate'])->name('granting-parties.deactivate');
            Route::patch('granting-parties/{grantingParty}/reactivate', [GrantingPartyController::class, 'reactivate'])->name('granting-parties.reactivate');
        });

        Route::middleware('can:viewAny,'.InternshipType::class)->group(function (): void {
            Route::livewire('internship-types', 'pages::internship-types.index')->name('internship-types.index');
            Route::livewire('internship-types/create', 'pages::internship-types.create')->name('internship-types.create');
            Route::livewire('internship-types/{internshipType}/edit', 'pages::internship-types.edit')->name('internship-types.edit');
            Route::livewire('internship-types/{internshipType}', 'pages::internship-types.show')->name('internship-types.show');
            Route::post('internship-types', [InternshipTypeController::class, 'store'])->name('internship-types.store');
            Route::put('internship-types/{internshipType}', [InternshipTypeController::class, 'update'])->name('internship-types.update');
            Route::patch('internship-types/{internshipType}/deactivate', [InternshipTypeController::class, 'deactivate'])->name('internship-types.deactivate');
            Route::patch('internship-types/{internshipType}/reactivate', [InternshipTypeController::class, 'reactivate'])->name('internship-types.reactivate');
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
            Route::livewire('users/import', 'pages::users.import')->middleware('can:import,'.User::class)->name('users.import');
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
