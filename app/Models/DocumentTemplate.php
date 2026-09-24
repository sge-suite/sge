<?php

namespace App\Models;

use App\Enums\GeneratedDocumentType;
use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['campus_id', 'name', 'description', 'document_type', 'deactivated_at'])]
class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use HasFactory;

    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'campus_id' => 'integer',
            'document_type' => GeneratedDocumentType::class,
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
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
    public function scopeAvailableToCampus(Builder $query, Campus|int $campus): Builder
    {
        $campusId = $campus instanceof Campus ? $campus->id : $campus;

        return $query->where(function (Builder $query) use ($campusId): void {
            $query->whereNull('campus_id')->orWhere('campus_id', $campusId);
        });
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
                $campusExists->whereNull('deactivated_at')->whereNull('deleted_at');
            }

            Validator::make($attributes, [
                'campus_id' => ['bail', 'nullable', 'integer', $campusExists],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'document_type' => ['required', Rule::enum(GeneratedDocumentType::class)],
                'deactivated_at' => ['nullable', 'date'],
            ])->validate();
        });
    }
}
