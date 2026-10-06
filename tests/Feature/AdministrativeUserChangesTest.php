<?php

use App\Actions\RequestEmailDelivery;
use App\Enums\AffiliationType;
use App\Enums\EmailMessagePurpose;
use App\Mail\DeliveryMail;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\EmailDeliveryAttempt;
use App\Models\User;
use App\Models\UserPersonalData;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;

beforeEach(function () {
    Bus::fake();
    Mail::fake();
    $this->actor = User::factory()->create();
    $this->selected = Affiliation::factory()->global()->for($this->actor)->create();
    $this->actingAs($this->actor)->withSession(['active_affiliation_id' => $this->selected->id]);
    $this->target = User::factory()->create(['cpf' => '52998224725', 'email' => 'previous@example.test']);
    $this->affiliation = Affiliation::factory()->global()->for($this->target)->create(['email' => 'affiliation@example.test']);
});

test('administrator edits name cpf and login while preserving passwords and all affiliations', function () {
    $original = $this->affiliation->fresh()->getAttributes();
    $password = $this->target->password;
    $this->get(route('users.edit', $this->target))->assertOk()->assertSee('Editar dados de acesso');
    $this->put(route('users.update', $this->target), ['name' => ' Nome corrigido ', 'cpf' => '111.444.777-35', 'email' => ' NEW@EXAMPLE.TEST '])->assertSessionHasNoErrors();
    expect($this->target->fresh()->name)->toBe('Nome corrigido')->and($this->target->fresh()->cpf)->toBe('11144477735')
        ->and($this->target->fresh()->email)->toBe('new@example.test')->and($this->target->fresh()->password)->toBe($password)
        ->and($this->affiliation->fresh()->getAttributes())->toBe($original);
    expect(EmailDeliveryAttempt::pluck('recipient_email')->sort()->values()->all())->toBe(['new@example.test', 'previous@example.test'])
        ->and(EmailDeliveryAttempt::pluck('purpose')->unique()->sole())->toBe(EmailMessagePurpose::AccountEmailChanged);
    $audit = Activity::forSubject($this->target)->where('event', 'updated')->sole();
    expect($audit->causer_id)->toBe($this->selected->id)->and($audit->attribute_changes['attributes']['cpf'])->toBe('11144477735')
        ->and($audit->attribute_changes['attributes'])->not->toHaveKey('password');
    Mail::assertNothingOutgoing();
});

test('unchanged login or name only corrections do not send email', function () {
    $this->put(route('users.update', $this->target), ['name' => 'Nome corrigido', 'cpf' => $this->target->cpf, 'email' => $this->target->email])->assertSessionHasNoErrors();
    expect(EmailDeliveryAttempt::count())->toBe(0);
});

test('account editing rejects duplicate cpf email invalid cpf and forged account fields', function (string $field) {
    $other = User::factory()->create(['cpf' => '11144477735', 'email' => 'occupied@example.test']);
    $data = ['name' => 'Novo nome', 'cpf' => $this->target->cpf, 'email' => $this->target->email];
    $data[$field] = match ($field) {
        'cpf' => $other->cpf, 'email' => 'OCCUPIED@EXAMPLE.TEST', default => 'forged'
    };
    $this->put(route('users.update', $this->target), $data)->assertSessionHasErrors($field);
    expect($this->target->fresh()->email)->toBe('previous@example.test');
})->with(['cpf', 'email', 'password', 'type', 'campus_id', 'user_id']);

test('invalid cpf correction preserves form values after validation', function () {
    $this->from(route('users.edit', $this->target))->put(route('users.update', $this->target), ['name' => 'Nome corrigido', 'cpf' => '00000000000', 'email' => 'new@example.test'])->assertSessionHasErrors('cpf');
    $this->get(route('users.edit', $this->target))->assertOk()->assertSee('Nome corrigido')->assertSee('new@example.test');
});

test('copied registration is scoped to the account and remains editable with only custom selects', function () {
    $foreign = Affiliation::factory()->global()->create(['registration_number' => 'OTHER']);
    $component = Livewire::test('users.form-fields', ['user' => $this->target]);
    $component->assertSee('select-trigger', false)->assertSee('Copiar matrícula')
        ->set('registrationSourceId', (string) $this->affiliation->id)->assertSet('registrationNumber', $this->affiliation->registration_number)
        ->set('registrationNumber', 'MANUAL')->assertSet('registrationNumber', 'MANUAL')
        ->set('registrationSourceId', (string) $foreign->id)->assertHasErrors('registrationSourceId')->assertSet('registrationNumber', 'MANUAL');
});

test('index rows and sidebar follow campus navigation patterns and account editing is separate', function () {
    $this->get(route('users.index'))->assertOk()->assertSee('data-user-link', false)->assertSee("closest('a, button')", false)->assertSee('group cursor-pointer', false);
    $this->get(route('users.show', $this->target))->assertOk()->assertSee('Dados de acesso')->assertSee('Editar dados de acesso')->assertSee('Editar vínculo')->assertSee('Excluir vínculo')->assertSee('Excluir conta')->assertSee('Desativar');
    expect(file_get_contents(resource_path('views/layouts/app/sidebar.blade.php')))->toContain('icon="users"');
    $this->get(route('users.show', $this->actor))->assertOk()->assertSee('Vínculo em uso')->assertSee('Outro Administrador do Sistema pode desativá-lo');
});

test('deactivation sends snapshot notices to deduplicated account and affiliation emails', function (bool $sameEmail) {
    if ($sameEmail) {
        $this->affiliation->update(['email' => $this->target->email]);
    }
    $this->patch(route('users.affiliations.deactivate', [$this->target, $this->affiliation]), ['confirmed' => 1, 'current_password' => 'password'])->assertSessionHasNoErrors();
    expect(EmailDeliveryAttempt::count())->toBe($sameEmail ? 1 : 2)
        ->and(EmailDeliveryAttempt::pluck('purpose')->unique()->sole())->toBe(EmailMessagePurpose::AdministrativeChange)
        ->and($this->affiliation->fresh()->deactivated_at)->not->toBeNull();
    foreach (EmailDeliveryAttempt::with('emailMessage')->get() as $attempt) {
        (new DeliveryMail($attempt))->assertSeeInHtml('Vínculo desativado')->assertSeeInText($this->affiliation->registration_number);
    }
    Mail::assertNothingOutgoing();
})->with([true, false]);

test('unused affiliation deletion retains the account audits deletion and still renders notice after removal', function () {
    $this->delete(route('users.affiliations.destroy', [$this->target, $this->affiliation]), ['confirmed' => 1, 'current_password' => 'password'])->assertSessionHasNoErrors();
    $this->assertModelMissing($this->affiliation);
    $this->assertModelExists($this->target);
    $this->get(route('users.show', $this->target))->assertOk()->assertSee('Nenhum vínculo administrativo')->assertSee('Excluir conta');
    expect(Activity::forSubject($this->affiliation)->where('event', 'deleted')->sole()->causer_id)->toBe($this->selected->id);
    foreach (EmailDeliveryAttempt::with('emailMessage')->get() as $attempt) {
        (new DeliveryMail($attempt))->assertSeeInText('Vínculo excluído');
    }
});

test('unused account deletion removes its affiliations and notifies each address once after deleting the records', function () {
    Affiliation::factory()->global()->deactivated()->for($this->target)->create(['email' => 'affiliation@example.test']);
    $this->delete(route('users.destroy', $this->target), ['confirmed' => 1, 'current_password' => 'password'])->assertSessionHasNoErrors()->assertRedirect(route('users.index'));
    $this->assertModelMissing($this->target);
    expect(Affiliation::where('user_id', $this->target->id)->exists())->toBeFalse()
        ->and(EmailDeliveryAttempt::pluck('recipient_email')->sort()->values()->all())->toBe(['affiliation@example.test', 'previous@example.test']);
    foreach (EmailDeliveryAttempt::with('emailMessage')->get() as $attempt) {
        (new DeliveryMail($attempt))->assertSeeInText('Conta excluída')->assertSeeInHtml($this->target->name);
    }
    Mail::assertNothingOutgoing();
});

test('deletion requires confirmation current password and blocks self deletion', function () {
    $this->delete(route('users.destroy', $this->target), ['confirmed' => 1, 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->delete(route('users.affiliations.destroy', [$this->target, $this->affiliation]), ['current_password' => 'password'])->assertSessionHasErrors('confirmed');
    $this->delete(route('users.destroy', $this->actor), ['confirmed' => 1, 'current_password' => 'password'])->assertForbidden();
    $this->delete(route('users.affiliations.destroy', [$this->actor, $this->selected]), ['confirmed' => 1, 'current_password' => 'password'])->assertForbidden();
});

test('associated notifications prevent deleting affiliation or account without removing history', function () {
    $this->affiliation->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'document.available', 'data' => ['title' => 'Documento']]);
    $this->delete(route('users.affiliations.destroy', [$this->target, $this->affiliation]), ['confirmed' => 1, 'current_password' => 'password'])->assertSessionHasErrors('affiliation');
    $this->delete(route('users.destroy', $this->target), ['confirmed' => 1, 'current_password' => 'password'])->assertSessionHasErrors('account');
    $this->assertModelExists($this->affiliation);
    $this->assertModelExists($this->target);
    expect(EmailDeliveryAttempt::count())->toBe(0);
});

test('personal data or nonadministrative history prevent account deletion', function (bool $personalData) {
    if ($personalData) {
        UserPersonalData::factory()->for($this->target)->create();
    } else {
        Affiliation::factory()->for($this->target)->create();
    }
    $this->delete(route('users.destroy', $this->target), ['confirmed' => 1, 'current_password' => 'password'])->assertSessionHasErrors('account');
    $this->assertModelExists($this->target);
})->with([true, false]);

test('administrative activity on another record prevents deleting the responsible affiliation', function () {
    app(CauserResolver::class)->withCauser($this->affiliation, fn () => Campus::factory()->create());
    $this->delete(route('users.affiliations.destroy', [$this->target, $this->affiliation]), ['confirmed' => 1, 'current_password' => 'password'])->assertSessionHasErrors('affiliation');
});

test('reservation failure rolls back account edit deletion or deactivation', function (string $operation) {
    $mock = $this->mock(RequestEmailDelivery::class);
    $mock->shouldReceive($operation === 'update' ? 'accountEmailChanged' : 'administrativeChange')->andThrow(new RuntimeException('reservation failed'));
    $this->withoutExceptionHandling();
    $request = fn () => match ($operation) {
        'update' => $this->put(route('users.update', $this->target), ['name' => 'Changed', 'cpf' => $this->target->cpf, 'email' => 'new@example.test']),
        'delete' => $this->delete(route('users.destroy', $this->target), ['confirmed' => 1, 'current_password' => 'password']),
        default => $this->patch(route('users.affiliations.deactivate', [$this->target, $this->affiliation]), ['confirmed' => 1, 'current_password' => 'password']),
    };
    expect($request)->toThrow(RuntimeException::class);
    $this->assertModelExists($this->target);
    $this->assertModelExists($this->affiliation);
    expect($this->target->fresh()->email)->toBe('previous@example.test')->and($this->affiliation->fresh()->deactivated_at)->toBeNull();
})->with(['update', 'delete', 'deactivate']);

test('changing selected role revokes mounted login editing and copying registration', function () {
    $component = Livewire::test('pages::users.edit', ['user' => $this->target]);
    $other = Affiliation::factory()->for($this->actor)->create(['type' => AffiliationType::CampusAdministrator]);
    session()->put('active_affiliation_id', $other->id);
    $component->call('$refresh')->assertForbidden();
    $this->delete(route('users.destroy', $this->target), ['confirmed' => 1, 'current_password' => 'password'])->assertForbidden();
});
