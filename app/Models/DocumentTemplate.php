<?php

namespace App\Models;

use App\Enums\GeneratedDocumentType;
use App\Enums\TemplateRemuneration;
use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int|null $current_version_id
 * @property int|null $campus_id
 * @property GeneratedDocumentType $document_type
 * @property TemplateRemuneration $remuneration
 * @property Carbon|null $deactivated_at
 */
#[Fillable(['campus_id', 'name', 'description', 'document_type', 'remuneration', 'deactivated_at'])]
class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use HasFactory;

    use LogsActivity;
    use Searchable;

    /** @return array{id: int, name: string, campus_id: int, document_type: string, remuneration: string, is_active: int} */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'campus_id' => (int) $this->campus_id,
            'document_type' => $this->document_type->value,
            'remuneration' => $this->remuneration->value,
            'is_active' => (int) ($this->deactivated_at === null),
        ];
    }

    protected $attributes = ['remuneration' => 'any'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'campus_id' => 'integer',
            'current_version_id' => 'integer',
            'document_type' => GeneratedDocumentType::class,
            'remuneration' => TemplateRemuneration::class,
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /** @return HasMany<TemplateVersion, $this> */
    public function templateVersions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'current_version_id');
    }

    /**
     * @param  Builder<DocumentTemplate>  $query
     * @return Builder<DocumentTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    /**
     * @param  Builder<DocumentTemplate>  $query
     * @return Builder<DocumentTemplate>
     */
    public function scopeMatchingRemuneration(Builder $query, bool $isRemunerated): Builder
    {
        return $query->whereIn('remuneration', [TemplateRemuneration::Any->value,
            $isRemunerated ? TemplateRemuneration::Paid->value : TemplateRemuneration::Unpaid->value]);
    }

    /**
     * @param  Builder<DocumentTemplate>  $query
     * @return Builder<DocumentTemplate>
     */
    public function scopeOrientationCertificate(Builder $query): Builder
    {
        return $query->where('document_type', GeneratedDocumentType::OrientationCertificate);
    }

    /**
     * @param  Builder<DocumentTemplate>  $query
     * @return Builder<DocumentTemplate>
     */
    public function scopeAvailableToCampus(Builder $query, Campus|int $campus): Builder
    {
        $campusId = $campus instanceof Campus ? $campus->id : $campus;

        return $query->where('campus_id', $campusId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            if (($template->getAttributes()['document_type'] ?? null) === GeneratedDocumentType::OrientationCertificate->value
                && ($template->getAttributes()['remuneration'] ?? null) !== TemplateRemuneration::Any->value) {
                throw ValidationException::withMessages(['remuneration' => 'O atestado de orientação é independente da remuneração.']);
            }
            if ($template->exists && $template->isDirty('remuneration') && $template->hasVersionHistory()) {
                throw ValidationException::withMessages(['remuneration' => 'A classificação de remuneração não pode mudar após o primeiro upload. Cadastre outro template.']);
            }
            if ($template->exists && $template->getRawOriginal('document_type') === GeneratedDocumentType::OrientationCertificate->value
                && $template->isDirty('document_type')) {
                throw ValidationException::withMessages(['document_type' => 'O atestado de orientação deve ser mantido neste cadastro e não pode mudar de tipo.']);
            }
            if (($template->getAttributes()['document_type'] ?? null) === GeneratedDocumentType::OrientationCertificate->value
                && self::query()->orientationCertificate()
                    ->when($template->exists, fn (Builder $query): Builder => $query->whereKeyNot($template->getKey()))->exists()) {
                throw ValidationException::withMessages(['document_type' => 'Já existe um template de atestado de orientação no sistema, mesmo que esteja desativado. Edite ou reative o cadastro existente com um vínculo autorizado.']);
            }
            if ($template->exists && $template->isDirty(['campus_id', 'document_type']) && $template->hasVersionHistory()) {
                throw ValidationException::withMessages(['document_type' => 'Tipo e escopo não podem mudar após o primeiro upload.']);
            }
            foreach (['name', 'description'] as $attribute) {
                $value = $template->getAttribute($attribute);

                if (is_string($value)) {
                    $value = trim($value);
                    $template->{$attribute} = $value === '' ? null : $value;
                }
            }

            if (blank($template->getAttributes()['campus_id'] ?? null)) {
                $template->campus_id = null;
            }

            $attributes = $template->getAttributes();
            $campusId = $attributes['campus_id'] ?? null;
            $requiresActiveCampus = ! $template->exists
                || $template->isDirty('campus_id')
                || ($template->getOriginal('deactivated_at') !== null && ($attributes['deactivated_at'] ?? null) === null);
            $campusExists = Rule::exists(Campus::class, 'id');

            if ($requiresActiveCampus) {
                $campusExists->whereNull('deactivated_at');
            }

            Validator::make($attributes, [
                'campus_id' => ['bail', 'nullable', 'integer', $campusExists],
                'current_version_id' => ['nullable', 'integer', Rule::exists(TemplateVersion::class, 'id')->where('document_template_id', $template->id)->where('validation_report->status', 'validated')],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'document_type' => ['required', Rule::enum(GeneratedDocumentType::class)],
                'remuneration' => ['required', Rule::enum(TemplateRemuneration::class)],
                'deactivated_at' => ['nullable', 'date'],
            ])->validate();
        });

        static::deleting(function (self $template): void {
            if ($template->templateVersions()->exists()) {
                throw ValidationException::withMessages(['template' => 'A exclusão do template exige a remoção segura das versões sem uso.']);
            }
        });
    }

    public function hasLinkedRecords(): bool
    {
        return $this->templateVersions()->whereHas('generatedDocuments')->exists();
    }

    public function hasVersionHistory(): bool
    {
        return $this->templateVersions()->exists() || Activity::query()->forSubject($this)
            ->where('event', 'template_version_uploaded')->exists();
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $activity->properties = ($activity->properties ?? collect())->put('campus_id', $this->campus_id);
    }
}
