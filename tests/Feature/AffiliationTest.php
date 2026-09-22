<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\User;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('creates the affiliations schema with expected columns and foreign keys', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $columns = collect(Schema::getColumns('affiliations'))->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'id',
        'user_id',
        'campus_id',
        'type',
        'registration_number',
        'email',
        'deactivated_at',
        'last_used_at',
        'created_at',
        'updated_at',
        'course_id',
    ]);

    foreach ([
        'id' => ['bigint', false],
        'user_id' => ['bigint', false],
        'campus_id' => ['bigint', true],
        'course_id' => ['bigint', true],
        'type' => ['character varying(255)', false],
        'registration_number' => ['character varying(255)', true],
        'email' => ['character varying(255)', false],
        'deactivated_at' => ['timestamp(0) without time zone', true],
        'last_used_at' => ['timestamp(0) without time zone', true],
        'created_at' => ['timestamp(0) without time zone', true],
        'updated_at' => ['timestamp(0) without time zone', true],
    ] as $name => [$type, $nullable]) {
        expect($columns->get($name))->toMatchArray(['type' => $type, 'nullable' => $nullable]);
    }

    $indexes = collect(Schema::getIndexes('affiliations'));
    $foreignKeys = collect(Schema::getForeignKeys('affiliations'));

    expect($indexes->firstWhere('primary', true))->toMatchArray([
        'columns' => ['id'], 'primary' => true, 'unique' => true,
    ])
        ->and($foreignKeys->firstWhere('columns', ['user_id']))->toMatchArray([
            'columns' => ['user_id'],
            'foreign_table' => 'users',
            'foreign_columns' => ['id'],
            'on_delete' => 'restrict',
        ])
        ->and($foreignKeys->firstWhere('columns', ['campus_id']))->toMatchArray([
            'columns' => ['campus_id'],
            'foreign_table' => 'campuses',
            'foreign_columns' => ['id'],
            'on_delete' => 'restrict',
        ])
        ->and($foreignKeys->firstWhere('columns', ['course_id']))->toMatchArray([
            'columns' => ['course_id'],
            'foreign_table' => 'courses',
            'foreign_columns' => ['id'],
            'on_delete' => 'restrict',
        ])
        ->and(Schema::hasColumn('affiliations', 'deleted_at'))->toBeFalse();
});

test('rolls back and reapplies affiliations with later dependencies on PostgreSQL', function () {
    $paths = glob(database_path('migrations/*_create_affiliations_table.php'));
    expect($paths)->toHaveCount(1);
    $migration = pathinfo($paths[0], PATHINFO_FILENAME);
    $steps = DB::table('migrations')->where('migration', '>=', $migration)->count();

    $this->artisan('migrate:rollback', ['--step' => $steps, '--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('affiliations'))->toBeFalse()
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('user_personal_data'))->toBeTrue()
        ->and(Schema::hasTable('campuses'))->toBeTrue();

    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    expect(Schema::hasTable('affiliations'))->toBeTrue()
        ->and(Schema::hasTable('campuses'))->toBeTrue();
});

test('restricts physical deletion of users and campuses with affiliations', function () {
    $affiliation = Affiliation::factory()->student()->create();
    $user = $affiliation->user()->sole();
    $campus = $affiliation->campus()->sole();

    expect(fn () => DB::transaction(fn () => $user->delete()))->toThrow(QueryException::class);
    $this->assertModelExists($user);

    $campus->delete();
    $this->assertModelExists($campus);
    $this->assertModelExists($affiliation);

    expect(fn () => DB::transaction(fn () => $campus->forceDelete()))->toThrow(QueryException::class);
    $this->assertModelExists($campus);
});

test('accepts every affiliation enum value with its required scope and registration data', function () {
    $this->freezeSecond();

    foreach (AffiliationType::cases() as $index => $type) {
        $isGlobal = $type === AffiliationType::SystemAdministrator;
        $isSupervisor = $type === AffiliationType::Supervisor;
        $campus = $isGlobal ? null : Campus::factory()->create();
        $affiliation = Affiliation::factory()->create([
            'type' => $type,
            'campus_id' => $campus?->id,
            'course_id' => $type === AffiliationType::Student
                ? Course::factory()->create(['campus_id' => $campus->id])->id
                : null,
            'registration_number' => $isSupervisor ? null : 'ENUM-REG-'.$index,
        ]);

        expect($affiliation->fresh()->type)->toBe($type);
    }
});

test('creates useful factory states and exposes affiliation relationships', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $campus = Campus::factory()->create();

    $defaultAffiliation = Affiliation::factory()->for($user)->for($campus)->create();
    $globalAffiliation = Affiliation::factory()->global()->for($user)->create();
    $campusAffiliation = Affiliation::factory()->onCampus()->for($user)->create();
    $serverAffiliation = Affiliation::factory()->server()->for($user)->create();
    $studentAffiliation = Affiliation::factory()->student()->for($user)->for($campus)->create();
    $supervisorAffiliation = Affiliation::factory()->supervisor()->for($user)->for($campus)->create();
    $deactivatedAffiliation = Affiliation::factory()->deactivated()->for($user)->for($campus)->create();
    $recentlyUsedAffiliation = Affiliation::factory()->recentlyUsed()->for($user)->for($campus)->create();

    expect($defaultAffiliation->exists)->toBeTrue()
        ->and($globalAffiliation->type)->toBe(AffiliationType::SystemAdministrator)
        ->and($globalAffiliation->campus_id)->toBeNull()
        ->and($campusAffiliation->campus_id)->not->toBeNull()
        ->and($serverAffiliation->type)->not->toBe(AffiliationType::Student)
        ->and($serverAffiliation->type)->not->toBe(AffiliationType::Supervisor)
        ->and($serverAffiliation->registration_number)->not->toBeNull()
        ->and($studentAffiliation->type)->toBe(AffiliationType::Student)
        ->and($studentAffiliation->registration_number)->not->toBeNull()
        ->and($supervisorAffiliation->type)->toBe(AffiliationType::Supervisor)
        ->and($supervisorAffiliation->registration_number)->toBeNull()
        ->and($deactivatedAffiliation->deactivated_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($recentlyUsedAffiliation->last_used_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($defaultAffiliation->user->is($user))->toBeTrue()
        ->and($defaultAffiliation->campus->is($campus))->toBeTrue()
        ->and($user->affiliations()->count())->toBe(8)
        ->and($campus->affiliations()->whereKey($studentAffiliation->id)->exists())->toBeTrue()
        ->and($campus->affiliations()->whereKey($supervisorAffiliation->id)->exists())->toBeTrue();
});

test('casts affiliation types and dates and supports multiple affiliations per user', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $campus = Campus::factory()->create();
    $lastUsedAt = now()->subDays(2);

    $firstAffiliation = Affiliation::factory()->student()->for($user)->for($campus)->create([
        'registration_number' => 'CAST-REG-001',
        'last_used_at' => $lastUsedAt,
        'deactivated_at' => now()->subDay(),
    ]);
    $secondAffiliation = Affiliation::factory()->server()->for($user)->for($campus)->create();
    $loadedAffiliation = $firstAffiliation->fresh()->load(['user', 'campus']);

    expect($loadedAffiliation->type)->toBe(AffiliationType::Student)
        ->and($loadedAffiliation->last_used_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($loadedAffiliation->deactivated_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($loadedAffiliation->last_used_at->toDateTimeString())->toBe($lastUsedAt->toDateTimeString())
        ->and($loadedAffiliation->user->is($user))->toBeTrue()
        ->and($loadedAffiliation->campus->is($campus))->toBeTrue()
        ->and($user->affiliations()->count())->toBe(2)
        ->and($campus->affiliations()->whereKey($firstAffiliation->id)->exists())->toBeTrue()
        ->and($campus->affiliations()->whereKey($secondAffiliation->id)->exists())->toBeTrue();
});

test('validates required campus, registration, email, and existing foreign keys', function (array $overrides) {
    $user = User::factory()->create();
    $campus = Campus::factory()->create();
    $course = Course::factory()->create(['campus_id' => $campus->id]);
    $attributes = [
        'user_id' => $user->id,
        'campus_id' => $campus->id,
        'course_id' => $course->id,
        'type' => AffiliationType::Student,
        'registration_number' => 'VALIDATION-REG-001',
        'email' => 'valid-affiliation@example.test',
    ];

    expect(fn () => Affiliation::factory()->create([...$attributes, ...$overrides]))
        ->toThrow(ValidationException::class)
        ->and(Affiliation::count())->toBe(0);
})->with([
    'missing local campus' => [['campus_id' => null]],
    'nonexistent campus' => [['campus_id' => PHP_INT_MAX]],
    'nonexistent user' => [['user_id' => PHP_INT_MAX]],
    'missing student course' => [['course_id' => null]],
    'nonexistent course' => [['course_id' => PHP_INT_MAX]],
    'missing student registration' => [['registration_number' => null]],
    'missing server registration' => [[
        'type' => AffiliationType::Coordinator,
        'course_id' => null,
        'registration_number' => null,
    ]],
    'supervisor with registration' => [[
        'type' => AffiliationType::Supervisor,
        'course_id' => null,
        'registration_number' => 'SUPERVISOR-REG-001',
    ]],
    'missing email' => [['email' => null]],
    'invalid email' => [['email' => 'not-an-email']],
]);

test('rejects invalid enum and date values before persistence', function (array $overrides, string $exceptionClass) {
    expect(fn () => Affiliation::factory()->create($overrides))->toThrow($exceptionClass);
})->with([
    'unknown type' => [['type' => 'unknown'], ValueError::class],
    'invalid deactivation date' => [
        ['deactivated_at' => 'not-a-date'],
        InvalidFormatException::class,
    ],
    'invalid last-used date' => [
        ['last_used_at' => 'not-a-date'],
        InvalidFormatException::class,
    ],
]);

test('requires an active non-deleted campus on creation and reactivation', function () {
    $deactivatedCampus = Campus::factory()->deactivated()->create();
    $deletedCampus = Campus::factory()->create();
    $deletedCampus->delete();

    expect(fn () => Affiliation::factory()->create(['campus_id' => $deactivatedCampus->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => Affiliation::factory()->create(['campus_id' => $deletedCampus->id]))
        ->toThrow(ValidationException::class);

    $activeCampus = Campus::factory()->create();
    $affiliation = Affiliation::factory()->deactivated()->create(['campus_id' => $activeCampus->id]);
    $activeCampus->update(['deactivated_at' => now()]);

    expect(fn () => $affiliation->update(['deactivated_at' => null]))
        ->toThrow(ValidationException::class);

    $activeCampus->update(['deactivated_at' => null]);
    $affiliation->update(['deactivated_at' => null]);

    expect($affiliation->fresh()->deactivated_at)->toBeNull();
});

test('validates student registration uniqueness and allows repeated server registrations', function () {
    $campus = Campus::factory()->create();
    $registrationNumber = 'APP-REG-REUSE-001';

    Affiliation::factory()->student()->for($campus)->create([
        'registration_number' => $registrationNumber,
    ]);

    expect(fn () => Affiliation::factory()->student()->for($campus)->create([
        'registration_number' => $registrationNumber,
    ]))->toThrow(ValidationException::class);

    $firstServer = Affiliation::factory()->server()->for($campus)->create([
        'type' => AffiliationType::CampusAdministrator,
        'registration_number' => 'SERVER-REG-REUSE-001',
    ]);
    $secondServer = Affiliation::factory()->server()->for($campus)->create([
        'type' => AffiliationType::Coordinator,
        'registration_number' => 'SERVER-REG-REUSE-001',
    ]);

    expect($firstServer->exists)->toBeTrue()
        ->and($secondServer->exists)->toBeTrue();
});

test('tracks affiliation lifecycle and excludes deactivated records from active affiliations', function () {
    $activeAffiliation = Affiliation::factory()->student()->create();
    $deactivatedAffiliation = Affiliation::factory()->deactivated()->student()->create();

    expect(Affiliation::query()->active()->pluck('id')->all())->toBe([$activeAffiliation->id])
        ->and($activeAffiliation->deactivated_at)->toBeNull()
        ->and($deactivatedAffiliation->deactivated_at)->toBeInstanceOf(DateTimeInterface::class);

    $deactivatedAffiliation->update(['deactivated_at' => null]);

    expect(Affiliation::query()->active()->pluck('id')->all())->toContain($deactivatedAffiliation->id)
        ->and($deactivatedAffiliation->fresh()->deactivated_at)->toBeNull();
});

test('orders active affiliations by latest use with nulls last and an id tie breaker', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $campus = Campus::factory()->create();
    $sameTimestamp = now()->subHour();

    $olderAffiliation = Affiliation::factory()->student()->for($user)->for($campus)->create([
        'registration_number' => 'ORDER-OLDER',
        'last_used_at' => now()->subDay(),
    ]);
    $firstTie = Affiliation::factory()->student()->for($user)->for($campus)->create([
        'registration_number' => 'ORDER-TIE-1',
        'last_used_at' => $sameTimestamp,
    ]);
    $secondTie = Affiliation::factory()->student()->for($user)->for($campus)->create([
        'registration_number' => 'ORDER-TIE-2',
        'last_used_at' => $sameTimestamp,
    ]);
    $newerAffiliation = Affiliation::factory()->student()->for($user)->for($campus)->create([
        'registration_number' => 'ORDER-NEWER',
        'last_used_at' => now()->subMinute(),
    ]);
    $neverUsedAffiliation = Affiliation::factory()->student()->for($user)->for($campus)->create([
        'registration_number' => 'ORDER-NEVER-USED',
        'last_used_at' => null,
    ]);
    $deactivatedAffiliation = Affiliation::factory()->student()->deactivated()->for($user)->for($campus)->create([
        'registration_number' => 'ORDER-DEACTIVATED',
        'last_used_at' => now(),
    ]);

    $orderedIds = $user->affiliations()
        ->active()
        ->orderByLastUsedAt()
        ->pluck('id')
        ->all();

    expect($orderedIds)->toBe([
        $newerAffiliation->id,
        $firstTie->id,
        $secondTie->id,
        $olderAffiliation->id,
        $neverUsedAffiliation->id,
    ])
        ->and($orderedIds)->not->toContain($deactivatedAffiliation->id)
        ->and($neverUsedAffiliation->fresh()->last_used_at)->toBeNull();

    $neverUsedAffiliation->markAsUsed();

    expect($neverUsedAffiliation->fresh()->last_used_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('audits relevant affiliation changes but not isolated last-used updates', function () {
    $causer = User::factory()->create();
    $this->actingAs($causer);

    $affiliation = Affiliation::factory()->student()->create([
        'email' => 'first@example.test',
        'last_used_at' => now()->subDay(),
    ]);
    $creation = Activity::forSubject($affiliation)->where('event', 'created')->sole();

    expect($creation->causer_id)->toBe($causer->id)
        ->and($creation->attribute_changes->get('attributes'))->not->toHaveKey('last_used_at');

    $affiliation->update(['email' => 'updated@example.test']);
    expect(Activity::forSubject($affiliation)->where('event', 'updated')->count())->toBe(1);

    $affiliation->update(['deactivated_at' => now()]);
    $affiliation->update(['deactivated_at' => null]);
    $activityCountBeforeUsage = Activity::forSubject($affiliation)->count();

    $affiliation->markAsUsed();

    expect(Activity::forSubject($affiliation)->count())->toBe($activityCountBeforeUsage)
        ->and(Activity::forSubject($affiliation)->count())->toBe(4)
        ->and(Activity::forSubject($affiliation)->where('event', 'updated')->count())->toBe(3);
});
