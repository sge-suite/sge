<?php

use App\Actions\AdministrativeAffiliationTransaction;
use App\Actions\GetAdministrativeDashboardMetrics;
use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\EmailDeliveryAttempt;
use App\Support\ActiveAffiliationContext;
use App\Support\AdministrativeActivityScope;
use App\Support\AdministrativeEmailLogScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

test('legacy campus administrators use the internship office context without rewriting data or logging selection', function () {
    $affiliation = Affiliation::factory()->create();
    $affiliation->newQuery()->whereKey($affiliation)->update(['type' => AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR]);
    $affiliation->refresh();
    $count = Activity::count();
    $this->actingAs($affiliation->user);

    app(ActiveAffiliationContext::class)->select($affiliation->user, app('session.store'), $affiliation->id);

    expect($affiliation->fresh()->type)->toBe(AffiliationType::InternshipOffice)
        ->and($affiliation->fresh()->toArray()['type'])->toBe(AffiliationType::InternshipOffice->value)
        ->and($affiliation->fresh()->getRawOriginal('type'))->toBe(AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR)
        ->and(Activity::count())->toBe($count);
    $this->get(route('campuses.own'))->assertOk()->assertSee($affiliation->campus->name);
    $this->post(route('courses.store'), ['name' => 'Curso pelo vínculo existente'])->assertRedirect();
    expect(Course::where('name', 'Curso pelo vínculo existente')->sole()->campus_id)->toBe($affiliation->campus_id);
    $this->get(route('audit.index'))->assertForbidden();
    $this->get(route('email-logs.index'))->assertForbidden();
    $affiliation->update(['deactivated_at' => now()]);
    Livewire::test('pages::courses.index')->assertForbidden();
});

test('legacy and current office affiliations share the duplicate guard for creation and reactivation', function () {
    $affiliation = Affiliation::factory()->create();
    $affiliation->newQuery()->whereKey($affiliation)->update(['type' => AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR]);

    expect(fn () => app(AdministrativeAffiliationTransaction::class)->assertNoActiveDuplicate(
        $affiliation->user, AffiliationType::InternshipOffice, $affiliation->campus_id,
    ))->toThrow(ValidationException::class, 'Já existe o vínculo ativo');
});

test('removed role cannot be provisioned and is absent from the affiliation selector', function () {
    $administrator = Affiliation::factory()->global()->create();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    Livewire::test('users.form-fields', ['user' => $administrator->user])->assertSee('Setor de Estágios')
        ->assertDontSee('Administrador do Campus')->assertDontSee('campus_administrator');
    $this->post(route('users.affiliations.store', $administrator->user), [
        'type' => AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR,
        'campus_id' => Campus::factory()->create()->id,
        'email' => 'office@example.test', 'registration_number' => 'LEGACY-NEW',
    ])->assertSessionHasErrors('type');
});

test('historical administrative types stay in audit and email scope', function () {
    $affiliation = Affiliation::factory()->create();
    $activity = Activity::create([
        'subject_type' => $affiliation->getMorphClass(), 'subject_id' => $affiliation->id,
        'event' => 'updated', 'description' => 'Histórico preservado',
        'attribute_changes' => ['attributes' => ['type' => AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR]],
    ]);
    $attempt = EmailDeliveryAttempt::factory()->accountCreated($affiliation->user)->create([
        'scope_context' => ['affiliation_types' => [AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR]],
    ]);
    expect(app(AdministrativeActivityScope::class)->apply(Activity::query())->whereKey($activity)->exists())->toBeTrue()
        ->and(app(AdministrativeEmailLogScope::class)->apply(EmailDeliveryAttempt::query())->whereKey($attempt)->exists())->toBeTrue();
});

test('internship office keeps global campus lifecycle and account administration restricted', function () {
    $affiliation = Affiliation::factory()->create();
    $this->actingAs($affiliation->user)->withSession(['active_affiliation_id' => $affiliation->id]);
    expect(Gate::allows('create', Campus::class))->toBeFalse()
        ->and(Gate::allows('deactivate', $affiliation->campus))->toBeFalse()
        ->and(Gate::allows('reactivate', $affiliation->campus))->toBeFalse()
        ->and(Gate::allows('delete', $affiliation->campus))->toBeFalse();
    $this->get(route('users.index'))->assertForbidden();
});

test('dashboard counts legacy and current local affiliations together as internship office', function () {
    $legacy = Affiliation::factory()->create();
    $legacy->newQuery()->whereKey($legacy)->update(['type' => AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR]);
    Affiliation::factory()->deactivated()->create();
    $metrics = app(GetAdministrativeDashboardMetrics::class)();
    $office = collect($metrics['affiliationTypes'])->firstWhere('label', AffiliationType::InternshipOffice->label());

    expect($metrics['affiliationsCount'])->toBe(2)
        ->and($office['value'])->toBe(2)->and($office['active'])->toBe(1);
});
