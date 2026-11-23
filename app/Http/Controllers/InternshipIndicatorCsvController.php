<?php

namespace App\Http\Controllers;

use App\Models\Internship;
use App\Support\CsvDownload;
use App\Support\InternshipIndicators;
use App\Support\StudentInternshipContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InternshipIndicatorCsvController extends Controller
{
    public function __invoke(Request $request, StudentInternshipContext $context, InternshipIndicators $indicators, CsvDownload $csv): StreamedResponse
    {
        Gate::authorize('viewIndicators', Internship::class);
        $affiliation = $context->selected($request->user());
        abort_unless($affiliation !== null && in_array($request->query('affiliation', $affiliation->id), [$affiliation->id, (string) $affiliation->id], true), 403);

        $validator = $indicators->validator($affiliation, $request->query());
        abort_if($validator->fails(), 422, $validator->errors()->first());
        $filters = $validator->validated();
        $metric = $filters['metric'];
        $scope = $filters['scope'] ?? 'details';
        $dataset = $indicators->dataset($affiliation, $filters);

        if ($scope === 'summary') {
            $rows = $indicators->summary($dataset, $metric)
                ->map(fn (stdClass $item): array => $indicators->summaryCells($item, $metric));

            return $csv->response(
                'indicador-resumo-'.str_replace('_', '-', $metric).'.csv',
                $indicators->summaryHeadings($metric),
                $rows,
            );
        }

        $rows = $indicators->details($dataset, $metric, $filters['group'] ?? '')
            ->lazy(500)
            ->map(fn (stdClass $row): array => $indicators->cells($row, $metric));

        return $csv->response(
            'indicador-registros-'.str_replace('_', '-', $metric).'.csv',
            $indicators->headings($metric),
            $rows,
        );
    }
}
