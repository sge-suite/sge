<?php

use App\Models\Affiliation;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

test('blocks functional access when no active affiliation exists', function () {
    $user = User::factory()->create();
    Affiliation::factory()->deactivated()->for($user)->create();

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    $this->get(route('affiliations.select'))->assertForbidden();
});

test('automatically selects the only active affiliation without updating its last use', function () {
    $user = User::factory()->create();
    $affiliation = Affiliation::factory()->for($user)->create();
    Affiliation::factory()->deactivated()->for($user)->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSessionHas('active_affiliation_id', $affiliation->id);

    expect($affiliation->fresh()->last_used_at)->toBeNull()
        ->and(app(ActiveAffiliationContext::class)->currentFor($user, app('session.store'))?->is($affiliation))->toBeTrue();
});

test('requires a choice for multiple affiliations that were never used', function () {
    $user = User::factory()->create();
    $first = Affiliation::factory()->for($user)->create();
    $second = Affiliation::factory()->for($user)->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('affiliations.select'));
    $this->get(route('affiliations.select'))->assertOk()->assertSee((string) $first->id)->assertSee((string) $second->id);
    $this->assertFalse(session()->has('active_affiliation_id'));
});

test('selects and switches affiliations explicitly while keeping the current choice in the session', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $first = Affiliation::factory()->for($user)->create();
    $second = Affiliation::factory()->for($user)->create();

    $this->actingAs($user)->post(route('affiliations.store'), ['affiliation_id' => $first->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('active_affiliation_id', $first->id);
    expect($first->fresh()->last_used_at)->not->toBeNull()
        ->and($second->fresh()->last_used_at)->toBeNull();

    $this->get(route('dashboard'))->assertOk()->assertSessionHas('active_affiliation_id', $first->id);
    $this->post(route('affiliations.store'), ['affiliation_id' => $second->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('active_affiliation_id', $second->id);

    expect($second->fresh()->last_used_at)->not->toBeNull()
        ->and(app(ActiveAffiliationContext::class)->currentFor($user, app('session.store'))?->is($second))->toBeTrue();
});

test('restores the most recently selected active affiliation without touching its last use', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    Affiliation::factory()->for($user)->create();
    $recent = Affiliation::factory()->recentlyUsed()->for($user)->create();
    $lastUsedAt = $recent->last_used_at;

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSessionHas('active_affiliation_id', $recent->id);

    expect($recent->fresh()->last_used_at->equalTo($lastUsedAt))->toBeTrue();
});

test('requires a choice when multiple affiliations share the most recent use time', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $lastUsedAt = now()->subMinute();
    $first = Affiliation::factory()->for($user)->create(['last_used_at' => $lastUsedAt]);
    $second = Affiliation::factory()->for($user)->create(['last_used_at' => $lastUsedAt]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertRedirect(route('affiliations.select'))
        ->assertSessionMissing('active_affiliation_id');

    expect($first->fresh()->last_used_at->equalTo($lastUsedAt))->toBeTrue()
        ->and($second->fresh()->last_used_at->equalTo($lastUsedAt))->toBeTrue();
});

test('rejects stale or forged session selections and selections belonging to another account', function () {
    $user = User::factory()->create();
    $own = Affiliation::factory()->for($user)->create();
    $other = Affiliation::factory()->create();

    $this->actingAs($user)->withSession(['active_affiliation_id' => $other->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('affiliations.select'));

    $this->withSession(['active_affiliation_id' => 'invalid'])
        ->get(route('dashboard'))
        ->assertRedirect(route('affiliations.select'));
    $this->get(route('dashboard'))->assertRedirect(route('affiliations.select'));

    $this->post(route('affiliations.store'), ['affiliation_id' => $other->id])->assertNotFound();
    expect($other->fresh()->last_used_at)->toBeNull()
        ->and($own->fresh()->last_used_at)->toBeNull();
});

test('invalidates an affiliation disabled after it was selected', function () {
    $user = User::factory()->create();
    $active = Affiliation::factory()->for($user)->create();
    $other = Affiliation::factory()->for($user)->create();

    $this->actingAs($user)->post(route('affiliations.store'), ['affiliation_id' => $active->id])
        ->assertRedirect(route('dashboard'));

    $active->update(['deactivated_at' => now()]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('affiliations.select'))
        ->assertSessionMissing('active_affiliation_id');
    $this->get(route('dashboard'))->assertRedirect(route('affiliations.select'));
    $this->post(route('affiliations.store'), ['affiliation_id' => $active->id])->assertNotFound();
    expect($other->fresh()->last_used_at)->toBeNull();
});
