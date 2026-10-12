<?php

use App\Actions\CreateCampusAffiliation;
use App\Actions\ManageAdministrativeUser;
use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use App\Models\UserPersonalData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Bus::fake();
    $this->office = Affiliation::factory()->server()->create();
    $this->actingAs($this->office->user)->withSession(['active_affiliation_id' => $this->office->id]);
    $this->target = User::factory()->create(['cpf' => '52998224725', 'email' => 'previous@example.test']);
    $this->local = Affiliation::factory()->supervisor()->for($this->target)->for($this->office->campus)->create();
});

test('office adds each permitted affiliation from the shared user detail flow', function (AffiliationType $type) {
    $this->local->update(['deactivated_at' => now()]);
    $original = $this->target->fresh()->getAttributes();
    $payload = ['type' => $type->value, 'email' => ' CONTEXT@EXAMPLE.TEST '];
    if ($type !== AffiliationType::Supervisor) {
        $payload['registration_number'] = ' MAT-NEW ';
    }
    if ($type === AffiliationType::Student) {
        $payload['course_id'] = Course::factory()->for($this->office->campus)->create()->id;
    }
    $this->post(route('users.affiliations.store', $this->target), $payload)->assertSessionHasNoErrors()->assertRedirect(route('users.show', $this->target));
    $created = $this->target->affiliations()->active()->sole();
    expect($created->type)->toBe($type)->and($created->campus_id)->toBe($this->office->campus_id)
        ->and($created->email)->toBe('context@example.test')->and($created->course_id)->toBe($payload['course_id'] ?? null)
        ->and($created->registration_number)->toBe($type === AffiliationType::Supervisor ? null : 'MAT-NEW')
        ->and($this->target->fresh()->getAttributes())->toBe($original)
        ->and(EmailDeliveryAttempt::pluck('purpose')->unique()->sole())->toBe(EmailMessagePurpose::NewAffiliation)
        ->and(EmailDeliveryAttempt::pluck('recipient_email')->sort()->values()->all())->toBe(['context@example.test', 'previous@example.test'])
        ->and(Activity::forSubject($created)->where('event', 'created')->sole()->causer_id)->toBe($this->office->id);
})->with(array_values(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => $type !== AffiliationType::SystemAdministrator)));

test('targeted creation rejects privilege campus course registration and identity injection', function (array $overrides, string $field) {
    $original = $this->target->fresh()->getAttributes();
    $this->post(route('users.affiliations.store', $this->target), [...[
        'type' => 'advisor', 'email' => 'context@example.test', 'registration_number' => 'MAT-NEW',
    ], ...$overrides])->assertSessionHasErrors($field);
    expect($this->target->affiliations()->count())->toBe(1)->and($this->target->fresh()->getAttributes())->toBe($original)
        ->and(EmailDeliveryAttempt::count())->toBe(0);
})->with([
    'administrator' => [['type' => 'system_administrator'], 'type'],
    'foreign campus' => [fn () => ['campus_id' => Campus::factory()->create()->id], 'campus_id'],
    'foreign student course' => [fn () => ['type' => 'student', 'course_id' => Course::factory()->create()->id], 'course_id'],
    'student missing course' => [['type' => 'student'], 'course_id'],
    'supervisor registration' => [['type' => 'supervisor'], 'registration_number'],
    'missing registration' => [['registration_number' => null], 'registration_number'],
    'foreign registration' => [fn () => ['registration_number' => Affiliation::factory()->create()->registration_number], 'registration_number'],
    'non supervisor profession' => [['job_role' => 'Engenheira'], 'job_role'],
    'cpf' => [['cpf' => '11144477735'], 'cpf'],
    'name' => [['name' => 'Nome adulterado'], 'name'],
    'target id' => [['user_id' => 123], 'user_id'],
]);

test('targeted supervisor creation preserves omitted professional data and rejects active duplicates', function () {
    $this->local->update(['deactivated_at' => now()]);
    $profile = UserPersonalData::factory()->for($this->target)->create(['job_role' => 'Anterior', 'training' => 'Formação anterior']);
    $payload = ['type' => 'supervisor', 'email' => $this->target->email, 'job_role' => 'Engenheira'];
    $this->post(route('users.affiliations.store', $this->target), $payload)->assertSessionHasNoErrors();
    expect($profile->fresh()->job_role)->toBe('Engenheira')->and($profile->fresh()->training)->toBe('Formação anterior')
        ->and($this->target->affiliations()->active()->sole()->registration_number)->toBeNull()->and(EmailDeliveryAttempt::count())->toBe(1);
    $this->post(route('users.affiliations.store', $this->target), $payload)->assertSessionHasErrors('type');
    expect($this->target->affiliations()->count())->toBe(2)->and(EmailDeliveryAttempt::count())->toBe(1);
});

test('office edits local access data with existing audit notices and reset token invalidation', function () {
    $foreign = Affiliation::factory()->for($this->target)->create();
    $originalLocal = $this->local->fresh()->getAttributes();
    $originalForeign = $foreign->fresh()->getAttributes();
    $password = $this->target->password;
    DB::table('password_reset_tokens')->insert(['email' => $this->target->email, 'token' => 'old-token', 'created_at' => now()]);
    $this->put(route('users.update', $this->target), ['name' => ' Nome corrigido ', 'cpf' => '111.444.777-35', 'email' => ' NEW@EXAMPLE.TEST '])
        ->assertSessionHasNoErrors()->assertRedirect(route('users.show', $this->target));
    expect($this->target->fresh()->name)->toBe('Nome corrigido')->and($this->target->fresh()->cpf)->toBe('11144477735')
        ->and($this->target->fresh()->email)->toBe('new@example.test')->and($this->target->fresh()->password)->toBe($password)
        ->and($this->local->fresh()->getAttributes())->toBe($originalLocal)->and($foreign->fresh()->getAttributes())->toBe($originalForeign)
        ->and(DB::table('password_reset_tokens')->where('email', 'previous@example.test')->exists())->toBeFalse()
        ->and(EmailDeliveryAttempt::pluck('purpose')->unique()->sole())->toBe(EmailMessagePurpose::AccountEmailChanged)
        ->and(EmailDeliveryAttempt::pluck('recipient_email')->sort()->values()->all())->toBe(['new@example.test', 'previous@example.test'])
        ->and(Activity::forSubject($this->target)->where('event', 'updated')->sole()->causer_id)->toBe($this->office->id);
});

test('office access editing rejects duplicate identities and forged fields', function (string $field) {
    $other = User::factory()->create(['cpf' => '11144477735', 'email' => 'occupied@example.test']);
    $payload = ['name' => 'Novo nome', 'cpf' => $this->target->cpf, 'email' => $this->target->email];
    $payload[$field] = match ($field) {
        'cpf' => $other->cpf, 'email' => 'OCCUPIED@EXAMPLE.TEST', default => 'forged',
    };
    $this->put(route('users.update', $this->target), $payload)->assertSessionHasErrors($field);
    expect($this->target->fresh()->name)->not->toBe('Novo nome')->and(EmailDeliveryAttempt::count())->toBe(0);
})->with(['cpf', 'email', 'password', 'type', 'campus_id', 'user_id']);

test('office edits its own access data even with an administrator affiliation on the same account', function (?bool $inactive) {
    $actor = $this->office->user;
    if ($inactive !== null) {
        Affiliation::factory()->global()->for($actor)->create(['deactivated_at' => $inactive ? now() : null]);
    }
    $originalAffiliations = $actor->affiliations()->orderBy('id')->get()->map->getAttributes()->all();
    $originalEmail = $actor->email;
    $originalPassword = $actor->password;
    $this->get(route('users.show', $actor))->assertOk();
    $this->get(route('users.edit', $actor))->assertOk();
    $this->put(route('users.update', $actor), ['name' => ' Nome corrigido ', 'cpf' => '111.444.777-35', 'email' => ' SELF@EXAMPLE.TEST '])
        ->assertSessionHasNoErrors()->assertRedirect(route('users.show', $actor));
    expect($actor->fresh()->name)->toBe('Nome corrigido')->and($actor->fresh()->cpf)->toBe('11144477735')
        ->and($actor->fresh()->email)->toBe('self@example.test')->and($actor->fresh()->password)->toBe($originalPassword)
        ->and($actor->affiliations()->orderBy('id')->get()->map->getAttributes()->all())->toBe($originalAffiliations)
        ->and(EmailDeliveryAttempt::pluck('recipient_email')->all())->toEqualCanonicalizing([$originalEmail, 'self@example.test'])
        ->and(EmailDeliveryAttempt::pluck('purpose')->unique()->sole())->toBe(EmailMessagePurpose::AccountEmailChanged)
        ->and(Activity::forSubject($actor)->where('event', 'updated')->sole()->causer_id)->toBe($this->office->id);
    $this->delete(route('users.destroy', $actor), ['confirmed' => 1, 'current_password' => 'password'])->assertForbidden();
})->with(['without administrator' => [null], 'active administrator' => [false], 'inactive administrator' => [true]]);

test('office cannot edit access to other global administrator accounts even with local history', function (bool $inactive) {
    Affiliation::factory()->global()->for($this->target)->create(['deactivated_at' => $inactive ? now() : null]);
    $this->get(route('users.show', $this->target))->assertOk();
    $this->put(route('users.update', $this->target), ['name' => 'Adulterado', 'cpf' => $this->target->cpf, 'email' => 'new@example.test'])->assertForbidden();
    expect(fn () => app(ManageAdministrativeUser::class)->handle($this->office->user, $this->target, 'update', [
        'name' => 'Adulterado', 'cpf' => $this->target->cpf, 'email' => 'new@example.test',
    ]))->toThrow(AuthorizationException::class);
    $this->get(route('users.edit', $this->target))->assertForbidden();
})->with([true, false]);

test('foreign or unaffiliated targets cannot use direct creation or access editing', function (bool $affiliated) {
    $target = $affiliated ? Affiliation::factory()->create()->user : User::factory()->create();
    $this->post(route('users.affiliations.store', $target), ['type' => 'supervisor', 'email' => $target->email])->assertForbidden();
    $this->put(route('users.update', $target), ['name' => 'Adulterado', 'cpf' => $target->cpf, 'email' => $target->email])->assertForbidden();
    expect(fn () => app(CreateCampusAffiliation::class)->handle($this->office->user, ['type' => 'supervisor', 'email' => $target->email], $target))
        ->toThrow(AuthorizationException::class);
    $this->get(route('users.edit', $target))->assertForbidden();
    $this->get(route('users.affiliations.create', $target))->assertForbidden();
    Livewire::test('users.form-fields', ['user' => $target])->assertForbidden();
})->with([true, false]);

test('inactive campus keeps details readable but denies both mutations and forms', function () {
    $this->office->campus->deactivate();
    $this->get(route('users.show', $this->target))->assertOk();
    $this->put(route('users.update', $this->target), ['name' => 'Adulterado', 'cpf' => $this->target->cpf, 'email' => $this->target->email])->assertForbidden();
    $this->post(route('users.affiliations.store', $this->target), ['type' => 'advisor', 'email' => $this->target->email, 'registration_number' => 'NEW'])->assertForbidden();
    $this->get(route('users.edit', $this->target))->assertForbidden();
    $this->get(route('users.affiliations.create', $this->target))->assertForbidden();
});

test('mounted account and affiliation forms revoke access after switching to another campus', function (string $component) {
    $test = Livewire::test($component, ['user' => $this->target]);
    $other = Affiliation::factory()->for($this->office->user)->create();
    session()->put('active_affiliation_id', $other->id);
    $test->call('$refresh')->assertForbidden();
})->with(['pages::users.edit', 'pages::users.affiliations.create', 'users.form-fields']);

test('access update rechecks authorization after locking the account', function (string $condition) {
    $other = Affiliation::factory()->for($this->office->user)->create();
    $triggered = false;
    DB::listen(function ($query) use ($condition, $other, &$triggered): void {
        if (! $triggered && str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) {
            $triggered = true;
            match ($condition) {
                'context' => session()->put('active_affiliation_id', $other->id),
                'campus' => $this->office->campus->deactivate(),
                'scope' => $this->local->delete(),
                'administrator' => Affiliation::factory()->global()->for($this->target)->create(),
            };
        }
    });
    $this->put(route('users.update', $this->target), ['name' => 'Adulterado', 'cpf' => $this->target->cpf, 'email' => 'new@example.test'])->assertForbidden();
    expect($this->target->fresh()->email)->toBe('previous@example.test')->and(EmailDeliveryAttempt::count())->toBe(0);
})->with(['context', 'campus', 'scope', 'administrator']);
