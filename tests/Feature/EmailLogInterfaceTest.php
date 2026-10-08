<?php

use App\Actions\RequestEmailDelivery;
use App\Enums\AffiliationType;
use App\Enums\EmailDeliveryAttemptStatus;
use App\Models\Affiliation;
use App\Models\EmailDeliveryAttempt;
use App\Models\EmailMessage;
use App\Support\EmailDeliveryContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Support\CauserResolver;

beforeEach(function () {
    Queue::fake();
});

function emailLogAdministrator(): Affiliation
{
    return Affiliation::factory()->global()->create();
}

function administrativeEmailAttempt(Affiliation $affiliation): EmailDeliveryAttempt
{
    return app(RequestEmailDelivery::class)->accountCreated($affiliation->user->email, (string) Str::uuid(), $affiliation);
}

test('email log routes require authentication', function () {
    $attempt = administrativeEmailAttempt(emailLogAdministrator());
    $this->get(route('email-logs.index'))->assertRedirect(route('login'));
    $this->get(route('email-logs.show', $attempt))->assertRedirect(route('login'));
});

test('system administrator sees only administrative email contexts and all supported purposes', function () {
    $administrator = emailLogAdministrator();
    $target = emailLogAdministrator();
    $campusAdministrator = Affiliation::factory()->create(['type' => AffiliationType::CampusAdministrator]);
    $operational = Affiliation::factory()->create();
    $request = app(RequestEmailDelivery::class);
    $expected = [
        administrativeEmailAttempt($target),
        $request->affiliationCreated($campusAdministrator->email, $campusAdministrator, (string) Str::uuid()),
        $request->affiliationCreated('external-contact@example.test', $target, (string) Str::uuid()),
        $request->accountEmailChanged('old@example.test', 'old@example.test', 'new@example.test', (string) Str::uuid(), $target->user),
        $request->administrativeChange($target->user->email, 'Aviso administrativo', 'Aviso registrado', (string) Str::uuid(), $target),
    ];
    $outside = [
        administrativeEmailAttempt($operational),
        app(CauserResolver::class)->withCauser($administrator, fn (): EmailDeliveryAttempt => $request->affiliationCreated($target->user->email, $operational, (string) Str::uuid())),
        $request->accountCreated($target->user->email, (string) Str::uuid()),
        EmailDeliveryAttempt::factory()->create(['scope_context' => app(EmailDeliveryContext::class)->forAffiliation($target)]),
    ];
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::email-logs.index')
        ->assertSee('external-contact@example.test')->assertSee('data-email-log-link', false)
        ->assertSee('font-medium text-zinc-900 underline-offset-4 group-hover:underline dark:text-white', false)
        ->assertSee('dark:focus-within:bg-zinc-700', false)
        ->assertSee('aria-haspopup="listbox"', false)->assertDontSee('Limpar filtros');
    expect($component->instance()->deliveries->pluck('id')->all())->toEqualCanonicalizing(array_map(fn (EmailDeliveryAttempt $attempt): int => $attempt->id, $expected));
    foreach ($outside as $attempt) {
        $this->get(route('email-logs.show', $attempt))->assertForbidden();
        Livewire::test('pages::email-logs.show', ['attempt' => $attempt])->assertForbidden();
    }
    $this->get(route('dashboard'))->assertOk()->assertSee(route('email-logs.index'), false);
});

test('email content renders inside a sandboxed preview without exposing delivery identifiers', function () {
    $administrator = emailLogAdministrator();
    $target = emailLogAdministrator();
    $html = '<html><body><h1>Conteúdo preservado</h1><script>window.parent.alert("unsafe")</script><img src="https://tracker.example.test/image"></body></html>';
    $message = EmailMessage::factory()->newAffiliation()->create(['subject' => 'Assunto preservado', 'content_html' => $html]);
    $attempt = EmailDeliveryAttempt::factory()->newAffiliation($target->user)->create([
        'email_message_id' => $message->id,
        'scope_context' => app(EmailDeliveryContext::class)->forAffiliation($target),
    ]);
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $this->get(route('email-logs.index'))->assertOk()->assertSee('Assunto preservado')->assertDontSee('Conteúdo preservado');
    $response = $this->get(route('email-logs.show', $attempt))->assertOk()->assertSee('Assunto preservado')
        ->assertSee('Conteúdo preservado')->assertDontSee($attempt->delivery_key)->assertDontSee($message->idempotency_key);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $frame = $xpath->query('//iframe')->item(0);
    expect($frame->getAttribute('sandbox'))->toBe('allow-same-origin')
        ->and($frame->hasAttribute('sandbox'))->toBeTrue()
        ->and($frame->getAttribute('referrerpolicy'))->toBe('no-referrer')
        ->and($frame->getAttribute('srcdoc'))->toContain('Content-Security-Policy', 'default-src', 'img-src data:', $html)
        ->and($frame->getAttribute('srcdoc'))->toContain('default-src &apos;none&apos;')
        ->and($frame->getAttribute('x-data'))->toContain('ResizeObserver', 'contentDocument')
        ->and($frame->getAttribute('class'))->not->toContain('h-[40rem]')
        ->and($frame->parentNode->getAttribute('class'))->toContain('scroll-fade-y', 'max-h-[70vh]', 'overflow-y-auto', 'overscroll-contain')
        ->and($frame->parentNode->getAttribute('tabindex'))->toBe('0')
        ->and($xpath->query('//*[@data-email-preview-layout]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-email-preview-layout]')->item(0)->getAttribute('class'))->toContain('xl:grid-cols-2')
        ->and($xpath->query('//*[@data-email-preview-layout]')->item(0)->parentNode->getAttribute('class'))->not->toContain('max-w-')
        ->and($xpath->query('//*[@data-email-preview-layout]/div[1]/section[1][@aria-labelledby="email-details-heading"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-email-preview-layout]/div[1]/section[2][@aria-labelledby="email-attempts-heading"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-email-preview-layout]/section[@aria-labelledby="email-content-heading"]')->item(0)->getAttribute('class'))->not->toContain('max-w-')
        ->and($xpath->query('//*[@data-email-details-card]//*[@id="email-details-heading"]')->length)->toBe(0)
        ->and($xpath->query('//script[contains(text(), "unsafe")]')->length)->toBe(0);
});

test('email logs show escaped text when only text was stored and explain missing content', function () {
    $administrator = emailLogAdministrator();
    $message = EmailMessage::factory()->newAffiliation()->create(['content_html' => null, 'content_text' => '<script>text-only</script>']);
    $attempt = EmailDeliveryAttempt::factory()->newAffiliation($administrator->user)->create([
        'email_message_id' => $message->id,
        'scope_context' => app(EmailDeliveryContext::class)->forAffiliation($administrator),
    ]);
    $withoutContent = EmailDeliveryAttempt::factory()->accountCreated($administrator->user)->create([
        'scope_context' => app(EmailDeliveryContext::class)->forAffiliation($administrator),
    ]);
    $withContent = administrativeEmailAttempt($administrator);
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $this->get(route('email-logs.show', $attempt))->assertOk()->assertSee('&lt;script&gt;text-only&lt;/script&gt;', false)
        ->assertSee('scroll-fade-y max-h-[70vh] overflow-y-auto overscroll-contain', false)->assertDontSee('<iframe', false);
    $this->get(route('email-logs.show', $withoutContent))->assertOk()->assertSee('O conteúdo deste envio não foi armazenado.');
    $this->get(route('email-logs.show', $withContent))->assertOk()->assertSee('Sua conta foi criada')
        ->assertSee('<iframe', false)->assertDontSee('O conteúdo deste envio não foi armazenado.');
});

test('non-system administrator affiliations cannot read email logs even on an administrator account', function (AffiliationType $type) {
    $administrator = emailLogAdministrator();
    $attempt = administrativeEmailAttempt($administrator);
    $factory = Affiliation::factory()->for($administrator->user)->state(['type' => $type]);
    if ($type === AffiliationType::Student) {
        $factory = $factory->student();
    } elseif ($type === AffiliationType::Supervisor) {
        $factory = $factory->supervisor();
    }
    $selected = $factory->create();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $selected->id]);
    $this->get(route('email-logs.index'))->assertForbidden();
    $this->get(route('email-logs.show', $attempt))->assertForbidden();
    Livewire::test('pages::email-logs.index')->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('email-logs.index'), false);
})->with(array_filter(AffiliationType::cases(), fn (AffiliationType $type): bool => $type !== AffiliationType::SystemAdministrator));

test('email logs reject inactive foreign and choice-required contexts', function (string $context) {
    $administrator = emailLogAdministrator();
    $attempt = administrativeEmailAttempt($administrator);
    $session = ['active_affiliation_id' => $administrator->id];
    if ($context === 'inactive') {
        $administrator->update(['deactivated_at' => now()]);
    } elseif ($context === 'foreign') {
        $session['active_affiliation_id'] = emailLogAdministrator()->id;
    } else {
        $session['active_affiliation_needs_choice'] = true;
    }
    $this->actingAs($administrator->user)->withSession($session);
    Livewire::test('pages::email-logs.index')->assertForbidden();
    Livewire::test('pages::email-logs.show', ['attempt' => $attempt])->assertForbidden();
})->with(['inactive', 'foreign', 'choice']);

test('email log components reauthorize refresh and filter requests', function (string $page) {
    $administrator = emailLogAdministrator();
    $attempt = administrativeEmailAttempt($administrator);
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::email-logs.'.$page, $page === 'show' ? ['attempt' => $attempt] : []);
    $administrator->update(['deactivated_at' => now()]);
    if ($page === 'index') {
        $component->set('status', 'failed')->assertForbidden();
    } else {
        $component->call('$refresh')->assertForbidden();
    }
})->with(['index', 'show']);

test('retry history preserves scope snapshots and lists only the latest attempt per delivery', function () {
    $administrator = emailLogAdministrator();
    $target = emailLogAdministrator();
    $first = administrativeEmailAttempt($target);
    $first->update(['status' => EmailDeliveryAttemptStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'transport_failed']);
    $retry = app(RequestEmailDelivery::class)->retry($first);
    expect($retry->scope_context)->toEqual($first->scope_context);
    $target->delete();
    $target->user->delete();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $component = Livewire::test('pages::email-logs.index');
    expect($component->instance()->deliveries->pluck('id')->all())->toBe([$retry->id]);
    $component->set('status', 'failed')->assertSee('Limpar filtros');
    expect($component->instance()->deliveries->total())->toBe(0);
    $component->call('clearFilters');
    expect($component->instance()->deliveries->total())->toBe(1);
    $details = Livewire::test('pages::email-logs.show', ['attempt' => $retry]);
    expect($details->instance()->attempts->pluck('id')->all())->toBe([$first->id, $retry->id]);
});

test('scope metadata is immutable and cannot be reused for another delivery context', function () {
    $administrator = emailLogAdministrator();
    $operational = Affiliation::factory()->create();
    $attempt = administrativeEmailAttempt($administrator);
    expect(fn (): bool => $attempt->update(['scope_context' => app(EmailDeliveryContext::class)->forAffiliation($operational)]))->toThrow(ValidationException::class);
    expect(fn (): EmailDeliveryAttempt => app(RequestEmailDelivery::class)->accountCreated($administrator->user->email, $attempt->delivery_key, $operational))->toThrow(ValidationException::class);
});

test('email log identity is locked', function () {
    $administrator = emailLogAdministrator();
    $attempt = administrativeEmailAttempt($administrator);
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    expect(fn () => Livewire::test('pages::email-logs.show', ['attempt' => $attempt])->set('attemptId', $attempt->id + 1))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('missing email log records are not found', function () {
    $administrator = emailLogAdministrator();
    $this->actingAs($administrator->user)->withSession(['active_affiliation_id' => $administrator->id]);
    $this->get(route('email-logs.show', PHP_INT_MAX))->assertNotFound();
});
