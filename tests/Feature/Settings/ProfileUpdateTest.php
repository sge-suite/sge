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

test('user can delete their account', function () {
    $this->markTestSkipped('Account deletion is not implemented in the current settings UI.');
});

test('correct password must be provided to delete account', function () {
    $this->markTestSkipped('Account deletion is not implemented in the current settings UI.');
});
