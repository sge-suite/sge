<?php

use App\Models\Affiliation;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

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

test('user can update their account email without changing affiliation emails', function () {
    $user = User::factory()->create(['email' => 'conta@example.test']);
    $affiliation = Affiliation::factory()->global()->for($user)->create([
        'email' => 'vinculo@example.test',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('email', ' NOVO@EXAMPLE.TEST ')
        ->call('updateEmail')
        ->assertHasNoErrors()
        ->assertSet('email', 'novo@example.test');

    $activity = Activity::forSubject($user)->where('event', 'updated')->sole();

    expect($user->fresh()->email)->toBe('novo@example.test')
        ->and($affiliation->fresh()->email)->toBe('vinculo@example.test')
        ->and($activity->attribute_changes->get('old'))->toMatchArray(['email' => 'conta@example.test'])
        ->and($activity->attribute_changes->get('attributes'))->toMatchArray(['email' => 'novo@example.test']);
});

test('email update rejects an address already used by another account', function () {
    $user = User::factory()->create(['email' => 'conta@example.test']);
    User::factory()->create(['email' => 'outro@example.test']);
    $affiliation = Affiliation::factory()->global()->for($user)->create([
        'email' => 'vinculo@example.test',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('email', 'OUTRO@EXAMPLE.TEST')
        ->call('updateEmail')
        ->assertHasErrors(['email' => 'unique']);

    expect($user->fresh()->email)->toBe('conta@example.test')
        ->and($affiliation->fresh()->email)->toBe('vinculo@example.test')
        ->and(Activity::forSubject($user)->where('event', 'updated')->exists())->toBeFalse();
});

test('email update rejects malformed addresses', function () {
    $user = User::factory()->create();
    Affiliation::factory()->global()->for($user)->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('email', 'not-an-email')
        ->call('updateEmail')
        ->assertHasErrors(['email']);

    expect($user->fresh()->email)->not->toBe('not-an-email');
});

test('user can delete their account', function () {
    $this->markTestSkipped('Account deletion is not implemented in the current settings UI.');
});

test('correct password must be provided to delete account', function () {
    $this->markTestSkipped('Account deletion is not implemented in the current settings UI.');
});
