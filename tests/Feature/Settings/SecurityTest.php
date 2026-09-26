<?php

use App\Actions\RequestEmailDelivery;
use App\Enums\EmailMessagePurpose;
use App\Jobs\SendEmailDelivery;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk()
        ->assertSee('Alterar e-mail da conta')
        ->assertSee('Confirmar novo e-mail')
        ->assertSee('Alterar senha')
        ->assertSee('Regras para senha')
        ->assertSee('A senha deve:')
        ->assertSee('Ter entre 8 e 64 caracteres.')
        ->assertSee('Conter letras maiúsculas e minúsculas.')
        ->assertSee('Conter pelo menos um número.')
        ->assertSee('Conter pelo menos um símbolo.')
        ->assertSee('Não ter sido exposta em vazamentos de dados conhecidos.')
        ->assertSee('passwordrules="minlength: 8; maxlength: 64; required: lower; required: upper; required: digit; required: special;"', false);
});

test('security settings page requires password confirmation when enabled', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
});

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('SgeCurrentPassword9!a'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'SgeCurrentPassword9!a')
        ->set('password', 'SgeNewPassword9!a')
        ->set('password_confirmation', 'SgeNewPassword9!a')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('SgeNewPassword9!a', $user->refresh()->password))->toBeTrue();

    $activity = Activity::forSubject($user)->where('event', 'password_changed')->sole();
    expect($activity->attribute_changes?->isEmpty() ?? true)->toBeTrue()
        ->and($activity->toJson())->not->toContain('SgeNewPassword9!a', $user->password);
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('SgeCurrentPassword9!a'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'wrong-password')
        ->set('password', 'SgeNewPassword9!a')
        ->set('password_confirmation', 'SgeNewPassword9!a')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});

test('account email change requires the current password and queues notices for both addresses', function () {
    Bus::fake();
    $user = User::factory()->create([
        'email' => 'conta@example.test',
        'password' => Hash::make('SgeCurrentPassword9!a'),
    ]);
    $affiliation = Affiliation::factory()->global()->for($user)->create(['email' => 'vinculo@example.test']);

    $this->actingAs($user)->withSession([
        'auth.password_confirmed_at' => time(),
        'active_affiliation_id' => $affiliation->id,
    ]);

    Livewire::test('pages::settings.security')
        ->assertSet('email', '')
        ->assertSet('email_confirmation', '')
        ->set('email', ' NOVO@EXAMPLE.TEST ')
        ->set('email_confirmation', ' novo@example.test ')
        ->set('email_current_password', 'SgeCurrentPassword9!a')
        ->call('updateEmail')
        ->assertHasNoErrors()
        ->assertSet('email', '')
        ->assertSet('email_confirmation', '')
        ->assertSet('email_current_password', '');

    $attempts = EmailDeliveryAttempt::query()->with('emailMessage')->orderBy('id')->get();
    $activity = Activity::forSubject($user)->where('event', 'updated')->sole();

    expect($user->fresh()->email)->toBe('novo@example.test')
        ->and($affiliation->fresh()->email)->toBe('vinculo@example.test')
        ->and($attempts->pluck('recipient_email')->all())->toBe(['conta@example.test', 'novo@example.test'])
        ->and($attempts->every(fn (EmailDeliveryAttempt $attempt): bool => $attempt->purpose === EmailMessagePurpose::AccountEmailChanged))->toBeTrue()
        ->and($attempts->every(fn (EmailDeliveryAttempt $attempt): bool => str_contains($attempt->emailMessage->content_text, 'conta@example.test') &&
            str_contains($attempt->emailMessage->content_text, 'novo@example.test')))->toBeTrue()
        ->and($attempts->every(fn (EmailDeliveryAttempt $attempt): bool => $attempt->requested_by_affiliation_id === $affiliation->id))->toBeTrue()
        ->and(EmailMessage::query()->count())->toBe(2)
        ->and($activity->attribute_changes->get('old'))->toMatchArray(['email' => 'conta@example.test'])
        ->and($activity->attribute_changes->get('attributes'))->toMatchArray(['email' => 'novo@example.test'])
        ->and($activity->causer_id)->toBe($affiliation->id);
    Bus::assertDispatched(SendEmailDelivery::class, 2);
});

test('account email and its delivery requests roll back together', function () {
    Bus::fake();
    $user = User::factory()->create([
        'email' => 'conta@example.test',
        'password' => Hash::make('SgeCurrentPassword9!a'),
    ]);
    Affiliation::factory()->for($user)->create();
    $this->actingAs($user);

    $this->app->instance(RequestEmailDelivery::class, new class extends RequestEmailDelivery
    {
        private int $requests = 0;

        public function accountEmailChanged(string $recipientEmail, string $previousEmail, string $newEmail, string $deliveryKey): EmailDeliveryAttempt
        {
            if (++$this->requests === 2) {
                throw new RuntimeException('Falha ao reservar o segundo aviso.');
            }

            return parent::accountEmailChanged($recipientEmail, $previousEmail, $newEmail, $deliveryKey);
        }
    });

    expect(fn () => Livewire::test('pages::settings.security')
        ->set('email', 'novo@example.test')
        ->set('email_confirmation', 'novo@example.test')
        ->set('email_current_password', 'SgeCurrentPassword9!a')
        ->call('updateEmail'))
        ->toThrow(RuntimeException::class, 'Falha ao reservar o segundo aviso.');

    expect($user->fresh()->email)->toBe('conta@example.test')
        ->and(EmailDeliveryAttempt::query()->exists())->toBeFalse()
        ->and(Activity::forSubject($user)->where('event', 'updated')->exists())->toBeFalse();
});

test('wrong password prevents account email change and notices', function () {
    Bus::fake();
    $user = User::factory()->create(['email' => 'conta@example.test']);
    Affiliation::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->set('email', 'novo@example.test')
        ->set('email_confirmation', 'novo@example.test')
        ->set('email_current_password', 'wrong-password')
        ->call('updateEmail')
        ->assertHasErrors(['email_current_password'])
        ->assertSet('email_current_password', '');

    expect($user->fresh()->email)->toBe('conta@example.test')
        ->and(EmailDeliveryAttempt::query()->exists())->toBeFalse();
    Bus::assertNotDispatched(SendEmailDelivery::class);
});

test('duplicate or invalid email prevents account email change', function (string $email) {
    Bus::fake();
    $user = User::factory()->create([
        'email' => 'conta@example.test',
        'password' => Hash::make('SgeCurrentPassword9!a'),
    ]);
    User::factory()->create(['email' => 'outro@example.test']);
    Affiliation::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->set('email', $email)
        ->set('email_confirmation', $email)
        ->set('email_current_password', 'SgeCurrentPassword9!a')
        ->call('updateEmail')
        ->assertHasErrors(['email']);

    expect($user->fresh()->email)->toBe('conta@example.test')
        ->and(EmailDeliveryAttempt::query()->exists())->toBeFalse();
    Bus::assertNotDispatched(SendEmailDelivery::class);
})->with(['OUTRO@EXAMPLE.TEST', 'not-an-email']);

test('unchanged account email creates no delivery attempts', function () {
    Bus::fake();
    $user = User::factory()->create([
        'email' => 'conta@example.test',
        'password' => Hash::make('SgeCurrentPassword9!a'),
    ]);
    Affiliation::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->set('email', ' CONTA@EXAMPLE.TEST ')
        ->set('email_confirmation', 'conta@example.test')
        ->set('email_current_password', 'SgeCurrentPassword9!a')
        ->call('updateEmail')
        ->assertHasNoErrors();

    expect($user->fresh()->email)->toBe('conta@example.test')
        ->and(EmailDeliveryAttempt::query()->exists())->toBeFalse();
    Bus::assertNotDispatched(SendEmailDelivery::class);
});

test('account email cannot change when the selected affiliation belongs to another account', function () {
    Bus::fake();
    $user = User::factory()->create([
        'email' => 'conta@example.test',
        'password' => Hash::make('SgeCurrentPassword9!a'),
    ]);
    $otherAffiliation = Affiliation::factory()->create();
    $this->actingAs($user)->withSession(['active_affiliation_id' => $otherAffiliation->id]);

    Livewire::test('pages::settings.security')
        ->set('email', 'novo@example.test')
        ->set('email_confirmation', 'novo@example.test')
        ->set('email_current_password', 'SgeCurrentPassword9!a')
        ->call('updateEmail')
        ->assertStatus(403);

    expect($user->fresh()->email)->toBe('conta@example.test')
        ->and(EmailDeliveryAttempt::query()->exists())->toBeFalse();
    Bus::assertNotDispatched(SendEmailDelivery::class);
});

test('account email change requires matching confirmation', function (string $confirmation) {
    Bus::fake();
    $user = User::factory()->create([
        'email' => 'conta@example.test',
        'password' => Hash::make('SgeCurrentPassword9!a'),
    ]);
    Affiliation::factory()->for($user)->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->set('email', 'novo@example.test')
        ->set('email_confirmation', $confirmation)
        ->set('email_current_password', 'SgeCurrentPassword9!a')
        ->call('updateEmail')
        ->assertHasErrors(['email_confirmation']);

    expect($user->fresh()->email)->toBe('conta@example.test')
        ->and(EmailDeliveryAttempt::query()->exists())->toBeFalse();
    Bus::assertNotDispatched(SendEmailDelivery::class);
})->with(['diferente@example.test', '']);
