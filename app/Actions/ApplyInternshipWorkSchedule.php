<?php

namespace App\Actions;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\InternshipWorkSchedule;
use App\Support\InternshipEndDateCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApplyInternshipWorkSchedule
{
    public function __construct(private ProjectInternshipEndDate $project) {}

    /**
     * O chamador mantém o estágio bloqueado e autoriza a alteração contratual.
     *
     * @param  array<string, mixed>  $data
     * @return array{starts_on: string, weekly_hours: array<string, int>, previous_schedule_id: ?int, projected_end_date: string}
     */
    public function proposal(Internship $internship, array $data): array
    {
        $rules = $internship->internship_type_snapshot['rules'];
        $values = Validator::make($data, [
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.CarbonImmutable::today(config('app.timezone'))->toDateString(),
                'after:'.$internship->planned_start_date->toDateString(), 'before_or_equal:'.$internship->planned_start_date->copy()->addYears(10)->toDateString()],
            'weekly_hours' => ['required', 'array:'.implode(',', InternshipEndDateCalculator::DAYS), 'size:7'],
            'weekly_hours.*' => ['required', 'integer', 'min:0', 'max:'.$rules['max_daily_hours']],
        ])->validate();
        $hours = array_map(fn (mixed $hours): int => (int) $hours, $values['weekly_hours']);
        if (array_sum($hours) < 1 || array_sum($hours) > $rules['max_weekly_hours']) {
            throw ValidationException::withMessages(['weekly_hours' => 'A nova jornada deve respeitar o limite semanal do tipo.']);
        }
        $previous = $internship->workSchedules()->orderByDesc('starts_on')->lockForUpdate()->first();
        if ($previous !== null && ($previous->ends_on !== null || $values['starts_on'] <= $previous->starts_on->toDateString())) {
            throw ValidationException::withMessages(['starts_on' => 'A nova jornada deve suceder a última vigência registrada.']);
        }
        $currentHours = $previous === null ? $internship->weekly_hours : $previous->weekly_hours;
        if ($hours == $currentHours) {
            throw ValidationException::withMessages(['weekly_hours' => 'Informe uma distribuição de horas diferente da jornada anterior.']);
        }
        $proposal = ['starts_on' => $values['starts_on'], 'weekly_hours' => $hours,
            'previous_schedule_id' => $previous?->id, 'projected_end_date' => ''];
        $candidate = clone $internship;
        $result = $this->project->details($candidate, includeCalendar: false, workSchedules: $this->schedules($internship, $proposal));
        if ($result['completion_date']->toDateString() < $values['starts_on']) {
            throw ValidationException::withMessages(['starts_on' => 'A nova jornada deve iniciar antes do cumprimento integral da carga prevista.']);
        }
        $proposal['projected_end_date'] = $result['projected_end_date']->toDateString();

        return $proposal;
    }

    /** @param array{starts_on: string, weekly_hours: array<string, int>, previous_schedule_id: ?int, projected_end_date: string} $proposal
     * @return list<array{starts_on: string, ends_on: ?string, weekly_hours: array<string, int>}>
     */
    public function schedules(Internship $internship, array $proposal): array
    {
        $schedules = $internship->workSchedules()->orderBy('starts_on')->get()->map(fn (InternshipWorkSchedule $schedule): array => [
            'starts_on' => $schedule->starts_on->toDateString(), 'ends_on' => $schedule->ends_on?->toDateString(), 'weekly_hours' => $schedule->weekly_hours,
        ])->values()->all();
        $previous = array_pop($schedules);
        if ($previous !== null) {
            $previous['ends_on'] = CarbonImmutable::parse($proposal['starts_on'])->subDay()->toDateString();
            $schedules[] = $previous;
        }
        $schedules[] = ['starts_on' => $proposal['starts_on'], 'ends_on' => null, 'weekly_hours' => $proposal['weekly_hours']];

        return array_values($schedules);
    }

    /** O chamador mantém estágio/documento bloqueados na transação da conferência. */
    public function handle(Internship $internship, GeneratedDocument $document): void
    {
        $proposal = $document->workScheduleChange();
        if ($proposal === null) {
            return;
        }
        abort_unless($document->internship_id === $internship->id && $document->type === GeneratedDocumentType::Addendum
            && $document->status === GeneratedDocumentStatus::Signed, 422);
        if ($internship->workSchedules()->where('generated_document_id', $document->id)->exists()) {
            return;
        }
        $previous = $internship->workSchedules()->orderByDesc('starts_on')->lockForUpdate()->first();
        if ($previous?->id !== $proposal['previous_schedule_id']) {
            throw ValidationException::withMessages(['document' => 'Outra jornada foi formalizada após a emissão deste aditivo. Cancele e emita um novo aditivo com os dados atuais.']);
        }
        $validated = $this->proposal($internship, $proposal);
        if ($validated['projected_end_date'] !== $proposal['projected_end_date']) {
            throw ValidationException::withMessages(['document' => 'A previsão mudou desde o registro do aditivo. Revise os dados e registre um novo documento antes de aplicar a jornada.']);
        }
        if ($previous !== null) {
            $previous->update(['ends_on' => CarbonImmutable::parse($proposal['starts_on'])->subDay()->toDateString()]);
        }
        $internship->workSchedules()->create(['starts_on' => $proposal['starts_on'], 'weekly_hours' => $proposal['weekly_hours'], 'generated_document_id' => $document->id]);
        $internship->projected_end_date = $this->project->handle($internship);
        $internship->setUpdatedAt($internship->freshTimestamp());
        $internship->save();
    }
}
