<?php

namespace App\Actions;

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Internship;
use App\Models\User;
use App\Support\InternshipGradeCalculator;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Support\CauserResolver;

class UpdateAdvisorGrades
{
    public function __construct(private StudentInternshipContext $context, private InternshipGradeCalculator $calculator,
        private CauserResolver $causerResolver, private NotifyInternshipEvaluationResults $results) {}

    /** @param array{report_grade: ?string, presentation_grade: ?string} $data */
    public function handle(User $actor, Internship $target, int $expectedAffiliationId, array $data): Internship
    {
        Gate::forUser($actor)->authorize('updateAdvisorGrades', $target);

        return DB::transaction(function () use ($actor, $target, $expectedAffiliationId, $data): Internship {
            abort_unless($this->context->selected($actor)?->id === $expectedAffiliationId, 403, 'O vínculo selecionado mudou. Reabra o estágio.');
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($expectedAffiliationId);
            abort_unless($affiliation->user_id === $actor->id && $affiliation->deactivated_at === null, 403);
            Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id)->assertWritable();
            $internship = Internship::query()->lockForUpdate()->findOrFail($target->id);
            $advisor = $internship->advisorAffiliation()->lockForUpdate()->firstOrFail();
            $internship->setRelation('advisorAffiliation', $advisor);
            Gate::forUser($actor)->authorize('updateAdvisorGrades', $internship);
            $weights = $this->calculator->weights($internship->internship_type_snapshot['rules'] ?? []);
            $normalized = array_map(fn ($value) => $value === null || trim($value) === '' ? null : str_replace(',', '.', trim($value)), $data);
            $values = Validator::make($normalized, [
                'report_grade' => ['present', 'nullable', 'numeric', 'decimal:0,1', 'min:0', 'max:'.$weights['report_weight']],
                'presentation_grade' => ['present', 'nullable', 'numeric', 'decimal:0,1', 'min:0', 'max:'.$weights['presentation_weight']],
            ], [], ['report_grade' => 'nota do relatório', 'presentation_grade' => 'nota da apresentação'])->validate();
            foreach (['report', 'presentation'] as $component) {
                $internship->{$component.'_grade'} = $weights[$component.'_weight'] === 0 ? 0 : $values[$component.'_grade'];
                if ($internship->isDirty($component.'_grade')) {
                    $internship->fill([
                        $component.'_graded_by_affiliation_id' => $advisor->id,
                        $component.'_graded_at' => now(),
                    ]);
                }
            }
            $evaluation = $internship->currentSupervisorEvaluation()->lockForUpdate()->first();
            $internship->setRelation('currentSupervisorEvaluation', $evaluation);
            $internship->fill($this->calculator->calculate($internship,
                $internship->report_grade === null ? null : (float) $internship->report_grade,
                $internship->presentation_grade === null ? null : (float) $internship->presentation_grade));
            $this->causerResolver->withCauser($affiliation, function () use ($internship): void {
                $internship->save();
                $this->results->finalGradeAvailable($internship);
            });

            return $internship;
        }, attempts: 3);
    }
}
