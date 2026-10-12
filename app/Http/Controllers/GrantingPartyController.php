<?php

namespace App\Http\Controllers;

use App\Actions\ManageGrantingParty;
use App\Http\Requests\DeactivateGrantingPartyRequest;
use App\Http\Requests\ReactivateGrantingPartyRequest;
use App\Http\Requests\StoreGrantingPartyRequest;
use App\Http\Requests\UpdateGrantingPartyRequest;
use App\Models\GrantingParty;
use Illuminate\Http\RedirectResponse;

class GrantingPartyController extends Controller
{
    public function __construct(private ManageGrantingParty $manageGrantingParty) {}

    public function store(StoreGrantingPartyRequest $request): RedirectResponse
    {
        $grantingParty = $this->manageGrantingParty->handle($request->user(), null, 'create', $request->validated());

        return redirect()->route('granting-parties.show', $grantingParty)->with('status', 'Parte concedente cadastrado com sucesso.');
    }

    public function update(UpdateGrantingPartyRequest $request, GrantingParty $grantingParty): RedirectResponse
    {
        $this->manageGrantingParty->handle($request->user(), $grantingParty, 'update', $request->validated());

        return redirect()->route('granting-parties.show', $grantingParty)->with('status', 'Parte concedente atualizado com sucesso.');
    }

    public function deactivate(DeactivateGrantingPartyRequest $request, GrantingParty $grantingParty): RedirectResponse
    {
        $this->manageGrantingParty->handle($request->user(), $grantingParty, 'deactivate', $request->validated());

        return redirect()->route('granting-parties.show', $grantingParty)->with('status', 'Parte concedente desativado com sucesso.');
    }

    public function reactivate(ReactivateGrantingPartyRequest $request, GrantingParty $grantingParty): RedirectResponse
    {
        $this->manageGrantingParty->handle($request->user(), $grantingParty, 'reactivate');

        return redirect()->route('granting-parties.show', $grantingParty)->with('status', 'Parte concedente reativado com sucesso.');
    }
}
