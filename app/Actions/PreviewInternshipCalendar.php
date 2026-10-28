<?php

namespace App\Actions;

use App\Models\City;
use App\Models\Internship;
use App\Models\InternshipType;
use App\Models\User;
use App\Support\InternshipCalendarOverrides;
use App\Support\InternshipEndDateCalculator;
use App\Support\InternshipPlannedPauses;
use App\Support\InternshipRequestSnapshots;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PreviewInternshipCalendar
{
    public function __construct(private ProjectInternshipEndDate $project, private InternshipRequestSnapshots $snapshots, private InternshipPlannedPauses $plannedPauses, private InternshipCalendarOverrides $overrides) {}

    /**
     * @param  array<string, mixed>|null  $data
     * @return array{completion_date: string, projected_end_date: string, saved_projected_end_date: string, credited_hours: int, margin_days: int, location: string, calendar: list<array{date: string, scheduled_hours: int, credited_hours: int, accumulated_hours: int, kind: string, reason?: string}>}
     */
    public function handle(User $actor, Internship $target, ?array $data = null, bool $includeCalendar = true): array
    {
        Gate::forUser($actor)->authorize($data === null ? 'view' : 'update', $target);

        return DB::transaction(function () use ($actor, $target, $data, $includeCalendar): array {
            $internship = Internship::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize($data === null ? 'view' : 'update', $internship);
            $candidate = clone $internship;
            $overrides = null;
            $city = $internship->workplaceAddress()->with('city')->first()?->city;
            if ($data !== null) {
                $values = Validator::make($data, [
                    'internship_type_id' => ['required', 'integer', 'min:1'],
                    'rules' => ['sometimes', 'required', 'array'],
                    'calendar_overrides' => ['sometimes', 'array'],
                    'planned_start_date' => ['required', 'date_format:Y-m-d'],
                    'city_id' => ['required', 'integer', 'min:1'],
                    'weekly_hours' => ['required', 'array:'.implode(',', InternshipEndDateCalculator::DAYS), 'size:7'],
                    'weekly_hours.*' => ['required', 'integer', 'min:0', 'max:24'],
                ] + $this->plannedPauses->rules(complete: true))->validate();
                $values['weekly_hours'] = array_map(fn (mixed $hours): int => (int) $hours, $values['weekly_hours']);
                if (! Gate::forUser($actor)->allows('updateAgreement', $internship)) {
                    foreach (['internship_type_id' => $internship->internship_type_id, 'planned_start_date' => $internship->planned_start_date->toDateString(),
                        'city_id' => $city?->id, 'weekly_hours' => $internship->weekly_hours, 'planned_pauses' => $internship->planned_pauses ?? []] as $field => $original) {
                        if (($values[$field] ?? []) != $original) {
                            throw ValidationException::withMessages([$field => 'Este dado já foi formalizado e não pode ser reescrito pela edição do estágio.']);
                        }
                    }
                }
                if ((int) $values['internship_type_id'] !== $internship->internship_type_id) {
                    $type = InternshipType::query()->where('course_id', $internship->course_id)->whereNull('deactivated_at')
                        ->whereKey((int) $values['internship_type_id'])->lockForUpdate()->first();
                    if ($type === null) {
                        throw ValidationException::withMessages(['internship_type_id' => 'Selecione um tipo disponível do curso do discente.']);
                    }
                    $candidate->internship_type_snapshot = $this->snapshots->type($type);
                }
                if (isset($values['rules'])) {
                    $candidate->internship_type_snapshot = $this->snapshots->withTypeRules($candidate->internship_type_snapshot, $values['rules']);
                }
                $rules = $candidate->internship_type_snapshot['rules'] ?? [];
                $limit = (int) ($rules['max_daily_hours'] ?? 0);
                Validator::make(['weekly_hours' => $values['weekly_hours']], ['weekly_hours.*' => ['integer', 'min:0', 'max:'.$limit]])->validate();
                if (array_sum($values['weekly_hours']) === 0 || array_sum($values['weekly_hours']) > (int) ($rules['max_weekly_hours'] ?? 0)) {
                    throw ValidationException::withMessages(['weekly_hours' => 'A jornada deve ter horas e respeitar o limite semanal do tipo selecionado.']);
                }
                $candidate->fill(['planned_start_date' => $values['planned_start_date'], 'weekly_hours' => $values['weekly_hours'], 'planned_pauses' => $values['planned_pauses'] ?? []]);
                if (array_key_exists('calendar_overrides', $values)) {
                    $overrides = $this->overrides->validate($candidate, $values['calendar_overrides']);
                    if ($overrides !== $this->overrides->values($internship)) {
                        Gate::forUser($actor)->authorize('manageCalendar', $internship);
                    }
                }
                $city = City::query()->find((int) $values['city_id']);
            }
            if ($city === null) {
                throw ValidationException::withMessages(['city_id' => 'Selecione um município válido para o local de estágio.']);
            }
            $result = $this->project->details($candidate, $city, $includeCalendar, $overrides,
                validateStartDate: $data !== null && Gate::forUser($actor)->allows('updateAgreement', $internship));

            return ['completion_date' => $result['completion_date']->toDateString(), 'projected_end_date' => $result['projected_end_date']->toDateString(),
                'saved_projected_end_date' => $internship->projected_end_date->toDateString(), 'credited_hours' => $result['credited_hours'],
                'margin_days' => (int) $candidate->internship_type_snapshot['rules']['safety_margin_days'], 'location' => $city->name.' / '.$city->state->value,
                'calendar' => $result['calendar']];
        });
    }
}
