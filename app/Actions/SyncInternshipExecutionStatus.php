<?php

namespace App\Actions;

use App\Enums\InternshipStatus;
use App\Models\Internship;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Enums\ActivityEvent;

class SyncInternshipExecutionStatus
{
    public function __construct(private NotifyInternshipExecutionStatus $notify) {}

    /**
     * O chamador mantém o campus e o estágio bloqueados na mesma transação.
     *
     * @param  array<string, mixed>  $audit
     */
    public function handle(Internship $internship, CarbonImmutable $date, array $audit = []): bool
    {
        if (! in_array($internship->status, [InternshipStatus::Released, InternshipStatus::InProgress, InternshipStatus::Paused], true)) {
            return false;
        }
        $day = $date->toDateString();
        $previousStatus = $internship->status;
        $changed = false;
        if ($internship->status === InternshipStatus::Released) {
            if ($internship->planned_start_date->toDateString() > $day) {
                return false;
            }
            $internship->status = InternshipStatus::InProgress;
            $this->saveTransition($internship, $date, $audit);
            $changed = true;
        }
        $activePause = $internship->pauses()->where('starts_at', '<=', $day)->where('ends_at', '>=', $day)->first();
        $paused = $activePause !== null;
        foreach ($internship->planned_pauses ?? [] as $pause) {
            $paused = $paused || ($pause['starts_at'] <= $day && $pause['ends_at'] >= $day);
        }
        $status = $paused ? InternshipStatus::Paused : InternshipStatus::InProgress;
        if ($internship->status !== $status) {
            $internship->status = $status;
            $this->saveTransition($internship, $date, $audit === [] ? [] : [...$audit, 'internship_pause_id' => $activePause?->id]);
            $changed = true;
        }
        if ($changed) {
            $this->notify->handle($internship, $previousStatus, $date);
        }

        return $changed;
    }

    /** @param array<string, mixed> $audit */
    private function saveTransition(Internship $internship, CarbonImmutable $date, array $audit): void
    {
        $previous = $internship->getRawOriginal('status');
        if ($audit === []) {
            $internship->save();

            return;
        }
        activity()->withoutLogging(fn (): bool => $internship->save());
        activity()->performedOn($internship)->causedByAnonymous()->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => ['status' => $internship->status->value], 'old' => ['status' => $previous]])
            ->withProperties([...$audit, 'reference_date' => $date->toDateString()])->log('updated');
    }
}
