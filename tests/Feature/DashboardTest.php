<?php

use App\Actions\GetAdministrativeDashboardMetrics;
use App\Enums\AffiliationType;
use App\Enums\InternshipStatus;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\GeneratedDocument;
use App\Models\Internship;
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

test('other profiles can use the dashboard without seeing global administrative metrics', function () {
    $user = User::factory()->create();
    Affiliation::factory()->for($user)->create();
    $this->mock(GetAdministrativeDashboardMetrics::class)->shouldNotReceive('__invoke');
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Painel')
        ->assertDontSee('Painel administrativo')
        ->assertDontSee('Usuários cadastrados')
        ->assertDontSee('Documentos por situação');
});

test('system administrators see global totals and progress charts for system records', function () {
    $admin = User::factory()->create();
    $systemAffiliation = Affiliation::factory()->global()->for($admin)->create();
    Affiliation::factory()->for($admin)->create();
    Affiliation::factory()->supervisor()->deactivated()->create();
    Campus::factory()->deactivated()->create();
    $internship = Internship::factory()->create(['status' => InternshipStatus::InProgress]);
    Internship::factory()->create([
        'course_id' => $internship->course_id,
        'internship_type_id' => $internship->internship_type_id,
        'status' => InternshipStatus::PendingCorrection,
    ]);
    GeneratedDocument::factory()->for($internship)->create();

    $this->actingAs($admin)->withSession(['active_affiliation_id' => $systemAffiliation->id]);

    $response = $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Painel administrativo')
        ->assertSee('Usuários cadastrados')
        ->assertSee('Vínculos por tipo')
        ->assertSee('Administrador do Sistema')
        ->assertSee('Supervisor')
        ->assertSee('Estágios por situação')
        ->assertSee(InternshipStatus::InProgress->label())
        ->assertSee(InternshipStatus::PendingCorrection->label())
        ->assertDontSee('Estágios cadastrados')
        ->assertSee('Documentos por situação')
        ->assertSee('Solicitações em análise')
        ->assertDontSee('Acompanhar')
        ->assertSee('(1 ativo)')
        ->assertSee('data-flux-progress', false);

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $progressBars = (new DOMXPath($document))->query('//ui-progress[@data-flux-progress]');

    expect($progressBars->length)->toBeGreaterThan(0);
    foreach ($progressBars as $progressBar) {
        expect($progressBar->getAttribute('style'))->toContain('--flux-progress-color: var(--color-brand)');
    }

    $metrics = app(GetAdministrativeDashboardMetrics::class)();
    $supervisorCounts = collect($metrics['affiliationTypes'])->firstWhere('label', 'Supervisor');
    $internshipCounts = collect($metrics['internshipsByStatus']);

    expect($metrics['affiliationsCount'])->toBe(Affiliation::query()->count())
        ->and($metrics['activeAffiliationsCount'])->toBe(Affiliation::query()->active()->count())
        ->and($metrics['campusesCount'])->toBe(Campus::query()->count())
        ->and($metrics['activeCampusesCount'])->toBe(Campus::query()->whereNull('deactivated_at')->count())
        ->and($supervisorCounts['value'])->toBe(Affiliation::query()->where('type', AffiliationType::Supervisor->value)->count())
        ->and($supervisorCounts['active'])->toBe(Affiliation::query()->active()->where('type', AffiliationType::Supervisor->value)->count())
        ->and($metrics['internshipsCount'])->toBe(2)
        ->and($internshipCounts->firstWhere('label', InternshipStatus::InProgress->label())['value'])->toBe(1)
        ->and($internshipCounts->firstWhere('label', InternshipStatus::PendingCorrection->label())['value'])->toBe(1)
        ->and($internshipCounts->firstWhere('label', InternshipStatus::Completed->label())['value'])->toBe(0)
        ->and($metrics['documentsCount'])->toBe(1);
});

test('all enum categories render zero progress charts without configured courses or internships', function () {
    $admin = User::factory()->create();
    $systemAffiliation = Affiliation::factory()->global()->for($admin)->create();

    $this->actingAs($admin)->withSession(['active_affiliation_id' => $systemAffiliation->id]);

    $response = $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Estágios por situação')
        ->assertDontSee('Estágios cadastrados')
        ->assertSee('1 vínculo ativo')
        ->assertSee('(1 ativo)')
        ->assertSee('text-brand', false);

    foreach (InternshipStatus::cases() as $status) {
        $response->assertSee($status->label());
    }

    $metrics = app(GetAdministrativeDashboardMetrics::class)();

    expect($metrics['internshipsByStatus'])
        ->toHaveCount(count(InternshipStatus::cases()))
        ->and($metrics['maxInternshipStatusCount'])->toBe(1)
        ->and($metrics['internshipsCount'])->toBe(0)
        ->and(collect($metrics['internshipsByStatus'])->every(fn (array $status): bool => $status['value'] === 0))
        ->toBeTrue()
        ->and(collect($metrics['documentsByStatus'])->every(fn (array $status): bool => $status['value'] === 0))
        ->toBeTrue();

    foreach ($metrics['internshipsByStatus'] as $status) {
        preg_match('/<ui-progress[^>]*aria-label="'.preg_quote($status['label'], '/').'"[^>]*>/', $response->getContent(), $progress);

        expect($progress[0] ?? '')->toContain('value="0"', 'max="1"');
    }
});

test('a system administrator only sees global metrics while the system affiliation is selected', function () {
    $user = User::factory()->create();
    $systemAffiliation = Affiliation::factory()->global()->for($user)->create();
    $officeAffiliation = Affiliation::factory()->for($user)->create();

    $this->actingAs($user)->withSession(['active_affiliation_id' => $systemAffiliation->id]);
    $this->get(route('dashboard'))->assertOk();

    $this->withSession(['active_affiliation_id' => $officeAffiliation->id]);
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Painel')
        ->assertDontSee('Painel administrativo')
        ->assertDontSee('Vínculos por tipo');
});
