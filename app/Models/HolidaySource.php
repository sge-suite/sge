<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $source_key
 * @property int $holiday_import_run_id
 * @property int|null $holiday_id
 * @property string $status
 * @property array<string, mixed> $payload
 * @property list<int>|null $candidate_ids
 * @property string|null $reason
 * @property string|null $resolution_notes
 * @property bool $missing_from_source
 * @property CarbonImmutable $last_seen_at
 * @property-read HolidayImportRun $importRun
 * @property-read Holiday|null $holiday
 */
#[Fillable(['source_key', 'holiday_import_run_id', 'holiday_id', 'status', 'payload', 'candidate_ids', 'reason', 'resolution_notes', 'missing_from_source', 'last_seen_at'])]
class HolidaySource extends Model
{
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'candidate_ids' => 'array', 'missing_from_source' => 'boolean', 'last_seen_at' => 'immutable_datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['holiday_import_run_id', 'holiday_id', 'status', 'reason', 'resolution_notes', 'missing_from_source'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Holiday, $this> */
    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class)->withTrashed();
    }

    /** @return BelongsTo<HolidayImportRun, $this> */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(HolidayImportRun::class, 'holiday_import_run_id');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, mixed>>
     */
    public static function resolutionRules(array $data): array
    {
        $rules = ['resolution' => ['required', Rule::in(['create', 'link', 'ignore'])], 'resolution_notes' => ['required', 'string', 'max:5000']];
        if (($data['resolution'] ?? null) === 'link') {
            $rules['holiday_id'] = ['required', 'integer', Rule::exists(Holiday::class, 'id')];
        }
        if (($data['resolution'] ?? null) === 'create') {
            $rules += Holiday::inputRules();
        }

        return $rules;
    }
}
