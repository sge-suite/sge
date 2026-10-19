<?php

use App\Actions\ManageDocumentTemplate;
use App\Actions\ManageTemplateVersion;
use App\Models\DocumentTemplate;
use App\Models\TemplateVersion;
use App\Support\ActivityAccess;
use App\Support\ActivityHistory;
use App\Support\ActiveAffiliationContext;
use App\Support\PageSize;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Versões do template DOCX')] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public int $templateId;

    #[Locked]
    public int $affiliationId;

    public ?\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file = null;

    public bool $showDeletion = false;
    public bool $showCurrentVersion = false;

    #[Locked]
    public ?int $currentVersionCandidateId = null;

    #[Locked]
    public ?int $currentVersionCandidateNumber = null;
    public bool $showAvailability = false;
    public bool $showReport = false;
    public bool $showPreview = false;

    #[Locked]
    public ?int $previewVersionId = null;
    public string $currentPassword = '';
    public string $deletionPassword = '';
    public string $versionDeletionPassword = '';
    public bool $showTemplateDeletion = false;

    #[Locked]
    public ?int $reportVersionId = null;

    #[Locked]
    public ?int $selectedVersionId = null;

    public function boot(): void
    {
        Gate::authorize('viewAny', DocumentTemplate::class);
        if (isset($this->affiliationId)) {
            abort_unless(app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))?->id === $this->affiliationId, 403);
        }
        if (isset($this->templateId)) {
            Gate::authorize('view', DocumentTemplate::findOrFail($this->templateId));
        }
    }

    public function mount(DocumentTemplate $documentTemplate): void
    {
        Gate::authorize('view', $documentTemplate);
        $this->templateId = $documentTemplate->id;
        $this->affiliationId = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))->id;
    }

    #[Computed]
    public function template(): DocumentTemplate
    {
        $template = DocumentTemplate::with(['campus', 'currentVersion'])->findOrFail($this->templateId);
        Gate::authorize('view', $template);

        return $template;
    }

    #[Computed]
    public function versions(): LengthAwarePaginator
    {
        return $this->template->templateVersions()->with(['uploadedByAffiliation.user'])
            ->withCount('generatedDocuments')->orderByDesc('version')->paginate(PageSize::PER_PAGE);
    }

    public function uploadVersion(ManageTemplateVersion $action): void
    {
        Gate::authorize('upload', $this->template);
        try {
            $this->validate(['file' => ['required', 'file', 'extensions:docx', 'max:10240']]);
            $action->upload(auth()->user(), $this->affiliationId, $this->template, $this->file);
        } finally {
            $this->file?->delete();
            $this->reset('file');
        }
        $this->resetPage();
        unset($this->template, $this->versions);
        Flux::toast(text: 'Arquivo validado e nova versão registrada.', variant: 'success');
    }

    public function prepareCurrentVersion(int $versionId): void
    {
        Gate::authorize('upload', $this->template);
        $version = $this->template->templateVersions()->findOrFail($versionId);
        $this->currentVersionCandidateId = $version->id;
        $this->currentVersionCandidateNumber = $version->version;
        $this->resetValidation('version');
        $this->showCurrentVersion = true;
    }

    public function makeCurrentVersion(ManageTemplateVersion $action): void
    {
        abort_if($this->currentVersionCandidateId === null, 404);
        $action->makeCurrent(auth()->user(), $this->affiliationId, $this->template, $this->currentVersionCandidateId);
        $this->showCurrentVersion = false;
        $this->currentVersionCandidateId = null;
        $this->currentVersionCandidateNumber = null;
        unset($this->template, $this->versions, $this->activityHistory);
        Flux::toast(text: 'Versão vigente alterada.', variant: 'success');
    }

    public function prepareDeletion(int $versionId): void
    {
        Gate::authorize('update', $this->template);
        $version = $this->template->templateVersions()->findOrFail($versionId);
        abort_if($version->generatedDocuments()->exists(), 403);
        $this->selectedVersionId = $version->id;
        $this->reset('versionDeletionPassword');
        $this->resetValidation();
        $this->showDeletion = true;
    }

    public function updatedShowDeletion(bool $show): void
    {
        if (! $show) {
            $this->reset('versionDeletionPassword', 'selectedVersionId');
            $this->resetValidation(['versionDeletionPassword', 'version']);
        }
    }

    public function closeVersionDeletion(): void
    {
        $this->showDeletion = false;
        $this->updatedShowDeletion(false);
    }

    public function deleteVersion(ManageTemplateVersion $action): void
    {
        Gate::authorize('update', $this->template);
        abort_if($this->selectedVersionId === null, 404);
        $this->validate(['versionDeletionPassword' => ['required', 'string', 'current_password']], [
            'versionDeletionPassword.required' => 'Informe sua senha atual.',
            'versionDeletionPassword.current_password' => 'A senha informada está incorreta.',
        ]);
        $action->delete(auth()->user(), $this->affiliationId, $this->template, $this->selectedVersionId, $this->versionDeletionPassword);
        $this->closeVersionDeletion();
        $this->resetPage();
        unset($this->template, $this->versions, $this->activityHistory);
        Flux::toast(text: 'Versão sem uso excluída.', variant: 'success');
    }

    public function updatedShowAvailability(bool $show): void
    {
        if (! $show) {
            $this->reset('currentPassword');
            $this->resetValidation('currentPassword');
        }
    }

    public function prepareTemplateDeletion(): void
    {
        Gate::authorize('delete', $this->template);
        $this->reset('deletionPassword');
        $this->resetValidation();
        $this->showTemplateDeletion = true;
    }

    public function updatedShowTemplateDeletion(bool $show): void
    {
        if (! $show) {
            $this->reset('deletionPassword');
            $this->resetValidation('deletionPassword');
            $this->resetValidation('template');
        }
    }

    public function closeTemplateDeletion(): void
    {
        $this->showTemplateDeletion = false;
        $this->updatedShowTemplateDeletion(false);
    }

    public function deleteTemplate(ManageDocumentTemplate $action): void
    {
        Gate::authorize('delete', $this->template);
        $this->validate(['deletionPassword' => ['required', 'string', 'current_password']], [
            'deletionPassword.required' => 'Informe sua senha atual.',
            'deletionPassword.current_password' => 'A senha informada está incorreta.',
        ]);
        $action->handle(auth()->user(), $this->affiliationId, $this->template, 'delete', ['current_password' => $this->deletionPassword]);
        $this->closeTemplateDeletion();
        session()->flash('status', 'Template excluído com sucesso.');
        $this->redirectRoute('document-templates.index', navigate: true);
    }

    public function toggleAvailability(ManageDocumentTemplate $action): void
    {
        $action->handle(auth()->user(), $this->affiliationId, $this->template, $this->template->deactivated_at === null ? 'deactivate' : 'reactivate', ['current_password' => $this->currentPassword]);
        $this->reset('currentPassword', 'showAvailability');
        unset($this->template);
        Flux::toast(text: 'Disponibilidade do template atualizada.', variant: 'success');
    }

    public function previewTemplate(int $versionId): void
    {
        Gate::authorize('view', $this->template);
        $this->previewVersionId = $this->template->templateVersions()->findOrFail($versionId)->id;
        $this->showPreview = true;
    }

    public function viewReport(int $versionId): void
    {
        $this->reportVersionId = $this->template->templateVersions()->findOrFail($versionId)->id;
        $this->showReport = true;
    }

    #[Computed]
    public function reportVersion(): ?TemplateVersion
    {
        return $this->reportVersionId === null ? null : $this->template->templateVersions()->find($this->reportVersionId);
    }

    #[Computed]
    public function activityHistory(): ?\Illuminate\Pagination\LengthAwarePaginator
    {
        $query = app(ActivityAccess::class)->forCurrentContext(auth()->user(), app('session.store'));

        return $query === null ? null : app(ActivityHistory::class)->forSubject($this->template, $query);
    }

    public function download(int $versionId): BinaryFileResponse
    {
        Gate::authorize('view', $this->template);
        $version = $this->template->templateVersions()->findOrFail($versionId);
        $media = $version->getFirstMedia('template_file');
        abort_unless($media !== null && $media->disk === 'local' && is_file($media->getPath()), 404);

        return response()->download($media->getPath(), 'template-'.$this->templateId.'-v'.$version->version.'.docx', ['Cache-Control' => 'private, no-store']);
    }
}; ?>

<div class="w-full space-y-8">
    @php($template = $this->template)
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('document-templates.index')" wire:navigate>Templates DOCX</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $template->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1" class="wrap-break-word">{{ $template->name }}</flux:heading>
                <flux:badge size="sm" :color="$template->deactivated_at === null ? 'green' : 'zinc'">{{ $template->deactivated_at === null ? 'Ativo' : 'Desativado' }}</flux:badge>
            </div>
            <flux:text class="mt-2">{{ $template->document_type->label() }} · {{ $template->campus?->name }} · {{ $template->remuneration->label() }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button variant="outline" icon="arrow-left" :href="route('document-templates.index')" wire:navigate>Voltar</flux:button>
            @can('update', $template)
                <flux:button icon="pencil-square" :href="route('document-templates.edit', $template)" wire:navigate>Editar template</flux:button>
            @endcan
        </div>
    </div>
    @if ($template->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Template desativado">
            <flux:callout.text>Desativado em {{ formatDateTime($template->deactivated_at) }}. O histórico foi preservado. Novos envios estão bloqueados.</flux:callout.text>
        </flux:callout>
    @elseif ($template->campus?->deactivated_at !== null)
        <flux:callout icon="information-circle" heading="Campus somente para leitura">
            <flux:callout.text>O campus está desativado. Templates e versões podem ser consultados, mas não podem ser alterados.</flux:callout.text>
        </flux:callout>
    @endif
    <section class="border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="template-details-heading">
        <flux:heading id="template-details-heading" size="lg" level="2">Dados do template</flux:heading>
        <dl class="mt-5 grid gap-6 md:grid-cols-2">
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Descrição</dt><dd class="mt-1 whitespace-pre-line text-sm">{{ $template->description ?: 'Não informada' }}</dd></div>
            <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">Versão vigente</dt><dd class="mt-1 text-sm">{{ $template->currentVersion ? 'Versão '.$template->currentVersion->version : 'Nenhuma versão disponível' }}</dd></div>
        </dl>
        <flux:text class="mt-5">A validação ocorre no upload. Um arquivo recusado preserva a versão vigente. Você pode tornar outra versão vigente sem excluir arquivos. Esta área armazena templates originais; a geração de documentos será uma etapa posterior.</flux:text>
    </section>
    @can('upload', $template)
        <section class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="template-upload-heading">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><flux:heading id="template-upload-heading" size="lg" level="2">Enviar nova versão</flux:heading><flux:text class="mt-1">Cada arquivo diferente cria uma versão. Revise o guia antes de enviar.</flux:text></div>
                <flux:button variant="outline" :href="route('document-templates.guide')" wire:navigate>Guia de variáveis</flux:button>
            </div>
            <form wire:submit="uploadVersion" class="space-y-6">
                <x-file-upload wire:model="file" :files="$file" label="Original DOCX" description="Somente DOCX, até 10 MiB. Fontes incorporadas e links para sites e e-mails são permitidos. Sem macros ou arquivos anexados." accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required />
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <span wire:loading wire:target="file,uploadVersion" class="text-sm text-zinc-500 dark:text-zinc-400" role="status">Recebendo e inspecionando o arquivo…</span>
                    <flux:button variant="primary" icon="arrow-up-tray" type="submit" wire:loading.attr="disabled" wire:target="file,uploadVersion">Enviar nova versão</flux:button>
                </div>
            </form>
        </section>
    @endcan
    <section class="space-y-4 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="template-versions-heading">
        <flux:heading id="template-versions-heading" size="lg" level="2">Histórico de versões</flux:heading>
        <flux:error name="version" />
        @php($versions = $this->versions)
        <x-loading-overlay target="uploadVersion,makeCurrentVersion,deleteVersion,gotoPage,nextPage,previousPage" label="Atualizando versões…" :dim-while-loading="true">
            @if ($versions->isNotEmpty())
                <flux:table :paginate="$versions">
                    <flux:table.columns>
                        <flux:table.column>Versão</flux:table.column>
                        <flux:table.column>Validação</flux:table.column>
                        <flux:table.column class="hidden md:table-cell">Enviada em</flux:table.column>
                        <flux:table.column class="hidden lg:table-cell">Responsável</flux:table.column>
                        <flux:table.column align="end">Ações</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($versions as $version)
                            <flux:table.row :key="$version->id">
                                <flux:table.cell>
                                    <span class="font-medium">v{{ $version->version }}</span>
                                    @if ($template->currentVersion?->id === $version->id)<flux:badge size="sm" color="blue">Vigente</flux:badge>@endif
                                    @if ($version->generated_documents_count > 0)<flux:badge size="sm">Utilizada</flux:badge>@endif
                                </flux:table.cell>
                                <flux:table.cell><flux:badge size="sm" :color="($version->validation_report['errors'] ?? []) !== [] ? 'red' : 'green'">{{ ($version->validation_report['errors'] ?? []) !== [] ? 'Com erros' : 'Validado no envio' }}</flux:badge></flux:table.cell>
                                <flux:table.cell class="hidden md:table-cell">{{ formatDateTime($version->created_at) }}</flux:table.cell>
                                <flux:table.cell class="hidden whitespace-normal lg:table-cell">{{ $version->uploadedByAffiliation?->user?->name ?? 'Vínculo #'.$version->uploaded_by_affiliation_id }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @can('upload', $template)
                                            @if ($template->current_version_id !== $version->id && ($version->validation_report['status'] ?? null) === 'validated')
                                                <flux:button size="sm" variant="outline" wire:click="prepareCurrentVersion({{ $version->id }})">Tornar vigente</flux:button>
                                            @endif
                                        @endcan
                                        <flux:button size="sm" variant="ghost" wire:click="viewReport({{ $version->id }})">Relatório</flux:button>
                                        <flux:button size="sm" variant="outline" icon="eye" wire:click="previewTemplate({{ $version->id }})">Visualizar</flux:button>
                                        <flux:button size="sm" variant="outline" icon="arrow-down-tray" wire:click="download({{ $version->id }})">Original</flux:button>
                                        @can('update', $template)
                                            @if ($version->generated_documents_count === 0)<flux:button size="sm" variant="ghost" icon="trash" :aria-label="'Excluir versão '.$version->version" wire:click="prepareDeletion({{ $version->id }})" />@endif
                                        @endcan
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @else
                <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-14 text-center dark:border-zinc-700">
                    <flux:icon.document class="mx-auto mb-4 size-8 text-zinc-400" />
                    <flux:heading size="lg">Nenhuma versão recebida</flux:heading>
                    <flux:text class="mt-2">As versões enviadas aparecerão aqui com o resultado da inspeção.</flux:text>
                </div>
            @endif
        </x-loading-overlay>
    </section>
    @can('update', $template)
        <section class="flex flex-col justify-between gap-5 rounded-xl border border-zinc-200 p-5 sm:flex-row sm:items-center dark:border-zinc-700" aria-labelledby="template-availability-heading">
            <div><flux:heading id="template-availability-heading" level="2">Disponibilidade do template</flux:heading><flux:text class="mt-1 max-w-xl">{{ $template->deactivated_at === null ? 'A desativação impede novos envios, preservando o histórico.' : 'A reativação permite novos envios no campus ativo.' }}</flux:text></div>
            <flux:button :variant="$template->deactivated_at === null ? 'danger' : 'primary'" wire:click="$set('showAvailability', true)">{{ $template->deactivated_at === null ? 'Desativar template' : 'Reativar template' }}</flux:button>
        </section>
    @endcan
    @can('delete', $template)
        <section class="flex flex-col justify-between gap-5 rounded-xl border border-red-200 p-5 sm:flex-row sm:items-center dark:border-red-900" aria-labelledby="template-deletion-heading">
            <div><flux:heading id="template-deletion-heading" level="2">Excluir template</flux:heading><flux:text class="mt-1 max-w-xl">O template e seus arquivos serão excluídos se nenhuma versão tiver sido usada por documentos. O histórico de auditoria será preservado.</flux:text></div>
            <flux:button variant="danger" icon="trash" wire:click="prepareTemplateDeletion">Excluir template</flux:button>
        </section>
    @endcan
    <flux:modal wire:model="showTemplateDeletion" class="md:w-md">
        <form wire:submit="deleteTemplate" class="space-y-6">
            <div><flux:heading size="lg">Excluir template</flux:heading><flux:text class="mt-2">Deseja excluir {{ $template->name }} e todos os seus arquivos? Esta ação não pode ser desfeita. O histórico de auditoria será preservado.</flux:text></div>
            <flux:error name="template" />
            <flux:input wire:model="deletionPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" variant="outline" wire:click="closeTemplateDeletion">Cancelar</flux:button><flux:button type="submit" variant="danger" icon="trash" wire:loading.attr="disabled" wire:target="deleteTemplate">Confirmar exclusão</flux:button></div>
        </form>
    </flux:modal>
    @can('viewAny', \Spatie\Activitylog\Models\Activity::class)
        <x-audit.activity-history :activities="$this->activityHistory" description="Alterações no template, envios, validações e exclusões de versões deste campus." />
    @endcan
    <flux:modal wire:model="showAvailability" class="md:w-md">
        <form wire:submit="toggleAvailability" class="space-y-6">
            <div><flux:heading size="lg">{{ $template->deactivated_at === null ? 'Desativar template' : 'Reativar template' }}</flux:heading><flux:text class="mt-2">Confirme a alteração de disponibilidade de {{ $template->name }}. As versões e o histórico serão preservados.</flux:text></div>
            @if ($template->deactivated_at === null)<flux:input wire:model="currentPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />@endif
            <div class="flex flex-wrap justify-end gap-3"><flux:modal.close><flux:button variant="outline">Cancelar</flux:button></flux:modal.close><flux:button type="submit" :variant="$template->deactivated_at === null ? 'danger' : 'primary'" wire:loading.attr="disabled">Confirmar</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="showCurrentVersion" class="md:w-md">
        <form wire:submit="makeCurrentVersion" class="space-y-6">
            <div><flux:heading size="lg">Alterar versão vigente</flux:heading><flux:text class="mt-2">A versão v{{ $currentVersionCandidateNumber }} passará a ser a vigente deste template. Todos os arquivos e documentos já vinculados serão preservados. Novos uploads válidos assumem a vigência automaticamente.</flux:text></div>
            <flux:error name="version" />
            <div class="flex flex-wrap justify-end gap-3"><flux:modal.close><flux:button type="button" variant="outline">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="makeCurrentVersion">Tornar vigente</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model.self="showDeletion" class="md:w-md">
        <form wire:submit="deleteVersion" class="space-y-6">
            <div><flux:heading size="lg">Excluir versão sem uso</flux:heading><flux:text class="mt-2">A versão e seu original privado serão removidos. Se for a vigente, a versão restante de maior número voltará a vigorar. O histórico e a numeração serão preservados.</flux:text></div>
            <flux:error name="version" />
            <flux:input wire:model="versionDeletionPassword" label="Sua senha atual" type="password" autocomplete="current-password" required viewable />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" variant="outline" wire:click="closeVersionDeletion">Cancelar</flux:button><flux:button type="submit" variant="danger" icon="trash" wire:loading.attr="disabled" wire:target="deleteVersion">Confirmar exclusão</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model.self="showPreview" class="w-[calc(100vw-2rem)] max-w-7xl!">
        <div class="space-y-4">
            <flux:heading size="lg">Visualização do template</flux:heading>
            <flux:text>Prévia aproximada do original, com os marcadores preservados. A paginação, as fontes e alguns detalhes de formatação podem diferir do Word.</flux:text>
            @if ($showPreview && $previewVersionId !== null)
                <iframe wire:key="template-preview-{{ $previewVersionId }}" src="{{ route('document-templates.preview', ['documentTemplate' => $template->id, 'templateVersion' => $previewVersionId]) }}" title="Visualização do template original" sandbox referrerpolicy="no-referrer" class="h-[72vh] w-full rounded-lg border border-zinc-200 bg-zinc-100 dark:border-zinc-700"></iframe>
            @endif
            <div class="flex justify-end"><flux:modal.close><flux:button variant="outline">Fechar</flux:button></flux:modal.close></div>
        </div>
    </flux:modal>
    <flux:modal wire:model="showReport" class="w-[calc(100vw-2rem)] max-w-5xl! max-h-[calc(100dvh-2rem)]">
        @if ($this->reportVersion)
            @php($reportVersion = $this->reportVersion)
            @php($report = $reportVersion->validation_report ?? [])
            <div class="space-y-6">
                <div><flux:heading size="lg">Inspeção da versão {{ $reportVersion->version }}</flux:heading><flux:text class="mt-2">{{ number_format($reportVersion->file_size / 1024, 1, ',', '.') }} KiB · enviada em {{ formatDateTime($reportVersion->created_at) }}</flux:text></div>
                @if (($report['errors'] ?? []) !== [])
                    <flux:callout variant="danger" heading="Erros na inspeção"><flux:callout.text>O original precisa de correção. Envie o arquivo corrigido como uma nova versão.</flux:callout.text></flux:callout>
                    @foreach ($report['errors'] as $error)
                        <flux:text wire:key="error-{{ $loop->index }}">{{ \App\Support\TemplateInspector::errorLabel($error) }}</flux:text>
                    @endforeach
                @else
                    <flux:callout icon="information-circle" heading="Inspeção sem erros registrados"><flux:callout.text>A estrutura do arquivo e os marcadores foram verificados no upload. O conteúdo e a formatação do original devem ser conferidos pelo responsável.</flux:callout.text></flux:callout>
                @endif
                <dl class="grid gap-6 text-sm md:grid-cols-2">
                    @foreach (['Variáveis detectadas' => $reportVersion->detected_variables ?? [], 'Marcadores e variações' => $report['detected_markers'] ?? []] as $label => $items)
                        <div wire:key="report-variables-{{ $loop->index }}"><dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-2"><ul class="space-y-2">@forelse ($items as $item)<li wire:key="report-marker-{{ $label }}-{{ $loop->index }}"><code class="break-all">{{ $item }}</code></li>@empty<li>Nenhum marcador.</li>@endforelse</ul></dd></div>
                    @endforeach
                </dl>
                <dl class="grid gap-6 text-sm md:grid-cols-2">
                    @foreach (['Enviada em' => formatDateTime($reportVersion->created_at), 'Vínculo de envio' => '#'.$reportVersion->uploaded_by_affiliation_id, 'Inspeção' => 'Concluída no envio'] as $label => $value)
                        <div wire:key="report-field-{{ $loop->index }}"><dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 wrap-break-word">{{ $value ?: 'Nenhuma' }}</dd></div>
                    @endforeach
                </dl>
                <x-accordion title="Identidade do arquivo e detalhes da inspeção">
                    <div class="space-y-3 text-sm"><p class="break-all">SHA-256: {{ $reportVersion->file_sha256 }}</p><p>Entradas: {{ $report['entries'] ?? '—' }} · bytes expandidos: {{ $report['expanded_bytes'] ?? '—' }}</p><p>Revisão do catálogo: {{ $report['catalog_revision'] ?? '—' }} · inspetor: {{ $report['inspector_revision'] ?? '—' }}</p></div>
                </x-accordion>
                <div class="flex justify-end"><flux:modal.close><flux:button variant="outline">Fechar</flux:button></flux:modal.close></div>
            </div>
        @endif
    </flux:modal>
</div>
