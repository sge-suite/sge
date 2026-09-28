<?php

use App\Http\Middleware\RequireActiveAffiliation;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\City;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use App\Models\UserPersonalData;
use Database\Seeders\CitySeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AuditCityJob implements ShouldQueue
{
    public function __construct(public int $cityId) {}

    public function handle(): void
    {
        City::findOrFail($this->cityId)->update(['name' => 'Cidade processada']);
    }
}

test('accounts for every database table and keeps model event auditing enabled', function () {
    $modelClasses = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path): string => 'App\\Models\\'.pathinfo($path, PATHINFO_FILENAME));

    foreach ($modelClasses as $class) {
        if (in_array($class, [EmailMessage::class, EmailDeliveryAttempt::class], true)) {
            expect(class_uses_recursive($class))->not->toHaveKey(LogsActivity::class);

            continue;
        }

        expect(class_uses_recursive($class))->toHaveKey(LogsActivity::class);
    }

    $modelTables = $modelClasses->map(fn (string $class): string => (new $class)->getTable())->all();
    $technicalTables = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'sessions', 'password_reset_tokens',
    ];
    $databaseTables = collect(Schema::getTables())->pluck('name')->all();

    expect($databaseTables)->toEqualCanonicalizing([
        ...$modelTables, 'media', 'notifications', 'activity_log', ...$technicalTables,
    ]);
});

test('records model changes and deletions with previous and new public values', function () {
    $city = City::factory()->create(['name' => 'Antes']);
    $city->update(['name' => 'Depois']);
    $city->delete();

    $activities = Activity::forSubject($city)->orderBy('id')->get();

    expect($activities->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($activities[1]->attribute_changes->get('old'))->toMatchArray(['name' => 'Antes'])
        ->and($activities[1]->attribute_changes->get('attributes'))->toMatchArray(['name' => 'Depois'])
        ->and($activities[2]->attribute_changes->get('old'))->toMatchArray(['name' => 'Depois']);
});

test('records fillable personal data and emancipation verification under the active affiliation', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->for($user)->create();
    $oldAddress = Address::factory()->create();
    $newAddress = Address::factory()->create();
    $oldRgIssueDate = Carbon::parse('2018-04-10');
    $oldBirthDate = Carbon::parse('1995-02-03');
    $newRgIssueDate = Carbon::parse('2021-08-12');
    $newBirthDate = Carbon::parse('1996-05-06');
    $oldEmancipationVerifiedAt = now()->subDay();
    $newEmancipationVerifiedAt = now();
    $personalData = UserPersonalData::factory()->withoutOptionalData()->for($user)->make([
        'rg' => '11.222.333-4',
        'rg_issuer' => 'SSP/RS',
        'rg_issue_date' => $oldRgIssueDate,
        'birth_date' => $oldBirthDate,
        'phone' => '(55) 99999-1111',
        'job_role' => 'Supervisora anterior',
        'qualification' => 'Formação anterior',
        'training' => 'Curso anterior',
        'professional_experience' => 'Experiência anterior',
        'address_id' => $oldAddress->id,
    ]);
    expect($personalData->isFillable('emancipation_verified_at'))->toBeFalse();

    $personalData->emancipation_verified_at = $oldEmancipationVerifiedAt;
    $personalData->save();

    $created = Activity::forSubject($personalData)->where('event', 'created')->sole();
    $loggedAttributes = collect($created->attribute_changes->get('attributes'));
    expect($loggedAttributes->keys()->all())->toEqualCanonicalizing([
        'user_id', 'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone',
        'job_role', 'qualification', 'training', 'professional_experience', 'address_id',
        'emancipation_verified_at',
    ])
        ->and($loggedAttributes)->toMatchArray([
            'user_id' => $user->id,
            'rg' => '11.222.333-4',
            'rg_issuer' => 'SSP/RS',
            'rg_issue_date' => $oldRgIssueDate->toJSON(),
            'birth_date' => $oldBirthDate->toJSON(),
            'phone' => '55999991111',
            'job_role' => 'Supervisora anterior',
            'qualification' => 'Formação anterior',
            'training' => 'Curso anterior',
            'professional_experience' => 'Experiência anterior',
            'address_id' => $oldAddress->id,
            'emancipation_verified_at' => $oldEmancipationVerifiedAt->toJSON(),
        ]);

    Route::middleware(['web', 'auth', RequireActiveAffiliation::class])
        ->put('/audit-personal-data-probe', function () use ($personalData, $newAddress, $newRgIssueDate, $newBirthDate, $newEmancipationVerifiedAt) {
            $personalData->update([
                'rg' => '55.666.777-8',
                'rg_issuer' => 'IF/RS',
                'rg_issue_date' => $newRgIssueDate,
                'birth_date' => $newBirthDate,
                'phone' => '(55) 99988-2222',
                'job_role' => 'Supervisora atual',
                'qualification' => 'Formação atual',
                'training' => 'Curso atual',
                'professional_experience' => 'Experiência atual',
                'address_id' => $newAddress->id,
            ]);
            $personalData->emancipation_verified_at = $newEmancipationVerifiedAt;
            $personalData->save();

            return response()->noContent();
        });
    Route::middleware(['web', 'auth', RequireActiveAffiliation::class])
        ->delete('/audit-personal-data-probe', function () use ($personalData) {
            $personalData->delete();

            return response()->noContent();
        });
    Route::middleware(['web', 'auth', RequireActiveAffiliation::class])
        ->post('/audit-personal-data-touch', function () use ($personalData) {
            $personalData->touch();

            return response()->noContent();
        });

    $this->actingAs($user)->post(route('affiliations.store'), ['affiliation_id' => $affiliation->id]);
    $this->travel(1)->seconds();
    $this->put('/audit-personal-data-probe')->assertNoContent();

    $updates = Activity::forSubject($personalData)->where('event', 'updated')->orderBy('id')->get();
    $activity = $updates->first();
    $emancipationActivity = $updates->last();
    expect($updates)->toHaveCount(2)
        ->and($activity->causer_type)->toBe(Affiliation::class)
        ->and($activity->causer_id)->toBe($affiliation->id)
        ->and($activity->properties->get('user_id'))->toBe($user->id)
        ->and($activity->properties->get('affiliation_id'))->toBe($affiliation->id)
        ->and(collect($activity->attribute_changes->get('old'))->keys()->all())->toEqualCanonicalizing([
            'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'job_role',
            'qualification', 'training', 'professional_experience', 'address_id',
        ])
        ->and($activity->attribute_changes->get('old'))->toMatchArray([
            'rg' => '11.222.333-4',
            'rg_issuer' => 'SSP/RS',
            'rg_issue_date' => $oldRgIssueDate->toJSON(),
            'birth_date' => $oldBirthDate->toJSON(),
            'phone' => '55999991111',
            'job_role' => 'Supervisora anterior',
            'qualification' => 'Formação anterior',
            'training' => 'Curso anterior',
            'professional_experience' => 'Experiência anterior',
            'address_id' => $oldAddress->id,
        ])
        ->and(collect($activity->attribute_changes->get('attributes'))->keys()->all())->toEqualCanonicalizing([
            'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'job_role',
            'qualification', 'training', 'professional_experience', 'address_id',
        ])
        ->and($activity->attribute_changes->get('attributes'))->toMatchArray([
            'rg' => '55.666.777-8',
            'rg_issuer' => 'IF/RS',
            'rg_issue_date' => $newRgIssueDate->toJSON(),
            'birth_date' => $newBirthDate->toJSON(),
            'phone' => '55999882222',
            'job_role' => 'Supervisora atual',
            'qualification' => 'Formação atual',
            'training' => 'Curso atual',
            'professional_experience' => 'Experiência atual',
            'address_id' => $newAddress->id,
        ])
        ->and($emancipationActivity->causer_type)->toBe(Affiliation::class)
        ->and($emancipationActivity->causer_id)->toBe($affiliation->id)
        ->and(collect($emancipationActivity->attribute_changes->get('old'))->keys()->all())
        ->toBe(['emancipation_verified_at'])
        ->and($emancipationActivity->attribute_changes->get('old'))
        ->toMatchArray(['emancipation_verified_at' => $oldEmancipationVerifiedAt->toJSON()])
        ->and(collect($emancipationActivity->attribute_changes->get('attributes'))->keys()->all())
        ->toBe(['emancipation_verified_at'])
        ->and($emancipationActivity->attribute_changes->get('attributes'))
        ->toMatchArray(['emancipation_verified_at' => $newEmancipationVerifiedAt->toJSON()]);

    $this->travel(1)->seconds();
    $updatesBeforeTimestampTouch = Activity::forSubject($personalData)->where('event', 'updated')->count();
    $this->post('/audit-personal-data-touch')->assertNoContent();

    expect(Activity::forSubject($personalData)->where('event', 'updated')->count())
        ->toBe($updatesBeforeTimestampTouch);

    $this->delete('/audit-personal-data-probe')->assertNoContent();

    $deletion = Activity::forSubject($personalData)->where('event', 'deleted')->sole();
    expect($deletion->causer_type)->toBe(Affiliation::class)
        ->and($deletion->causer_id)->toBe($affiliation->id)
        ->and(collect($deletion->attribute_changes->get('old'))->keys()->all())->toEqualCanonicalizing([
            'user_id', 'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone',
            'job_role', 'qualification', 'training', 'professional_experience', 'address_id',
            'emancipation_verified_at',
        ])
        ->and($deletion->attribute_changes->get('old'))->toMatchArray([
            'user_id' => $user->id,
            'rg' => '55.666.777-8',
            'rg_issuer' => 'IF/RS',
            'rg_issue_date' => $newRgIssueDate->toJSON(),
            'birth_date' => $newBirthDate->toJSON(),
            'phone' => '55999882222',
            'job_role' => 'Supervisora atual',
            'qualification' => 'Formação atual',
            'training' => 'Curso atual',
            'professional_experience' => 'Experiência atual',
            'address_id' => $newAddress->id,
            'emancipation_verified_at' => $newEmancipationVerifiedAt->toJSON(),
        ]);
});

test('attributes an explicit selection to the selected affiliation', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->for($user)->create();

    $this->actingAs($user)->post(route('affiliations.store'), ['affiliation_id' => $affiliation->id])
        ->assertRedirect(route('dashboard'));

    $selection = Activity::forSubject($affiliation)->where('event', 'updated')->sole();

    expect($selection->causer_type)->toBe(Affiliation::class)
        ->and($selection->causer_id)->toBe($affiliation->id)
        ->and($selection->attribute_changes->get('attributes'))->toHaveKey('last_used_at');
});

test('attributes a business change in a web request to its active affiliation and account', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->for($user)->create();
    $city = City::factory()->create(['name' => 'Antes']);
    Route::middleware(['web', 'auth', RequireActiveAffiliation::class])
        ->post('/audit-city-probe', function () use ($city) {
            $city->update(['name' => 'Depois']);

            return response()->noContent();
        });

    $this->actingAs($user)->post(route('affiliations.store'), ['affiliation_id' => $affiliation->id]);
    $this->post('/audit-city-probe')->assertNoContent();

    $activity = Activity::forSubject($city)->where('event', 'updated')->sole();
    expect($activity->causer_type)->toBe(Affiliation::class)
        ->and($activity->causer_id)->toBe($affiliation->id)
        ->and($activity->properties->get('user_id'))->toBe($user->id)
        ->and($activity->properties->get('affiliation_id'))->toBe($affiliation->id);
});

test('records account changes while excluding email tables from the activity log', function () {
    $user = User::factory()->create(['password' => 'Secret-Password-123!']);
    expect(Activity::forSubject($user)->where('event', 'created')->sole()->attribute_changes->get('attributes'))
        ->toHaveKeys(['cpf', 'email']);
    $oldEmail = $user->email;
    $user->update(['email' => 'novo@example.test']);
    $message = EmailMessage::factory()->create();
    $attempt = EmailDeliveryAttempt::factory()->for($message)->create();

    $accountActivity = Activity::forSubject($user)->where('event', 'created')->sole();
    expect($accountActivity->attribute_changes->toJson())
        ->not->toContain('Secret-Password-123!', $user->password)
        ->and(Activity::forSubject($message)->exists())->toBeFalse()
        ->and(Activity::forSubject($attempt)->exists())->toBeFalse();

    $accountChange = Activity::forSubject($user)->where('event', 'updated')->sole();
    expect($accountChange->attribute_changes->get('old'))->toMatchArray(['email' => $oldEmail])
        ->and($accountChange->attribute_changes->get('attributes'))->toMatchArray(['email' => 'novo@example.test']);

});

test('audits city catalog imports through Eloquent as system operations', function () {
    File::partialMock()->shouldReceive('get')
        ->with(database_path('data/cities.json'))
        ->andReturn(json_encode([
            ['ibge_code' => '4300001', 'name' => 'Cidade teste', 'state' => 'RS'],
        ], JSON_THROW_ON_ERROR));

    $this->seed(CitySeeder::class);
    $city = City::query()->where('ibge_code', '4300001')->sole();
    $activity = Activity::forSubject($city)->where('event', 'created')->sole();

    expect($activity->causer_id)->toBeNull()
        ->and($activity->properties->get('actor'))->toBe('system')
        ->and($activity->attribute_changes->get('attributes'))->toHaveKeys(['ibge_code', 'name', 'state']);

    $this->seed(CitySeeder::class);
    expect(Activity::forSubject($city)->count())->toBe(1);
});

test('audits notification state and media metadata without copying notification payloads', function () {
    $user = User::factory()->create();
    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'document.available',
        'data' => ['url' => 'https://example.test/signed?signature=secret'],
    ]);
    $notification->markAsRead();

    $notificationActivities = Activity::query()
        ->where('properties->table', 'notifications')
        ->where('properties->record_id', $notification->id)
        ->orderBy('id')
        ->get();
    expect($notificationActivities->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($notificationActivities[0]->attribute_changes->toJson())->not->toContain('signature=secret')
        ->and($notificationActivities[1]->attribute_changes->get('attributes'))->toHaveKey('read_at');

    $media = Media::query()->create([
        'model_type' => User::class,
        'model_id' => $user->id,
        'collection_name' => 'document',
        'name' => 'Documento',
        'file_name' => 'documento.pdf',
        'disk' => 'local',
        'size' => 1,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->update(['name' => 'Documento atualizado']);

    $mediaActivities = Activity::forSubject($media)->orderBy('id')->get();
    expect($mediaActivities->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($mediaActivities[1]->attribute_changes->get('old'))->toMatchArray(['name' => 'Documento'])
        ->and($mediaActivities[1]->attribute_changes->get('attributes'))->toMatchArray(['name' => 'Documento atualizado']);
});

test('rolls back model activities with a failed transaction', function () {
    $before = Activity::query()->count();

    try {
        DB::transaction(function (): void {
            City::factory()->create();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Activity::query()->count())->toBe($before);
});

test('attributes queued model changes to the system', function () {
    $city = City::factory()->create();
    config()->set('queue.default', 'database');
    dispatch(new AuditCityJob($city->id));
    $this->artisan('queue:work', ['--once' => true, '--no-interaction' => true])->assertSuccessful();

    $activity = Activity::forSubject($city)->where('event', 'updated')->sole();
    expect($activity->causer_id)->toBeNull()
        ->and($activity->properties->get('actor'))->toBe('system');
});

test('blocks changes to activity models', function () {
    $city = City::factory()->create();
    $activity = Activity::forSubject($city)->sole();

    expect(fn () => $activity->update(['description' => 'forged']))->toThrow(RuntimeException::class)
        ->and(fn () => $activity->delete())->toThrow(RuntimeException::class);

});

test('audits personal data removed with its account', function () {
    $user = User::factory()->create();
    $personalData = UserPersonalData::factory()->for($user)->create();

    $user->delete();

    expect(Activity::forSubject($personalData)->where('event', 'deleted')->exists())->toBeTrue()
        ->and(Activity::forSubject($user)->where('event', 'deleted')->exists())->toBeTrue();
});
