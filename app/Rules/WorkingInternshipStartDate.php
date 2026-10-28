<?php

namespace App\Rules;

use App\Support\InternshipEndDateCalculator;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class WorkingInternshipStartDate implements ValidationRule
{
    /**
     * @param  array<string, mixed>  $weeklyHours
     * @param  array<string, string>  $blockedDates
     */
    public function __construct(private array $weeklyHours, private array $blockedDates) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Informe uma data de início válida.');

            return;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (InvalidFormatException) {
            $date = null;
        }
        if ($date === null || $date->toDateString() !== $value) {
            $fail('Informe uma data de início válida.');

            return;
        }
        if ((int) ($this->weeklyHours[InternshipEndDateCalculator::DAYS[$date->dayOfWeek]] ?? 0) < 1) {
            $fail('Escolha uma data de início em um dia com horas previstas na jornada semanal.');

            return;
        }
        if (isset($this->blockedDates[$value])) {
            $fail('A data de início não está disponível: '.$this->blockedDates[$value].'. Escolha um dia com atividades de estágio.');
        }
    }
}
