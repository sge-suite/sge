<?php

use App\Actions\ManageDocumentTemplate;
use App\Actions\ManageTemplateVersion;
use App\Enums\GeneratedDocumentType;
use App\Enums\TemplateRemuneration;
use App\Models\DocumentTemplate;
use App\Support\ActiveAffiliationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Cadastro de template DOCX')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $templateId = null;

    #[Locked]
    public int $affiliationId;

    #[Locked]
    public bool $typeLocked = false;

    public string $name = '';
    public string $description = '';
    public string $document_type = 'main';
    public string $remuneration = 'any';

    public ?TemporaryUploadedFile $file = null;

    public function boot(): void
    {
        Gate::authorize('viewAny', DocumentTemplate::class);
        if (isset($this->affiliationId)) {
            abort_unless(app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))?->id === $this->affiliationId, 403);
        }
        if ($this->templateId !== null) {
            Gate::authorize('update', DocumentTemplate::findOrFail($this->templateId));
        }
    }

    public function mount(?DocumentTemplate $documentTemplate = null): void
    {
        $this->affiliationId = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'))->id;
        if ($documentTemplate?->exists) {
            Gate::authorize('update', $documentTemplate);
            $this->templateId = $documentTemplate->id;
            $this->name = $documentTemplate->name;
            $this->description = $documentTemplate->description ?? '';
            $this->document_type = $documentTemplate->document_type->value;
            $this->remuneration = $documentTemplate->remuneration->value;
            $this->typeLocked = $documentTemplate->hasVersionHistory()
                || $documentTemplate->document_type === GeneratedDocumentType::OrientationCertificate;
        } else {
            Gate::authorize('create', DocumentTemplate::class);
        }
    }

    public function updatedDocumentType(): void
    {
        if (! $this->typeLocked && $this->document_type === GeneratedDocumentType::OrientationCertificate->value) {
            $this->name = GeneratedDocumentType::OrientationCertificate->label();
            $this->remuneration = TemplateRemuneration::Any->value;
        }
    }

    public function save(ManageDocumentTemplate $action, ManageTemplateVersion $versions): void
    {
        $data = $this->only(['name', 'description', 'document_type', 'remuneration']);
        if ($this->templateId === null) {
            try {
                $this->validate(['file' => ['required', 'file', 'extensions:docx', 'max:10240']]);
                $template = $versions->createWithUpload(auth()->user(), $this->affiliationId, $data, $this->file);
            } finally {
                $this->file?->delete();
                $this->reset('file');
            }
        } else {
            $template = $action->handle(auth()->user(), $this->affiliationId,
                DocumentTemplate::findOrFail($this->templateId), 'update', $data);
        }
        $this->dispatch('form-saved', scope: 'template-edit', savedAt: formatDateTime(now()));
        $this->redirectRoute('document-templates.show', ['documentTemplate' => $template->id], navigate: true);
    }

    #[Computed]
    public function otherOrientationCertificateExists(): bool
    {
        return DocumentTemplate::query()->orientationCertificate()
            ->when($this->templateId !== null, fn (Builder $query): Builder => $query->whereKeyNot($this->templateId))->exists();
    }

    /** @return array<string, string> */
    #[Computed]
    public function documentTypeOptions(): array
    {
        return GeneratedDocumentType::options();
    }

    #[Computed]
    public function certificateEditUrl(): ?string
    {
        $office = app(ActiveAffiliationContext::class)->currentFor(auth()->user(), app('session.store'));
        $certificate = DocumentTemplate::query()->orientationCertificate()->where('campus_id', $office->campus_id)->first();

        return $certificate !== null && Gate::allows('update', $certificate)
            ? route('document-templates.edit', $certificate) : null;
    }
}; ?>

<div class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('document-templates.index')" wire:navigate>Templates DOCX</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $templateId ? 'Editar' : 'Cadastrar' }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>
    <form wire:submit="save" class="space-y-8" novalidate data-unsaved-changes="template-edit" data-unsaved-models='["name", "description", "document_type", "remuneration", "file"]' data-unsaved-actions='["save"]'>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ $templateId ? 'Editar template' : 'Cadastrar template' }}</flux:heading>
                <flux:text class="mt-2">O template pertence ao campus do vínculo selecionado.</flux:text>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" :href="$templateId ? route('document-templates.show', $templateId) : route('document-templates.index')" wire:navigate>Cancelar</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="file,save">{{ $templateId ? 'Salvar alterações' : 'Cadastrar template' }}</flux:button>
            </div>
        </div>
        <section class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="template-data-heading">
            <flux:heading id="template-data-heading" size="lg" level="2">Dados do template</flux:heading>
            <div class="grid items-start gap-6 md:grid-cols-2">
                <flux:input label="Nome" wire:model="name" maxlength="255" required />
                <x-select label="Tipo documental" wire:model.live="document_type" :value="$document_type" :options="$this->documentTypeOptions" :disabled="$typeLocked" :error="$errors->first('document_type')" required />
            </div>
            <x-select label="Remuneração do modelo" wire:model.live="remuneration" :value="$remuneration" :options="TemplateRemuneration::options()" :disabled="$typeLocked || $document_type === GeneratedDocumentType::OrientationCertificate->value" :error="$errors->first('remuneration')" required />
            <flux:text>Use cadastros distintos para modelos com e sem remuneração. O texto da cláusula fica no DOCX; somente os valores usam variáveis. O atestado é independente da remuneração.</flux:text>
            @if ($document_type === GeneratedDocumentType::OrientationCertificate->value)
                <flux:callout icon="information-circle" heading="Emissão exclusiva da coordenação do curso">
                    <flux:callout.text>O Setor mantém o template, mas somente o coordenador titular ou substituto do curso pode emitir o atestado. Mantenha a identificação da coordenação no DOCX e use a variável COORDENADOR_NOME para o nome do coordenador emitente. Cada emissão preservará seus dados e a versão usada.</flux:callout.text>
                </flux:callout>
            @endif
            @if ($document_type === GeneratedDocumentType::OrientationCertificate->value && $this->otherOrientationCertificateExists)
                <flux:callout icon="information-circle" heading="Atestado de orientação já cadastrado">
                    <flux:callout.text>O sistema permite um único cadastro de atestado de orientação, inclusive quando desativado. Para mantê-lo, edite ou reative o existente com um vínculo autorizado.</flux:callout.text>
                    @if ($this->certificateEditUrl)
                        <flux:button class="mt-4" variant="outline" :href="$this->certificateEditUrl" wire:navigate>Editar atestado existente</flux:button>
                    @endif
                </flux:callout>
            @endif
            <flux:textarea label="Descrição" wire:model="description" maxlength="10000" rows="3" />
            <flux:text>Após o primeiro upload, o tipo documental e a classificação de remuneração ficam preservados.</flux:text>
            <flux:text>Após a primeira emissão, o template e a versão utilizada não poderão ser excluídos. Correções no arquivo devem ser enviadas como nova versão.</flux:text>
        </section>
        @if ($templateId === null)
            <section class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="template-file-heading">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <flux:heading id="template-file-heading" size="lg" level="2">Arquivo do template</flux:heading>
                        <flux:text class="mt-1">Envie o original para cadastrar a primeira versão. A estrutura e os marcadores serão validados no envio.</flux:text>
                    </div>
                    <flux:button variant="outline" :href="route('document-templates.guide')" wire:navigate>Guia de variáveis</flux:button>
                </div>
                <x-file-upload wire:model="file" :files="$file" label="Original DOCX" description="Somente DOCX, até 10 MiB. Fontes incorporadas e links para sites e e-mails são permitidos. Sem macros ou arquivos anexados." accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required />
                <span wire:loading wire:target="file,save" class="text-sm text-zinc-500 dark:text-zinc-400" role="status">Recebendo e inspecionando o arquivo…</span>
            </section>
        @endif
    </form>
</div>
