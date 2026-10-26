<?php

use App\Actions\CancelGeneratedDocument;
use App\Actions\GenerateInternshipDocument;
use App\Actions\ManageDocumentSignature;
use App\Actions\RegisterExternalInternshipDocument;
use App\Actions\RequestExpiredDocumentCorrection;
use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Support\PageSize;
use App\Support\StudentInternshipContext;
use App\Support\InternshipRecipients;
use App\Support\CancellationReasonOptions;
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
    public bool $showExternal = false;
    public bool $showDateCorrection = false;
    #[Locked] public ?int $dateCorrectionDocumentId = null;
    #[Locked] public string $externalToken = '';
    public string $externalName = '';
    public string $externalType = 'main';
    public string $externalProtocol = '';
    public string $dateCorrectionReason = '';
    public bool $changesWorkSchedule = false;
    public string $scheduleStartsOn = '';
    /** @var array<string, int|string> */
    public array $scheduleWeeklyHours = [];
    public bool $confirmedExpiredDate = false;

    #[Computed]
    public function signatureDateExpired(): bool
    {
        return $this->signatureDocumentId !== null
            && $this->internship->generatedDocuments()->findOrFail($this->signatureDocumentId)->signatureDateExpired();
    }

    /** @return array<string, mixed>|null */
    #[Computed]
    public function signatureWorkSchedule(): ?array
    {
        return $this->signatureDocumentId === null ? null
            : $this->internship->generatedDocuments()->findOrFail($this->signatureDocumentId)->workScheduleChange();
    }

    public function openExternal(): void
    {
        Gate::authorize('registerExternalDocument', $this->internship);
        $this->resetValidation();
        $this->reset('externalName', 'externalProtocol', 'changesWorkSchedule', 'scheduleStartsOn');
        $this->externalType = 'main';
        $this->externalToken = (string) Str::uuid();
        $this->resetWorkScheduleProposal();
        $this->showExternal = true;
    }

    public function registerExternal(RegisterExternalInternshipDocument $register): void
    {
        $register->handle(auth()->user(), $this->affiliationId, $this->internship, $this->externalToken, [
            'document_name' => trim($this->externalName), 'type' => $this->externalType,
            'protocol_number' => trim($this->externalProtocol) === '' ? null : trim($this->externalProtocol),
            'work_schedule_change' => $this->workScheduleData($this->externalType),
        ]);
        $this->showExternal = false;
        $this->externalToken = (string) Str::uuid();
        $this->resetPage('documentsPage');
        unset($this->documents);
        $this->dispatch('internship-updated');
        Flux::toast('Documento externo registrado. Confira as assinaturas pelo fluxo de documentos.', variant: 'success');
    }

    public function openDateCorrection(int $id): void
    {
        Gate::authorize('requestDateCorrection', $this->internship->generatedDocuments()->findOrFail($id));
        $this->resetValidation();
        $this->dateCorrectionDocumentId = $id;
        $this->dateCorrectionReason = '';
        $this->showDateCorrection = true;
    }

    public function requestDateCorrection(RequestExpiredDocumentCorrection $correction): void
    {
        abort_if($this->dateCorrectionDocumentId === null, 422);
        $correction->handle(auth()->user(), $this->affiliationId,
            $this->internship->generatedDocuments()->findOrFail($this->dateCorrectionDocumentId), $this->dateCorrectionReason);
        $this->showDateCorrection = false;
        unset($this->documents, $this->internship);
        $this->dispatch('internship-updated');
        Flux::toast('Correção solicitada ao discente. Os documentos pendentes foram cancelados e preservados no histórico.', variant: 'success');
    }

    /** @return array{starts_on: string, weekly_hours: array<string, int|string>}|null */
    private function workScheduleData(string $documentType): ?array
    {
        return $this->changesWorkSchedule && $documentType === GeneratedDocumentType::Addendum->value
            ? ['starts_on' => $this->scheduleStartsOn, 'weekly_hours' => $this->scheduleWeeklyHours] : null;
    }

    private function resetWorkScheduleProposal(): void
    {
        $this->changesWorkSchedule = false;
        $this->scheduleStartsOn = '';
        $latest = $this->internship->workSchedules()->latest('starts_on')->first();
        $this->scheduleWeeklyHours = $latest === null ? $this->internship->weekly_hours : $latest->weekly_hours;
    }
    #[Locked] public ?int $signatureDocumentId = null;
    #[Locked] public string $signatureOperation = '';
    public bool $showSignature = false;
    public bool $confirmedSignature = false;
    public bool $sendSignatureNotice = true;
    public string $signatureLocation = '';
    public string $signatureDocumentName = '';
    public string $signatureProtocolNumber = '';
    /** @var list<string> */
    public array $signatureRecipientKeys = [];
    /** @var array<string, string> */
    public array $signatureMessages = [];

    /** @return array<string, array{name: string, email: string, role: string}> */
    #[Computed]
    public function signatureRecipients(): array
    {
        if ($this->signatureDocumentId === null || ! in_array($this->signatureOperation, ['await_signature', 'notify_signature'], true)) {
            return [];
        }
        $document = $this->internship->generatedDocuments()->findOrFail($this->signatureDocumentId);
        Gate::authorize($this->signatureOperation === 'notify_signature' ? 'notifySignature' : 'requestSignature', $document);

        return collect(app(InternshipRecipients::class)->forInternship($this->internship))
            ->map(fn (array $recipient): array => \Illuminate\Support\Arr::only($recipient, ['name', 'email', 'role']))->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function cancellationReasons(): array
    {
        return app(CancellationReasonOptions::class)->forUser(auth()->user());
    }

    public function openSignature(int $id, string $operation): void
    {
        abort_unless(in_array($operation, ['await_signature', 'confirm_signature', 'notify_signature', 'update_protocol'], true), 404);
        $document = $this->internship->generatedDocuments()->findOrFail($id);
        Gate::authorize(match ($operation) { 'await_signature' => 'requestSignature', 'notify_signature' => 'notifySignature', 'update_protocol' => 'updateProtocol', default => 'confirmSignature' }, $document);
        $this->resetValidation();
        $this->signatureDocumentId = $id;
        unset($this->signatureWorkSchedule, $this->signatureDateExpired);
        $this->signatureOperation = $operation;
        $this->signatureLocation = $document->signature_availability_location ?? '';
        $this->signatureProtocolNumber = $document->protocol_number ?? '';
        $this->signatureRecipientKeys = [];
        $this->signatureMessages = [];
        $this->signatureDocumentName = '';
        $this->confirmedSignature = false;
        $this->confirmedExpiredDate = false;
        $this->sendSignatureNotice = true;
        $this->showSignature = true;
    }

    public function saveSignature(ManageDocumentSignature $signature): void
    {
        abort_if($this->signatureDocumentId === null, 422);
        $signature->handle(auth()->user(), $this->affiliationId,
            $this->internship->generatedDocuments()->findOrFail($this->signatureDocumentId), $this->signatureOperation, [
                'document_name' => $this->signatureDocumentName, 'signature_availability_location' => $this->signatureLocation, 'protocol_number' => $this->signatureProtocolNumber, 'recipients' => $this->signatureRecipientKeys,
                'messages' => $this->signatureMessages, 'send_notice' => $this->sendSignatureNotice, 'confirm_signature' => $this->confirmedSignature,
                'confirm_expired_date' => $this->confirmedExpiredDate,
            ]);
        $this->reset('showSignature', 'signatureDocumentId', 'signatureOperation', 'signatureRecipientKeys', 'signatureMessages', 'confirmedSignature', 'signatureProtocolNumber', 'signatureDocumentName');
        unset($this->documents, $this->internship, $this->signatureRecipients, $this->signatureWorkSchedule, $this->signatureDateExpired);
        $this->dispatch('internship-updated');
        Flux::toast('Operação documental registrada.', variant: 'success');
    }

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
        return $this->internship->generatedDocuments()->with(['templateVersion.documentTemplate', 'internship.course.campus', 'externalRegistration'])
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
        $this->resetWorkScheduleProposal();
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
            (int) $this->templateId, $this->versionId, $this->generationToken, $this->workScheduleData($this->documentType));
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
        $this->dispatch('internship-updated');
    }
}; ?>

<section class="space-y-5 border-t border-zinc-200 pt-6 dark:border-zinc-700" aria-labelledby="documents-heading"
    x-data x-on:document-generated.window="if ($event.detail.internshipId === {{ $internshipId }}) window.location.assign($event.detail.url)">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div><flux:heading id="documents-heading" size="lg" level="2">Documentos</flux:heading><flux:text class="mt-1">Cada emissão preserva os dados e a versão do template. Baixe em DOCX ou PDF.</flux:text></div>
        <div class="flex flex-wrap gap-2">
        @can('registerExternalDocument', $this->internship)
            <flux:button wire:click="openExternal">Registrar documento externo</flux:button>
        @endcan
        @if ($this->typeOptions !== [])
            @if ($this->isCoordinator)
                <flux:button variant="primary" icon="document-text" wire:click="openGeneration">Gerar atestado de orientação</flux:button>
            @else
                <flux:button variant="primary" icon="document-text" wire:click="openGeneration">Gerar documento</flux:button>
            @endif
        @endif
        </div>
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
                            <flux:table.cell class="whitespace-normal"><span class="font-medium">Nº {{ $document->id }} · {{ $document->type->label() }}</span><flux:text size="sm">{{ $document->snapshot['template_name'] ?? $document->externalRegistration?->properties->get('document_name') ?? $document->templateVersion?->documentTemplate?->name ?? 'Documento externo' }} {{ $document->templateVersion ? '· v'.$document->templateVersion->version : '' }}</flux:text><flux:text size="sm">Origem: {{ $document->origin->label() }}</flux:text></flux:table.cell>
                            <flux:table.cell class="whitespace-normal">{{ formatDateTime($document->generated_at ?? $document->created_at) }}<flux:text size="sm">{{ data_get($document->snapshot, 'actor.name', 'Responsável registrado na auditoria') }}</flux:text></flux:table.cell>
                            <flux:table.cell class="whitespace-normal"><flux:badge size="sm" :color="$document->status === GeneratedDocumentStatus::Cancelled ? 'red' : 'zinc'">{{ $document->status->label() }}</flux:badge>
                                @if ($document->protocol_number !== null)<flux:text size="sm">Número de protocolo: {{ $document->protocol_number }}</flux:text>@endif
                                @if ($document->signature_availability_location)<flux:text size="sm">Disponibilizado em: {{ $document->signature_availability_location }}</flux:text>@endif
                                @if ($document->cancelled_at)<flux:text size="sm">{{ formatDateTime($document->cancelled_at) }} · {{ $document->cancellation_reason }}</flux:text>@endif
                                @if ($document->origin === \App\Enums\GeneratedDocumentOrigin::SGE)
                                    <flux:text size="sm">{{ match ($this->downloadStates[$document->id] ?? null) { 'document_download_failed' => 'O último download falhou. Tente novamente.', 'document_download_transmitted' => 'Download transmitido.', default => 'Download ainda não confirmado.' } }}</flux:text>
                                @endif
                                @if ($document->status === GeneratedDocumentStatus::AwaitingSignature && $document->signatureDateExpired())
                                    <flux:badge size="sm" color="amber">Data inicial vencida · análise do Setor</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell><div class="flex flex-wrap justify-end gap-2">
                                @can('updateProtocol', $document)<flux:button size="sm" wire:click="openSignature({{ $document->id }}, 'update_protocol')">Editar protocolo</flux:button>@endcan
                                @can('requestSignature', $document)<flux:button size="sm" wire:click="openSignature({{ $document->id }}, 'await_signature')">Disponibilizar para assinatura</flux:button>@endcan
                                @can('notifySignature', $document)<flux:button size="sm" wire:click="openSignature({{ $document->id }}, 'notify_signature')">Enviar aviso de assinatura</flux:button>@endcan
                                @can('confirmSignature', $document)<flux:button size="sm" variant="primary" wire:click="openSignature({{ $document->id }}, 'confirm_signature')">Conferir assinaturas</flux:button>@endcan
                                @can('requestDateCorrection', $document)<flux:button size="sm" wire:click="openDateCorrection({{ $document->id }})">Solicitar correção da data</flux:button>@endcan
                                @can('download', $document)<flux:button size="sm" variant="outline" icon="arrow-down-tray" wire:click="openDownload({{ $document->id }})">Baixar novamente</flux:button>@endcan
                                @can('cancel', $document)<flux:button size="sm" variant="danger" wire:click="confirmCancellation({{ $document->id }})">Cancelar</flux:button>@endcan
                            </div></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-700"><flux:heading size="lg">Nenhum documento emitido</flux:heading><flux:text class="mt-2">As emissões autorizadas deste estágio aparecerão aqui.</flux:text></div>
        @endif
    </x-loading-overlay>
    <flux:modal wire:model.self="showGeneration" class="md:w-lg">
        <form wire:submit="generate" class="space-y-6" data-unsaved-changes="dialog-generate" data-unsaved-models='["documentType", "templateId", "format", "changesWorkSchedule", "scheduleStartsOn", "scheduleWeeklyHours"]' data-unsaved-actions='["generate"]'>
            <x-form-save-status />
            <flux:heading size="lg" level="2">{{ $this->isCoordinator ? 'Gerar atestado de orientação' : 'Gerar documento' }}</flux:heading>
            @if (! $this->isCoordinator)
            <x-select label="Tipo documental" wire:model.live="documentType" :value="$documentType" :options="$this->typeOptions" required />
            <x-select label="Template e versão vigente" wire:model.live="templateId" :value="$templateId" :options="['' => 'Selecione um template'] + $this->templateOptions" :error="$errors->first('templateId')" required />
            @else
            <flux:text>O atestado será gerado com o template vigente do campus.</flux:text>
            @endif
            @if ($this->templateOptions === [])<flux:callout icon="information-circle" heading="Nenhum template disponível"><flux:callout.text>O Setor deve cadastrar um template ativo deste tipo no campus, compatível com a remuneração do estágio.</flux:callout.text></flux:callout>@endif
            @if ($documentType === GeneratedDocumentType::Addendum->value)
                @can('applyWorkSchedule', $this->internship)
                    @include('components.internships.work-schedule-fields', ['keyPrefix' => 'generated'])
                @endcan
            @endif
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
        <form wire:submit="cancelDocument" class="space-y-6" data-unsaved-changes="dialog-cancelDocument" data-unsaved-models='["cancellationReason"]' data-unsaved-actions='["cancelDocument"]'>
            <x-form-save-status /><flux:heading size="lg" level="2">Cancelar documento</flux:heading><flux:text>O registro e os dados originais serão preservados. Esta ação não cancela o estágio.</flux:text><x-select-create label="Motivo" wire:model="cancellationReason" :value="$cancellationReason" :options="$this->cancellationReasons" :error="$errors->first('cancellation_reason')" required /><div class="flex flex-wrap justify-end gap-3"><flux:button type="button" variant="outline" wire:click="$set('showCancellation', false)">Voltar</flux:button><flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancelDocument">Cancelar documento</flux:button></div></form>
    </flux:modal>
    <flux:modal wire:model.self="showSignature" class="md:w-2xl">
        <form wire:submit="saveSignature" class="space-y-6" data-unsaved-changes="dialog-saveSignature" data-unsaved-models='["signatureLocation", "signatureDocumentName", "signatureProtocolNumber", "sendSignatureNotice", "signatureRecipientKeys", "signatureMessages", "confirmedSignature", "confirmedExpiredDate"]' data-unsaved-actions='["saveSignature"]'>
            <x-form-save-status />
            <flux:heading size="lg" level="2">{{ match ($signatureOperation) { 'await_signature' => 'Disponibilizar para assinatura', 'notify_signature' => 'Enviar aviso de assinatura', 'update_protocol' => 'Editar número de protocolo', default => 'Conferir assinaturas' } }}</flux:heading>
            @if (in_array($signatureOperation, ['await_signature', 'notify_signature'], true))
                <flux:text>Documento nº {{ $signatureDocumentId }}. Informe onde o documento está disponível e escolha quem deve ser avisado. Cada pessoa recebe uma mensagem individual, com seu nome, papel no estágio e a orientação personalizada.</flux:text>
                <flux:input wire:model="signatureLocation" label="Local de disponibilização para assinatura" maxlength="500" placeholder="Ex.: portal institucional de assinaturas" required /><flux:error name="signature_availability_location" />
                <flux:input wire:model="signatureProtocolNumber" label="Número de protocolo (opcional)" description:trailing="Informe o número de protocolo deste documento. Ele será incluído no aviso, se preenchido." maxlength="255" /><flux:error name="protocol_number" />
                @if ($signatureOperation === 'await_signature')<flux:checkbox wire:model.live="sendSignatureNotice" label="Avisar os interessados agora" />@endif
                <flux:error name="send_notice" />
                @if ($sendSignatureNotice)
                <flux:input wire:model="signatureDocumentName" label="Nome do documento no aviso" placeholder="Ex.: Termo de Compromisso de Estágio" description:trailing="Este nome será usado nos avisos enviados aos destinatários." maxlength="255" required /><flux:error name="document_name" />
                <flux:checkbox.group wire:model.live="signatureRecipientKeys" label="Pessoas que receberão o aviso">
                    @foreach ($this->signatureRecipients as $key => $recipient)
                        <flux:checkbox :value="$key" :label="$recipient['role'].' · '.$recipient['name']" :description="$recipient['email']" wire:key="signature-recipient-{{ $key }}" />
                    @endforeach
                </flux:checkbox.group>
                <flux:error name="recipients" /><flux:error name="recipients.*" />
                @if ($this->signatureRecipients === [])<flux:callout variant="warning" heading="Nenhum contato elegível">Confira os vínculos ativos e os e-mails cadastrados das partes deste estágio.</flux:callout>@endif
                @foreach ($this->signatureRecipients as $key => $recipient)
                    @if (in_array($key, $signatureRecipientKeys, true))
                        <div wire:key="signature-message-{{ $key }}" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:heading level="3">{{ $recipient['name'] }} · {{ $recipient['role'] }}</flux:heading>
                            <flux:text size="sm">O aviso identifica o estágio e o documento e informa o local de assinatura. {{ $key === 'granting_party' ? 'A concedente recebe somente e-mail.' : 'Esta pessoa recebe aviso no SGE e por e-mail.' }}</flux:text>
                            <flux:textarea wire:model="signatureMessages.{{ $key }}" label="Mensagem adicional para esta pessoa (opcional)" maxlength="5000" /><flux:error :name="'messages.'.$key" />
                        </div>
                    @endif
                @endforeach
                @endif
            @elseif ($signatureOperation === 'update_protocol')
                <flux:text>Registre ou corrija o protocolo deste documento sem alterar sua situação ou enviar avisos.</flux:text>
                <flux:input wire:model="signatureProtocolNumber" label="Número de protocolo (opcional)" maxlength="255" /><flux:error name="protocol_number" />
            @elseif ($signatureOperation === 'confirm_signature')
                <flux:text>Confirme somente depois de conferir todas as assinaturas exigidas no documento nº {{ $signatureDocumentId }}. O arquivo assinado permanece no local de disponibilização registrado.</flux:text>
                @if ($this->signatureWorkSchedule !== null)
                    <flux:callout heading="Este aditivo altera a jornada"><flux:callout.text>Confira se o documento assinado corresponde à distribuição abaixo e ao início em {{ formatShort($this->signatureWorkSchedule['starts_on']) }}. Ao confirmar as assinaturas, a nova vigência será aplicada e a previsão de término será recalculada.</flux:callout.text></flux:callout>
                    <flux:text>{{ collect($this->signatureWorkSchedule['weekly_hours'])->map(fn ($hours, $day) => ['sunday' => 'Dom', 'monday' => 'Seg', 'tuesday' => 'Ter', 'wednesday' => 'Qua', 'thursday' => 'Qui', 'friday' => 'Sex', 'saturday' => 'Sáb'][$day].': '.$hours.' h')->implode(' · ') }}</flux:text>
                @endif
                <flux:checkbox wire:model="confirmedSignature" label="Conferi todas as assinaturas exigidas neste documento." /><flux:error name="confirm_signature" />
                @if ($this->signatureDateExpired)
                    <flux:callout variant="warning" heading="A data inicial deste documento já passou"><flux:callout.text>Analise o documento antes de decidir. Você pode voltar para cancelá-lo ou solicitar correção quando disponível. Uma nova jornada não pode ser aplicada em dias passados.</flux:callout.text></flux:callout>
                    <flux:checkbox wire:model="confirmedExpiredDate" label="Analisei a data vencida e confirmo o registro das assinaturas." /><flux:error name="confirm_expired_date" />
                @endif
                <flux:error name="starts_on" /><flux:error name="weekly_hours" />
                <flux:text>Depois da conferência dos documentos principais, use a ação Liberar estágio para autorizar a execução.</flux:text>
            @endif
            <flux:error name="status" /><flux:error name="document" />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" wire:click="$set('showSignature', false)">Voltar</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveSignature">{{ match ($signatureOperation) { 'await_signature' => $sendSignatureNotice ? 'Disponibilizar e enviar avisos' : 'Disponibilizar para assinatura', 'notify_signature' => 'Enviar avisos', 'update_protocol' => 'Salvar protocolo', default => 'Registrar como assinado' } }}</flux:button></div>
            <flux:text size="sm" wire:loading wire:target="saveSignature">Registrando operação…</flux:text>
        </form>
    </flux:modal>
    <flux:modal wire:model.self="showExternal" class="md:w-2xl">
        <form wire:submit="registerExternal" class="space-y-6" data-unsaved-changes="dialog-registerExternal" data-unsaved-models='["externalName", "externalType", "externalProtocol", "changesWorkSchedule", "scheduleStartsOn", "scheduleWeeklyHours"]' data-unsaved-actions='["registerExternal"]'>
            <x-form-save-status />
            <flux:heading size="lg" level="2">Registrar documento externo</flux:heading>
            <flux:text>Registre a existência do documento fornecido pela concedente. O arquivo permanece fora do SGE. Depois, registre o local de disponibilização e confira as assinaturas pelo fluxo de documentos.</flux:text>
            <flux:input wire:model="externalName" label="Nome do documento" maxlength="255" required /><flux:error name="document_name" />
            <x-select label="Tipo documental" wire:model.live="externalType" :value="$externalType" :options="['main' => 'Documento principal', 'addendum' => 'Aditivo']" required />
            <flux:error name="type" />
            <flux:input wire:model="externalProtocol" label="Número de protocolo (opcional)" maxlength="255" /><flux:error name="protocol_number" />
            @if ($externalType === GeneratedDocumentType::Addendum->value)
                @can('applyWorkSchedule', $this->internship)
                    @include('components.internships.work-schedule-fields', ['keyPrefix' => 'external'])
                @endcan
            @endif
            <flux:error name="document" /><flux:error name="generation_token" />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" wire:click="$set('showExternal', false)">Voltar</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="registerExternal">Registrar documento</flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model.self="showDateCorrection" class="md:w-lg">
        <form wire:submit="requestDateCorrection" class="space-y-6" data-unsaved-changes="dialog-requestDateCorrection" data-unsaved-models='["dateCorrectionReason"]' data-unsaved-actions='["requestDateCorrection"]'>
            <x-form-save-status />
            <flux:heading size="lg" level="2">Solicitar correção da data inicial</flux:heading>
            <flux:text>A seção de estágio será devolvida ao discente para corrigir a data. Os documentos de formalização pendentes serão cancelados, preservando o histórico. O novo envio precisará da análise e do aceite do Setor.</flux:text>
            <flux:textarea wire:model="dateCorrectionReason" label="Orientação para a correção" maxlength="5000" required /><flux:error name="reason" />
            <div class="flex flex-wrap justify-end gap-3"><flux:button type="button" wire:click="$set('showDateCorrection', false)">Voltar</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="requestDateCorrection">Solicitar correção</flux:button></div>
        </form>
    </flux:modal>
</section>
