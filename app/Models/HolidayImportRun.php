<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $year
 * @property string $territory
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable|null $queried_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $error
 * @property string|null $source_url
 * @property list<array<string, mixed>>|null $payload
 * @property array<string, int>|null $summary
 */
#[Fillable(['year', 'territory', 'status', 'attempts', 'queried_at', 'completed_at', 'error', 'source_url', 'payload', 'summary'])]
class HolidayImportRun extends Model
{
    use LogsActivity;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['year' => 'integer', 'attempts' => 'integer', 'queried_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'payload' => 'array', 'summary' => 'array'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['year', 'territory', 'status', 'attempts', 'queried_at', 'completed_at', 'error', 'summary'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return HasMany<HolidaySource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(HolidaySource::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'completed' => 'Concluída', 'failed' => 'Falhou', 'running' => 'Consultando', default => 'Pendente',
        };
    }
}
