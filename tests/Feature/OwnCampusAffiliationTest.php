<?php

use App\Actions\UpdateAdministrativeAffiliation;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Bus::fake();
    $this->office = Affiliation::factory()->server()->create(['email' => 'office@example.test', 'registration_number' => 'OFFICE-001']);
    $this->actor = $this->office->user;
    $this->actingAs($this->actor)->withSession(['active_affiliation_id' => $this->office->id]);
});

test('office sees and uses the existing form to edit its own selected affiliation', function () {
    $this->get(route('users.show', $this->actor))->assertOk()->assertSee('Editar vínculo')->assertDontSee('Excluir vínculo')
        ->assertSee('Outro Administrador do Sistema ou servidor com vínculo de Setor de Estágio pode desativá-lo');
    $this->get(route('users.affiliations.edit', [$this->actor, $this->office]))->assertOk()
        ->assertSee('office@example.test')->assertSee('OFFICE-001')->assertSee('Matrícula')
        ->assertDontSee('name="type"', false)->assertDontSee('name="campus_id"', false)
        ->assertDontSee('Este cadastro adiciona um vínculo');
    $originalAccount = $this->actor->fresh()->getAttributes();
    $other = Affiliation::factory()->global()->for($this->actor)->create();
    $originalOther = $other->fresh()->getAttributes();
    $this->put(route('users.affiliations.update', [$this->actor, $this->office]), [
        'email' => ' NEW@EXAMPLE.TEST ', 'registration_number' => ' OFFICE-002 ',
    ])->assertSessionHasNoErrors()->assertRedirect(route('users.show', $this->actor));
    expect($this->office->fresh()->email)->toBe('new@example.test')->and($this->office->fresh()->registration_number)->toBe('OFFICE-002')
        ->and($this->office->fresh()->campus_id)->toBe($this->office->campus_id)->and($this->office->fresh()->deactivated_at)->toBeNull()
        ->and($this->actor->fresh()->getAttributes())->toBe($originalAccount)->and($other->fresh()->getAttributes())->toBe($originalOther)
        ->and(Activity::forSubject($this->office)->where('event', 'updated')->sole()->causer_id)->toBe($this->office->id)
        ->and(EmailDeliveryAttempt::count())->toBe(0);
});

test('own affiliation editing rejects invalid contact registration ownership and immutable fields', function (string $field) {
    $payload = ['email' => 'new@example.test', 'registration_number' => 'OFFICE-002'];
    $payload[$field] = match ($field) {
        'email' => 'invalid',
        'registration_number' => Affiliation::factory()->server()->create()->registration_number,
        default => 'forged',
    };
    $this->put(route('users.affiliations.update', [$this->actor, $this->office]), $payload)->assertSessionHasErrors($field);
    expect($this->office->fresh()->email)->toBe('office@example.test')->and($this->office->fresh()->registration_number)->toBe('OFFICE-001');
})->with(['email', 'registration_number', 'type', 'campus_id', 'course_id', 'user_id', 'deactivated_at']);

test('office cannot deactivate delete or reactivate its own affiliation', function (string $operation) {
    $data = ['confirmed' => 1, 'current_password' => 'password'];
    $route = route('users.affiliations.'.($operation === 'delete' ? 'destroy' : $operation), [$this->actor, $this->office]);
    if ($operation === 'delete') {
        $this->delete($route, $data)->assertForbidden();
    } else {
        $this->patch($route, $data)->assertForbidden();
    }
    Livewire::test('pages::users.show', ['user' => $this->actor])->call('openConfirmation', $this->office->id, $operation)->assertForbidden();
    expect(fn () => app(UpdateAdministrativeAffiliation::class)->handle($this->actor, $this->actor, $this->office, $operation, $data))
        ->toThrow(AuthorizationException::class);
    $this->assertModelExists($this->office);
    expect($this->office->fresh()->deactivated_at)->toBeNull()->and(EmailDeliveryAttempt::count())->toBe(0);
})->with(['deactivate', 'delete', 'reactivate']);

test('office cannot edit affiliations outside the selected campus even when they are its own', function (bool $own) {
    $other = $own
        ? Affiliation::factory()->server()->for($this->actor)->create()
        : Affiliation::factory()->server()->create();
    $this->put(route('users.affiliations.update', [$other->user, $other]), ['email' => 'new@example.test', 'registration_number' => 'NEW'])->assertForbidden();
    expect(fn () => app(UpdateAdministrativeAffiliation::class)->handle($this->actor, $other->user, $other, 'update', [
        'email' => 'new@example.test', 'registration_number' => 'NEW',
    ]))->toThrow(AuthorizationException::class);
    $this->get(route('users.affiliations.edit', [$other->user, $other]))->assertForbidden();
    Livewire::test('users.form-fields', ['user' => $other->user, 'affiliation' => $other])->assertForbidden();
})->with([true, false]);

test('own affiliation forms revoke editing after campus inactivity or a selected context switch', function (string $component, bool $switchContext) {
    $params = ['user' => $this->actor, 'affiliation' => $this->office];
    $test = Livewire::test($component, $params);
    if ($switchContext) {
        $other = Affiliation::factory()->server()->for($this->actor)->create();
        session()->put('active_affiliation_id', $other->id);
    } else {
        $this->office->campus->deactivate();
    }
    $test->call('$refresh')->assertForbidden();
    $this->put(route('users.affiliations.update', [$this->actor, $this->office]), ['email' => 'new@example.test', 'registration_number' => 'NEW'])->assertForbidden();
})->with(['pages::users.affiliations.edit', 'users.form-fields'])->with([true, false]);

test('own affiliation editing preserves submitted values after validation', function () {
    $url = route('users.affiliations.edit', [$this->actor, $this->office]);
    $this->from($url)->put(route('users.affiliations.update', [$this->actor, $this->office]), [
        'email' => 'invalid', 'registration_number' => 'OFFICE-CORRECTED',
    ])->assertSessionHasErrors('email');
    $this->get($url)->assertOk()->assertSee('invalid')->assertSee('OFFICE-CORRECTED');
});

test('own affiliation update reauthorizes after acquiring record locks', function () {
    $other = Affiliation::factory()->server()->for($this->actor)->create();
    $triggered = false;
    DB::listen(function ($query) use ($other, &$triggered): void {
        if (! $triggered && str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) {
            $triggered = true;
            session()->put('active_affiliation_id', $other->id);
        }
    });
    $this->put(route('users.affiliations.update', [$this->actor, $this->office]), ['email' => 'new@example.test', 'registration_number' => 'NEW'])->assertForbidden();
    expect($this->office->fresh()->email)->toBe('office@example.test')->and($this->office->fresh()->registration_number)->toBe('OFFICE-001');
});
