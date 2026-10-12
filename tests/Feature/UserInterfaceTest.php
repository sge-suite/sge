<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    $this->admin = User::factory()->create();
    $this->selected = Affiliation::factory()->global()->for($this->admin)->create();
    $this->actingAs($this->admin)->withSession(['active_affiliation_id' => $this->selected->id]);
});

test('administrative user pages respond and form exposes its email input', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    foreach (['users.index', 'users.create', 'users.show', 'users.affiliations.create', 'users.affiliations.edit'] as $route) {
        $this->get(route($route, ['user' => $user, 'affiliation' => $affiliation]))->assertOk();
    }
    $this->get(route('users.show', $user))->assertOk();
    $this->get(route('dashboard'))->assertOk();
    Livewire::test('users.form-fields')->set('cpf', '529.982.247-25')->call('lookupCpf')->assertSee('name="email"', false);
    Livewire::test('users.form-fields', ['user' => $user])->assertSee('name="email"', false);
    Livewire::test('users.form-fields', ['user' => $user, 'affiliation' => $affiliation])->assertSee('name="email"', false);
});

test('cpf lookup finds nonadministrative users and changing cpf clears consulted identity', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);
    Livewire::test('users.form-fields')->assertSet('type', '')
        ->set('cpf', '529.982.247-25')->call('lookupCpf')->assertSet('email', $user->email)
        ->set('cpf', '11111111111')->assertSet('consultedCpf', '')->assertSet('email', '')
        ->call('lookupCpf')->assertHasErrors('cpf');
});

test('http validation restores values and consulted account on the create page', function () {
    $this->from(route('users.create'))->post(route('users.store'), [
        'cpf' => '52998224725', 'consulted_cpf' => '52998224725', 'name' => 'Ada', 'email' => 'ada@example.test',
        'type' => '', 'registration_number' => 'ADM',
    ])->assertSessionHasErrors('type');
    $this->get(route('users.create'))->assertOk();
});

test('confirmation modal restores password errors after http validation failure', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    $this->patch(route('users.affiliations.deactivate', [$user, $affiliation]), ['confirmed' => 1, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->get(route('users.show', ['user' => $user, 'affiliation' => $affiliation->id, 'operation' => 'deactivate']))->assertOk()
        ->assertSee('name="current_password"', false);
});

test('list includes only administrative persons with inactive history and paginates fifteen', function () {
    $hidden = User::factory()->create(['name' => 'Pessoa sem vínculo administrativo']);
    $inactive = User::factory()->create(['name' => 'Administrador Desativado']);
    Affiliation::factory()->global()->deactivated()->for($inactive)->create();
    $multiple = User::factory()->create(['name' => 'Administradora com vários vínculos']);
    Affiliation::factory()->global()->deactivated()->for($multiple)->create();
    Affiliation::factory()->for(Campus::factory())->for($multiple)->create(['type' => AffiliationType::InternshipOffice]);
    Affiliation::factory()->global()->count(16)->create();
    $component = Livewire::test('pages::users.index');
    expect($component->instance()->users->perPage())->toBe(15)
        ->and($component->instance()->users->total())->toBe(19)
        ->and($component->instance()->users->getCollection()->pluck('id'))->toContain($inactive->id, $multiple->id)
        ->not->toContain($hidden->id);

    $filtered = $component->set('search', $multiple->cpf);
    expect($filtered->instance()->users->total())->toBe(1)
        ->and($filtered->instance()->users->getCollection()->pluck('id'))->toContain($multiple->id);
});

test('user search matches cpf and login email by equality', function () {
    $user = User::factory()->create(['cpf' => '52998224725', 'email' => 'ada@example.test']);
    Affiliation::factory()->global()->for($user)->create();
    $component = Livewire::test('pages::users.index')->set('search', '529.982.247-25');
    expect($component->instance()->users->getCollection()->pluck('id'))->toContain($user->id);
    $component->set('search', '529982');
    expect($component->instance()->users->getCollection()->pluck('id'))->not->toContain($user->id);
    $component->set('search', 'ADA@EXAMPLE.TEST');
    expect($component->instance()->users->getCollection()->pluck('id'))->toContain($user->id);
    $component->set('search', 'ada@');
    expect($component->instance()->users->getCollection()->pluck('id'))->not->toContain($user->id);
});

test('mounted user pages reauthorize every refresh and identity is locked', function (string $component) {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    $parameters = ['user' => $user, 'affiliation' => $affiliation];
    $test = Livewire::test($component, $parameters);
    $this->selected->update(['deactivated_at' => now()]);
    $test->call('$refresh')->assertForbidden();
})->with(['pages::users.index', 'pages::users.create', 'pages::users.show', 'pages::users.affiliations.create', 'pages::users.affiliations.edit', 'users.form-fields']);

test('client cannot change consulted cpf or routed identities', function (string $field) {
    $user = User::factory()->create();
    expect(fn () => Livewire::test('users.form-fields', ['user' => $user])->set($field, 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['userId', 'affiliationId', 'consultedCpf']);

test('inactive campus affiliations show read only and reject editing on refresh', function () {
    $campus = Campus::factory()->create();
    $affiliation = Affiliation::factory()->for($campus)->create(['type' => AffiliationType::InternshipOffice]);
    $component = Livewire::test('pages::users.affiliations.edit', ['user' => $affiliation->user, 'affiliation' => $affiliation]);
    $campus->deactivate();
    $component->call('$refresh')->assertForbidden();
    $this->get(route('users.show', $affiliation->user))->assertOk();
});

test('backend rechecks a cpf whose account was created after the explicit lookup', function () {
    Livewire::test('users.form-fields')->set('cpf', '52998224725')->call('lookupCpf');
    $user = User::factory()->create(['cpf' => '52998224725']);
    $original = $user->fresh()->getAttributes();
    $this->post(route('users.store'), [
        'cpf' => '52998224725', 'consulted_cpf' => '52998224725', 'name' => 'Nome do cadastro anterior',
        'email' => 'affiliation@example.test', 'type' => 'system_administrator', 'registration_number' => 'ADM',
    ])->assertSessionHasNoErrors()->assertRedirect(route('users.show', $user));
    expect($user->fresh()->getAttributes())->toBe($original)->and($user->affiliations()->sole()->email)->toBe('affiliation@example.test');
});

test('changing to a different active system administrator during a write rejects stale context', function () {
    $other = Affiliation::factory()->global()->for($this->admin)->create();
    $triggered = false;
    DB::listen(function ($query) use ($other, &$triggered): void {
        if (! $triggered && str_contains($query->sql, '"users"') && str_contains($query->sql, 'for update')) {
            $triggered = true;
            session()->put('active_affiliation_id', $other->id);
        }
    });
    $this->post(route('users.store'), [
        'cpf' => '52998224725', 'consulted_cpf' => '52998224725', 'name' => 'Ada', 'email' => 'ada@example.test',
        'type' => 'system_administrator', 'registration_number' => 'ADM',
    ])->assertForbidden();
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse();
});

test('user list clears search and returns to the first page', function () {
    Livewire::test('pages::users.index')->set('search', 'Inexistente')
        ->call('gotoPage', 2)->call('clearFilters')
        ->assertSet('search', '')->assertSet('paginators.page', 1);
});

test('user filters require profile and status on the same visible affiliation', function () {
    $user = User::factory()->create(['name' => 'Pessoa filtrada']);
    Affiliation::factory()->global()->for($user)->create(['deactivated_at' => now()]);
    Affiliation::factory()->for($user)->create(['type' => AffiliationType::InternshipOffice]);
    $component = Livewire::test('pages::users.index')->set('role', 'system_administrator')->set('status', 'active')->set('search', 'Pessoa filtrada');
    expect($component->get('users')->total())->toBe(0);
    $component->set('status', 'inactive');
    expect($component->get('users')->pluck('id')->all())->toBe([$user->id]);
    $component->call('clearFilters')->assertSet('role', 'all')->assertSet('status', 'all')->assertSet('search', '');
});
