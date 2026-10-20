<?php

use App\Actions\CancelGeneratedDocument;
use App\Actions\GenerateInternshipDocument;
use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Support\PageSize;
use App\Support\StudentInternshipContext;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

new class extends Component
{
    use WithPagination;

    #[Locked] public int $internshipId;
    #[Locked] public int $affiliationId;
    #[Locked] public string $generationToken;
    #[Locked] public ?int $versionId = null;
    #[Locked] public ?int $documentToCancel = null;
    #[Locked] public ?int $documentToDownload = null;
    public string $documentType = '';
    public string $format = 'docx';
    public string $templateId = '';
    public string $cancellationReason = '';
    public bool $showGeneration = false;
    public bool $showCancellation = false;
    public bool $showDownload = false;

    public function mount(int $internshipId, StudentInternshipContext $context): void
    {
        Gate::authorize('viewAny', GeneratedDocument::class);
        $this->internshipId = $internshipId;
        Gate::authorize('viewDocuments', $this->internship);
        $this->affiliationId = $context->selected(auth()->user())->id;
        $this->generationToken = (string) Str::uuid();
    }

    public function hydrate(StudentInternshipContext $context): void
    {
        Gate::authorize('viewAny', GeneratedDocument::class);
        abort_unless($context->selected(auth()->user())?->id === $this->affiliationId, 403);
        Gate::authorize('viewDocuments', $this->internship);
    }

    #[Computed]
    public function internship(): Internship
    {
        $stage = Internship::query()->with('course.campus')->findOrFail($this->internshipId);
        Gate::authorize('viewDocuments', $stage);

        return $stage;
    }

    #[Computed]
    public function isCoordinator(): bool
    {
        return app(StudentInternshipContext::class)->selected(auth()->user())->type === AffiliationType::Coordinator;
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return collect(GeneratedDocumentType::cases())->filter(fn ($type) => Gate::allows('generateDocument', [$this->internship, $type]))
            ->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all();
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function templateOptions(): array
    {
        $type = GeneratedDocumentType::tryFrom($this->documentType);
        if ($type === null || ! Gate::allows('generateDocument', [$this->internship, $type])) {
            return [];
        }

        return DocumentTemplate::query()->active()->availableToCampus($this->internship->course->campus_id)
            ->matchingRemuneration($this->internship->is_remunerated)->where('document_type', $type)
            ->whereHas('currentVersion', fn ($query) => $query->where('validation_report->status', 'validated'))
            ->with('currentVersion')->orderBy('name')->orderBy('id')->get()
            ->filter(fn ($template) => $template->currentVersion !== null)
            ->mapWithKeys(fn ($template) => [$template->id => $template->name.' · v'.$template->currentVersion->version])->all();
    }

    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        return $this->internship->generatedDocuments()->with(['templateVersion.documentTemplate', 'internship.course.campus'])
            ->when($this->isCoordinator, fn ($query) => $query->where('type', GeneratedDocumentType::OrientationCertificate))
            ->latest('generated_at')->latest('id')->paginate(PageSize::PER_PAGE, pageName: 'documentsPage');
    }

    /** @return array<int, string> */
    #[Computed]
    public function downloadStates(): array
    {
        $ids = $this->documents->getCollection()->modelKeys();
        $latestAttempts = Activity::query()->where('subject_type', (new \App\Models\GeneratedDocument)->getMorphClass())
            ->whereIn('subject_id', $ids)->whereIn('event', ['document_download_transmitted', 'document_download_failed'])
            ->selectRaw('MAX(id)')->groupBy('subject_id');

        return Activity::query()->whereIn('id', $latestAttempts)->pluck('event', 'subject_id')->all();
    }

    public function openGeneration(): void
    {
        abort_if($this->typeOptions === [], 403);
        $this->resetValidation();
        $this->format = 'docx';
        $this->documentType = (string) array_key_first($this->typeOptions);
        $this->updatedDocumentType();
        $this->showGeneration = true;
    }

    private function selectOrientationTemplate(): void
    {
        Gate::authorize('generateDocument', [$this->internship, GeneratedDocumentType::OrientationCertificate]);
        $this->resetValidation();
        $this->documentType = GeneratedDocumentType::OrientationCertificate->value;
        unset($this->templateOptions);
        $templateId = array_key_first($this->templateOptions);
        if ($templateId === null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['document' => 'Não há template vigente de atestado de orientação disponível. Solicite ao Setor de Estágio a ativação do template e de uma versão válida.']);
        }
        $this->templateId = (string) $templateId;
        $this->versionId = DocumentTemplate::query()->whereKey($templateId)->value('current_version_id');
    }

    public function updatedDocumentType(): void
    {
        $this->reset('templateId', 'versionId');
        $this->generationToken = (string) Str::uuid();
        $this->resetValidation();
        unset($this->templateOptions);
    }

    public function updatedTemplateId(): void
    {
        $this->versionId = null;
        $this->generationToken = (string) Str::uuid();
        $this->resetValidation();
        if (array_key_exists($this->templateId, $this->templateOptions)) {
            $this->versionId = DocumentTemplate::query()->whereKey($this->templateId)->value('current_version_id');
        }
    }

    public function generate(GenerateInternshipDocument $generate): void
    {
        if ($this->isCoordinator) {
            $this->selectOrientationTemplate();
        }
        $this->validate(['format' => ['required', \Illuminate\Validation\Rule::in(['docx', 'pdf'])], 'templateId' => ['required', 'integer'], 'documentType' => ['required', \Illuminate\Validation\Rule::enum(GeneratedDocumentType::class)]]);
        if (! array_key_exists($this->templateId, $this->templateOptions) || $this->versionId === null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['templateId' => 'Selecione novamente um template disponível para confirmar a versão vigente.']);
        }
        $document = $generate->handle(auth()->user(), $this->affiliationId, $this->internship,
            (int) $this->templateId, $this->versionId, $this->generationToken);
        $this->showGeneration = false;
        $this->resetPage('documentsPage');
        unset($this->documents, $this->downloadStates);
        $this->dispatch('document-generated', internshipId: $this->internshipId,
            url: route('internships.documents.download', [$this->internshipId, $document->id, 'format' => $this->format]));
        $this->generationToken = (string) Str::uuid();
        Flux::toast('Emissão registrada. Download iniciado; você também pode baixar pelo histórico.', variant: 'success');
    }

    public function openDownload(int $id): void
    {
        Gate::authorize('download', $this->internship->generatedDocuments()->findOrFail($id));
        $this->resetValidation();
        $this->documentToDownload = $id;
        $this->format = 'docx';
        $this->showDownload = true;
    }

    public function download(): void
    {
        $this->validate(['format' => ['required', \Illuminate\Validation\Rule::in(['docx', 'pdf'])]]);
        abort_if($this->documentToDownload === null, 422);
        $document = $this->internship->generatedDocuments()->findOrFail($this->documentToDownload);
        Gate::authorize('download', $document);
        $this->showDownload = false;
        $this->dispatch('document-generated', internshipId: $this->internshipId,
            url: route('internships.documents.download', [$this->internshipId, $document->id, 'format' => $this->format]));
    }

    public function confirmCancellation(int $id): void
    {
        $document = $this->internship->generatedDocuments()->findOrFail($id);
        Gate::authorize('cancel', $document);
        $this->documentToCancel = $id;
        $this->cancellationReason = '';
        $this->resetValidation();
        $this->showCancellation = true;
    }

    public function cancelDocument(CancelGeneratedDocument $cancel): void
    {
        abort_if($this->documentToCancel === null, 422);
        $cancel->handle(auth()->user(), $this->affiliationId,
            $this->internship->generatedDocuments()->findOrFail($this->documentToCancel), $this->cancellationReason);
        $this->reset('showCancellation', 'documentToCancel', 'cancellationReason');
        unset($this->documents, $this->downloadStates);
        Flux::toast('Documento cancelado. O registro histórico foi preservado.', variant: 'success');
    }
}; ?>

<section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="documents-heading"
    x-data x-on:document-generated.window="if ($event.detail.internshipId === {{ $internshipId }}) window.location.assign($event.detail.url)">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><flux:heading id="documents-heading" size="lg" level="2">Documentos</flux:heading><flux:text class="mt-1">Cada emissão preserva os dados e a versão do template. Baixe em DOCX ou PDF.</flux:text></div>
        @if ($this->typeOptions !== [])
            @if ($this->isCoordinator)
                <flux:button variant="primary" icon="document-text" wire:click="openGeneration">Gerar atestado de orientação</flux:button>
            @else
                <flux:button variant="primary" icon="document-text" wire:click="openGeneration">Gerar documento</flux:button>
            @endif
        @endif
    </div>
    <flux:text>O atestado de orientação é emitido exclusivamente pelo coordenador titular ou substituto do curso. Baixar novamente reproduz a emissão original, sem criar outro registro.</flux:text>
    <flux:error name="document" />
    @php($documents = $this->documents)
    <x-loading-overlay target="generate,download,cancelDocument,gotoPage,nextPage,previousPage" label="Atualizando documentos…" :dim-while-loading="true">
        @if ($documents->isNotEmpty())
            <flux:table :paginate="$documents">
                <flux:table.columns><flux:table.column>Documento / Template</flux:table.column><flux:table.column>Emissão</flux:table.column><flux:table.column>Situação</flux:table.column><flux:table.column align="end">Ações</flux:table.column></flux:table.columns>
                <flux:table.rows>
                    @foreach ($documents as $document)
                        <flux:table.row :key="'document-'.$document->id">
                            <flux:table.cell class="whitespace-normal"><span class="font-medium">Nº {{ $document->id }} · {{ $document->type->label() }}</span><flux:text size="sm">{{ $document->snapshot['template_name'] ?? $document->templateVersion?->documentTemplate?->name ?? 'Documento externo' }} {{ $document->templateVersion ? '· v'.$document->templateVersion->version : '' }}</flux:text></flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ formatDateTime($document->generated_at) }}<flux:text size="sm">{{ data_get($document->snapshot, 'actor.name', 'Responsável registrado na auditoria') }}</flux:text></flux:table.cell>
                            <flux:table.cell class="whitespace-normal"><flux:badge size="sm" :color="$document->status === GeneratedDocumentStatus::Cancelled ? 'red' : 'zinc'">{{ $document->status->label() }}</flux:badge>
                                @if ($document->cancelled_at)<flux:text size="sm">{{ formatDateTime($document->cancelled_at) }} · {{ $document->cancellation_reason }}</flux:text>@endif
                                <flux:text size="sm">{{ match ($this->downloadStates[$document->id] ?? null) { 'document_download_failed' => 'O último download falhou. Tente novamente.', 'document_download_transmitted' => 'Download transmitido.', default => 'Download ainda não confirmado.' } }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell><div class="flex flex-wrap justify-end gap-2">@can('download', $document)<flux:button size="sm" variant="outline" icon="arrow-down-tray" wire:click="openDownload({{ $document->id }})">Baixar novamente</flux:button>@endcan @can('cancel', $document)<flux:button size="sm" variant="danger" wire:click="confirmCancellation({{ $document->id }})">Cancelar</flux:button>@endcan</div></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-700"><flux:heading size="lg">Nenhum documento emitido</flux:heading><flux:text class="mt-2">As emissões autorizadas deste estágio aparecerão aqui.</flux:text></div>
        @endif
    </x-loading-overlay>
    <flux:modal wire:model.self="showGeneration" class="md:w-lg">
        <form wire:submit="generate" class="space-y-6">
            <flux:heading size="lg" level="2">{{ $this->isCoordinator ? 'Gerar atestado de orientação' : 'Gerar documento' }}</flux:heading>
            @if (! $this->isCoordinator)
            <x-select label="Tipo documental" wire:model.live="documentType" :value="$documentType" :options="$this->typeOptions" required />
            <x-select label="Template e versão vigente" wire:model.live="templateId" :value="$templateId" :options="['' => 'Selecione um template'] + $this->templateOptions" :error="$errors->first('templateId')" required />
            @else
            <flux:text>O atestado será gerado com o template vigente do campus.</flux:text>
            @endif
            @if ($this->templateOptions === [])<flux:callout icon="information-circle" heading="Nenhum template disponível"><flux:callout.text>O Setor deve cadastrar um template ativo deste tipo no campus, compatível com a remuneração do estágio.</flux:callout.text></flux:callout>@endif
            <flux:radio.group wire:model="format" label="Formato do download" variant="segmented">
                <flux:radio value="docx" label="DOCX" />
                <flux:radio value="pdf" label="PDF" />
            </flux:radio.group>
            <flux:error name="format" />
            <flux:text>Os dados serão congelados nesta emissão. Alterações posteriores no estágio ou no template não modificarão este documento.</flux:text>
            <flux:error name="document" /><flux:error name="generation_token" />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" variant="outline" wire:click="$set('showGeneration', false)">Voltar</flux:button><flux:button type="submit" variant="primary" :disabled="$this->templateOptions === [] || (! $this->isCoordinator && $templateId === '')" wire:loading.attr="disabled" wire:target="generate,templateId,documentType">Gerar e baixar</flux:button></div>
            <span wire:loading wire:target="generate" role="status" class="text-sm text-zinc-500 dark:text-zinc-400">Preparando o documento…</span>
        </form>
    </flux:modal>
    <flux:modal wire:model.self="showDownload" class="md:w-lg">
        <form wire:submit="download" class="space-y-6">
            <flux:heading size="lg" level="2">Baixar documento</flux:heading>
            <flux:text>O arquivo usará os dados e a versão do template preservados na emissão original.</flux:text>
            <flux:radio.group wire:model="format" label="Formato do download" variant="segmented">
                <flux:radio value="docx" label="DOCX" />
                <flux:radio value="pdf" label="PDF" />
            </flux:radio.group>
            <flux:error name="format" />
            <div class="flex flex-wrap justify-end gap-3">
                <flux:button type="button" variant="outline" wire:click="$set('showDownload', false)">Voltar</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="download">Baixar</flux:button>
            </div>
        </form>
    </flux:modal>
    <flux:modal wire:model.self="showCancellation" class="md:w-lg">
        <form wire:submit="cancelDocument" class="space-y-6"><flux:heading size="lg" level="2">Cancelar documento</flux:heading><flux:text>O registro e os dados originais serão preservados. Esta ação não cancela o estágio.</flux:text><flux:textarea label="Motivo" wire:model="cancellationReason" required maxlength="5000" /><flux:error name="cancellation_reason" /><div class="flex flex-wrap justify-end gap-3"><flux:button type="button" variant="outline" wire:click="$set('showCancellation', false)">Voltar</flux:button><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancelDocument">Cancelar documento</flux:button></div></form>
    </flux:modal>
</section>
