<?php

use App\Models\Affiliation;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    Affiliation::factory()->for($user)->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk()
        ->assertSee(config('app.name'))
        ->assertDontSee('Repositório')
        ->assertDontSee('Documentação')
        ->assertDontSee('https://github.com/laravel/livewire-starter-kit')
        ->assertDontSee('https://laravel.com/docs/starter-kits#livewire');
});
