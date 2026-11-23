<?php

namespace App\Http\Controllers;

use App\Enums\PartyDocumentType;
use App\Models\Internship;
use App\Support\CsvDownload;
use App\Support\InternshipListing;
use App\Support\SearchQuery;
use App\Support\SearchUnavailable;
use App\Support\StudentInternshipContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InternshipCsvController extends Controller
{
    public function __invoke(Request $request, StudentInternshipContext $context, InternshipListing $listing, SearchQuery $search, CsvDownload $csv): StreamedResponse
    {
        Gate::authorize('exportCsv', Internship::class);
        $affiliation = $context->selected($request->user());
        abort_unless($affiliation !== null && in_array($request->query('affiliation', $affiliation->id), [$affiliation->id, (string) $affiliation->id], true), 403);

        $validator = $listing->validator($request->query());
        abort_if($validator->fails(), 422, $validator->errors()->first());

        try {
            $query = $search->run(fn () => $listing->query($affiliation, $validator->validated(), coordinatedOnly: true))
                ->select(['id', 'course_id', 'advisor_affiliation_id', 'student_snapshot', 'granting_party_snapshot',
                    'supervisor_snapshot', 'internship_type_snapshot', 'planned_start_date', 'projected_end_date',
                    'status', 'supervisor_grade', 'report_grade', 'presentation_grade', 'consolidated_grade'])
                ->with(['course:id,name', 'advisorAffiliation:id,user_id,campus_id', 'advisorAffiliation.user:id,name']);
        } catch (SearchUnavailable) {
            abort(503, 'A busca está indisponível. Tente novamente para baixar o CSV com os filtros aplicados.');
        }

        $rows = $query->lazy(500)->map(function (Internship $internship) use ($affiliation): array {
            $advisor = $internship->advisorAffiliation;
            $canViewAdvisorGrades = $advisor?->user_id === $affiliation->user_id && $advisor->campus_id === $affiliation->campus_id;

            return $this->row($internship, $canViewAdvisorGrades);
        });

        return $csv->response('estagios-coordenacao.csv', [
            'Curso', 'Matrícula', 'Nome do Aluno', 'Concedente', 'CNPJ da Concedente',
            'Data de Início', 'Data de Fim', 'Orientador', 'Supervisor', 'Status',
            'Nota do Supervisor', 'Nota do Relatório', 'Nota da Apresentação', 'Nota Final',
        ], $rows);
    }

    /** @return list<string|float> */
    private function row(Internship $internship, bool $canViewAdvisorGrades): array
    {
        $party = $internship->granting_party_snapshot ?? [];
        $rules = $internship->internship_type_snapshot['rules'] ?? [];

        return [
            $this->text($internship->course?->name),
            $this->text($internship->student_snapshot['registration_number'] ?? null),
            $this->text($internship->student_snapshot['name'] ?? null),
            $this->text($party['name'] ?? null),
            ($party['document_type'] ?? null) === PartyDocumentType::CNPJ->value ? $this->text($party['document_number'] ?? null) : '',
            $internship->getRawOriginal('planned_start_date') === null ? '' : formatShort($internship->planned_start_date),
            $internship->getRawOriginal('projected_end_date') === null ? '' : formatShort($internship->projected_end_date),
            $this->text($internship->advisorAffiliation?->user?->name),
            $this->text($internship->supervisor_snapshot['name'] ?? null),
            $internship->status->label(),
            $this->grade($internship->supervisor_grade, $rules['supervisor_evaluation_weight'] ?? null),
            $canViewAdvisorGrades ? $this->grade($internship->report_grade, $rules['report_weight'] ?? null) : '',
            $canViewAdvisorGrades ? $this->grade($internship->presentation_grade, $rules['presentation_weight'] ?? null) : '',
            $internship->consolidated_grade ?? '',
        ];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function grade(string|float|null $grade, mixed $weight): string|float
    {
        return in_array($weight, [0, '0'], true) ? '' : ($grade ?? '');
    }
}
