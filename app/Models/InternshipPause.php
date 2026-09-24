<?php

namespace App\Models;

use App\Enums\InternshipStatus;
use Database\Factories\InternshipPauseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['internship_id', 'starts_at', 'ends_at', 'reason'])]
class InternshipPause extends Model
{
    /** @use HasFactory<InternshipPauseFactory> */
    use HasFactory;

    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['internship_id' => 'integer', 'starts_at' => 'date', 'ends_at' => 'date'];
    }

    /** @return BelongsTo<Internship, $this> */
    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (self $pause): void {
            if (is_string($pause->reason)) {
                $pause->reason = trim($pause->reason);
            }

            $validator = Validator::make($pause->getAttributes(), [
                'internship_id' => ['required', 'integer', Rule::exists(Internship::class, 'id')],
                'starts_at' => ['required', 'date'],
                'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
                'reason' => ['required', 'string'],
            ]);

            $validator->after(function (LaravelValidator $validator) use ($pause): void {
                $internship = Internship::find($pause->internship_id);
                if (! $internship || ! $pause->starts_at || ! $pause->ends_at) {
                    return;
                }

                if (! $pause->exists && $internship->status !== InternshipStatus::InProgress) {
                    $validator->errors()->add('internship_id', 'A pausa exige estágio em andamento.');
                }

                if ($pause->starts_at->lt($internship->planned_start_date)) {
                    $validator->errors()->add('starts_at', 'A pausa não pode anteceder o início planejado.');
                }

                $overlaps = self::query()->where('internship_id', $pause->internship_id)
                    ->where('starts_at', '<=', $pause->ends_at)
                    ->where('ends_at', '>=', $pause->starts_at)
                    ->when($pause->exists, fn ($query) => $query->whereKeyNot($pause->id))
                    ->exists();

                if ($overlaps) {
                    $validator->errors()->add('starts_at', 'O período se sobrepõe a outra pausa.');
                }
            });

            $validator->validate();
        });
    }
}
