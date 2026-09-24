<?php

namespace App\Models;

use App\Enums\GeneratedDocumentOrigin;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'internship_id', 'template_version_id', 'origin', 'type', 'status',
    'signature_availability_location', 'snapshot', 'generation_token',
    'output_filename', 'template_sha256', 'generated_at', 'cancelled_at',
    'cancellation_reason',
])]
class GeneratedDocument extends Model
{
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            foreach (['signature_availability_location', 'output_filename', 'cancellation_reason'] as $attribute) {
                if (is_string($document->{$attribute})) {
                    $document->{$attribute} = trim($document->{$attribute}) ?: null;
                }
            }

            $isSge = $document->origin === GeneratedDocumentOrigin::SGE;
            $isCancelled = $document->status === GeneratedDocumentStatus::Cancelled;
            $validator = Validator::make([...$document->getAttributes(), 'snapshot' => $document->snapshot], [
                'internship_id' => ['required', 'integer', Rule::exists(Internship::class, 'id')],
                'template_version_id' => [$isSge ? 'required' : 'prohibited', 'nullable', 'integer', Rule::exists(TemplateVersion::class, 'id')->whereNotNull('validated_at')],
                'origin' => ['required', Rule::enum(GeneratedDocumentOrigin::class)],
                'type' => ['required', Rule::enum(GeneratedDocumentType::class)],
                'status' => ['required', Rule::enum(GeneratedDocumentStatus::class)],
                'signature_availability_location' => [$document->status === GeneratedDocumentStatus::AwaitingSignature ? 'required' : 'nullable', 'string', 'max:500'],
                'snapshot' => [$isSge ? 'required' : 'prohibited', 'nullable', 'array'],
                'generation_token' => ['required', 'uuid', Rule::unique('generated_documents', 'generation_token')->ignore($document->id)],
                'output_filename' => [$isSge ? 'required' : 'prohibited', 'nullable', 'string', 'max:255'],
                'template_sha256' => [$isSge ? 'required' : 'prohibited', 'nullable', 'regex:/\A[a-f0-9]{64}\z/'],
                'generated_at' => [$isSge ? 'required' : 'nullable', 'date'],
                'cancelled_at' => [$isCancelled ? 'required' : 'prohibited', 'nullable', 'date'],
                'cancellation_reason' => [$isCancelled ? 'required' : 'prohibited', 'nullable', 'string'],
            ]);

            $validator->after(function (LaravelValidator $validator) use ($document): void {
                if ($document->exists && $document->isDirty(['internship_id', 'template_version_id', 'origin', 'type', 'snapshot', 'generation_token', 'output_filename', 'template_sha256', 'generated_at'])) {
                    $validator->errors()->add('snapshot', 'Os dados da geração não podem ser alterados.');
                }

                if ($document->origin === GeneratedDocumentOrigin::SGE && $document->template_version_id !== null) {
                    $version = TemplateVersion::find($document->template_version_id);
                    if ($version && $version->file_sha256 !== $document->template_sha256) {
                        $validator->errors()->add('template_sha256', 'O hash deve corresponder à versão do template.');
                    }
                }

                if ($document->type === GeneratedDocumentType::OrientationCertificate && $document->status === GeneratedDocumentStatus::AwaitingSignature) {
                    $validator->errors()->add('status', 'Atestados de orientação não passam por assinatura.');
                }
            });

            $validator->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages(['document' => 'Documentos registrados não podem ser excluídos fisicamente.']);
        });
    }
}
