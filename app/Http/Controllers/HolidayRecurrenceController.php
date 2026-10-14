<?php

namespace App\Http\Controllers;

use App\Actions\ManageHolidayRecurrence;
use App\Http\Requests\HolidayRecurrenceRequest;
use App\Models\HolidayRecurrence;
use Illuminate\Http\RedirectResponse;

class HolidayRecurrenceController extends Controller
{
    public function __construct(private ManageHolidayRecurrence $manage) {}

    public function store(HolidayRecurrenceRequest $request): RedirectResponse
    {
        $this->manage->handle($request->user(), null, $request->validated());

        return redirect()->route('holiday-recurrences.index')->with('status', 'Recorrência cadastrada e calendário preparado.');
    }

    public function update(HolidayRecurrenceRequest $request, HolidayRecurrence $holidayRecurrence): RedirectResponse
    {
        $this->manage->handle($request->user(), $holidayRecurrence, $request->validated());

        return redirect()->route('holiday-recurrences.index')->with('status', 'Recorrência atualizada. Datas futuras sem ajustes manuais foram atualizadas.');
    }

    public function destroy(HolidayRecurrenceRequest $request, HolidayRecurrence $holidayRecurrence): RedirectResponse
    {
        $this->manage->handle($request->user(), $holidayRecurrence, operation: 'delete');

        return redirect()->route('holiday-recurrences.index')->with('status', 'Recorrência movida para a lixeira. As datas já geradas foram mantidas.');
    }

    public function restore(HolidayRecurrenceRequest $request, HolidayRecurrence $holidayRecurrence): RedirectResponse
    {
        $this->manage->handle($request->user(), $holidayRecurrence, operation: 'restore');

        return redirect()->route('holiday-recurrences.trash')->with('status', 'Recorrência restaurada. Ocorrências excluídas continuam na lixeira de feriados.');
    }
}
