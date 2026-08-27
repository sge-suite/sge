<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();
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
