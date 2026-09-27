<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeactivateCampusRequest;
use App\Http\Requests\ReactivateCampusRequest;
use App\Http\Requests\StoreCampusRequest;
use App\Http\Requests\UpdateCampusRequest;
use App\Models\Address;
use App\Models\Campus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CampusController extends Controller
{
    public function store(StoreCampusRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated): void {
            Gate::authorize('create', Campus::class);
            $address = Address::create($validated['address']);
            unset($validated['address']);

            Campus::create([...$validated, 'address_id' => $address->getKey()]);
        });

        return redirect()->route('dashboard')->with('status', 'Campus criado com sucesso.');
    }

    public function update(UpdateCampusRequest $request, Campus $campus): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($campus, $validated): void {
            $campus = Campus::query()->lockForUpdate()->findOrFail($campus->getKey());
            Gate::authorize('update', $campus);
            $campus->assertWritable();

            $addressData = $validated['address'] ?? null;
            unset($validated['address']);

            if ($addressData !== null) {
                $address = Address::query()->lockForUpdate()->findOrFail($campus->address_id);

                if ($address->hasReferencesOutsideCampus($campus)) {
                    throw ValidationException::withMessages([
                        'address' => 'O endereço do campus também está associado a outro cadastro ou registro histórico.',
                    ]);
                }

                $address->update($addressData);
            }

            $campus->update($validated);
        });

        return redirect()->route('dashboard')->with('status', 'Campus atualizado com sucesso.');
    }

    public function deactivate(DeactivateCampusRequest $request, Campus $campus): RedirectResponse
    {
        DB::transaction(function () use ($campus): void {
            $campus = Campus::query()->lockForUpdate()->findOrFail($campus->getKey());
            Gate::authorize('deactivate', $campus);
            $campus->deactivate();
        });

        return redirect()->route('dashboard')->with('status', 'Campus desativado com sucesso.');
    }

    public function reactivate(ReactivateCampusRequest $request, Campus $campus): RedirectResponse
    {
        DB::transaction(function () use ($campus): void {
            $campus = Campus::query()->lockForUpdate()->findOrFail($campus->getKey());
            Gate::authorize('reactivate', $campus);
            $campus->reactivate();
        });

        return redirect()->route('dashboard')->with('status', 'Campus reativado com sucesso.');
    }
}
