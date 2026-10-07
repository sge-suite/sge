<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\GrantingParty;
use App\Models\Internship;
use App\Models\User;
use App\Models\UserPersonalData;
use App\Support\AdministrativeActivityScope;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;

function auditAdministrator(): Affiliation
{
    return activity()->withoutLogging(fn (): Affiliation => Affiliation::factory()->global()
        ->for(User::factory()->state(['name' => 'Administradora da auditoria']))->create());
}

test('audit page requires authentication', function () {
    $activity = Activity::forSubject(Campus::factory()->create())->sole();
    $this->get(route('audit.index'))->assertRedirect(route('login'));
    $this->get(route('audit.show', $activity))->assertRedirect(route('login'));
});

test('selected system administrator can read audit and navigate without changing logs', function () {
    $administrator = auditAdministrator();
    $campus = Campus::factory()->create(['name' => 'Campus auditado']);
    $count = Activity::count();
    $activity = Activity::forSubject($campus)->sole();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);

    $dashboard = $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('audit.index').'"', false);
    $document = new DOMDocument;
    @$document->loadHTML($dashboard->getContent());
    expect((new DOMXPath($document))->query('//a[@href="'.route('audit.index').'"]//*[@data-flux-icon]')->length)->toBe(1);
    $this->get(route('audit.index'))->assertOk()->assertSee('Campus auditado')->assertSee('Auditoria')
        ->assertSee('data-audit-link', false)->assertSee('href="'.route('audit.show', $activity).'"', false)
        ->assertDontSee('Valor anterior')->assertDontSee('Campos alterados')->assertDontSee('<details', false)
        ->assertSee('tabindex="0"', false)->assertSee('x-on:keydown.enter.prevent=', false)
        ->assertSee('aria-haspopup="listbox"', false)->assertSee('select-trigger')->assertSee('data-select-option', false);
    Livewire::test('pages::audit.index')->assertSee('Campus auditado')->call('$refresh')->assertOk();
    $this->get(route('audit.show', $activity))->assertOk()->assertSee('Campus auditado')->assertSee('Campos alterados')->assertSee('Voltar');

    expect(Activity::count())->toBe($count)
        ->and(Gate::allows('view', Activity::forSubject($campus)->sole()))->toBeTrue();
});

test('campus detail shows only its own audit history', function () {
    $administrator = auditAdministrator();
    $campus = Campus::factory()->create(['name' => 'Campus com histórico']);
    $otherCampus = Campus::factory()->create(['name' => 'Campus fora do histórico']);
    $campus->update(['phone' => '5555555555']);
    $otherCampus->update(['phone' => '5555555556']);
    $campusUpdate = Activity::forSubject($campus)->where('event', 'updated')->sole();
    $otherCampusUpdate = Activity::forSubject($otherCampus)->where('event', 'updated')->sole();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);

    $this->get(route('campuses.show', $campus))
        ->assertOk()
        ->assertSee('Histórico de auditoria')
        ->assertSee('tabindex="0"', false)
        ->assertSee(route('audit.show', $campusUpdate->id), false)
        ->assertDontSee(route('audit.show', $otherCampusUpdate->id), false);
});

test('user detail groups account and current or deleted administrative affiliation history', function () {
    $administrator = auditAdministrator();
    $account = User::factory()->create(['name' => 'Conta com histórico']);
    $systemAffiliation = Affiliation::factory()->global()->for($account)->create();
    $campusAffiliation = Affiliation::factory()->for($account)->create(['type' => AffiliationType::CampusAdministrator]);
    $deletedAffiliation = Affiliation::factory()->global()->for($account)->create();
    $account->update(['name' => 'Conta com nome alterado']);
    $systemAffiliation->update(['email' => 'sistema-alterado@example.test']);
    $campusAffiliation->update(['email' => 'campus-alterado@example.test']);
    $deletedAffiliation->delete();
    $relevantIds = Activity::forSubject($account)->pluck('id')
        ->merge(Activity::forSubject($systemAffiliation)->pluck('id'))
        ->merge(Activity::forSubject($campusAffiliation)->pluck('id'))
        ->merge(Activity::forSubject($deletedAffiliation)->pluck('id'));
    $outside = User::factory()->create();
    $outsideAffiliation = Affiliation::factory()->global()->for($outside)->create();
    $outsideAffiliation->update(['email' => 'fora-do-historico@example.test']);
    $outsideActivity = Activity::forSubject($outsideAffiliation)->where('event', 'updated')->sole();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);

    $this->get(route('users.show', $account))->assertOk()
        ->assertSee('Histórico de auditoria')
        ->assertSee('tabindex="0"', false)
        ->assertDontSee(route('audit.show', $outsideActivity->id), false);

    $component = Livewire::test('pages::users.show', ['user' => $account]);
    $historyIds = $component->instance()->activityHistory->getCollection()->pluck('id')->all();
    $component->call('gotoPage', 2, 'auditHistoryPage');
    $historyIds = [...$historyIds, ...$component->instance()->activityHistory->getCollection()->pluck('id')->all()];

    expect($historyIds)->toEqualCanonicalizing($relevantIds->all())
        ->and($historyIds)->not->toContain($outsideActivity->id);
});

test('every other selected profile is forbidden even when the account also has a system administrator affiliation', function (AffiliationType $type) {
    $administrator = auditAdministrator();
    $factory = Affiliation::factory()->for($administrator->user)->state(['type' => $type]);
    if ($type === AffiliationType::Student) {
        $factory = $factory->student();
    } elseif ($type === AffiliationType::Supervisor) {
        $factory = $factory->supervisor();
    }
    $selected = $factory->create();
    $activity = Activity::forSubject(Campus::factory()->create())->sole();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $selected->id]);

    $this->get(route('audit.index'))->assertForbidden();
    Livewire::test('pages::audit.index')->assertForbidden();
    $this->get(route('audit.show', $activity))->assertForbidden();
    Livewire::test('pages::audit.show', ['activity' => $activity])->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertDontSee('href="'.route('audit.index').'"', false);
    expect(Gate::allows('viewAny', Activity::class))->toBeFalse();
})->with(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => $type !== AffiliationType::SystemAdministrator));

test('missing inactive foreign and invalid contexts do not grant audit access', function (string $context) {
    $administrator = auditAdministrator();
    $activity = Activity::forSubject(Campus::factory()->create())->sole();
    $user = $administrator->user;
    $session = ['active_affiliation_id' => $administrator->id];
    $expectsChoice = false;

    switch ($context) {
        case 'no affiliation':
            $user = User::factory()->create();
            $session = [];
            break;
        case 'inactive':
            $administrator->update(['deactivated_at' => now()]);
            break;
        case 'foreign':
            $session['active_affiliation_id'] = auditAdministrator()->id;
            $expectsChoice = true;
            break;
        case 'invalid':
            $session['active_affiliation_id'] = 'invalid';
            $expectsChoice = true;
            break;
        case 'missing selection':
            Affiliation::factory()->global()->for($user)->create();
            $session = [];
            $expectsChoice = true;
            break;
        case 'choice required':
            $session['active_affiliation_needs_choice'] = true;
            $expectsChoice = true;
            break;
    }

    $this->actingAs($user)->withSession($session);
    $response = $this->get(route('audit.index'));
    if ($expectsChoice) {
        $response->assertRedirect(route('affiliations.select'));
        $this->get(route('audit.show', $activity))->assertRedirect(route('affiliations.select'));
    } else {
        $response->assertForbidden();
        $this->get(route('audit.show', $activity))->assertForbidden();
    }
    Livewire::test('pages::audit.index')->assertForbidden();
    Livewire::test('pages::audit.show', ['activity' => $activity])->assertForbidden();
})->with(['no affiliation', 'inactive', 'foreign', 'invalid', 'missing selection', 'choice required']);

test('mounted audit reauthorizes every refresh filter and pagination request', function (string $revocation, string $request) {
    $administrator = auditAdministrator();
    $other = Affiliation::factory()->for($administrator->user)->create(['type' => AffiliationType::CampusAdministrator]);
    $foreign = auditAdministrator();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::audit.index');

    match ($revocation) {
        'deactivated' => $administrator->update(['deactivated_at' => now()]),
        'profile changed' => $administrator->update(['type' => AffiliationType::CampusAdministrator, 'campus_id' => $other->campus_id]),
        'selected other profile' => session()->put('active_affiliation_id', $other->id),
        'foreign selection' => session()->put('active_affiliation_id', $foreign->id),
        'deleted' => $administrator->delete(),
    };

    (match ($request) {
        'refresh' => $component->call('$refresh'),
        'filter' => $component->set('entity', 'user'),
        'pagination' => $component->call('gotoPage', 2),
    })->assertForbidden();
})->with(['deactivated', 'profile changed', 'selected other profile', 'foreign selection', 'deleted'])
    ->with(['refresh', 'filter', 'pagination']);

test('scope includes campuses and administrative accounts and affiliations regardless of actor and excludes other subjects', function () {
    $administrator = auditAdministrator();
    [$campus, $administrativeUser, $administrative, $nonadministrativeUser, $nonadministrative, $unaffiliated] = activity()->withoutLogging(function (): array {
        $campus = Campus::factory()->create();
        $user = User::factory()->create();
        $administrative = Affiliation::factory()->global()->deactivated()->for($user)->create();
        $other = Affiliation::factory()->for($user)->create();
        $outside = Affiliation::factory()->create();

        return [$campus, $user, $administrative, $outside->user, $other, User::factory()->create()];
    });
    $allowed = [];
    $excluded = [];
    app(CauserResolver::class)->withCauser($nonadministrative, function () use ($campus, $administrativeUser, $administrative, &$allowed): void {
        $campus->update(['name' => 'Campus alterado por outro perfil']);
        $administrativeUser->update(['name' => 'Conta administrativa alterada']);
        $administrative->update(['email' => 'administrative@example.test']);
        foreach ([$campus, $administrativeUser, $administrative] as $subject) {
            $allowed[] = Activity::forSubject($subject)->where('event', 'updated')->sole()->id;
        }
    });
    app(CauserResolver::class)->withCauser($administrator, function () use ($administrator, $nonadministrativeUser, $nonadministrative, $unaffiliated, &$excluded): void {
        foreach ([$nonadministrativeUser, $unaffiliated] as $user) {
            $user->update(['name' => 'Conta fora do escopo '.$user->id]);
            $excluded[] = Activity::forSubject($user)->where('event', 'updated')->sole()->id;
        }
        $nonadministrative->update(['email' => 'outside@example.test']);
        $excluded[] = Activity::forSubject($nonadministrative)->where('event', 'updated')->sole()->id;
        foreach ([Internship::class, GeneratedDocument::class, DocumentTemplate::class, GrantingParty::class, UserPersonalData::class] as $type) {
            $excluded[] = Activity::create([
                'subject_type' => $type, 'subject_id' => 123, 'causer_type' => $administrator->getMorphClass(),
                'causer_id' => $administrator->id, 'event' => 'updated', 'description' => 'Fora do escopo',
                'attribute_changes' => ['attributes' => ['name' => 'Conteúdo fora do escopo']],
            ])->id;
        }
    });
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::audit.index')->set('event', 'updated');

    expect($component->instance()->activities->getCollection()->pluck('id')->all())->toEqualCanonicalizing($allowed);
    foreach ($allowed as $id) {
        expect(Gate::allows('view', Activity::findOrFail($id)))->toBeTrue();
    }
    foreach ($excluded as $id) {
        expect(Gate::allows('view', Activity::findOrFail($id)))->toBeFalse();
        $this->get(route('audit.show', $id))->assertForbidden();
        Livewire::test('pages::audit.show', ['activity' => Activity::findOrFail($id)])->assertForbidden();
    }
    $component->assertDontSee('Conteúdo fora do escopo')->assertDontSee('outside@example.test');
});

test('administrative campus affiliations and inactive campuses remain in scope', function () {
    $administrator = auditAdministrator();
    $campus = Campus::factory()->create();
    $affiliation = Affiliation::factory()->for($campus)->create(['type' => AffiliationType::CampusAdministrator]);
    $campus->deactivate();
    $affiliation->update(['deactivated_at' => now()]);
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::audit.index')->set('event', 'updated');
    expect($component->instance()->activities->getCollection()->pluck('id')->all())->toEqualCanonicalizing([
        Activity::forSubject($campus)->where('event', 'updated')->sole()->id,
        Activity::forSubject($affiliation)->where('event', 'updated')->sole()->id,
    ]);
});

test('deleted administrative accounts and affiliations retain auditable history with recorded evidence', function () {
    $administrator = auditAdministrator();
    $user = User::factory()->create(['name' => 'Conta administrativa excluída']);
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    $affiliation->update(['email' => 'deleted-administrator@example.test']);
    $affiliation->delete();
    $user->delete();
    $outside = User::factory()->create(['name' => 'Conta operacional excluída']);
    $outsideAffiliation = Affiliation::factory()->for($outside)->create();
    $outsideAffiliation->delete();
    $outside->delete();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::audit.index')->set('event', 'deleted');
    $expected = [
        Activity::forSubject($affiliation)->where('event', 'deleted')->sole()->id,
        Activity::forSubject($user)->where('event', 'deleted')->sole()->id,
    ];

    expect($component->instance()->activities->getCollection()->pluck('id')->all())->toEqualCanonicalizing($expected);
    $component->assertSee('Conta administrativa excluída')->assertSee('Vínculo #'.$affiliation->id.' (indisponível)')
        ->assertDontSee('Conta operacional excluída');
});

test('historical affiliation types cannot expose operational changes when the current type becomes administrative', function () {
    $administrator = auditAdministrator();
    $affiliation = Affiliation::factory()->create();
    $created = Activity::forSubject($affiliation)->where('event', 'created')->sole();
    $affiliation->update(['email' => 'operational-history@example.test']);
    $operational = Activity::forSubject($affiliation)->where('event', 'updated')->sole();
    $affiliation->update(['type' => AffiliationType::CampusAdministrator]);
    $transition = Activity::forSubject($affiliation)->where('event', 'updated')->latest('id')->first();
    $affiliation->update(['email' => 'administrative-history@example.test']);
    $administrative = Activity::forSubject($affiliation)->where('event', 'updated')->latest('id')->first();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);

    expect(Gate::allows('view', $created))->toBeFalse()
        ->and(Gate::allows('view', $operational))->toBeFalse()
        ->and(Gate::allows('view', $transition))->toBeTrue()
        ->and(Gate::allows('view', $administrative))->toBeTrue();

    $component = Livewire::test('pages::audit.index')->set('entity', 'affiliation');
    expect($component->instance()->activities->getCollection()->pluck('id')->all())
        ->toEqualCanonicalizing([$transition->id, $administrative->id]);
    $this->get(route('audit.show', $administrative))->assertOk()->assertSee('administrative-history@example.test');
});

test('audit presents actual changes actor identity and password events without secrets or arbitrary properties', function () {
    $this->freezeSecond();
    $administrator = auditAdministrator();
    $target = User::factory()->create(['name' => 'Nome anterior']);
    Affiliation::factory()->global()->for($target)->create();
    app(CauserResolver::class)->withCauser($administrator, function () use ($target): void {
        $target->update(['name' => '<script>alert("audit")</script>']);
        $target->update(['password' => 'new-password-never-shown']);
    });
    $nameChange = Activity::forSubject($target)->where('event', 'updated')->sole();
    $passwordChange = Activity::forSubject($target)->where('event', 'password_changed')->sole();
    $forged = Activity::create([
        'subject_type' => $target->getMorphClass(), 'subject_id' => $target->id,
        'description' => 'secret-in-description', 'event' => 'updated',
        'attribute_changes' => [
            'old' => ['email' => null, 'password' => 'old-secret', 'name' => 'unchanged'],
            'attributes' => ['email' => 'visible@example.test', 'name' => 'unchanged', 'password' => 'new-secret',
                'remember_token' => 'remember-secret', 'token' => 'token-secret', 'api_key' => 'api-secret',
                'nested' => ['password' => 'nested-secret']],
        ],
        'properties' => ['signed_url' => 'signed-url-secret', 'token' => 'property-secret'],
    ]);
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $index = Livewire::test('pages::audit.index')->set('entity', 'user')
        ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false)
        ->assertSee('Senha alterada')->assertDontSee('visible@example.test');
    $nameDetails = Livewire::test('pages::audit.show', ['activity' => $nameChange])
        ->assertSee('Nome anterior')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false)
        ->assertSee('Administradora da auditoria')->assertSee('Vínculo #'.$administrator->id)
        ->assertSee(formatDateTime(now()))->assertSee('text-red-700', false)->assertSee('text-green-700', false)
        ->assertSee('dark:text-red-400', false)->assertSee('dark:text-green-400', false);
    $passwordDetails = Livewire::test('pages::audit.show', ['activity' => $passwordChange])
        ->assertSee('Senha alterada')->assertSee('Nenhum valor de campo disponível');
    $details = Livewire::test('pages::audit.show', ['activity' => $forged])
        ->assertSee('Não registrado')->assertSee('Sem valor')->assertSee('visible@example.test');
    foreach (['new-password-never-shown', $target->fresh()->password, 'old-secret', 'new-secret', 'remember-secret',
        'token-secret', 'api-secret', 'nested-secret', 'signed-url-secret', 'property-secret', 'secret-in-description'] as $secret) {
        foreach ([$index, $nameDetails, $passwordDetails, $details] as $component) {
            $component->assertDontSee($secret, true, false);
        }
    }
    expect($details->instance()->entry['changes'])->toBe([['field' => 'E-mail de login', 'before' => 'Sem valor', 'after' => 'visible@example.test']]);
    expect($index->instance()->activities->getCollection()->firstWhere('id', $forged->id))->not->toHaveKey('changes');

});

test('audit identifies system terminal account and deleted affiliation actors without inventing authors', function () {
    $administrator = auditAdministrator();
    $actor = auditAdministrator();
    $campus = Campus::factory()->create();
    app(CauserResolver::class)->withCauser($actor, fn (): bool => $campus->update(['name' => 'Autor excluído']));
    $actor->delete();
    app(CauserResolver::class)->withCauser($administrator->user, fn (): bool => $campus->update(['name' => 'Autor conta']));
    foreach (['terminal', 'system', null] as $actorType) {
        Activity::create([
            'subject_type' => $campus->getMorphClass(), 'subject_id' => $campus->id,
            'description' => 'Evento', 'properties' => ['actor' => $actorType],
        ]);
    }
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    Livewire::test('pages::audit.index')->assertSee('Terminal')->assertSee('Sistema')
        ->assertSee('Autor não registrado')->assertSee('Conta #'.$administrator->user_id)
        ->assertSee('Vínculo #'.$actor->id.' (indisponível)');
});

test('clear audit filters is shown only when a filter differs from its default', function () {
    $administrator = auditAdministrator();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);

    Livewire::test('pages::audit.index')->assertDontSee('Limpar filtros')
        ->set('entity', 'campus')->assertSee('Limpar filtros')
        ->set('entity', 'all')->assertDontSee('Limpar filtros')
        ->set('event', 'updated')->assertSee('Limpar filtros')
        ->set('entity', 'affiliation')->assertSee('Limpar filtros')
        ->set('event', 'all')->assertSee('Limpar filtros')
        ->call('clearFilters')->assertSet('entity', 'all')->assertSet('event', 'all')
        ->assertDontSee('Limpar filtros');

    $this->get(route('audit.index'))->assertOk()->assertDontSee('Limpar filtros');
    $this->get(route('audit.index', ['event' => 'updated']))->assertOk()->assertSee('Limpar filtros');
    $this->get(route('audit.index', ['entity' => 'campus']))->assertOk()->assertSee('Limpar filtros');
});

test('pagination and filters are scoped ordered stable and reset when changed', function () {
    $this->freezeSecond();
    $administrator = auditAdministrator();
    $campus = activity()->withoutLogging(fn (): Campus => Campus::factory()->create());
    $events = [];
    for ($index = 0; $index < 17; $index++) {
        $campus->update(['name' => 'Campus página '.$index]);
        $events[] = Activity::forSubject($campus)->latest('id')->first()->id;
    }
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::audit.index');
    expect($component->instance()->activities->perPage())->toBe(15)
        ->and($component->instance()->activities->total())->toBe(17)
        ->and($component->instance()->activities->getCollection()->pluck('id')->all())->toBe(array_slice(array_reverse($events), 0, 15));
    $component->call('gotoPage', 2)->assertSet('paginators.page', 2);
    expect($component->instance()->activities->getCollection()->pluck('id')->all())->toBe(array_slice(array_reverse($events), 15));
    $component->set('entity', 'user')->assertSet('paginators.page', 1)->assertSee('Nenhum registro de auditoria')
        ->call('gotoPage', 2)->set('event', 'created')->assertSet('paginators.page', 1)
        ->call('clearFilters')->assertSet('entity', 'all')->assertSet('event', 'all')->assertSet('paginators.page', 1)
        ->set('entity', 'internship')->assertSet('entity', 'all')->set('event', 'invalid')->assertSet('event', 'all');
    $this->get(route('audit.index', ['entity' => 'campus', 'event' => 'updated']))->assertOk()->assertSee('Campus página 16');
});

test('soft deleted campus is visible and current account without administrative affiliations stays outside scope', function () {
    $administrator = auditAdministrator();
    $campus = Campus::factory()->create(['name' => 'Campus excluído']);
    $campus->delete();
    $user = User::factory()->create(['name' => 'Conta sem vínculo administrativo atual']);
    $affiliation = Affiliation::factory()->global()->for($user)->create();
    $affiliation->delete();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    expect(app(AdministrativeActivityScope::class)->apply(Activity::forSubject($user))->exists())->toBeFalse();
    Livewire::test('pages::audit.index')->assertSee('Campus excluído')->assertDontSee('Conta sem vínculo administrativo atual');
});

test('audit details reauthorize the selected context on every request', function (string $revocation) {
    $administrator = auditAdministrator();
    $other = Affiliation::factory()->for($administrator->user)->create(['type' => AffiliationType::CampusAdministrator]);
    $foreign = auditAdministrator();
    $activity = Activity::forSubject(Campus::factory()->create())->sole();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::audit.show', ['activity' => $activity]);

    match ($revocation) {
        'deactivated' => $administrator->update(['deactivated_at' => now()]),
        'profile changed' => $administrator->update(['type' => AffiliationType::CampusAdministrator, 'campus_id' => $other->campus_id]),
        'selected other profile' => session()->put('active_affiliation_id', $other->id),
        'foreign selection' => session()->put('active_affiliation_id', $foreign->id),
        'deleted' => $administrator->delete(),
    };

    $component->call('$refresh')->assertForbidden();
})->with(['deactivated', 'profile changed', 'selected other profile', 'foreign selection', 'deleted']);

test('audit detail identity cannot be changed by the client', function () {
    $administrator = auditAdministrator();
    $activity = Activity::forSubject(Campus::factory()->create())->sole();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    expect(fn () => Livewire::test('pages::audit.show', ['activity' => $activity])->set('activityId', $activity->id + 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('missing audit records return not found', function () {
    $administrator = auditAdministrator();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $this->get(route('audit.show', PHP_INT_MAX))->assertNotFound();
});

test('audit details revalidate the subject scope when its last administrative affiliation is removed', function () {
    $administrator = auditAdministrator();
    $target = User::factory()->create();
    $affiliation = Affiliation::factory()->global()->for($target)->create();
    $activity = Activity::forSubject($target)->sole();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::audit.show', ['activity' => $activity]);
    $affiliation->delete();
    $component->call('$refresh')->assertForbidden();
});
