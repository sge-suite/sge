<?php

namespace App\Http\Controllers;

use App\Helpers\BrazilianContactHelper;
use App\Models\GrantingParty;
use App\Support\ActiveAffiliationContext;
use App\Support\CsvDownload;
use App\Support\GrantingPartyListing;
use App\Support\SearchQuery;
use App\Support\SearchUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GrantingPartyCsvController extends Controller
{
    public function __invoke(
        Request $request,
        ActiveAffiliationContext $context,
        GrantingPartyListing $listing,
        SearchQuery $search,
        CsvDownload $csv,
    ): StreamedResponse {
        Gate::authorize('viewAny', GrantingParty::class);
        $affiliation = $context->currentFor($request->user(), app('session.store'));
        abort_unless(
            $affiliation !== null && in_array($request->query('affiliation', $affiliation->id), [$affiliation->id, (string) $affiliation->id], true),
            403,
        );

        $validator = $listing->validator($request->query());
        abort_if($validator->fails(), 422, $validator->errors()->first());

        try {
            $query = $search->run(fn () => $listing->exportQuery($affiliation, $validator->validated()));
        } catch (SearchUnavailable) {
            abort(503, 'A busca está indisponível. Tente novamente para baixar o CSV com os filtros aplicados.');
        }

        $rows = $query->lazy(500)->map(function (GrantingParty $party): array {
            $city = $party->address?->city;

            return [
                (string) $party->name,
                $city !== null ? $city->name.' / '.$city->state->value : '',
                $party->phone !== null && $party->phone !== '' ? BrazilianContactHelper::formatPhone($party->phone) : '',
                (string) ($party->email ?? ''),
            ];
        });

        return $csv->response('partes-concedentes.csv', [
            'Nome/Razão Social',
            'Cidade',
            'Telefone',
            'Email',
        ], $rows);
    }
}
