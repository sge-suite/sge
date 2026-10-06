<?php

use App\Actions\CreateAdministrativeAffiliation;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

/** @return array<string, string> */
function runConcurrentAdministrativeWrites(array $operations, Affiliation $oldest): array
{
    $script = <<<'WORKER'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['database.connections.pgsql.database' => 'testing', 'database.connections.pgsql.url' => null, 'scout.driver' => 'null', 'queue.default' => 'sync', 'mail.default' => 'array']);
    Illuminate\Support\Facades\Bus::fake();
    $actor = App\Models\User::findOrFail((int) $argv[1]);
    $target = App\Models\User::findOrFail((int) $argv[2]);
    $selected = $actor->affiliations()->active()->sole();
    auth()->login($actor);
    session()->put('active_affiliation_id', $selected->id);
    echo 'ready:'.Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
    flush();
    try {
        if ($argv[3] === 'create') {
            app(App\Actions\CreateAdministrativeAffiliation::class)->handle([
                'type' => 'system_administrator', 'email' => 'administrative@example.test', 'registration_number' => 'NEW',
            ], $actor, $target);
        } else {
            $affiliation = $target->affiliations()->findOrFail((int) $argv[4]);
            app(App\Actions\UpdateAdministrativeAffiliation::class)->handle($actor, $target, $affiliation, $argv[3], [
                'confirmed' => 1, 'current_password' => 'password',
            ]);
        }
        echo "result:success\n";
    } catch (Illuminate\Validation\ValidationException $exception) {
        echo "result:validation\n";
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        echo 'result:'.$exception->getStatusCode()."\n";
    }
    WORKER;

    $workers = [];
    DB::beginTransaction();
    try {
        Affiliation::query()->whereKey($oldest)->lockForUpdate()->firstOrFail();
        foreach ($operations as $key => $operation) {
            $workers[$key] = new Process([PHP_BINARY, '-r', $script, ...array_map(strval(...), $operation)], base_path(), ['APP_ENV' => 'testing']);
            $workers[$key]->setTimeout(15)->start();
        }
        $deadline = microtime(true) + 10;
        $pids = [];
        while (count($pids) !== count($workers) && microtime(true) < $deadline) {
            foreach ($workers as $key => $worker) {
                if (preg_match('/ready:(\d+)/', $worker->getOutput(), $matches)) {
                    $pids[$key] = (int) $matches[1];
                }
            }
            usleep(10000);
        }
        expect($pids)->toHaveCount(count($workers));
        $waiting = 0;
        while ($waiting !== count($workers) && microtime(true) < $deadline) {
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
            usleep(10000);
        }
        expect($waiting)->toBe(count($workers));
        DB::commit();
        $results = [];
        foreach ($workers as $key => $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            preg_match('/result:(\w+)/', $worker->getOutput(), $matches);
            $results[$key] = $matches[1] ?? 'missing';
        }

        return $results;
    } finally {
        if (DB::transactionLevel() !== 0) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
}

test('concurrent duplicate creations serialize on the oldest administrator even when it is inactive', function () {
    $oldest = Affiliation::factory()->global()->deactivated()->create();
    $actor = User::factory()->create();
    Affiliation::factory()->global()->for($actor)->create();
    $target = User::factory()->create();
    $results = runConcurrentAdministrativeWrites([
        [$actor->id, $target->id, 'create'], [$actor->id, $target->id, 'create'],
    ], $oldest);
    sort($results);
    expect($results)->toBe(['success', 'validation'])->and($target->affiliations()->active()->count())->toBe(1);
});

test('concurrent creation and reactivation leave exactly one active affiliation', function () {
    $oldest = Affiliation::factory()->global()->deactivated()->create();
    $actor = User::factory()->create();
    Affiliation::factory()->global()->for($actor)->create();
    $target = User::factory()->create();
    $inactive = Affiliation::factory()->global()->deactivated()->for($target)->create();
    $results = runConcurrentAdministrativeWrites([
        [$actor->id, $target->id, 'create'], [$actor->id, $target->id, 'reactivate', $inactive->id],
    ], $oldest);
    sort($results);
    expect($results)->toBe(['success', 'validation'])->and($target->affiliations()->active()->count())->toBe(1);
});

test('concurrent administrators deactivating each other cannot remove the last active administrator', function () {
    $oldest = Affiliation::factory()->global()->deactivated()->create();
    $first = Affiliation::factory()->global()->create();
    $second = Affiliation::factory()->global()->create();
    $results = runConcurrentAdministrativeWrites([
        [$first->user_id, $second->user_id, 'deactivate', $second->id],
        [$second->user_id, $first->user_id, 'deactivate', $first->id],
    ], $oldest);
    sort($results);
    expect($results)->toBe(['403', 'success'])->and(Affiliation::query()->active()->count())->toBe(1);
});

test('administrative notices are reserved inside the transaction and queued only after commit', function () {
    config(['queue.default' => 'database']);
    Mail::fake();
    $actor = User::factory()->create();
    $selected = Affiliation::factory()->global()->for($actor)->create();
    $this->actingAs($actor)->withSession(['active_affiliation_id' => $selected->id]);
    $payload = [
        'cpf' => '52998224725', 'name' => 'Ada Lovelace', 'type' => 'system_administrator',
        'email' => 'ada@example.test', 'registration_number' => 'ADM',
    ];
    DB::beginTransaction();
    try {
        $affiliation = app(CreateAdministrativeAffiliation::class)->handle($payload, $actor);
        expect(EmailDeliveryAttempt::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0);
        DB::rollBack();
        expect(User::where('cpf', '52998224725')->exists())->toBeFalse()
            ->and(EmailDeliveryAttempt::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0)
            ->and(Activity::forSubject($affiliation)->count())->toBe(0);
        DB::beginTransaction();
        app(CreateAdministrativeAffiliation::class)->handle($payload, $actor);
        expect(DB::table('jobs')->count())->toBe(0);
        DB::commit();
        expect(DB::table('jobs')->count())->toBe(1);
        Mail::assertNothingOutgoing();
    } finally {
        if (DB::transactionLevel() !== 0) {
            DB::rollBack();
        }
    }
});
