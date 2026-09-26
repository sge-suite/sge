<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk()
        ->assertSee('A nova senha deve:')
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
