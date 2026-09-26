<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->assertSet('name', $user->name)
        ->assertSet('email', $user->email);
});

test('profile shows the account email and directs changes to security', function () {
    $user = User::factory()->create(['email' => 'conta@example.test']);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->assertSet('email', 'conta@example.test')
        ->assertSee('Segurança')
        ->assertDontSee('Salvar e-mail');
});

test('user can delete their account', function () {
    $this->markTestSkipped('Account deletion is not implemented in the current settings UI.');
});

test('correct password must be provided to delete account', function () {
    $this->markTestSkipped('Account deletion is not implemented in the current settings UI.');
});
