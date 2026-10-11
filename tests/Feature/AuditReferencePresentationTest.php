<?php

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

test('course audit resolves both previous and new coordinator names and the campus name', function () {
    $office = Affiliation::factory()->create();
    $campus = $office->campus;
    $former = Affiliation::factory()->for($campus)->for(User::factory()->state(['name' => 'Coordenadora anterior']))
        ->create(['type' => AffiliationType::Coordinator]);
    $current = Affiliation::factory()->for($campus)->for(User::factory()->state(['name' => 'Coordenador atual']))
        ->create(['type' => AffiliationType::Coordinator]);
    $course = Course::factory()->for($campus)->create(['primary_coordinator_affiliation_id' => $former->id]);
    $created = Activity::forSubject($course)->where('event', 'created')->sole();
    $course->update(['primary_coordinator_affiliation_id' => $current->id, 'secondary_coordinator_affiliation_id' => $former->id]);
    $updated = Activity::forSubject($course)->where('event', 'updated')->sole();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);

    $creation = Livewire::test('pages::audit.show', ['activity' => $created]);
    $fields = collect($creation->instance()->entry['changes'])->keyBy('field');
    expect($fields['Campus']['after'])->toBe($campus->name.' · Campus #'.$campus->id.' (identificação atual)')
        ->and($fields['Coordenador titular']['after'])->toBe('Coordenadora anterior · Vínculo #'.$former->id.' (identificação atual)')
        ->and($fields['Coordenador substituto']['after'])->toBe('Sem valor');

    $change = Livewire::test('pages::audit.show', ['activity' => $updated]);
    $fields = collect($change->instance()->entry['changes'])->keyBy('field');
    expect($fields['Coordenador titular']['before'])->toContain('Coordenadora anterior')
        ->and($fields['Coordenador titular']['after'])->toContain('Coordenador atual')
        ->and($fields['Coordenador substituto']['before'])->toBe('Sem valor')
        ->and($fields['Coordenador substituto']['after'])->toContain('Coordenadora anterior');
    $this->get(route('audit.show', $updated))->assertOk()->assertSee('Coordenadora anterior')->assertSee('Coordenador atual')
        ->assertDontSee('ID do coordenador titular');
});

test('affiliation audit identifies the related account campus and course using names', function () {
    $office = Affiliation::factory()->create();
    $course = Course::factory()->for($office->campus)->create(['name' => 'Técnico em Informática']);
    $student = Affiliation::factory()->student()->for($office->campus)
        ->for(User::factory()->state(['name' => 'Discente identificada']))->create(['course_id' => $course->id]);
    $entry = Activity::forSubject($student)->where('event', 'created')->sole();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $component = Livewire::test('pages::audit.show', ['activity' => $entry]);
    $fields = collect($component->instance()->entry['changes'])->keyBy('field');

    expect($fields['Usuário']['after'])->toContain('Discente identificada', 'Conta #'.$student->user_id)
        ->and($fields['Campus']['after'])->toContain($office->campus->name)
        ->and($fields['Curso']['after'])->toContain('Técnico em Informática', 'Curso #'.$course->id);
});

test('campus audit describes the address instead of displaying only its identifier', function () {
    $office = Affiliation::factory()->create();
    $campus = $office->campus;
    $address = $campus->address;
    $entry = Activity::forSubject($campus)->where('event', 'created')->sole();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $component = Livewire::test('pages::audit.show', ['activity' => $entry]);
    $field = collect($component->instance()->entry['changes'])->firstWhere('field', 'Endereço');

    expect($field['after'])->toContain($address->street, $address->number, $address->city->name, 'Endereço #'.$address->id);
});

test('missing references preserve their identifier and renamed references are explicitly current names', function () {
    $office = Affiliation::factory()->create();
    $coordinator = Affiliation::factory()->for($office->campus)->create(['type' => AffiliationType::Coordinator]);
    $course = Course::factory()->for($office->campus)->create(['primary_coordinator_affiliation_id' => $coordinator->id]);
    $entry = Activity::forSubject($course)->where('event', 'created')->sole();
    $course->update(['primary_coordinator_affiliation_id' => null]);
    $coordinator->delete();
    $office->campus->update(['name' => 'Campus renomeado']);
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $component = Livewire::test('pages::audit.show', ['activity' => $entry]);
    $fields = collect($component->instance()->entry['changes'])->keyBy('field');

    expect($fields['Coordenador titular']['after'])->toBe('Vínculo #'.$coordinator->id.' (identificação indisponível)')
        ->and($fields['Campus']['after'])->toBe('Campus renomeado · Campus #'.$office->campus_id.' (identificação atual)');
});

test('reference descriptions do not reveal identities or addresses from another campus', function () {
    $office = Affiliation::factory()->create();
    $foreign = Affiliation::factory()->for(User::factory()->state(['name' => 'Identidade externa protegida']))
        ->create(['type' => AffiliationType::Coordinator]);
    $global = Affiliation::factory()->global()->for($office->campus)->create();
    $course = Course::factory()->for($office->campus)->create();
    $entry = Activity::create(['subject_type' => $course->getMorphClass(), 'subject_id' => $course->id,
        'event' => 'updated', 'description' => 'Referências não autorizadas', 'attribute_changes' => ['attributes' => [
            'primary_coordinator_affiliation_id' => $foreign->id, 'secondary_coordinator_affiliation_id' => $global->id,
        ]]]);
    $campusEntry = Activity::create(['subject_type' => (new Campus)->getMorphClass(), 'subject_id' => $office->campus_id,
        'event' => 'updated', 'description' => 'Endereço externo', 'attribute_changes' => ['attributes' => ['address_id' => $foreign->campus->address_id]]]);
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);

    $this->get(route('audit.show', $entry))->assertOk()->assertSee('identificação indisponível')
        ->assertDontSee('Identidade externa protegida')->assertDontSee($global->user->name);
    $this->get(route('audit.show', $campusEntry))->assertOk()->assertDontSee($foreign->campus->address->street);
});

test('reference names are escaped and invalid identifiers are not rendered as content', function () {
    $office = Affiliation::factory()->create();
    $coordinator = Affiliation::factory()->for($office->campus)
        ->for(User::factory()->state(['name' => '<script>unsafe-reference</script>']))->create(['type' => AffiliationType::Coordinator]);
    $course = Course::factory()->for($office->campus)->create(['primary_coordinator_affiliation_id' => $coordinator->id]);
    $entry = Activity::forSubject($course)->where('event', 'created')->sole();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $this->get(route('audit.show', $entry))->assertOk()->assertSee('&lt;script&gt;unsafe-reference&lt;/script&gt;', false)
        ->assertDontSee('<script>unsafe-reference', false);

    $invalid = Activity::create(['subject_type' => $course->getMorphClass(), 'subject_id' => $course->id,
        'event' => 'updated', 'description' => 'Referência inválida',
        'attribute_changes' => ['attributes' => ['primary_coordinator_affiliation_id' => 'not-an-id']]]);
    $this->get(route('audit.show', $invalid))->assertOk()->assertSee('Valor não exibido')->assertDontSee('not-an-id');
});

test('system administrator also sees reference descriptions including soft deleted campus names', function () {
    $administrator = Affiliation::factory()->global()->create();
    $office = Affiliation::factory()->create();
    $entry = Activity::forSubject($office)->where('event', 'created')->sole();
    $office->campus->delete();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::audit.show', ['activity' => $entry]);
    $fields = collect($component->instance()->entry['changes'])->keyBy('field');

    expect($fields['Campus']['after'])->toContain($office->campus->name)
        ->and($fields['Usuário']['after'])->toContain($office->user->name);
});

test('both coordinators and both changed values are resolved in a single affiliation query', function () {
    $office = Affiliation::factory()->create();
    $first = Affiliation::factory()->for($office->campus)->create(['type' => AffiliationType::Coordinator]);
    $second = Affiliation::factory()->for($office->campus)->create(['type' => AffiliationType::Coordinator]);
    $course = Course::factory()->for($office->campus)->create([
        'primary_coordinator_affiliation_id' => $first->id, 'secondary_coordinator_affiliation_id' => $second->id,
    ]);
    $course->update(['primary_coordinator_affiliation_id' => $second->id, 'secondary_coordinator_affiliation_id' => $first->id]);
    $entry = Activity::forSubject($course)->where('event', 'updated')->sole();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'from "affiliations" where "affiliations"."id" in')) {
            $queries[] = $query->sql;
        }
    });
    Livewire::test('pages::audit.show', ['activity' => $entry])->assertOk();

    expect($queries)->toHaveCount(1);
});
