<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Scout\Jobs\MakeSearchable;
use Livewire\Livewire;
use Meilisearch\Client;

beforeEach(function () {
    config(['scout.driver' => 'meilisearch', 'scout.prefix' => 'sge_test_'.Str::lower(Str::random(16)).'_', 'scout.queue' => false]);
    $task = app(Client::class)->index((new User)->searchableAs())->updateSettings(config('scout.meilisearch.index-settings.'.User::class));
    expect(app(Client::class)->waitForTask($task['taskUid'])['status'])->toBe('succeeded');
});

afterEach(function () {
    $task = app(Client::class)->deleteIndex((new User)->searchableAs());
    app(Client::class)->waitForTask($task['taskUid']);
});

function settleUserSearchIndex(): void
{
    foreach (app(Client::class)->index((new User)->searchableAs())->getTasks()->getResults() as $task) {
        expect(app(Client::class)->waitForTask($task['uid'])['status'])->toBe('succeeded');
    }
}

test('meilisearch indexes only minimal administrative identity and supports typo tolerant names', function () {
    $actor = User::factory()->create();
    Affiliation::factory()->global()->for($actor)->create();
    $this->actingAs($actor);
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    Affiliation::factory()->global()->deactivated()->for($user)->create();
    $hidden = User::factory()->create(['name' => 'Outra Ada Lovelace']);
    settleUserSearchIndex();
    expect($user->toSearchableArray())->toBe(['id' => $user->id, 'name' => 'Ada Lovelace'])
        ->and(app(Client::class)->index($user->searchableAs())->getDocument($user->id))->toEqual(['id' => $user->id, 'name' => 'Ada Lovelace'])
        ->and($hidden->shouldBeSearchable())->toBeFalse();
    Livewire::test('pages::users.index')->set('search', 'Lovelacce')->assertSee($user->name)->assertDontSee($hidden->name);
});

test('eligibility follows affiliation creation removal and inactive history after commit', function () {
    $user = User::factory()->create(['name' => 'Administrativo Historico']);
    settleUserSearchIndex();
    expect(User::search('Historico')->raw()['hits'])->toBeEmpty();
    $affiliation = DB::transaction(fn () => Affiliation::factory()->global()->for($user)->create());
    settleUserSearchIndex();
    expect(User::search('Historico')->get()->modelKeys())->toBe([$user->id]);
    DB::transaction(fn () => $affiliation->update(['deactivated_at' => now()]));
    DB::transaction(fn () => $user->update(['name' => 'Administrativo Alterado']));
    settleUserSearchIndex();
    expect(User::search('Historico')->raw()['hits'])->toBeEmpty()->and(User::search('Alterado')->get()->modelKeys())->toBe([$user->id]);
    DB::transaction(fn () => $affiliation->delete());
    settleUserSearchIndex();
    expect(User::search('Alterado')->raw()['hits'])->toBeEmpty();
});

test('rollback does not publish administrative eligibility or queue indexing', function () {
    $user = User::factory()->create(['name' => 'Conta Descartada']);
    expect(fn () => DB::transaction(function () use ($user): void {
        Affiliation::factory()->global()->for($user)->create();
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);
    settleUserSearchIndex();
    expect(User::search('Descartada')->raw()['hits'])->toBeEmpty();
    config(['scout.queue' => true]);
    Bus::fake([MakeSearchable::class]);
    expect(fn () => DB::transaction(function () use ($user): void {
        Affiliation::factory()->global()->for($user)->create();
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);
    Bus::assertNotDispatched(MakeSearchable::class);
    DB::transaction(fn () => Affiliation::factory()->global()->for($user)->create());
    Bus::assertDispatched(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains($user));
});

test('reassigning or changing the type of an affiliation updates both accounts eligibility', function () {
    $first = User::factory()->create(['name' => 'Administrador Original']);
    $second = User::factory()->create(['name' => 'Administrador Destino']);
    $affiliation = Affiliation::factory()->global()->for($first)->create();
    DB::transaction(fn () => $affiliation->update(['user_id' => $second->id]));
    settleUserSearchIndex();
    expect(User::search('Original')->raw()['hits'])->toBeEmpty()->and(User::search('Destino')->get()->modelKeys())->toBe([$second->id]);
    $campus = City::withoutSyncingToSearch(fn () => Campus::withoutSyncingToSearch(fn () => Campus::factory()->create()));
    DB::transaction(fn () => $affiliation->update(['type' => AffiliationType::InternshipOffice, 'campus_id' => $campus->id]));
    settleUserSearchIndex();
    expect(User::search('Destino')->raw()['hits'])->toBeEmpty();
});
