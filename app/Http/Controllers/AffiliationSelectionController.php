<?php

namespace App\Http\Controllers;

use App\Support\ActiveAffiliationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AffiliationSelectionController extends Controller
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $affiliations = $this->context->availableFor($user);

        abort_if($affiliations->isEmpty(), 403, 'Nenhum vínculo ativo está disponível para esta conta.');

        return view('affiliations.select', [
            'affiliations' => $affiliations,
            'currentAffiliation' => $this->context->resolve($user, $request->session()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['affiliation_id' => ['required', 'integer']]);
        $affiliation = $this->context->select($request->user(), $request->session(), $validated['affiliation_id']);

        abort_if($affiliation === null, 404);

        return redirect()->route('dashboard');
    }
}
