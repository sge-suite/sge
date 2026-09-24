<?php

namespace App\Models;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use Database\Factories\InternshipWorkScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['internship_id', 'starts_on', 'ends_on', 'weekly_hours', 'generated_document_id'])]
class InternshipWorkSchedule extends Model
{
    /** @use HasFactory<InternshipWorkScheduleFactory> */
    use HasFactory;

    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'internship_id' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'weekly_hours' => 'array',
            'generated_document_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Internship, $this> */
    public function internship(): BelongsTo
    {
        return $this->belongsTo(Internship::class);
    }

    /** @return BelongsTo<GeneratedDocument, $this> */
    public function generatedDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (self $schedule): void {
            $validator = Validator::make([...$schedule->getAttributes(), 'weekly_hours' => $schedule->weekly_hours], [
                'internship_id' => ['required', 'integer', Rule::exists(Internship::class, 'id')],
                'starts_on' => ['required', 'date'],
                'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
                'weekly_hours' => ['required', 'array'],
                'generated_document_id' => ['required', 'integer', Rule::exists(GeneratedDocument::class, 'id')],
            ]);

            $validator->after(function (LaravelValidator $validator) use ($schedule): void {
                $internship = Internship::find($schedule->internship_id);
                $document = GeneratedDocument::find($schedule->generated_document_id);
                if ($document && ($document->internship_id !== $schedule->internship_id || $document->type !== GeneratedDocumentType::Addendum || $document->status !== GeneratedDocumentStatus::Signed)) {
                    $validator->errors()->add('generated_document_id', 'A jornada exige um aditivo assinado deste estágio.');
                }

                if ($schedule->exists && $schedule->isDirty(['internship_id', 'starts_on', 'weekly_hours', 'generated_document_id'])) {
                    $validator->errors()->add('weekly_hours', 'A vigência histórica não pode ser reescrita.');
                }

                if ($schedule->exists && $schedule->isDirty('ends_on') && ($schedule->getRawOriginal('ends_on') !== null || $schedule->ends_on === null)) {
                    $validator->errors()->add('ends_on', 'A vigência só pode ser encerrada uma vez.');
                }

                if (! $internship || ! $schedule->starts_on) {
                    return;
                }

                if ($schedule->starts_on->lte($internship->planned_start_date)) {
                    $validator->errors()->add('starts_on', 'O aditivo deve começar depois da jornada inicial.');
                }

                $hours = $schedule->weekly_hours;
                $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                $rules = $internship->internship_type_snapshot['rules'] ?? [];
                if (! is_array($hours) || array_diff(array_keys($hours), $days) !== [] || array_diff($days, array_keys($hours)) !== []) {
                    $validator->errors()->add('weekly_hours', 'A jornada deve informar os sete dias da semana.');
                } else {
                    $total = 0;
                    foreach ($hours as $value) {
                        if (! is_int($value) || $value < 0 || $value > ($rules['max_daily_hours'] ?? 0)) {
                            $validator->errors()->add('weekly_hours', 'A jornada diária excede o limite do tipo.');
                            break;
                        }
                        $total += $value;
                    }
                    if ($total < 1 || $total > ($rules['max_weekly_hours'] ?? 0)) {
                        $validator->errors()->add('weekly_hours', 'A carga semanal deve ser positiva e respeitar o limite do tipo.');
                    }
                }

                $otherSchedules = self::query()->where('internship_id', $schedule->internship_id)
                    ->when($schedule->exists, fn ($query) => $query->where('id', '!=', $schedule->id));
                $overlap = (clone $otherSchedules)
                    ->where(function ($query) use ($schedule): void {
                        if ($schedule->ends_on !== null) {
                            $query->where('starts_on', '<=', $schedule->ends_on);
                        }
                    })
                    ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $schedule->starts_on))
                    ->exists();
                if ($overlap) {
                    $validator->errors()->add('starts_on', 'A vigência se sobrepõe a outra jornada.');
                }

                if (! $schedule->exists) {
                    $previous = (clone $otherSchedules)->where('starts_on', '<', $schedule->starts_on)->latest('starts_on')->first();
                    if ($previous && ($previous->ends_on === null || ! $previous->ends_on->copy()->addDay()->isSameDay($schedule->starts_on))) {
                        $validator->errors()->add('starts_on', 'A vigência deve começar no dia seguinte à anterior.');
                    }

                    if ((clone $otherSchedules)->where('generated_document_id', $schedule->generated_document_id)->exists()) {
                        $validator->errors()->add('generated_document_id', 'Este aditivo já possui uma vigência.');
                    }
                }
            });

            $validator->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages(['schedule' => 'Vigências de jornada não podem ser excluídas fisicamente.']);
        });
    }
}
