<?php

namespace App\Support;

use App\Models\Internship;
use App\Models\InternshipCalendarOverride;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InternshipCalendarOverrides
{
    /** @return list<array{id: int, date: string, is_working_day: bool, reason: string}> */
    public function values(Internship $internship): array
    {
        return array_values($internship->calendarOverrides()->orderBy('date')->orderBy('id')->get()
            ->map(fn (InternshipCalendarOverride $override): array => ['id' => $override->id, 'date' => $override->date->toDateString(),
                'is_working_day' => $override->is_working_day, 'reason' => $override->reason])->all());
    }

    /** @return list<array{id: ?int, date: string, is_working_day: bool, reason: string}> */
    public function validate(Internship $internship, mixed $data, ?string $plannedStart = null): array
    {
        $start = $plannedStart ?? $internship->planned_start_date->toDateString();
        $end = CarbonImmutable::parse($start, config('app.timezone'))->addYears(10)->toDateString();
        $validated = Validator::make(['calendar_overrides' => $data], [
            'calendar_overrides' => ['present', 'array', 'list', 'max:3661'],
            'calendar_overrides.*' => ['array:id,date,is_working_day,reason'],
            'calendar_overrides.*.id' => ['present', 'nullable', 'integer', 'min:1'],
            'calendar_overrides.*.date' => ['required', 'date_format:Y-m-d', 'distinct', 'after_or_equal:'.$start, 'before_or_equal:'.$end],
            'calendar_overrides.*.is_working_day' => ['required', 'boolean'],
            'calendar_overrides.*.reason' => ['required', 'string', 'max:1000'],
        ], attributes: ['calendar_overrides' => 'exceções do calendário', 'calendar_overrides.*.date' => 'data da exceção',
            'calendar_overrides.*.reason' => 'justificativa da exceção', 'calendar_overrides.*.is_working_day' => 'expediente liberado'])->validate();
        $current = $this->values($internship);
        $ids = array_column($current, 'id');
        $providedIds = [];
        $dates = array_column($current, 'id', 'date');
        $rows = [];
        foreach ($validated['calendar_overrides'] as $index => $row) {
            $id = $row['id'] === null ? null : (int) $row['id'];
            if ($id !== null) {
                if (! in_array($id, $ids, true) || in_array($id, $providedIds, true)) {
                    throw ValidationException::withMessages(['calendar_overrides.'.$index.'.id' => 'Selecione uma exceção deste estágio, sem repetir registros.']);
                }
                $providedIds[] = $id;
            }
            if (isset($dates[$row['date']]) && $dates[$row['date']] !== $id) {
                throw ValidationException::withMessages(['calendar_overrides.'.$index.'.date' => 'Esta data já possui uma exceção. Edite o registro existente.']);
            }
            $rows[] = ['id' => $id, 'date' => $row['date'], 'is_working_day' => (bool) $row['is_working_day'], 'reason' => trim($row['reason'])];
        }
        if (array_diff($ids, $providedIds) !== []) {
            throw ValidationException::withMessages(['calendar_overrides' => 'As exceções já registradas devem ser preservadas. Recarregue o formulário para consultar os registros atuais.']);
        }

        return $rows;
    }

    /** @param list<array{id: ?int, date: string, is_working_day: bool, reason: string}> $rows */
    public function sync(Internship $internship, array $rows): bool
    {
        $current = $internship->calendarOverrides()->lockForUpdate()->get()->keyBy('id');
        $changed = false;
        foreach ($rows as $row) {
            $override = $row['id'] === null ? $internship->calendarOverrides()->make() : $current->get($row['id']);
            abort_if($override === null, 409);
            $override->fill(['date' => $row['date'], 'is_working_day' => $row['is_working_day'], 'reason' => $row['reason']]);
            if (! $override->exists || $override->isDirty()) {
                $override->save();
                $changed = true;
            }
        }

        return $changed;
    }
}
