<?php

namespace App\Console\Commands;

use App\Actions\SyncInternshipExecutionStatus;
use App\Enums\InternshipStatus;
use App\Models\Campus;
use App\Models\Internship;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class SyncInternshipExecutionStatuses extends Command
{
    protected $signature = 'internships:sync-execution-status {--date= : Data de referência em AAAA-MM-DD}';

    protected $description = 'Reconcilia início, pausa e retomada dos estágios em campi ativos e notifica os discentes';

    public function handle(SyncInternshipExecutionStatus $sync): int
    {
        $dateValue = $this->option('date') ?? CarbonImmutable::today(config('app.timezone'))->toDateString();
        $validator = Validator::make(['date' => $dateValue], ['date' => ['required', 'date_format:Y-m-d']]);
        if ($validator->fails()) {
            $this->error('Informe --date no formato AAAA-MM-DD.');

            return self::FAILURE;
        }
        $date = CarbonImmutable::parse($dateValue, config('app.timezone'));
        $runId = (string) Str::uuid();
        $failed = false;
        $changed = 0;
        Internship::query()->whereIn('status', [InternshipStatus::Released, InternshipStatus::InProgress, InternshipStatus::Paused])
            ->whereHas('course.campus', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->with('course:id,campus_id')->eachById(function (Internship $target) use ($sync, $date, $runId, &$failed, &$changed): void {
                try {
                    $updated = DB::transaction(function () use ($target, $sync, $date, $runId): bool {
                        $campus = Campus::query()->lockForUpdate()->find($target->course->campus_id);
                        if ($campus === null || $campus->deactivated_at !== null) {
                            return false;
                        }
                        $internship = Internship::query()->lockForUpdate()->findOrFail($target->id);
                        if (! $internship->course()->where('campus_id', $campus->id)->exists()) {
                            return false;
                        }

                        return $sync->handle($internship, $date, ['source' => 'scheduled', 'command' => 'internships:sync-execution-status', 'run_id' => $runId]);
                    }, attempts: 3);
                    $changed += (int) $updated;
                } catch (Throwable $exception) {
                    $failed = true;
                    report($exception);
                    $this->error('Falha ao sincronizar o estágio '.$target->id.'. Consulte o log da aplicação.');
                }
            }, count: 100);
        $this->info($changed.' estágio(s) atualizado(s).');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
