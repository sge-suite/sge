<?php

namespace App\Models;

use Database\Factories\InternshipCalendarOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['internship_id', 'date', 'is_working_day', 'reason'])]
class InternshipCalendarOverride extends Model
{
    /** @use HasFactory<InternshipCalendarOverrideFactory> */
    use HasFactory;

    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['internship_id' => 'integer', 'date' => 'date', 'is_working_day' => 'boolean'];
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
        static::saving(function (self $override): void {
            if (is_string($override->reason)) {
                $override->reason = trim($override->reason);
            }

            Validator::make($override->getAttributes(), [
                'internship_id' => ['required', 'integer', Rule::exists(Internship::class, 'id')],
                'date' => ['required', 'date', Rule::unique('internship_calendar_overrides', 'date')->where('internship_id', $override->internship_id)->ignore($override->id)],
                'is_working_day' => ['required', 'boolean'],
                'reason' => ['required', 'string'],
            ])->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages(['calendar_override' => 'Exceções de calendário não podem ser excluídas fisicamente.']);
        });
    }
}
