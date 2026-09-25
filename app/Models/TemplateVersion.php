<?php

namespace App\Models;

use Database\Factories\TemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'document_template_id', 'version', 'file_sha256', 'file_size',
    'required_variables', 'optional_variables', 'detected_variables',
    'validation_report', 'uploaded_by_affiliation_id', 'validated_at',
    'validated_by_affiliation_id',
])]
class TemplateVersion extends Model implements HasMedia
{
    /** @use HasFactory<TemplateVersionFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_template_id' => 'integer',
            'version' => 'integer',
            'file_size' => 'integer',
            'required_variables' => 'array',
            'optional_variables' => 'array',
            'detected_variables' => 'array',
            'validation_report' => 'array',
            'uploaded_by_affiliation_id' => 'integer',
            'validated_at' => 'datetime',
            'validated_by_affiliation_id' => 'integer',
        ];
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    /** @return HasMany<GeneratedDocument, $this> */
    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function uploadedByAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'uploaded_by_affiliation_id');
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function validatedByAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'validated_by_affiliation_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('template_file')
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
            ->acceptsFile(fn (): bool => ! $this->media()->where('collection_name', 'template_file')->exists());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (self $templateVersion): void {
            Validator::make([
                ...$templateVersion->getAttributes(),
                'required_variables' => $templateVersion->required_variables,
                'optional_variables' => $templateVersion->optional_variables,
                'detected_variables' => $templateVersion->detected_variables,
                'validation_report' => $templateVersion->validation_report,
            ], [
                'document_template_id' => ['required', 'integer', Rule::exists(DocumentTemplate::class, 'id')],
                'version' => ['required', 'integer', 'min:1', Rule::unique('template_versions')->where('document_template_id', $templateVersion->document_template_id)->ignore($templateVersion->id)],
                'file_sha256' => ['required', 'regex:/\A[a-f0-9]{64}\z/', Rule::unique('template_versions')->where('document_template_id', $templateVersion->document_template_id)->ignore($templateVersion->id)],
                'file_size' => ['required', 'integer', 'min:1'],
                'required_variables' => ['present', 'array'],
                'optional_variables' => ['present', 'array'],
                'detected_variables' => ['nullable', 'array'],
                'validation_report' => ['nullable', 'array'],
                'uploaded_by_affiliation_id' => ['required', 'integer', Rule::exists(Affiliation::class, 'id')],
                'validated_at' => ['nullable', 'date'],
                'validated_by_affiliation_id' => ['nullable', 'integer', Rule::exists(Affiliation::class, 'id')],
            ])->validate();
        });
    }
}
