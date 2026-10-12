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

test('office can access the campus user list and registration page', function () {
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('users.index'))->assertOk();
    $this->get(route('users.create'))->assertOk();
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
    $test = Livewire::test('pages::users.index');
    expect($test->instance()->users->total())->toBe(18)
        ->and($test->instance()->users->perPage())->toBe(15)
        ->and($test->instance()->users->getCollection()->pluck('id'))->toContain($local->id)
        ->not->toContain($foreign->id, $unaffiliated->id, $global->id);
    $filtered = $test->set('search', $local->cpf);
    expect($filtered->instance()->users->total())->toBe(1)
        ->and($filtered->instance()->users->getCollection()->pluck('id'))->toContain($local->id);
});

test('user detail paginates local affiliations with the shared page size', function () {
    $account = User::factory()->create(['name' => 'Conta com vários vínculos']);
    Affiliation::factory()->supervisor()->for($account)->for($this->office->campus)->count(16)->create();

    $component = Livewire::test('pages::users.show', ['user' => $account]);
    $affiliations = $component->instance()->affiliations;

    expect($affiliations->total())->toBe(16)
        ->and($affiliations->perPage())->toBe(15)
        ->and($affiliations->count())->toBe(15);

    $component->call('gotoPage', 2, 'affiliationsPage');

    expect($component->instance()->affiliations->count())->toBe(1);
});

test('name cpf and email search never reveal foreign or unaffiliated accounts', function (string $field) {
    $local = User::factory()->create(['name' => 'Ada Local', 'cpf' => '52998224725', 'email' => 'ada.local@example.test']);
    Affiliation::factory()->supervisor()->for($local)->for($this->office->campus)->create();
    $foreign = User::factory()->create(['name' => 'Ada Externa', 'cpf' => '11144477735', 'email' => 'ada.external@example.test']);
    Affiliation::factory()->for($foreign)->create();
    $unaffiliated = User::factory()->create(['name' => 'Ada Sem Vínculo']);
    $test = Livewire::test('pages::users.index')->set('search', match ($field) {
        'name' => 'ada', 'cpf' => '529.982.247-25', 'email' => 'ADA.LOCAL@EXAMPLE.TEST',
    });
    expect($test->instance()->users->getCollection()->pluck('id'))->toContain($local->id)
        ->not->toContain($foreign->id, $unaffiliated->id);
    if ($field !== 'name') {
        $test->set('search', $foreign->{$field});
        expect($test->instance()->users->total())->toBe(0)
            ->and($test->instance()->users->getCollection()->pluck('id'))->toBeEmpty();
    }
})->with(['name', 'cpf', 'email']);

test('office can access local user pages and cannot delete user accounts', function () {
    $local = User::factory()->create();
    $course = Course::factory()->for($this->office->campus)->create();
    $student = Affiliation::factory()->student()->for($local)->for($this->office->campus)->create(['course_id' => $course->id]);
    $foreign = Affiliation::factory()->for($local)->create(['registration_number' => 'FOREIGN', 'email' => 'foreign@example.test']);
    $this->get(route('users.show', $local))->assertOk();
    $this->get(route('users.edit', $local))->assertOk();
    $this->get(route('users.affiliations.create', $local))->assertOk();
    $this->get(route('users.affiliations.edit', [$local, $student]))->assertOk();
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
    $component = Livewire::test('users.form-fields')->set('cpf', $user->cpf)->call('lookupCpf');
    expect($component->get('consultedCpf'))->toBe($user->cpf)
        ->and($component->get('account')->is($user))->toBeTrue()
        ->and($component->get('registrationOptions'))->toBeEmpty();
})->with([true, false]);

test('local inactive history stays readable while inactive campus blocks creation', function () {
    $local = User::factory()->create();
    Affiliation::factory()->supervisor()->deactivated()->for($local)->for($this->office->campus)->create();
    $this->office->campus->deactivate();
    $this->get(route('users.index'))->assertOk();
    $this->get(route('users.show', $local))->assertOk();
    $this->get(route('users.create'))->assertForbidden();
});

test('changing selected campus refreshes the list and revokes an open foreign detail', function () {
    $local = User::factory()->create();
    Affiliation::factory()->supervisor()->for($local)->for($this->office->campus)->create();
    $otherOffice = Affiliation::factory()->for($this->office->user)->create();
    $otherUser = Affiliation::factory()->student()->for($otherOffice->campus)->create()->user;
    $list = Livewire::test('pages::users.index');
    $detail = Livewire::test('pages::users.show', ['user' => $local]);
    session()->put('active_affiliation_id', $otherOffice->id);
    $list->call('$refresh');
    $detail->call('$refresh')->assertForbidden();
});

test('administrative creation action stays forbidden to office context', function () {
    expect(fn () => app(CreateAdministrativeAffiliation::class)->handle([
        'cpf' => '52998224725', 'name' => 'Ada', 'email' => 'ada@example.test', 'type' => 'system_administrator', 'registration_number' => 'ADM',
    ], $this->office->user))->toThrow(AuthorizationException::class);
    expect(User::where('cpf', '52998224725')->exists())->toBeFalse();
});
