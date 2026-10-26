<?php

namespace App\Models;

use App\Enums\GeneratedDocumentOrigin;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use Carbon\CarbonImmutable;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property GeneratedDocumentOrigin $origin
 * @property GeneratedDocumentType $type
 * @property GeneratedDocumentStatus $status
 * @property array<string, mixed>|null $snapshot
 * @property Carbon|null $generated_at
 */
#[Fillable([
    'internship_id', 'template_version_id', 'origin', 'type', 'status',
    'signature_availability_location', 'protocol_number', 'snapshot', 'generation_token',
    'output_filename', 'template_sha256', 'generated_at', 'cancelled_at',
    'cancellation_reason',
])]
class GeneratedDocument extends Model
{
    /** @var list<string> */
    public array $signatureNoticeCategories = [];

    /** @use HasFactory<GeneratedDocumentFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = ['status' => 'generated'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'internship_id' => 'integer',
            'template_version_id' => 'integer',
            'origin' => GeneratedDocumentOrigin::class,
            'type' => GeneratedDocumentType::class,
            'status' => GeneratedDocumentStatus::class,
            'snapshot' => 'array',
            'generated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Internship, $this> */
    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class);
    }

    /** @return HasMany<InternshipWorkSchedule, $this> */
    public function workSchedules(): HasMany
    {
        return $this->hasMany(InternshipWorkSchedule::class);
    }

    /** @return MorphOne<Activity, $this> */
    public function externalRegistration(): MorphOne
    {
        return $this->morphOne(Activity::class, 'subject')->where('event', 'external_document_registered');
    }

    /** @return array{starts_on: string, weekly_hours: array<string, int>, previous_schedule_id: ?int, projected_end_date: string}|null */
    public function workScheduleChange(): ?array
    {
        return $this->origin === GeneratedDocumentOrigin::SGE ? ($this->snapshot['work_schedule_change'] ?? null)
            : $this->externalRegistration?->properties->get('work_schedule_change');
    }

    public function signatureDateExpired(): bool
    {
        $date = $this->origin === GeneratedDocumentOrigin::SGE ? ($this->snapshot['signature_start_date'] ?? null)
            : $this->externalRegistration?->properties->get('signature_start_date');
        $date ??= $this->workScheduleChange()['starts_on'] ?? null;
        if ($date === null && $this->type === GeneratedDocumentType::Main) {
            $formatted = $this->snapshot['values']['ESTAGIO_DATA_INICIO'] ?? null;
            $date = is_string($formatted) ? CarbonImmutable::createFromFormat('!d/m/Y', $formatted, config('app.timezone'))?->toDateString() : null;
            $date ??= $this->internship->planned_start_date->toDateString();
        }

        return is_string($date) && $date < CarbonImmutable::today(config('app.timezone'))->toDateString();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['internship_id', 'template_version_id', 'origin', 'type', 'status', 'signature_availability_location', 'protocol_number',
            'template_sha256', 'generated_at', 'cancelled_at', 'cancellation_reason'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $activity->properties = ($activity->properties ?? collect())->put('internship_id', $this->internship_id)
            ->put('campus_id', $this->internship()->firstOrFail()->course()->firstOrFail()->campus_id);
        if ($this->signatureNoticeCategories !== []) {
            $activity->properties = $activity->properties->put('signature_recipient_categories', $this->signatureNoticeCategories)
                ->put('signature_recipient_count', count($this->signatureNoticeCategories));
        }
    }

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            foreach (['signature_availability_location', 'protocol_number', 'output_filename', 'cancellation_reason'] as $attribute) {
                if (is_string($document->{$attribute})) {
                    $value = trim($document->{$attribute});
                    $document->{$attribute} = $value === '' ? null : $value;
                }
            }

            $isSge = $document->origin === GeneratedDocumentOrigin::SGE;
            $isCancelled = $document->status === GeneratedDocumentStatus::Cancelled;
            $validator = Validator::make([...$document->getAttributes(), 'snapshot' => $document->snapshot], [
                'internship_id' => ['required', 'integer', Rule::exists(Internship::class, 'id')],
                'template_version_id' => [$isSge ? 'required' : 'prohibited', 'nullable', 'integer', Rule::exists(TemplateVersion::class, 'id')->where('validation_report->status', 'validated')],
                'origin' => ['required', Rule::enum(GeneratedDocumentOrigin::class)],
                'type' => ['required', Rule::enum(GeneratedDocumentType::class)],
                'status' => ['required', Rule::enum(GeneratedDocumentStatus::class)],
                'signature_availability_location' => [$document->status === GeneratedDocumentStatus::AwaitingSignature ? 'required' : 'nullable', 'string', 'max:500'],
                'protocol_number' => ['nullable', 'string', 'max:255'],
                'snapshot' => [$isSge ? 'required' : 'prohibited', 'nullable', 'array'],
                'generation_token' => ['required', 'uuid', Rule::unique('generated_documents', 'generation_token')->ignore($document->id)],
                'output_filename' => [$isSge ? 'required' : 'prohibited', 'nullable', 'string', 'max:255'],
                'template_sha256' => [$isSge ? 'required' : 'prohibited', 'nullable', 'regex:/\A[a-f0-9]{64}\z/'],
                'generated_at' => [$isSge ? 'required' : 'nullable', 'date'],
                'cancelled_at' => [$isCancelled ? 'required' : 'prohibited', 'nullable', 'date'],
                'cancellation_reason' => [$isCancelled ? 'required' : 'prohibited', 'nullable', 'string'],
            ]);

            $validator->after(function (LaravelValidator $validator) use ($document): void {
                if ($document->exists && $document->isDirty('status')) {
                    $allowed = match ($document->getRawOriginal('status')) {
                        'generated' => [GeneratedDocumentStatus::AwaitingSignature, GeneratedDocumentStatus::Cancelled],
                        'awaiting_signature' => [GeneratedDocumentStatus::Signed, GeneratedDocumentStatus::Cancelled],
                        default => [],
                    };
                    if (! in_array($document->status, $allowed, true)) {
                        $validator->errors()->add('status', 'Transição documental inválida.');
                    }
                }
                if ($document->exists && $document->isDirty(['internship_id', 'template_version_id', 'origin', 'type', 'snapshot', 'generation_token', 'output_filename', 'template_sha256', 'generated_at'])) {
                    $validator->errors()->add('snapshot', 'Os dados da geração não podem ser alterados.');
                }

                if ($document->origin === GeneratedDocumentOrigin::SGE && $document->template_version_id !== null) {
                    $version = TemplateVersion::find($document->template_version_id);
                    if ($version && $version->file_sha256 !== $document->template_sha256) {
                        $validator->errors()->add('template_sha256', 'O hash deve corresponder à versão do template.');
                    }
                    if (! $document->exists && $version && $version->documentTemplate->document_type !== $document->type) {
                        $validator->errors()->add('type', 'O tipo do documento deve corresponder ao template.');
                    }
                }

                if ($document->type === GeneratedDocumentType::OrientationCertificate
                    && in_array($document->status, [GeneratedDocumentStatus::AwaitingSignature, GeneratedDocumentStatus::Signed], true)) {
                    $validator->errors()->add('status', 'Atestados de orientação não passam por assinatura.');
                }
                if ($document->exists && in_array($document->getRawOriginal('status'), [GeneratedDocumentStatus::Cancelled->value, GeneratedDocumentStatus::Signed->value], true)
                    && $document->isDirty(['status', 'cancelled_at', 'cancellation_reason', 'signature_availability_location'])) {
                    $validator->errors()->add('status', 'O ciclo deste documento não pode ser reaberto ou reescrito.');
                }
            });

            $validator->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages(['document' => 'Documentos registrados não podem ser excluídos fisicamente.']);
        });
    }
}
