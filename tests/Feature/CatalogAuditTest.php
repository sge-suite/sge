<?php

use App\Models\Address;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\GrantingParty;
use App\Models\InternshipType;
use App\Models\UserPersonalData;
use App\Support\ActivityAccess;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;

test('office audits granting parties and internship types only within its selected campus including deleted records', function () {
    $office = activity()->withoutLogging(fn () => Affiliation::factory()->create());
    $foreign = activity()->withoutLogging(fn () => Affiliation::factory()->for($office->user)->create());
    $localParty = GrantingParty::factory()->for($office->campus)->create(['name' => 'Concedente local']);
    $foreignParty = GrantingParty::factory()->for($foreign->campus)->create(['name' => 'Concedente externa']);
    $localType = InternshipType::factory()->for(Course::factory()->for($office->campus))->create(['name' => 'Tipo local']);
    $foreignType = InternshipType::factory()->for(Course::factory()->for($foreign->campus))->create(['name' => 'Tipo externo']);
    foreach ([$localParty, $foreignParty, $localType, $foreignType] as $record) {
        $record->update(['name' => $record->name.' alterado']);
        $record->delete();
    }
    $localType->course()->delete();
    $foreignType->course()->delete();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $component = Livewire::test('pages::audit.index');
    foreach (['granting_party' => $localParty, 'internship_type' => $localType] as $filter => $record) {
        $expected = Activity::forSubject($record)->pluck('id')->all();
        $component->set('entity', $filter);
        expect($component->instance()->activities->pluck('id')->all())->toEqualCanonicalizing($expected);
        foreach ($expected as $id) {
            $this->get(route('audit.show', $id))->assertOk();
            $entry = Livewire::test('pages::audit.show', ['activity' => Activity::findOrFail($id)])->instance()->entry;
            expect($entry['changes'])->not->toBeEmpty();
        }
    }
    foreach ([$foreignParty, $foreignType] as $record) {
        foreach (Activity::forSubject($record)->get() as $entry) {
            $this->get(route('audit.show', $entry))->assertForbidden();
            Livewire::test('pages::audit.show', ['activity' => $entry])->assertForbidden();
        }
    }
    session()->put('active_affiliation_id', $foreign->id);
    $component->set('entity', 'granting_party');
    expect($component->instance()->activities->pluck('id')->all())->toEqualCanonicalizing(Activity::forSubject($foreignParty)->pluck('id')->all());
});

test('address audit identifies its owner and before after fields preserving recorded identity after rename and deletion', function (string $ownerType) {
    $office = activity()->withoutLogging(fn () => Affiliation::factory()->create());
    $owner = $ownerType === 'campus' ? $office->campus : GrantingParty::factory()->for($office->campus)->create();
    $address = $owner->address;
    $recordedName = $owner->name;
    $oldStreet = $address->street;
    app(CauserResolver::class)->withCauser($office, fn () => $address->update(['street' => 'Rua alterada']));
    $updated = Activity::forSubject($address)->where('event', 'updated')->sole();
    $owner->update(['name' => 'Nome atual diferente']);
    if ($ownerType !== 'campus') {
        $owner->delete();
    }
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $this->get(route('audit.show', $updated))->assertOk();
    $entry = Livewire::test('pages::audit.show', ['activity' => $updated])->instance()->entry;
    expect($entry['subject'])->toContain($recordedName, '#'.$owner->id)
        ->not->toContain('Nome atual diferente')
        ->and($entry['actor'])->toContain('Vínculo #'.$office->id)
        ->and(collect($entry['changes'])->firstWhere('field', 'Logradouro'))
        ->toBe(['field' => 'Logradouro', 'before' => $oldStreet, 'after' => 'Rua alterada']);
    $component = Livewire::test('pages::audit.index')->set('entity', 'address')->set('event', 'updated');
    expect($component->instance()->activities->pluck('id')->all())->toContain($updated->id);
})->with(['campus', 'granting_party']);

test('created and detached address logs use immutable catalog association evidence', function () {
    $office = activity()->withoutLogging(fn () => Affiliation::factory()->create());
    $party = GrantingParty::factory()->for($office->campus)->create(['name' => 'Unidade identificada']);
    $original = $party->address;
    $creation = Activity::forSubject($original)->where('event', 'created')->sole();
    $replacement = Address::factory()->create();
    $party->update(['address_id' => $replacement->id]);
    $party->delete();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $entry = Livewire::test('pages::audit.show', ['activity' => $creation])->assertOk()->instance()->entry;
    expect($entry['subject'])->toContain('Unidade identificada', 'Parte concedente #'.$party->id);
});

test('address audit isolates campuses and does not expose personal or shared foreign addresses', function () {
    $office = activity()->withoutLogging(fn () => Affiliation::factory()->create());
    $foreign = GrantingParty::factory()->create();
    $foreign->address->update(['street' => 'Endereço externo protegido']);
    $personal = UserPersonalData::factory()->create();
    $personal->address->update(['street' => 'Endereço residencial protegido']);
    $shared = GrantingParty::factory()->for($office->campus)->create();
    GrantingParty::factory()->for($shared->address)->create();
    $shared->address->update(['street' => 'Endereço de campi diferentes']);
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    foreach ([$foreign->address, $personal->address, $shared->address] as $address) {
        foreach (Activity::forSubject($address)->get() as $entry) {
            expect(Gate::allows('view', $entry))->toBeFalse();
            $this->get(route('audit.show', $entry))->assertForbidden();
        }
    }
});

test('system administrator audits campus addresses but cannot access office catalogs through that profile', function () {
    $admin = Affiliation::factory()->global()->create();
    $campusAddress = Campus::factory()->create()->address;
    $campusAddress->update(['street' => 'Endereço institucional']);
    $party = GrantingParty::factory()->create();
    $party->address->update(['street' => 'Endereço de concedente']);
    $type = InternshipType::factory()->create();
    $this->actingAs($admin->user)->withSession(['active_affiliation_id' => $admin->id]);
    $updated = Activity::forSubject($campusAddress)->where('event', 'updated')->sole();
    $this->get(route('audit.show', $updated))->assertOk();
    foreach ([$party, $party->address, $type] as $subject) {
        foreach (Activity::forSubject($subject)->get() as $entry) {
            $this->get(route('audit.show', $entry))->assertForbidden();
        }
    }
    expect(app(ActivityAccess::class)->entityOptionsForCurrentContext($admin->user, app('session.store')))->toHaveKey('address');
});

test('legacy type logs recover scope from course history after deletion', function () {
    $office = activity()->withoutLogging(fn () => Affiliation::factory()->create());
    $course = Course::factory()->for($office->campus)->create();
    $type = InternshipType::factory()->for($course)->create();
    $entry = Activity::create(['subject_type' => $type->getMorphClass(), 'subject_id' => $type->id,
        'event' => 'updated', 'description' => 'Evento legado', 'attribute_changes' => ['attributes' => ['name' => 'Nome legado']]]);
    $type->delete();
    $course->delete();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $this->get(route('audit.show', $entry))->assertOk();
});

test('unowned addresses stay outside catalog audit even when local granting parties have recorded activities', function () {
    $office = Affiliation::factory()->create();
    GrantingParty::factory()->for($office->campus)->create();
    $address = Address::factory()->create();
    $entry = Activity::forSubject($address)->sole();
    $this->actingAs($office->user)->withSession(['active_affiliation_id' => $office->id]);
    $this->get(route('audit.show', $entry))->assertForbidden();
});
