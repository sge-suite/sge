<?php

use App\Actions\RequestEmailDelivery;
use App\Enums\AffiliationType;
use App\Enums\EmailDeliveryAttemptStatus;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\EmailDeliveryAttempt;
use App\Models\Internship;
use App\Models\User;
use App\Support\ActivityAccess;
use App\Support\EmailDeliveryContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;

beforeEach(function () {
    Queue::fake();
});

function campusLogOffice(): Affiliation
{
    return activity()->withoutLogging(fn (): Affiliation => Affiliation::factory()->create());
}

test('internship office shares log links and sees only the campus records it manages regardless of actor', function () {
    $office = campusLogOffice();
    [$local, $external, $externalUser, $course, $externalCourse, $protected, $unaffiliated] = activity()->withoutLogging(function () use ($office): array {
        $local = Affiliation::factory()->for($office->campus)->supervisor()->create();
        $external = Affiliation::factory()->for($local->user)->create();
        $protected = Affiliation::factory()->for($office->campus)->create();
        Affiliation::factory()->global()->for($protected->user)->create();

        return [$local, $external, Affiliation::factory()->create()->user,
            Course::factory()->for($office->campus)->create(), Course::factory()->create(), $protected, User::factory()->create()];
    });
    app(CauserResolver::class)->withCauser($external, function () use ($office, $local, $course): void {
        $office->campus->update(['name' => 'Campus permitido']);
        $local->update(['email' => 'local-supervisor@example.test']);
        $local->user->update(['name' => 'Conta local permitida']);
        $course->update(['name' => 'Curso permitido']);
    });
    app(CauserResolver::class)->withCauser($office, function () use ($external, $externalUser, $externalCourse, $protected, $unaffiliated): void {
        $external->campus->update(['name' => 'Campus externo oculto']);
        $external->update(['email' => 'external-hidden@example.test']);
        $externalUser->update(['name' => 'Conta externa oculta']);
        $externalCourse->update(['name' => 'Curso externo oculto']);
        $protected->user->update(['name' => 'Administrador protegido']);
        $unaffiliated->update(['name' => 'Conta sem campus oculta']);
    });
    $allowedIds = collect([$office->campus, $local, $local->user, $course])
        ->map(fn ($subject): int => Activity::forSubject($subject)->where('event', 'updated')->sole()->id)->all();
    $excludedIds = Activity::query()->where('event', 'updated')->whereNotIn('id', $allowedIds)->pluck('id');
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $count = Activity::count();

    $this->get(route('dashboard'))->assertOk()->assertSee('Logs')
        ->assertSee(route('audit.index'), false)->assertSee(route('email-logs.index'), false);
    $component = Livewire::test('pages::audit.index')->assertSee('Curso permitido')->assertSee('Campus permitido')
        ->assertDontSee('Curso externo oculto')->assertDontSee('Administrador protegido');
    expect($component->instance()->activities->pluck('id')->all())->toEqualCanonicalizing($allowedIds);
    foreach ($allowedIds as $id) {
        $this->get(route('audit.show', $id))->assertOk();
    }
    foreach ($excludedIds as $id) {
        $this->get(route('audit.show', $id))->assertForbidden();
        Livewire::test('pages::audit.show', ['activity' => Activity::findOrFail($id)])->assertForbidden();
    }
    $component->set('entity', 'course')->assertSee('Curso permitido')
        ->assertDontSee(route('audit.show', $allowedIds[2]), false);
    expect($component->instance()->activities->total())->toBe(1);
    $this->get(route('audit.show', $allowedIds[3]))->assertOk()->assertSee('Curso #'.$course->id)->assertSee('Nome');
    $this->get(route('users.show', $local->user))->assertOk()->assertSee('Histórico de auditoria')
        ->assertSee(route('audit.show', $allowedIds[1]), false)->assertDontSee('external-hidden@example.test');
    expect(Activity::count())->toBe($count);
});

test('campus audit includes all permitted affiliation types and excludes global administrators', function (AffiliationType $type) {
    $office = campusLogOffice();
    $factory = Affiliation::factory()->for($office->campus)->state(['type' => $type]);
    if ($type === AffiliationType::Supervisor) {
        $factory = $factory->supervisor();
    } elseif ($type === AffiliationType::Student) {
        $factory = $factory->student();
    }
    $target = $factory->create();
    $entry = Activity::forSubject($target)->where('event', 'created')->sole();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    expect(Gate::allows('view', $entry))->toBe($type !== AffiliationType::SystemAdministrator);
})->with(AffiliationType::cases());

test('dirty-only affiliation and course logs survive deletion without exposing foreign or global records', function () {
    $office = campusLogOffice();
    $local = Affiliation::factory()->for($office->campus)->create(['type' => AffiliationType::Advisor]);
    $external = Affiliation::factory()->create(['type' => AffiliationType::Advisor]);
    $global = Affiliation::factory()->global()->for($office->campus)->create();
    $course = Course::factory()->for($office->campus)->create();
    $externalCourse = Course::factory()->create();
    $allowed = [];
    $excluded = [];
    foreach ([$local, $external, $global, $course, $externalCourse] as $subject) {
        $subject->update($subject instanceof Course ? ['name' => 'Nome histórico '.$subject->id] : ['email' => 'history-'.$subject->id.'@example.test']);
        $ids = Activity::forSubject($subject)->pluck('id')->all();
        $subject->delete();
        $ids[] = Activity::forSubject($subject)->where('event', 'deleted')->sole()->id;
        if ($subject->is($local) || $subject->is($course)) {
            $allowed = [...$allowed, ...$ids];
        } else {
            $excluded = [...$excluded, ...$ids];
        }
    }
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $ids = app(ActivityAccess::class)->forCurrentContext($office->user, app('session.store'))->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing($allowed);
    foreach ($excluded as $id) {
        expect(Gate::allows('view', Activity::findOrFail($id)))->toBeFalse();
    }
});

test('recorded campus and type take precedence over live affiliation and unsafe historical scope is rejected', function () {
    $office = campusLogOffice();
    $local = activity()->withoutLogging(fn (): Affiliation => Affiliation::factory()->for($office->campus)->create());
    $entries = [];
    foreach ([['campus_id' => null, 'type' => AffiliationType::InternshipOffice->value],
        ['campus_id' => Campus::factory()->create()->id, 'type' => AffiliationType::InternshipOffice->value],
        ['campus_id' => $office->campus_id, 'type' => AffiliationType::SystemAdministrator->value]] as $snapshot) {
        $entries[] = Activity::create(['subject_type' => $local->getMorphClass(), 'subject_id' => $local->id,
            'event' => 'updated', 'description' => 'Evento fora do escopo', 'attribute_changes' => ['attributes' => $snapshot]]);
    }
    $entries[] = Activity::create(['subject_type' => $local->getMorphClass(), 'subject_id' => $local->id,
        'event' => 'updated', 'description' => 'Campos sem escopo', 'attribute_changes' => ['attributes' => ['email' => 'hidden@example.test']]]);
    $entries[] = Activity::create(['subject_type' => Internship::class, 'subject_id' => 999,
        'event' => 'updated', 'description' => 'Operação sem Policy', 'attribute_changes' => ['attributes' => ['campus_id' => $office->campus_id]]]);
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    foreach ($entries as $entry) {
        $this->get(route('audit.show', $entry))->assertForbidden();
    }
});

test('office email logs use affected record snapshots and purposes instead of recipient or requester', function () {
    $office = campusLogOffice();
    $local = Affiliation::factory()->for($office->campus)->supervisor()->create();
    $external = Affiliation::factory()->for($local->user)->create();
    $global = Affiliation::factory()->global()->for($office->campus)->create();
    $request = app(RequestEmailDelivery::class);
    $allowed = [
        $request->accountCreated($local->user->email, (string) Str::uuid(), $local),
        $request->affiliationCreated('external-contact@example.test', $local, (string) Str::uuid()),
        $request->accountEmailChanged('old@example.test', 'old@example.test', 'new@example.test', (string) Str::uuid(), $local->user),
        $request->administrativeChange($local->email, 'Vínculo local desativado', 'Aviso local', (string) Str::uuid(), $local),
    ];
    $outside = [
        app(CauserResolver::class)->withCauser($office, fn (): EmailDeliveryAttempt => $request->affiliationCreated($local->email, $external, (string) Str::uuid())),
        $request->affiliationCreated($local->email, $global, (string) Str::uuid()),
        $request->accountCreated($local->email, (string) Str::uuid()),
        EmailDeliveryAttempt::factory()->create(['scope_context' => app(EmailDeliveryContext::class)->forAffiliation($local)]),
        EmailDeliveryAttempt::factory()->accountCreated($local->user)->create(['scope_context' => ['campus_ids' => [$office->campus_id]]]),
    ];
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $component = Livewire::test('pages::email-logs.index')->assertSee('external-contact@example.test');
    expect($component->instance()->deliveries->pluck('id')->all())->toEqualCanonicalizing(array_map(fn (EmailDeliveryAttempt $attempt): int => $attempt->id, $allowed));
    foreach ($allowed as $attempt) {
        $this->get(route('email-logs.show', $attempt))->assertOk()->assertSee('Conteúdo registrado');
    }
    foreach ($outside as $attempt) {
        $this->get(route('email-logs.show', $attempt))->assertForbidden();
        Livewire::test('pages::email-logs.show', ['attempt' => $attempt])->assertForbidden();
    }
    $component->set('purpose', 'new_affiliation');
    expect($component->instance()->deliveries->total())->toBe(1);
    $component->set('purpose', 'notification')->assertSet('purpose', 'all');
});

test('campus email retries and snapshots remain visible after the affected affiliation is deleted', function () {
    $office = campusLogOffice();
    $local = Affiliation::factory()->for($office->campus)->create();
    $attempt = app(RequestEmailDelivery::class)->affiliationCreated($local->email, $local, (string) Str::uuid());
    $attempt->update(['status' => EmailDeliveryAttemptStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'transport_failed']);
    $retry = app(RequestEmailDelivery::class)->retry($attempt);
    $local->delete();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $component = Livewire::test('pages::email-logs.index');
    expect($component->instance()->deliveries->pluck('id')->all())->toBe([$retry->id]);
    $details = Livewire::test('pages::email-logs.show', ['attempt' => $retry]);
    expect($details->instance()->attempts->pluck('id')->all())->toBe([$attempt->id, $retry->id]);
});

test('selected office on an administrator account sees its own access changes but no other administrator account changes', function () {
    $office = campusLogOffice();
    Affiliation::factory()->global()->for($office->user)->create();
    $other = Affiliation::factory()->for($office->campus)->create();
    Affiliation::factory()->global()->for($other->user)->create();
    $office->user->update(['name' => 'Minha conta']);
    $other->user->update(['name' => 'Outro administrador']);
    $own = Activity::forSubject($office->user)->where('event', 'updated')->sole();
    $foreign = Activity::forSubject($other->user)->where('event', 'updated')->sole();
    $request = app(RequestEmailDelivery::class);
    $ownEmail = $request->accountEmailChanged('old@example.test', 'old@example.test', 'new@example.test', (string) Str::uuid(), $office->user);
    $foreignEmail = $request->accountEmailChanged('old2@example.test', 'old2@example.test', 'new2@example.test', (string) Str::uuid(), $other->user);
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $this->get(route('audit.show', $own))->assertOk();
    $this->get(route('audit.show', $foreign))->assertForbidden();
    $this->get(route('email-logs.show', $ownEmail))->assertOk();
    $this->get(route('email-logs.show', $foreignEmail))->assertForbidden();
});

test('campus log pages reauthorize details and index filters after context changes', function (string $page, string $change) {
    $office = campusLogOffice();
    $other = Affiliation::factory()->for($office->user)->create();
    $entry = Activity::forSubject(Course::factory()->for($office->campus)->create())->where('event', 'created')->sole();
    $attempt = app(RequestEmailDelivery::class)->affiliationCreated($office->email, $office, (string) Str::uuid());
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $parameters = match ($page) {
        'audit.show' => ['activity' => $entry],
        'email-logs.show' => ['attempt' => $attempt],
        default => [],
    };
    $component = Livewire::test('pages::'.$page, $parameters);
    match ($change) {
        'deactivated' => $office->update(['deactivated_at' => now()]),
        'other campus' => session()->put('active_affiliation_id', $other->id),
        'profile changed' => $office->update(['type' => AffiliationType::Advisor]),
        'choice required' => session()->put('active_affiliation_needs_choice', true),
    };
    if ($change === 'other campus' && str_ends_with($page, 'index')) {
        $component->call('$refresh')->assertOk();
        $records = str_starts_with($page, 'audit') ? $component->instance()->activities : $component->instance()->deliveries;
        expect($records->pluck('id')->all())->not->toContain(str_starts_with($page, 'audit') ? $entry->id : $attempt->id);
    } else {
        $component->call('$refresh')->assertForbidden();
    }
})->with(['audit.index', 'audit.show', 'email-logs.index', 'email-logs.show'])
    ->with(['deactivated', 'other campus', 'profile changed', 'choice required']);

test('inactive campuses keep read only local logs available', function () {
    $office = campusLogOffice();
    $office->campus->deactivate();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $this->get(route('audit.index'))->assertOk();
    $this->get(route('email-logs.index'))->assertOk();
});
