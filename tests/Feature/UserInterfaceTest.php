<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

function administrativeEmailInputCount(string $html): int
{
    $document = new DOMDocument;
    @$document->loadHTML($html);

    return (new DOMXPath($document))->query('//input[@name="email"]')->length;
}

beforeEach(function () {
    Bus::fake();
    $this->admin = User::factory()->create();
    $this->selected = Affiliation::factory()->global()->for($this->admin)->create();
    $this->actingAs($this->admin)->withSession(['active_affiliation_id' => $this->selected->id]);
});

test('all administrative user pages render and forms have exactly one email field', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    foreach (['users.index', 'users.create', 'users.show', 'users.affiliations.create', 'users.affiliations.edit'] as $route) {
        $this->get(route($route, ['user' => $user, 'affiliation' => $affiliation]))->assertOk();
    }
    $this->get(route('users.show', $user))->assertOk()->assertSee('Voltar');
    $this->get(route('dashboard'))->assertSee('Usuários');
    $component = Livewire::test('users.form-fields')->set('cpf', '529.982.247-25')->call('lookupCpf');
    expect(administrativeEmailInputCount($component->html()))->toBe(1);
    expect(administrativeEmailInputCount(Livewire::test('users.form-fields', ['user' => $user])->html()))->toBe(1);
    expect(administrativeEmailInputCount(Livewire::test('users.form-fields', ['user' => $user, 'affiliation' => $affiliation])->html()))->toBe(1);
});

test('cpf lookup finds nonadministrative users and changing cpf clears consulted identity', function () {
    $user = User::factory()->create(['cpf' => '52998224725']);
    Livewire::test('users.form-fields')->assertSet('type', '')->assertSee('wire:keydown.enter.prevent="lookupCpf"', false)->assertDontSee('name="email"', false)
        ->set('cpf', '529.982.247-25')->call('lookupCpf')->assertSee($user->name)->assertSet('email', $user->email)
        ->assertDontSee('name="name"', false)->set('cpf', '11111111111')->assertSet('consultedCpf', '')->assertSet('email', '')
        ->assertDontSee($user->name)->call('lookupCpf')->assertHasErrors('cpf');
});

test('http validation restores values and consulted account on the create page', function () {
    $this->from(route('users.create'))->post(route('users.store'), [
        'cpf' => '52998224725', 'consulted_cpf' => '52998224725', 'name' => 'Ada', 'email' => 'ada@example.test',
        'type' => '', 'registration_number' => 'ADM',
    ])->assertSessionHasErrors('type');
    $this->get(route('users.create'))->assertOk()->assertSee('ada@example.test')->assertSee('Ada')->assertSee('ADM');
});

test('confirmation modal restores password errors after http validation failure', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    $this->patch(route('users.affiliations.deactivate', [$user, $affiliation]), ['confirmed' => 1, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->get(route('users.show', ['user' => $user, 'affiliation' => $affiliation->id, 'operation' => 'deactivate']))->assertOk()->assertSee('name="current_password"', false);
});

test('list includes only administrative persons with inactive history and paginates fifteen', function () {
    $hidden = User::factory()->create(['name' => 'Pessoa sem vínculo administrativo']);
    $inactive = User::factory()->create(['name' => 'Administrador Desativado']);
    Affiliation::factory()->global()->deactivated()->for($inactive)->create();
    $multiple = User::factory()->create(['name' => 'Administradora com vários vínculos']);
    Affiliation::factory()->global()->deactivated()->for($multiple)->create();
    Affiliation::factory()->for(Campus::factory())->for($multiple)->create(['type' => AffiliationType::CampusAdministrator]);
    Affiliation::factory()->global()->count(16)->create();
    $component = Livewire::test('pages::users.index')->assertDontSee($hidden->name)->assertSee($inactive->name);
    Livewire::test('pages::users.index')->set('search', $multiple->cpf)
        ->assertSee('2 vínculos')->assertSee('1 global · 1 vínculo de campus')->assertSee('1 ativo')->assertSee('1 desativado');
    expect($component->instance()->users->perPage())->toBe(15)->and($component->instance()->users->total())->toBe(19);
});

test('user search matches cpf and login email by equality', function () {
    $user = User::factory()->create(['cpf' => '52998224725', 'email' => 'ada@example.test']);
    Affiliation::factory()->global()->for($user)->create();
    Livewire::test('pages::users.index')->set('search', '529.982.247-25')->assertSee($user->name)
        ->set('search', '529982')->assertDontSee($user->name)->set('search', 'ADA@EXAMPLE.TEST')->assertSee($user->name)
        ->set('search', 'ada@')->assertDontSee($user->name);
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
    $affiliation = Affiliation::factory()->for($campus)->create(['type' => AffiliationType::CampusAdministrator]);
    $component = Livewire::test('pages::users.affiliations.edit', ['user' => $affiliation->user, 'affiliation' => $affiliation]);
    $campus->deactivate();
    $component->call('$refresh')->assertForbidden();
    $this->get(route('users.show', $affiliation->user))->assertOk()->assertSee('Somente leitura');
});

test('backend rechecks a cpf whose account was created after the explicit lookup', function () {
    Livewire::test('users.form-fields')->set('cpf', '52998224725')->call('lookupCpf')->assertSee('Nenhuma conta encontrada');
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
