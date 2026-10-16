<?php

namespace App\Http\Controllers;

use App\Actions\UpdateOwnPersonalData;
use App\Http\Requests\UpdateOwnPersonalDataRequest;
use Illuminate\Http\RedirectResponse;

class OwnPersonalDataController extends Controller
{
    public function update(UpdateOwnPersonalDataRequest $request, UpdateOwnPersonalData $update): RedirectResponse
    {
        $data = $request->validated();
        $expectedAffiliationId = (int) $data['context_affiliation_id'];
        unset($data['context_affiliation_id']);
        $update->handle($request->user(), $expectedAffiliationId, $data);

        return to_route('personal-data.edit')->with('status', 'Dados pessoais salvos.');
    }
}
