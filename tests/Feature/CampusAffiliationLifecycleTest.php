<?php

use App\Actions\RequestEmailDelivery;
use App\Actions\UpdateAdministrativeAffiliation;
use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Mail\DeliveryMail;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\EmailDeliveryAttempt;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\SupervisorEvaluation;
use App\Models\SupervisorRegistrationRequest;
use App\Models\User;
use App\Models\UserPersonalData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;

beforeEach(function () {
    Bus::fake();
    $this->office = Affiliation::factory()->server()->create();
    $this->actingAs($this->office->user)->withSession(['active_affiliation_id' => $this->office->id]);
    $this->target = User::factory()->create(['email' => 'account@example.test']);
    $this->local = Affiliation::factory()->server()->for($this->target)->for($this->office->campus)->create(['email' => 'context@example.test']);
});

test('office deactivates and deletes each permitted local affiliation while preserving account and profile', function (AffiliationType $type, string $operation) {
    $this->local->delete();
    $this->local = Affiliation::factory()->for($this->target)->for($this->office->campus)->create([
        'type' => $type,
        'registration_number' => $type === AffiliationType::Supervisor ? null : 'LOCAL-ROLE',
        'course_id' => $type === AffiliationType::Student ? Course::factory()->for($this->office->campus)->create()->id : null,
        'email' => 'context@example.test',
    ]);
    $profile = UserPersonalData::factory()->for($this->target)->create();
    $accountAttributes = $this->target->fresh()->getAttributes();
    $profileAttributes = $profile->fresh()->getAttributes();
    $url = route('users.affiliations.'.($operation === 'delete' ? 'destroy' : $operation), [$this->target, $this->local]);
    Livewire::test('pages::users.show', ['user' => $this->target])
        ->call('openConfirmation', $this->local->id, $operation)
        ->assertSet('showConfirmation', true)->assertSee('name="current_password"', false);
    $response = $this->{$operation === 'delete' ? 'delete' : 'patch'}($url, ['confirmed' => 1, 'current_password' => 'password']);
    $response->assertSessionHasNoErrors()->assertRedirect($operation === 'delete' ? route('users.index') : route('users.show', $this->target));
    if ($operation === 'delete') {
        $this->assertModelMissing($this->local);
    } else {
        expect($this->local->fresh()->deactivated_at)->not->toBeNull();
    }
    expect($this->target->fresh()->getAttributes())->toBe($accountAttributes)->and($profile->fresh()->getAttributes())->toBe($profileAttributes)
        ->and(EmailDeliveryAttempt::pluck('recipient_email')->sort()->values()->all())->toBe(['account@example.test', 'context@example.test'])
        ->and(EmailDeliveryAttempt::pluck('purpose')->unique()->sole())->toBe(EmailMessagePurpose::AdministrativeChange);
    $event = $operation === 'delete' ? 'deleted' : 'updated';
    $audit = Activity::forSubject($this->local)->where('event', $event)->sole();
    expect($audit->causer_id)->toBe($this->office->id)->and($audit->toJson())->not->toContain('current_password');
    if ($type === AffiliationType::Supervisor) {
        foreach (EmailDeliveryAttempt::with('emailMessage')->get() as $attempt) {
            (new DeliveryMail($attempt))->assertDontSeeInText('matrícula');
        }
    }
})->with(array_values(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => $type !== AffiliationType::SystemAdministrator)))
    ->with(['deactivate', 'delete']);

test('both administrative roles require current password and confirmation for lifecycle actions', function (bool $systemAdministrator, string $operation, string $condition) {
    if ($systemAdministrator) {
        $selected = Affiliation::factory()->global()->for($this->office->user)->create();
        $this->withSession(['active_affiliation_id' => $selected->id]);
    }
    $payload = ['confirmed' => 1, 'current_password' => 'password'];
    if ($condition === 'wrong password') {
        $payload['current_password'] = 'incorrect';
    } else {
        unset($payload[$condition === 'no password' ? 'current_password' : 'confirmed']);
    }
    $url = route('users.affiliations.'.($operation === 'delete' ? 'destroy' : $operation), [$this->target, $this->local]);
    $response = $this->{$operation === 'delete' ? 'delete' : 'patch'}($url, $payload);
    $field = $condition === 'no confirmation' ? 'confirmed' : 'current_password';
    $response->assertSessionHasErrors($field);
    expect(fn () => app(UpdateAdministrativeAffiliation::class)->handle($this->office->user, $this->target, $this->local, $operation, $payload))
        ->toThrow(ValidationException::class);
    $this->assertModelExists($this->local);
    expect($this->local->fresh()->deactivated_at)->toBeNull()->and(EmailDeliveryAttempt::count())->toBe(0);
})->with([false, true])->with(['deactivate', 'delete'])->with(['no password', 'wrong password', 'no confirmation']);

test('both administrative roles protect only the selected affiliation of their own account', function (bool $systemAdministrator, string $operation, bool $selectedTarget) {
    $selected = $systemAdministrator ? Affiliation::factory()->global()->for($this->office->user)->create() : $this->office;
    $this->withSession(['active_affiliation_id' => $selected->id]);
    $target = $selectedTarget ? $selected : Affiliation::factory()->server()->for($this->office->user)->for($this->office->campus)->create();
    $payload = ['confirmed' => 1, 'current_password' => 'password'];
    $url = route('users.affiliations.'.($operation === 'delete' ? 'destroy' : $operation), [$target->user, $target]);
    if ($selectedTarget) {
        $this->{$operation === 'delete' ? 'delete' : 'patch'}($url, $payload)->assertForbidden();
        expect(fn () => app(UpdateAdministrativeAffiliation::class)->handle($target->user, $target->user, $target, $operation, $payload))
            ->toThrow(AuthorizationException::class);
        $this->assertModelExists($target);
        expect($target->fresh()->deactivated_at)->toBeNull();
    } else {
        Livewire::test('pages::users.show', ['user' => $target->user])->call('openConfirmation', $target->id, $operation)
            ->assertSet('showConfirmation', true);
        $this->{$operation === 'delete' ? 'delete' : 'patch'}($url, $payload)->assertSessionHasNoErrors();
        if ($operation === 'delete') {
            $this->assertModelMissing($target);
        } else {
            expect($target->fresh()->deactivated_at)->not->toBeNull();
        }
        expect(session('active_affiliation_id'))->toBe($selected->id);
        $this->assertModelExists($selected);
    }
})->with([false, true])->with(['deactivate', 'delete'])->with([true, false]);

test('office rejects foreign campus and global administrator affiliations', function (bool $global, string $operation) {
    $target = $global ? Affiliation::factory()->global()->for($this->target)->create() : Affiliation::factory()->server()->for($this->target)->create();
    $payload = ['confirmed' => 1, 'current_password' => 'password'];
    $url = route('users.affiliations.'.($operation === 'delete' ? 'destroy' : $operation), [$this->target, $target]);
    $this->{$operation === 'delete' ? 'delete' : 'patch'}($url, $payload)->assertForbidden();
    Livewire::test('pages::users.show', ['user' => $this->target])->call('openConfirmation', $target->id, $operation)->assertForbidden();
    $this->assertModelExists($target);
})->with([false, true])->with(['deactivate', 'delete']);

test('linked operational records prevent deletion but permit deactivation', function (string $reference) {
    if (str_contains($reference, 'coordinator')) {
        $this->local->update(['type' => AffiliationType::Coordinator]);
        Course::factory()->for($this->office->campus)->create([
            $reference === 'primary coordinator' ? 'primary_coordinator_affiliation_id' : 'secondary_coordinator_affiliation_id' => $this->local->id,
        ]);
    } elseif ($reference === 'internship release') {
        Internship::factory()->released()->create(['released_by_affiliation_id' => $this->local->id]);
    } elseif ($reference === 'notification') {
        $this->local->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'document.available', 'data' => ['title' => 'Documento']]);
    } else {
        $this->local->update(['type' => AffiliationType::Supervisor, 'registration_number' => null]);
        match ($reference) {
            'internship' => Internship::factory()->create(['supervisor_affiliation_id' => $this->local->id]),
            'internship request' => InternshipRequest::factory()->create(['supervisor_affiliation_id' => $this->local->id]),
            'supervisor evaluation' => SupervisorEvaluation::factory()->create([
                'internship_id' => Internship::factory()->create(['supervisor_affiliation_id' => $this->local->id])->id,
                'supervisor_affiliation_id' => $this->local->id,
            ]),
            'supervisor request' => SupervisorRegistrationRequest::factory()->create(['supervisor_affiliation_id' => $this->local->id]),
        };
    }
    expect($this->local->hasLinkedRecords())->toBeTrue();
    $payload = ['confirmed' => 1, 'current_password' => 'password'];
    $this->delete(route('users.affiliations.destroy', [$this->target, $this->local]), $payload)->assertSessionHasErrors('affiliation');
    $this->assertModelExists($this->local);
    expect(EmailDeliveryAttempt::count())->toBe(0);
    $this->patch(route('users.affiliations.deactivate', [$this->target, $this->local]), $payload)->assertSessionHasNoErrors();
    expect($this->local->fresh()->deactivated_at)->not->toBeNull();
})->with(['primary coordinator', 'secondary coordinator', 'internship', 'internship release', 'internship request', 'supervisor evaluation', 'supervisor request', 'notification']);

test('deleting a local affiliation preserves other campus affiliations and their profile', function () {
    $foreign = Affiliation::factory()->for($this->target)->create();
    $profile = UserPersonalData::factory()->for($this->target)->create();
    $originalForeign = $foreign->fresh()->getAttributes();
    $this->delete(route('users.affiliations.destroy', [$this->target, $this->local]), ['confirmed' => 1, 'current_password' => 'password'])
        ->assertSessionHasNoErrors()->assertRedirect(route('users.index'));
    expect($foreign->fresh()->getAttributes())->toBe($originalForeign);
    $this->assertModelExists($profile);
    $this->assertModelExists($this->target);
});

test('deleting an inactive affiliation keeps remaining local details accessible', function () {
    $this->local->update(['deactivated_at' => now()]);
    Affiliation::factory()->supervisor()->for($this->target)->for($this->office->campus)->create();
    $this->delete(route('users.affiliations.destroy', [$this->target, $this->local]), ['confirmed' => 1, 'current_password' => 'password'])
        ->assertSessionHasNoErrors()->assertRedirect(route('users.show', $this->target));
    $this->assertModelMissing($this->local);
});

test('audit history survives physical deletion of its subject and causer affiliation', function () {
    $campus = app(CauserResolver::class)->withCauser($this->local, fn () => Campus::factory()->create());
    $caused = Activity::forSubject($campus)->where('event', 'created')->sole();
    $created = Activity::forSubject($this->local)->where('event', 'created')->sole();
    $this->delete(route('users.affiliations.destroy', [$this->target, $this->local]), ['confirmed' => 1, 'current_password' => 'password'])->assertSessionHasNoErrors();
    $this->assertModelMissing($this->local);
    expect($created->fresh()->subject_id)->toBe($this->local->id)->and($caused->fresh()->causer_id)->toBe($this->local->id)
        ->and(Activity::forSubject($this->local)->where('event', 'deleted')->sole()->causer_id)->toBe($this->office->id);
    $this->assertModelExists($campus);
});

test('lifecycle actions recheck campus and selected context after locking the target', function (string $operation, string $condition) {
    $other = Affiliation::factory()->server()->for($this->office->user)->create();
    $triggered = false;
    DB::listen(function ($query) use ($other, $condition, &$triggered): void {
        if (! $triggered && str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) {
            $triggered = true;
            match ($condition) {
                'context' => session()->put('active_affiliation_id', $other->id),
                'campus' => $this->office->campus->deactivate(),
                'affiliation' => $this->office->update(['deactivated_at' => now()]),
            };
        }
    });
    $url = route('users.affiliations.'.($operation === 'delete' ? 'destroy' : $operation), [$this->target, $this->local]);
    $this->{$operation === 'delete' ? 'delete' : 'patch'}($url, ['confirmed' => 1, 'current_password' => 'password'])->assertForbidden();
    $this->assertModelExists($this->local);
    expect($this->local->fresh()->deactivated_at)->toBeNull()->and(EmailDeliveryAttempt::count())->toBe(0);
})->with(['deactivate', 'delete'])->with(['context', 'campus', 'affiliation']);

test('open lifecycle confirmations reauthorize when the campus becomes inactive', function (string $operation) {
    $component = Livewire::test('pages::users.show', ['user' => $this->target])->call('openConfirmation', $this->local->id, $operation);
    $this->office->campus->deactivate();
    $component->call('$refresh')->assertForbidden();
})->with(['deactivate', 'delete']);

test('email reservation failure rolls back lifecycle changes and their audit', function (string $operation) {
    $auditCount = Activity::count();
    $this->mock(RequestEmailDelivery::class)->shouldReceive('administrativeChange')->andThrow(new RuntimeException('reservation failed'));
    expect(fn () => app(UpdateAdministrativeAffiliation::class)->handle($this->office->user, $this->target, $this->local, $operation, [
        'confirmed' => 1, 'current_password' => 'password',
    ]))->toThrow(RuntimeException::class);
    $this->assertModelExists($this->local);
    expect($this->local->fresh()->deactivated_at)->toBeNull()->and(Activity::count())->toBe($auditCount)->and(EmailDeliveryAttempt::count())->toBe(0);
})->with(['deactivate', 'delete']);
