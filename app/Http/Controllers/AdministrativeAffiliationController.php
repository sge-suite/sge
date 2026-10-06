<?php

namespace App\Http\Controllers;

use App\Actions\CreateAdministrativeAffiliation;
use App\Actions\UpdateAdministrativeAffiliation;
use App\Http\Requests\DeactivateAdministrativeAffiliationRequest;
use App\Http\Requests\DeleteAdministrativeAffiliationRequest;
use App\Http\Requests\ReactivateAdministrativeAffiliationRequest;
use App\Http\Requests\StoreAdministrativeAffiliationRequest;
use App\Http\Requests\UpdateAdministrativeAffiliationRequest;
use App\Models\Affiliation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AdministrativeAffiliationController extends Controller
{
    public function store(StoreAdministrativeAffiliationRequest $request, User $user, CreateAdministrativeAffiliation $create): RedirectResponse
    {
        $create->handle($request->validated(), $request->user(), $user);

        return redirect()->route('users.show', $user)->with('status', 'Vínculo administrativo cadastrado com sucesso.');
    }

    public function update(UpdateAdministrativeAffiliationRequest $request, User $user, Affiliation $affiliation, UpdateAdministrativeAffiliation $update): RedirectResponse
    {
        $update->handle($request->user(), $user, $affiliation, 'update', $request->validated());

        return redirect()->route('users.show', $user)->with('status', 'Vínculo atualizado com sucesso.');
    }

    public function destroy(DeleteAdministrativeAffiliationRequest $request, User $user, Affiliation $affiliation, UpdateAdministrativeAffiliation $update): RedirectResponse
    {
        try {
            $update->handle($request->user(), $user, $affiliation, 'delete', $request->validated());
        } catch (ValidationException $exception) {
            $exception->redirectTo(route('users.show', ['user' => $user, 'affiliation' => $affiliation->id, 'operation' => 'delete']));
            throw $exception;
        }

        return redirect()->route('users.show', $user)->with('status', 'Vínculo excluído. Os avisos foram enfileirados.');
    }

    public function deactivate(DeactivateAdministrativeAffiliationRequest $request, User $user, Affiliation $affiliation, UpdateAdministrativeAffiliation $update): RedirectResponse
    {
        try {
            $update->handle($request->user(), $user, $affiliation, 'deactivate', $request->validated());
        } catch (ValidationException $exception) {
            $exception->redirectTo(route('users.show', ['user' => $user, 'affiliation' => $affiliation->id, 'operation' => 'deactivate']));
            throw $exception;
        }

        return redirect()->route('users.show', $user)->with('status', 'Vínculo desativado com sucesso.');
    }

    public function reactivate(ReactivateAdministrativeAffiliationRequest $request, User $user, Affiliation $affiliation, UpdateAdministrativeAffiliation $update): RedirectResponse
    {
        try {
            $update->handle($request->user(), $user, $affiliation, 'reactivate', $request->validated());
        } catch (ValidationException $exception) {
            $exception->redirectTo(route('users.show', ['user' => $user, 'affiliation' => $affiliation->id, 'operation' => 'reactivate']));
            throw $exception;
        }

        return redirect()->route('users.show', $user)->with('status', 'Vínculo reativado com sucesso.');
    }
}
