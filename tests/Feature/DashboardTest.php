<?php

use App\Models\Affiliation;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('welcome page explains the internship workflow without claiming signature features', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('O SGE organiza a jornada do estágio')
        ->assertSee('Solicitação e análise')
        ->assertSee('Formalização e acompanhamento')
        ->assertSee('Avaliação e conclusão')
        ->assertSee('cumprimento da carga horária')
        ->assertDontSee('assinaturas');
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    Affiliation::factory()->for($user)->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk()
        ->assertSee('Gestão de Estágios')
        ->assertSee('text-brand')
        ->assertSee('text-red-500')
        ->assertSee('data-active:!text-red-600')
        ->assertSee('dark:data-active:!text-red-400')
        ->assertSee('**:data-flux-menu-item-icon:!text-red-400')
        ->assertDontSee('Repositório')
        ->assertDontSee('Documentação')
        ->assertDontSee('https://github.com/laravel/livewire-starter-kit')
        ->assertDontSee('https://laravel.com/docs/starter-kits#livewire');
});
