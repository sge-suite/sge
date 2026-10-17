<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

class InternshipPlannedPauses
{
    public const MAX_PERIODS = 20;

    /** @return array<string, list<mixed>> */
    public function rules(bool $complete = false): array
    {
        $presence = $complete ? 'required' : 'nullable';

        return [
            'planned_pauses' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_PERIODS],
            'planned_pauses.*' => ['array:starts_at,ends_at,reason'],
            'planned_pauses.*.starts_at' => ['bail', $presence, 'date_format:Y-m-d'],
            'planned_pauses.*.ends_at' => ['bail', $presence, 'date_format:Y-m-d'],
            'planned_pauses.*.reason' => [$presence, 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return ['planned_pauses' => 'pausas previstas', 'planned_pauses.*.starts_at' => 'início da pausa',
            'planned_pauses.*.ends_at' => 'fim da pausa', 'planned_pauses.*.reason' => 'motivo da pausa'];
    }

    public function validate(mixed $pauses, ?string $plannedStart, bool $complete = false): void
    {
        $validator = Validator::make(['planned_pauses' => $pauses], $this->rules($complete), attributes: $this->labels());
        $validator->after(function (LaravelValidator $validator) use ($pauses, $plannedStart): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $periods = [];
            foreach ($pauses ?? [] as $key => $pause) {
                if (empty($pause['starts_at'])) {
                    continue;
                }
                $start = $pause['starts_at'];
                if ($plannedStart !== null && $start < $plannedStart) {
                    $validator->errors()->add('planned_pauses.'.$key.'.starts_at', 'A pausa deve começar na data de início do estágio ou depois.');
                }
                if (empty($pause['ends_at'])) {
                    continue;
                }
                if ($pause['ends_at'] < $start) {
                    $validator->errors()->add('planned_pauses.'.$key.'.ends_at', 'O fim da pausa deve ser na data de início da pausa ou depois.');

                    continue;
                }
                if ($plannedStart !== null && $pause['ends_at'] > CarbonImmutable::parse($plannedStart)->addYears(10)->toDateString()) {
                    $validator->errors()->add('planned_pauses.'.$key.'.ends_at', 'A pausa deve estar dentro dos dez anos após o início do estágio.');
                }
                foreach ($periods as $period) {
                    if ($start <= $period['ends_at'] && $pause['ends_at'] >= $period['starts_at']) {
                        $validator->errors()->add('planned_pauses.'.$key.'.starts_at', 'Este período se sobrepõe a outra pausa. Ajuste as datas.');
                        break;
                    }
                }
                $periods[] = $pause;
            }
        });
        $validator->validate();
    }

    /**
     * @param  array<string|int, array{starts_at: string, ends_at: string, reason: string}>  $pauses
     * @return list<string>
     */
    public function blockedDates(array $pauses, string $plannedStart): array
    {
        $this->validate($pauses, $plannedStart, complete: true);
        $dates = [];
        foreach ($pauses as $pause) {
            $end = CarbonImmutable::parse($pause['ends_at']);
            for ($day = CarbonImmutable::parse($pause['starts_at']); $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }
}
