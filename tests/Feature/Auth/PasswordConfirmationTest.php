<?php

use App\Models\User;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk()
        ->assertSee('text-brand')
        ->assertSee('href="'.route('dashboard').'"', false)
        ->assertSeeInOrder([
            'data-test="confirm-password-button"',
            'data-test="confirm-password-back"',
        ], false);
});

test('confirm password screen returns to the previous internal page', function () {
    $user = User::factory()->create();
    $previousUrl = route('appearance.edit');

    $response = $this->actingAs($user)
        ->from($previousUrl)
        ->get(route('password.confirm'));

    $response->assertOk()
        ->assertSee('href="'.$previousUrl.'"', false);
});

test('confirm password screen falls back to the dashboard when the previous page is itself', function () {
    $user = User::factory()->create();
    $confirmationUrl = route('password.confirm');

    $response = $this->actingAs($user)
        ->from($confirmationUrl)
        ->get($confirmationUrl);

    $response->assertOk()
        ->assertSee('href="'.route('dashboard').'"', false);
});

test('confirm password screen does not link back to an external previous page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from('https://example.com/profile')
        ->get(route('password.confirm'));

    $response->assertOk()
        ->assertSee('href="'.route('dashboard').'"', false)
        ->assertDontSee('href="https://example.com/profile"', false);
});
