<?php

use App\Enums\BrazilianState;
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
    config([
        'scout.driver' => 'meilisearch',
        'scout.prefix' => 'sge_test_'.Str::lower(Str::random(16)).'_',
        'scout.queue' => false,
    ]);

    foreach ([Campus::class, City::class] as $modelClass) {
        $index = app(Client::class)->index((new $modelClass)->searchableAs());
        $task = $index->updateSettings(config('scout.meilisearch.index-settings.'.$modelClass));
        expect(app(Client::class)->waitForTask($task['taskUid'])['status'])->toBe('succeeded');
    }
});

afterEach(function () {
    foreach ([Campus::class, City::class] as $modelClass) {
        $task = app(Client::class)->deleteIndex((new $modelClass)->searchableAs());
        app(Client::class)->waitForTask($task['taskUid']);
    }
});

function settleCampusSearchIndex(string $modelClass = Campus::class): void
{
    $tasks = app(Client::class)->index((new $modelClass)->searchableAs())->getTasks()->getResults();

    foreach ($tasks as $task) {
        $result = app(Client::class)->waitForTask($task['uid']);
        expect($result['status'])->toBe('succeeded');
    }
}

function authenticateCampusSearchAdministrator(): void
{
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();
    test()->actingAs($user);
}

test('campus interface uses meilisearch for typo tolerant name search and status filters', function () {
    authenticateCampusSearchAdministrator();
    $active = Campus::factory()->create(['name' => 'Campus Santa Rosa', 'cnpj' => '04252011000110']);
    $inactive = Campus::factory()->deactivated()->create(['name' => 'Campus Alegrete', 'cnpj' => '11222333000181']);
    settleCampusSearchIndex();

    Livewire::test('pages::campuses.index')
        ->set('search', 'Santa Rossa')->assertSee($active->name)->assertDontSee($inactive->name)
        ->set('search', '04.252.011/0001-10')->assertSee('Nenhum campus encontrado')->assertDontSee($active->name)
        ->set('search', 'Campus')->set('status', 'inactive')->assertSee($inactive->name)->assertDontSee($active->name)
        ->set('status', 'active')->assertSee($active->name)->assertDontSee($inactive->name);

    expect($active->toSearchableArray())->toBe([
        'id' => $active->id, 'name' => $active->name, 'deactivated_at' => null,
    ]);
});

test('meilisearch follows committed campus changes transitions and deletion', function () {
    $campus = DB::transaction(fn () => Campus::factory()->create(['name' => 'Campus Original']));
    settleCampusSearchIndex();
    expect(Campus::search('Original')->get()->modelKeys())->toBe([$campus->id]);

    DB::transaction(fn () => $campus->update(['name' => 'Campus Alterado']));
    DB::transaction(fn () => $campus->deactivate());
    settleCampusSearchIndex();
    expect(Campus::search('Original')->get())->toBeEmpty()
        ->and(Campus::search('Alterado')->where('deactivated_at', '!=', null)->get()->modelKeys())->toBe([$campus->id])
        ->and(Campus::search('Alterado')->where('deactivated_at', null)->get())->toBeEmpty();

    DB::transaction(fn () => $campus->reactivate());
    settleCampusSearchIndex();
    expect(Campus::search('Alterado')->where('deactivated_at', null)->get()->modelKeys())->toBe([$campus->id]);

    DB::transaction(fn () => $campus->delete());
    settleCampusSearchIndex();
    expect(Campus::search('Alterado')->raw()['hits'])->toBeEmpty();
});

test('rollback never publishes a campus to meilisearch or its indexing queue', function () {
    expect(fn () => DB::transaction(function (): void {
        Campus::factory()->create(['name' => 'Campus Descartado']);
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);

    settleCampusSearchIndex();
    expect(Campus::search('Descartado')->raw()['hits'])->toBeEmpty();

    config(['scout.queue' => true]);
    Bus::fake([MakeSearchable::class]);
    expect(fn () => DB::transaction(function (): void {
        Campus::factory()->create();
        throw new RuntimeException('rollback');
    }))->toThrow(RuntimeException::class);
    Bus::assertNotDispatched(MakeSearchable::class);

    $campus = DB::transaction(fn () => Campus::factory()->create());
    Bus::assertDispatched(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains($campus));
});

test('searchable city selection searches meilisearch and respects the selected state', function () {
    authenticateCampusSearchAdministrator();
    $rs = City::factory()->create(['name' => 'Santa Rosa', 'state' => BrazilianState::RioGrandeDoSul]);
    $sc = City::factory()->create(['name' => 'Santa Rosa de Lima', 'state' => BrazilianState::SantaCatarina]);
    settleCampusSearchIndex(City::class);

    Livewire::test('cities.select')
        ->set('state', 'RS')->set('citySearch', 'Santa Rossa')
        ->assertSee($rs->name)->assertDontSee($sc->name)
        ->set('cityId', (string) $rs->id)->assertSet('citySearch', '')
        ->set('state', 'SC')->assertSet('cityId', '')
        ->set('citySearch', 'Santa Rosa')->assertSee($sc->name);
});
