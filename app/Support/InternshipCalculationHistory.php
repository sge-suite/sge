<?php

namespace App\Support;

use App\Models\Internship;
use App\Models\InternshipRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

class InternshipCalculationHistory
{
    public function __construct(private InternshipEndDateCalculator $calculator) {}

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $blockedReasons
     * @return array<string, mixed>
     */
    public function snapshot(array $inputs, array $result, string $location, array $blockedReasons = []): array
    {
        $end = $result['projected_end_date']->toDateString();
        $inputs['blocked_dates'] = array_values(array_filter($inputs['blocked_dates'], fn (string $date): bool => $date <= $end));
        sort($inputs['blocked_dates']);
        $blockedReasons = array_intersect_key($blockedReasons, array_flip($inputs['blocked_dates']));
        ksort($blockedReasons);

        return ['schema_revision' => 1, 'calculator_revision' => InternshipEndDateCalculator::REVISION,
            'timezone' => config('app.timezone'), 'inputs' => $inputs, 'location' => $location, 'blocked_reasons' => $blockedReasons,
            'result' => ['completion_date' => $result['completion_date']->toDateString(),
                'projected_end_date' => $end, 'credited_hours' => $result['credited_hours']]];
    }

    public function record(Internship|InternshipRequest $record): void
    {
        $snapshot = $record->calculationSnapshot;
        $record->calculationSnapshot = null;
        if ($record instanceof Internship) {
            $record->calculationActivityId = null;
        }
        if ($snapshot === null) {
            return;
        }
        $hash = $this->fingerprint($snapshot);
        $previous = Activity::forSubject($record)->where('event', 'end_date_calculated')->latest('id')->first();
        if ($previous?->properties->get('calculation_hash') === $hash) {
            return;
        }
        $activity = activity()->performedOn($record)->event('end_date_calculated')
            ->withProperties(['calculation' => $snapshot, 'calculation_hash' => $hash])
            ->log('Previsão de término calculada');
        if ($record instanceof Internship && $activity instanceof Activity) {
            $record->calculationActivityId = (int) $activity->getKey();
        }
    }

    /** @return array<string, mixed> */
    public function replay(Activity $activity): array
    {
        $snapshot = $activity->properties->get('calculation');
        if (! is_array($snapshot) || ($snapshot['schema_revision'] ?? null) !== 1
            || ($snapshot['calculator_revision'] ?? null) !== InternshipEndDateCalculator::REVISION
            || $this->fingerprint($snapshot) !== $activity->properties->get('calculation_hash')) {
            throw ValidationException::withMessages(['calculation' => 'Este cálculo não possui uma base histórica compatível e íntegra.']);
        }
        $inputs = $snapshot['inputs'];
        $result = $this->calculator->handle(CarbonImmutable::parse($inputs['start'], $snapshot['timezone']),
            $inputs['required_hours'], $inputs['weekly_hours'], $inputs['blocked_dates'], $inputs['margin_days'], $inputs['work_schedules'], true);
        if ($result['projected_end_date']->toDateString() !== $snapshot['result']['projected_end_date']
            || $result['completion_date']->toDateString() !== $snapshot['result']['completion_date']
            || $result['credited_hours'] !== $snapshot['result']['credited_hours']) {
            throw ValidationException::withMessages(['calculation' => 'A reprodução não corresponde ao resultado preservado.']);
        }
        foreach ($result['calendar'] as &$day) {
            $day['reason'] = $snapshot['blocked_reasons'][$day['date']] ?? match ($day['kind']) {
                'work_day' => 'Dia de estágio', 'safety_margin' => 'Margem de segurança',
                'projected_end' => 'Término previsto', 'blocked' => 'Feriado, pausa ou exceção registrada', default => 'Sem jornada neste dia',
            };
        }
        unset($day);

        return [...$snapshot['result'], 'saved_projected_end_date' => $snapshot['result']['projected_end_date'],
            'margin_days' => $inputs['margin_days'], 'location' => $snapshot['location'], 'calendar' => $result['calendar']];
    }

    /** @param array<string, mixed> $snapshot */
    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            $item = $this->canonicalize($item);
        }
        unset($item);

        return $value;
    }
}
