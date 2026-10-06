<?php

namespace App\Http\Controllers;

use App\Actions\CreateAdministrativeAffiliation;
use App\Actions\ManageAdministrativeUser;
use App\Http\Requests\DeleteAdministrativeUserRequest;
use App\Http\Requests\StoreAdministrativeUserRequest;
use App\Http\Requests\UpdateAdministrativeUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function update(UpdateAdministrativeUserRequest $request, User $user, ManageAdministrativeUser $manage): RedirectResponse
    {
        $manage->handle($request->user(), $user, 'update', $request->validated());

        return redirect()->route('users.show', $user)->with('status', 'Dados de acesso atualizados com sucesso.');
    }

    public function destroy(DeleteAdministrativeUserRequest $request, User $user, ManageAdministrativeUser $manage): RedirectResponse
    {
        try {
            $manage->handle($request->user(), $user, 'delete', $request->validated());
        } catch (ValidationException $exception) {
            $exception->redirectTo(route('users.show', ['user' => $user, 'operation' => 'delete_account']));
            throw $exception;
        }

        return redirect()->route('users.index')->with('status', 'Conta excluída. Os avisos foram enfileirados.');
    }

    public function store(StoreAdministrativeUserRequest $request, CreateAdministrativeAffiliation $create): RedirectResponse
    {
        $affiliation = $create->handle($request->safe()->except('consulted_cpf'), $request->user());

        return redirect()->route('users.show', $affiliation->user_id)->with('status', 'Vínculo administrativo cadastrado com sucesso. Os avisos foram enfileirados.');
    }
}
