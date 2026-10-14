<?php

namespace App\Http\Controllers;

use App\Actions\ManageHoliday;
use App\Http\Requests\HolidayRequest;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;

class HolidayController extends Controller
{
    public function __construct(private ManageHoliday $manage) {}

    public function store(HolidayRequest $request): RedirectResponse
    {
        $this->manage->handle($request->user(), null, 'create', $request->validated());

        return redirect()->route('holidays.index')->with('status', 'Feriado cadastrado.');
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $this->manage->handle($request->user(), $holiday, 'update', $request->validated());

        return redirect()->route('holidays.index')->with('status', 'Feriado atualizado; ajustes manuais serão preservados.');
    }

    public function destroy(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $this->manage->handle($request->user(), $holiday, 'delete');

        return redirect()->route('holidays.index')->with('status', 'Feriado excluído do calendário.');
    }

    public function restore(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $this->manage->handle($request->user(), $holiday, 'restore');

        return redirect()->route('holidays.trash')->with('status', 'Feriado restaurado.');
    }
}
