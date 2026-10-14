<?php

namespace App\Http\Controllers;

use App\Actions\ResolveHolidaySource;
use App\Http\Requests\ResolveHolidaySourceRequest;
use App\Models\HolidaySource;
use Illuminate\Http\RedirectResponse;

class HolidayImportController extends Controller
{
    public function resolve(ResolveHolidaySourceRequest $request, HolidaySource $holidaySource, ResolveHolidaySource $resolve): RedirectResponse
    {
        $resolve->handle($request->user(), $holidaySource, $request->validated());

        return redirect()->route('holidays.index')->with('status', 'Pendência resolvida e decisão registrada.');
    }
}
