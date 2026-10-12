<?php

use App\Actions\GetAdministrativeDashboardMetrics;
use App\Enums\AffiliationType;
use App\Enums\InternshipStatus;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('other profiles can use the dashboard without seeing global administrative metrics', function () {
    $user = User::factory()->create();
    Affiliation::factory()->for($user)->create();
    $this->mock(GetAdministrativeDashboardMetrics::class)->shouldNotReceive('__invoke');
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('system administrators receive global totals for system records', function () {
    $admin = User::factory()->create();
    $systemAffiliation = Affiliation::factory()->global()->for($admin)->create();
    Affiliation::factory()->for($admin)->create();
    Affiliation::factory()->supervisor()->deactivated()->create();
    Campus::factory()->deactivated()->create();
    $internship = Internship::factory()->create(['status' => InternshipStatus::InProgress]);
    Internship::factory()->create([
        'course_id' => $internship->course_id,
        'internship_type_id' => $internship->internship_type_id,
        'status' => InternshipStatus::PendingCorrection,
    ]);
    GeneratedDocument::factory()->for($internship)->create();

    $this->actingAs($admin)->withSession(['active_affiliation_id' => $systemAffiliation->id]);

    $this->get(route('dashboard'))->assertOk();

    $metrics = app(GetAdministrativeDashboardMetrics::class)();
    $supervisorCounts = collect($metrics['affiliationTypes'])->firstWhere('label', 'Supervisor');
    $internshipCounts = collect($metrics['internshipsByStatus']);

    expect($metrics['affiliationsCount'])->toBe(Affiliation::query()->count())
        ->and($metrics['activeAffiliationsCount'])->toBe(Affiliation::query()->active()->count())
        ->and($metrics['campusesCount'])->toBe(Campus::query()->count())
        ->and($metrics['activeCampusesCount'])->toBe(Campus::query()->whereNull('deactivated_at')->count())
        ->and($supervisorCounts['value'])->toBe(Affiliation::query()->where('type', AffiliationType::Supervisor->value)->count())
        ->and($supervisorCounts['active'])->toBe(Affiliation::query()->active()->where('type', AffiliationType::Supervisor->value)->count())
        ->and($metrics['internshipsCount'])->toBe(2)
        ->and($internshipCounts->firstWhere('label', InternshipStatus::InProgress->label())['value'])->toBe(1)
        ->and($internshipCounts->firstWhere('label', InternshipStatus::PendingCorrection->label())['value'])->toBe(1)
        ->and($internshipCounts->firstWhere('label', InternshipStatus::Completed->label())['value'])->toBe(0)
        ->and($metrics['documentsCount'])->toBe(1);
});

test('administrative dashboard metrics include zero values for every enum category', function () {
    $admin = User::factory()->create();
    $systemAffiliation = Affiliation::factory()->global()->for($admin)->create();

    $this->actingAs($admin)->withSession(['active_affiliation_id' => $systemAffiliation->id]);

    $this->get(route('dashboard'))->assertOk();

    $metrics = app(GetAdministrativeDashboardMetrics::class)();

    expect($metrics['internshipsByStatus'])
        ->toHaveCount(count(InternshipStatus::cases()))
        ->and($metrics['affiliationsCount'])->toBe(1)
        ->and($metrics['activeAffiliationsCount'])->toBe(1)
        ->and($metrics['maxInternshipStatusCount'])->toBe(1)
        ->and($metrics['internshipsCount'])->toBe(0)
        ->and(collect($metrics['internshipsByStatus'])->every(fn (array $status): bool => $status['value'] === 0))
        ->toBeTrue()
        ->and(collect($metrics['documentsByStatus'])->every(fn (array $status): bool => $status['value'] === 0))
        ->toBeTrue();

});

test('a system administrator only sees global metrics while the system affiliation is selected', function () {
    $user = User::factory()->create();
    $systemAffiliation = Affiliation::factory()->global()->for($user)->create();
    $officeAffiliation = Affiliation::factory()->for($user)->create();

    $this->actingAs($user)->withSession(['active_affiliation_id' => $systemAffiliation->id]);
    $this->get(route('dashboard'))->assertOk();

    $this->withSession(['active_affiliation_id' => $officeAffiliation->id]);
    $this->get(route('dashboard'))->assertOk();
});
