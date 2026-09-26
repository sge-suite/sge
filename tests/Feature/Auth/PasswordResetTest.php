<?php

use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Models\User;
use App\Notifications\QueuedPasswordReset;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('invitation link pre-fills the password reset email', function () {
    $this->get(route('password.request', ['email' => 'invited@example.test']))
        ->assertOk()
        ->assertSee('value="invited@example.test"', false);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedPasswordReset::class, function (QueuedPasswordReset $notification): bool {
        return $notification instanceof ShouldQueue && $notification instanceof ShouldBeEncrypted && $notification->afterCommit === true;
    });

    expect(EmailMessage::query()->exists())->toBeFalse()
        ->and(EmailDeliveryAttempt::query()->exists())->toBeFalse();
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedPasswordReset::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk()
            ->assertSee('Regras para senha')
            ->assertSee('A senha deve:')
            ->assertSee('Ter entre 8 e 64 caracteres.')
            ->assertSee('Conter letras maiúsculas e minúsculas.')
            ->assertSee('Conter pelo menos um número.')
            ->assertSee('Conter pelo menos um símbolo.')
            ->assertSee('Não ter sido exposta em vazamentos de dados conhecidos.')
            ->assertSee('passwordrules="minlength: 8; maxlength: 64; required: lower; required: upper; required: digit; required: special;"', false);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedPasswordReset::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'SgeResetPassword9!a',
            'password_confirmation' => 'SgeResetPassword9!a',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        $activity = Activity::forSubject($user)->where('event', 'password_changed')->sole();
        expect($activity->causer_type)->toBe(User::class)
            ->and($activity->causer_id)->toBe($user->id)
            ->and($activity->attribute_changes?->isEmpty() ?? true)->toBeTrue()
            ->and($activity->toJson())->not->toContain('SgeResetPassword9!a', $user->password);

        return true;
    });
});
