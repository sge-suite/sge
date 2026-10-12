<?php

use App\Actions\RequestEmailDelivery;
use App\Enums\AffiliationType;
use App\Enums\EmailDeliveryAttemptStatus;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Support\EmailDeliveryContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Support\CauserResolver;

beforeEach(function () {
    Queue::fake();
});

function emailLogAdministrator(): Affiliation
{
    return Affiliation::factory()->global()->create();
}

function administrativeEmailAttempt(Affiliation $affiliation): EmailDeliveryAttempt
{
    return app(RequestEmailDelivery::class)->accountCreated($affiliation->user->email, (string) Str::uuid(), $affiliation);
}

test('email log routes require authentication', function () {
    $attempt = administrativeEmailAttempt(emailLogAdministrator());
    $this->get(route('email-logs.index'))->assertRedirect(route('login'));
    $this->get(route('email-logs.show', $attempt))->assertRedirect(route('login'));
});

test('system administrator sees only administrative email contexts and all supported purposes', function () {
    $administrator = emailLogAdministrator();
    $target = emailLogAdministrator();
    $internshipOffice = Affiliation::factory()->create(['type' => AffiliationType::InternshipOffice]);
    $operational = Affiliation::factory()->create(['type' => AffiliationType::Advisor]);
    $request = app(RequestEmailDelivery::class);
    $expected = [
        administrativeEmailAttempt($target),
        $request->affiliationCreated($internshipOffice->email, $internshipOffice, (string) Str::uuid()),
        $request->affiliationCreated('external-contact@example.test', $target, (string) Str::uuid()),
        $request->accountEmailChanged('old@example.test', 'old@example.test', 'new@example.test', (string) Str::uuid(), $target->user),
        $request->administrativeChange($target->user->email, 'Aviso administrativo', 'Aviso registrado', (string) Str::uuid(), $target),
    ];
    $outside = [
        administrativeEmailAttempt($operational),
        app(CauserResolver::class)->withCauser($administrator, fn (): EmailDeliveryAttempt => $request->affiliationCreated($target->user->email, $operational, (string) Str::uuid())),
        $request->accountCreated($target->user->email, (string) Str::uuid()),
        EmailDeliveryAttempt::factory()->create(['scope_context' => app(EmailDeliveryContext::class)->forAffiliation($target)]),
    ];
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::email-logs.index');
    expect($component->instance()->deliveries->pluck('id')->all())->toEqualCanonicalizing(array_map(fn (EmailDeliveryAttempt $attempt): int => $attempt->id, $expected));
    foreach ($outside as $attempt) {
        $this->get(route('email-logs.show', $attempt))->assertForbidden();
        Livewire::test('pages::email-logs.show', ['attempt' => $attempt])->assertForbidden();
    }
    $this->get(route('dashboard'))->assertOk();
});

test('email detail paginates delivery attempts with the shared page size', function () {
    $administrator = emailLogAdministrator();
    $attempt = administrativeEmailAttempt($administrator);

    for ($number = 2; $number <= 17; $number++) {
        $retry = $attempt->replicate();
        $retry->attempt_number = $number;
        $retry->save();
    }

    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::email-logs.show', ['attempt' => $attempt]);
    $attempts = $component->instance()->attempts;

    expect($attempts->total())->toBe(17)
        ->and($attempts->perPage())->toBe(15)
        ->and($attempts->count())->toBe(15);

    $component->call('gotoPage', 2, 'attemptsPage');

    expect($component->instance()->attempts->count())->toBe(2);
});

test('nonadministrative affiliations cannot read email logs even on an administrator account', function (AffiliationType $type) {
    $administrator = emailLogAdministrator();
    $attempt = administrativeEmailAttempt($administrator);
    $factory = Affiliation::factory()->for($administrator->user)->state(['type' => $type]);
    if ($type === AffiliationType::Student) {
        $factory = $factory->student();
    } elseif ($type === AffiliationType::Supervisor) {
        $factory = $factory->supervisor();
    }
    $selected = $factory->create();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $selected->id]);
    $this->get(route('email-logs.index'))->assertForbidden();
    $this->get(route('email-logs.show', $attempt))->assertForbidden();
    Livewire::test('pages::email-logs.index')->assertForbidden();
    $this->get(route('dashboard'))->assertOk();
})->with(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => ! in_array($type, [AffiliationType::SystemAdministrator, AffiliationType::InternshipOffice], true)));

test('email logs reject inactive foreign and choice-required contexts', function (string $context) {
    $administrator = emailLogAdministrator();
    $attempt = administrativeEmailAttempt($administrator);
    $session = ['active_affiliation_id' => $administrator->id];
    if ($context === 'inactive') {
        $administrator->update(['deactivated_at' => now()]);
    } elseif ($context === 'foreign') {
        $session['active_affiliation_id'] = emailLogAdministrator()->id;
    } else {
        $session['active_affiliation_needs_choice'] = true;
    }
    $this->actingAs($administrator->user)->withSession($session);
    Livewire::test('pages::email-logs.index')->assertForbidden();
    Livewire::test('pages::email-logs.show', ['attempt' => $attempt])->assertForbidden();
})->with(['inactive', 'foreign', 'choice']);

test('email log components reauthorize refresh and filter requests', function (string $page) {
    $administrator = emailLogAdministrator();
    $attempt = administrativeEmailAttempt($administrator);
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::email-logs.'.$page, $page === 'show' ? ['attempt' => $attempt] : []);
    $administrator->update(['deactivated_at' => now()]);
    if ($page === 'index') {
        $component->set('status', 'failed')->assertForbidden();
    } else {
        $component->call('$refresh')->assertForbidden();
    }
})->with(['index', 'show']);

test('retry history preserves scope snapshots and lists only the latest attempt per delivery', function () {
    $administrator = emailLogAdministrator();
    $target = emailLogAdministrator();
    $first = administrativeEmailAttempt($target);
    $first->update(['status' => EmailDeliveryAttemptStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'transport_failed']);
    $retry = app(RequestEmailDelivery::class)->retry($first);
    expect($retry->scope_context)->toEqual($first->scope_context);
    $target->delete();
    $target->user->delete();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::email-logs.index');
    expect($component->instance()->deliveries->pluck('id')->all())->toBe([$retry->id]);
    $component->set('status', 'failed');
    expect($component->instance()->deliveries->total())->toBe(0);
    $component->call('clearFilters');
    expect($component->instance()->deliveries->total())->toBe(1);
    $details = Livewire::test('pages::email-logs.show', ['attempt' => $retry]);
    expect($details->instance()->attempts->pluck('id')->all())->toBe([$first->id, $retry->id]);
});

test('scope metadata is immutable and cannot be reused for another delivery context', function () {
    $administrator = emailLogAdministrator();
    $operational = Affiliation::factory()->create(['type' => AffiliationType::Advisor]);
    $attempt = administrativeEmailAttempt($administrator);
    expect(fn (): bool => $attempt->update(['scope_context' => app(EmailDeliveryContext::class)->forAffiliation($operational)]))->toThrow(ValidationException::class);
    expect(fn (): EmailDeliveryAttempt => app(RequestEmailDelivery::class)->accountCreated($administrator->user->email, $attempt->delivery_key, $operational))->toThrow(ValidationException::class);
});

test('email log identity is locked', function () {
    $administrator = emailLogAdministrator();
    $attempt = administrativeEmailAttempt($administrator);
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    expect(fn () => Livewire::test('pages::email-logs.show', ['attempt' => $attempt])->set('attemptId', $attempt->id + 1))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('missing email log records are not found', function () {
    $administrator = emailLogAdministrator();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $this->get(route('email-logs.show', PHP_INT_MAX))->assertNotFound();
});
