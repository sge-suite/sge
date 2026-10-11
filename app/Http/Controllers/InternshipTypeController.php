<?php

namespace App\Http\Controllers;

use App\Actions\ManageInternshipType;
use App\Http\Requests\DeactivateInternshipTypeRequest;
use App\Http\Requests\ReactivateInternshipTypeRequest;
use App\Http\Requests\StoreInternshipTypeRequest;
use App\Http\Requests\UpdateInternshipTypeRequest;
use App\Models\InternshipType;
use Illuminate\Http\RedirectResponse;

class InternshipTypeController extends Controller
{
    public function __construct(private ManageInternshipType $manageInternshipType) {}

    public function store(StoreInternshipTypeRequest $request): RedirectResponse
    {
        $internshipType = $this->manageInternshipType->handle($request->user(), null, 'create', $request->validated());

        return redirect()->route('internship-types.show', $internshipType)->with('status', 'Tipo de estágio cadastrado com sucesso.');
    }

    public function update(UpdateInternshipTypeRequest $request, InternshipType $internshipType): RedirectResponse
    {
        $this->manageInternshipType->handle($request->user(), $internshipType, 'update', $request->validated());

        return redirect()->route('internship-types.show', $internshipType)->with('status', 'Tipo de estágio atualizado com sucesso.');
    }

    public function deactivate(DeactivateInternshipTypeRequest $request, InternshipType $internshipType): RedirectResponse
    {
        $this->manageInternshipType->handle($request->user(), $internshipType, 'deactivate', $request->validated());

        return redirect()->route('internship-types.show', $internshipType)->with('status', 'Tipo de estágio desativado com sucesso.');
    }

    public function reactivate(ReactivateInternshipTypeRequest $request, InternshipType $internshipType): RedirectResponse
    {
        $this->manageInternshipType->handle($request->user(), $internshipType, 'reactivate');

        return redirect()->route('internship-types.show', $internshipType)->with('status', 'Tipo de estágio reativado com sucesso.');
    }
}
