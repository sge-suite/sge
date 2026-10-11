<?php

use App\Actions\CreateAdministrativeAffiliation;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    $this->office = Affiliation::factory()->server()->create();
    $this->actingAs($this->office->user)->withSession(['active_affiliation_id' => $this->office->id]);
});

test('office uses the shared users sidebar link and pages without administrative metrics', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee(route('users.index'), false)->assertSee('Usuários')
        ->assertDontSee('Painel administrativo')->assertDontSee('Vínculos por tipo');
    $this->get(route('users.index'))->assertOk()->assertSee('Contas com vínculos no seu campus')->assertSee(route('users.create'), false);
    $this->get(route('users.create'))->assertOk()->assertSee(route('users.store'), false);
});

test('list and pagination include only local accounts and local affiliation summaries', function () {
    $local = User::factory()->create(['name' => 'Pessoa Local']);
    Affiliation::factory()->student()->for($local)->for($this->office->campus)->create();
    Affiliation::factory()->supervisor()->deactivated()->for($local)->for($this->office->campus)->create();
    $foreignAffiliation = Affiliation::factory()->for($local)->create(['email' => 'foreign-affiliation@example.test']);
    Affiliation::factory()->global()->for($local)->create();
    $foreign = Affiliation::factory()->create()->user;
    $unaffiliated = User::factory()->create();
    $global = Affiliation::factory()->global()->create()->user;
    Affiliation::factory()->student()->for($this->office->campus)->count(16)->create();
    $test = Livewire::test('pages::users.index')->assertDontSee($foreign->name)->assertDontSee($unaffiliated->name)->assertDontSee($global->name);
    expect($test->instance()->users->total())->toBe(18)->and($test->instance()->users->perPage())->toBe(15);
    $test->set('search', $local->cpf)->assertSee('2 vínculos')->assertSee('1 ativo')->assertSee('1 desativado')
        ->assertSee('Discente')->assertSee('Supervisor')->assertDontSee('Administrador do Sistema')->assertDontSee($foreignAffiliation->email);
    expect($test->instance()->users->total())->toBe(1);
});

test('name cpf and email search never reveal foreign or unaffiliated accounts', function (string $field) {
    $local = User::factory()->create(['name' => 'Ada Local', 'cpf' => '52998224725', 'email' => 'ada.local@example.test']);
    Affiliation::factory()->supervisor()->for($local)->for($this->office->campus)->create();
    $foreign = User::factory()->create(['name' => 'Ada Externa', 'cpf' => '11144477735', 'email' => 'ada.external@example.test']);
    Affiliation::factory()->for($foreign)->create();
    $unaffiliated = User::factory()->create(['name' => 'Ada Sem Vínculo']);
    $test = Livewire::test('pages::users.index')->set('search', match ($field) {
        'name' => 'ada', 'cpf' => '529.982.247-25', 'email' => 'ADA.LOCAL@EXAMPLE.TEST',
    })->assertSee($local->name)->assertDontSee($foreign->name)->assertDontSee($unaffiliated->name);
    if ($field !== 'name') {
        $test->set('search', $foreign->{$field})->assertDontSee($foreign->name);
        expect($test->instance()->users->total())->toBe(0);
    }
})->with(['name', 'cpf', 'email']);

test('detail page shows local affiliations and shared creation and access editing controls', function () {
    $local = User::factory()->create();
    $course = Course::factory()->for($this->office->campus)->create();
    $student = Affiliation::factory()->student()->for($local)->for($this->office->campus)->create(['course_id' => $course->id]);
    $foreign = Affiliation::factory()->for($local)->create(['registration_number' => 'FOREIGN', 'email' => 'foreign@example.test']);
    $this->get(route('users.show', $local))->assertOk()->assertSee('Vínculos no campus')->assertSee($student->registration_number)->assertSee($course->name)
        ->assertDontSee($foreign->email)->assertDontSee($foreign->registration_number)
        ->assertSee('Editar dados de acesso')->assertSee('Adicionar vínculo')->assertDontSee('Excluir conta')->assertSee('Auditoria');
    $this->get(route('users.edit', $local))->assertOk()->assertSee(route('users.update', $local), false);
    $this->get(route('users.affiliations.create', $local))->assertOk()->assertSee(route('users.affiliations.store', $local), false)
        ->assertDontSee('value="system_administrator"', false)->assertDontSee('Consultar CPF');
    $this->get(route('users.affiliations.edit', [$local, $student]))->assertOk()->assertSee('Matrícula');
    $this->delete(route('users.destroy', $local))->assertForbidden();
    Livewire::test('pages::users.show', ['user' => $local])->call('openAccountDeletion')->assertForbidden();
    Livewire::test('pages::users.show', ['user' => $local])->call('openConfirmation', $student->id, 'delete')
        ->assertSet('showConfirmation', true)->assertSee('name="current_password"', false);
});

test('foreign and unaffiliated accounts are forbidden in details but found by explicit cpf lookup', function (bool $withAffiliation) {
    $user = User::factory()->create(['cpf' => '52998224725']);
    if ($withAffiliation) {
        Affiliation::factory()->for($user)->create();
    }
    $this->get(route('users.show', $user))->assertForbidden();
    Livewire::test('users.form-fields')->assertDontSee($user->name)->set('cpf', $user->cpf)->call('lookupCpf')
        ->assertSee($user->name)->assertSee($user->email)->assertDontSee(route('users.show', $user), false);
})->with([true, false]);

test('local inactive history stays readable while inactive campus blocks creation', function () {
    $local = User::factory()->create();
    Affiliation::factory()->supervisor()->deactivated()->for($local)->for($this->office->campus)->create();
    $this->office->campus->deactivate();
    $this->get(route('users.index'))->assertOk()->assertSee($local->name)->assertDontSee('Cadastrar usuário');
    $this->get(route('users.show', $local))->assertOk()->assertSee('Desativado');
    $this->get(route('users.create'))->assertForbidden();
});

test('changing selected campus refreshes the list and revokes an open foreign detail', function () {
    $local = User::factory()->create();
    Affiliation::factory()->supervisor()->for($local)->for($this->office->campus)->create();
    $otherOffice = Affiliation::factory()->for($this->office->user)->create();
    $otherUser = Affiliation::factory()->student()->for($otherOffice->campus)->create()->user;
    $list = Livewire::test('pages::users.index')->assertSee($local->name)->assertDontSee($otherUser->name);
    $detail = Livewire::test('pages::users.show', ['user' => $local]);
    session()->put('active_affiliation_id', $otherOffice->id);
    $list->call('$refresh')->assertDontSee($local->name)->assertSee($otherUser->name);
    $detail->call('$refresh')->assertForbidden();
});

test('administrative creation action stays forbidden to office context', function () {
    expect(fn () => app(CreateAdministrativeAffiliation::class)->handle([
        'cpf' => '52998224725', 'name' => 'Ada', 'email' => 'ada@example.test', 'type' => 'system_administrator', 'registration_number' => 'ADM',
    ], $this->office->user))->toThrow(AuthorizationException::class);
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse();
});
