@if ($editable)
    <flux:text>Confira as respostas antes de enviar ao Setor de Estágio. Use os botões de edição para voltar à etapa correspondente.</flux:text>
@elseif (! ($officeView ?? false))
    <flux:text>Estas são as respostas registradas nesta avaliação.</flux:text>
@endif
<div class="space-y-6">
    <section @class(['space-y-4', 'rounded-xl border border-zinc-200 p-5 dark:border-zinc-700' => ! ($officeView ?? false)])>
        <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading level="3">Carga horária</flux:heading>@if ($editable)<flux:button type="button" size="sm" variant="ghost" icon="pencil-square" wire:click="goTo(0)">Editar</flux:button>@endif</div>
        <dl class="grid gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Carga horária exigida</dt><dd class="mt-1 text-sm">{{ $rules['required_hours'] ?? '—' }} h</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Carga horária cumprida</dt><dd class="mt-1 text-sm">{{ match ((string) ($values['hours_requirement_met'] ?? '')) { '1' => 'Sim', '0' => 'Não', default => 'Não informado' } }}</dd></div>
            @if ((string) ($values['hours_requirement_met'] ?? '') === '0')<div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Horas faltantes</dt><dd class="mt-1 text-sm">{{ $values['estimated_hours_remaining'] ?? 'Não informado' }} h</dd></div>@endif
        </dl>
    </section>
    @if ((string) ($values['hours_requirement_met'] ?? '') === '1')
        @if ($officeView ?? false)<flux:separator />@endif
        <section @class(['space-y-4', 'rounded-xl border border-zinc-200 p-5 dark:border-zinc-700' => ! ($officeView ?? false)])>
            <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading level="3">Formação e atuação</flux:heading>@if ($editable)<flux:button type="button" size="sm" variant="ghost" icon="pencil-square" wire:click="goTo(1)">Editar</flux:button>@endif</div>
            <dl class="grid gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Cargo ou função</dt><dd class="mt-1 text-sm">{{ $values['job_role'] ?: 'Não informado' }}</dd></div>
                <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Qualificação</dt><dd class="mt-1 text-sm">{{ match ((string) ($values['has_academic_background'] ?? '')) { '1' => 'Formação na área', '0' => 'Experiência profissional na área', default => 'Não informado' } }}</dd></div>
                @if ((string) ($values['has_academic_background'] ?? '') === '1')
                    <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Curso de formação</dt><dd class="mt-1 text-sm">{{ $values['training_course'] ?: 'Não informado' }}</dd></div>
                    <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Nível de formação</dt><dd class="mt-1 text-sm">{{ \App\Enums\EducationLevel::tryFrom((string) ($values['education_level'] ?? ''))?->label() ?? 'Não informado' }}</dd></div>
                @elseif ((string) ($values['has_academic_background'] ?? '') === '0')
                    <div class="sm:col-span-2"><dt class="text-sm text-zinc-500 dark:text-zinc-400">Área e tempo de experiência</dt><dd class="mt-1 whitespace-pre-line text-sm">{{ $values['experience_time'] ?: 'Não informado' }}</dd></div>
                @endif
            </dl>
        </section>
        @if ($officeView ?? false)<flux:separator />@endif
        <section @class(['space-y-4', 'rounded-xl border border-zinc-200 p-5 dark:border-zinc-700' => ! ($officeView ?? false)])>
            <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading level="3">Critérios de avaliação</flux:heading>@if ($editable)<flux:button type="button" size="sm" variant="ghost" icon="pencil-square" wire:click="goTo(2)">Editar</flux:button>@endif</div>
            <dl class="grid gap-5 sm:grid-cols-2">
                @foreach (\App\Models\SupervisorEvaluation::criteria() as $criterion => $label)
                    <div wire:key="review-criterion-{{ $criterion }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 text-sm">{{ $this->conceptOptions[$values[$criterion] ?? ''] ?? 'Não informado' }}</dd></div>
                @endforeach
            </dl>
        </section>
        @if ($officeView ?? false)<flux:separator />@endif
        <section @class(['space-y-4', 'rounded-xl border border-zinc-200 p-5 dark:border-zinc-700' => ! ($officeView ?? false)])>
            <div class="flex flex-wrap items-center justify-between gap-3"><flux:heading level="3">Comentários</flux:heading>@if ($editable)<flux:button type="button" size="sm" variant="ghost" icon="pencil-square" wire:click="goTo(3)">Editar</flux:button>@endif</div>
            <dl class="space-y-4">
                @foreach (\App\Models\SupervisorEvaluation::comments() as $field => $label)
                    <div wire:key="review-comment-{{ $field }}"><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 whitespace-pre-line text-sm">{{ filled($values[$field] ?? null) ? $values[$field] : 'Não informado' }}</dd></div>
                @endforeach
            </dl>
        </section>
    @elseif ((string) ($values['hours_requirement_met'] ?? '') === '0')
        <flux:callout variant="warning" heading="Envio com carga horária pendente"><flux:callout.text>A avaliação informará somente as horas faltantes. Formação, critérios e comentários não fazem parte desta resposta.</flux:callout.text></flux:callout>
    @endif
</div>
@if ($editable)<flux:text size="sm">Após o envio, as respostas ficam bloqueadas até eventual devolução pelo Setor. A aprovação de uma avaliação com carga horária cumprida conclui o estágio.</flux:text>@endif
