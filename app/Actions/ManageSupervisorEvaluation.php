<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\EducationLevel;
use App\Enums\EvaluationStatus;
use App\Jobs\DeliverSupervisorEvaluationNotice;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Internship;
use App\Models\SupervisorEvaluation;
use App\Models\User;
use App\Support\InternshipGradeCalculator;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Support\CauserResolver;

class ManageSupervisorEvaluation
{
    public function __construct(private StudentInternshipContext $context, private CauserResolver $causerResolver,
        private InternshipGradeCalculator $calculator, private ManageInternshipLifecycle $lifecycle,
        private NotifyInternshipEvaluationResults $results) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, Internship $target, int $expectedAffiliationId, string $operation, array $data = [], ?int $evaluationId = null): SupervisorEvaluation
    {
        abort_unless(in_array($operation, ['release', 'save', 'submit', 'return', 'approve', 'cancel'], true), 404);

        return DB::transaction(function () use ($actor, $target, $expectedAffiliationId, $operation, $data, $evaluationId): SupervisorEvaluation {
            abort_unless($this->context->selected($actor)?->id === $expectedAffiliationId, 403, 'O vínculo selecionado mudou. Reabra a avaliação.');
            $selected = Affiliation::query()->lockForUpdate()->findOrFail($expectedAffiliationId);
            abort_unless($selected->user_id === $actor->id && $selected->deactivated_at === null, 403);
            $internship = Internship::query()->lockForUpdate()->findOrFail($target->id);
            $course = Course::query()->lockForUpdate()->findOrFail($internship->course_id);
            $campus = Campus::query()->lockForUpdate()->findOrFail($course->campus_id);
            $course->setRelation('campus', $campus);
            $internship->setRelation('course', $course);
            $supervisor = Affiliation::query()->lockForUpdate()->findOrFail($internship->supervisor_affiliation_id);
            $internship->setRelation('supervisorAffiliation', $supervisor);

            return $this->causerResolver->withCauser($selected, function () use ($actor, $internship, $selected, $supervisor, $operation, $data, $evaluationId, $campus): SupervisorEvaluation {
                if ($operation === 'release') {
                    Gate::forUser($actor)->authorize('release', [SupervisorEvaluation::class, $internship]);
                    $internship->update(['evaluation_released_at' => now(), 'evaluation_released_by_affiliation_id' => $selected->id]);
                    $evaluation = $internship->supervisorEvaluations()->create(['supervisor_affiliation_id' => $supervisor->id]);
                    $this->notify($evaluation, $supervisor, 'Avaliação de estágio solicitada', 'O Setor de Estágio solicitou sua avaliação. Acesse o SGE para responder.');

                    return $evaluation;
                }

                $evaluation = $internship->supervisorEvaluations()->lockForUpdate()->findOrFail($evaluationId);
                $evaluation->setRelation('internship', $internship);
                Gate::forUser($actor)->authorize(match ($operation) {
                    'save', 'submit' => 'update',
                    'cancel' => 'cancel',
                    default => 'review',
                }, $evaluation);
                $campus->assertWritable();

                if (in_array($operation, ['save', 'submit'], true)) {
                    $evaluation->fill($this->answers($data));
                    if ($operation === 'submit') {
                        $evaluation->fill(['status' => EvaluationStatus::Submitted, 'submitted_at' => now(), 'reviewed_at' => null, 'review_notes' => null]);
                    }
                } elseif ($operation === 'cancel') {
                    $reason = Validator::make($data, ['cancellation_reason' => ['required', 'string', 'max:5000']])->validate();
                    $evaluation->fill(['status' => EvaluationStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason['cancellation_reason']]);
                } else {
                    $reason = Validator::make($data, ['review_notes' => [$operation === 'return' ? 'required' : 'nullable', 'string', 'max:5000']])->validate();
                    $evaluation->fill(['status' => $operation === 'approve' ? EvaluationStatus::Approved : EvaluationStatus::Returned,
                        'reviewed_at' => now(), 'review_notes' => $reason['review_notes'] ?? null]);
                }
                $evaluation->save();

                if ($operation === 'approve') {
                    $current = $internship->supervisorEvaluations()->where('status', EvaluationStatus::Approved)
                        ->orderByDesc('submitted_at')->orderByDesc('id')->lockForUpdate()->firstOrFail();
                    $internship->currentSupervisorEvaluation()->associate($current);
                    $internship->fill($this->calculator->calculate($internship,
                        $internship->report_grade === null ? null : (float) $internship->report_grade,
                        $internship->presentation_grade === null ? null : (float) $internship->presentation_grade));
                    $internship->save();
                    $completed = $this->lifecycle->handle($actor, $internship, $selected->id, 'complete_from_evaluation');
                    $this->results->finalGradeAvailable($completed);
                }
                if (in_array($operation, ['return', 'approve'], true)) {
                    $this->notify($evaluation, $evaluation->supervisorAffiliation()->firstOrFail(), 'Avaliação de estágio '.$evaluation->status->label(),
                        $evaluation->review_notes ?? 'O Setor de Estágio aprovou sua avaliação.', $operation === 'approve');
                }
                if ($operation === 'submit') {
                    foreach (Affiliation::query()->active()->where('campus_id', $campus->id)->whereIn('type', AffiliationType::InternshipOffice->databaseValues())->get() as $office) {
                        $this->notify($evaluation, $office, 'Avaliação de estágio enviada', 'O supervisor enviou a avaliação do estágio nº '.$internship->id.' para análise.');
                    }
                }

                return $evaluation;
            });
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function answers(array $data): array
    {
        $data = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
        $hours = Validator::make($data, ['hours_requirement_met' => ['nullable', 'boolean']])->validate();
        $met = isset($hours['hours_requirement_met']) ? (bool) $hours['hours_requirement_met'] : null;
        $fields = ['has_academic_background', 'job_role', 'training_course', 'education_level', 'experience_time',
            ...array_keys(SupervisorEvaluation::criteria()), ...array_keys(SupervisorEvaluation::comments())];
        $answer = array_fill_keys($fields, null);
        if ($met === true) {
            $answer = array_replace($answer, Arr::only($data, $fields));
            Validator::make($answer, ['has_academic_background' => ['nullable', 'boolean']])->validate();
            $academic = $answer['has_academic_background'];
            $answer['has_academic_background'] = $academic === null || $academic === '' ? null : (bool) $academic;
            if ($answer['has_academic_background'] === true) {
                $answer['experience_time'] = null;
            } elseif ($answer['has_academic_background'] === false) {
                $answer['training_course'] = $answer['education_level'] = null;
            }
            Validator::make($answer, ['education_level' => ['nullable', Rule::enum(EducationLevel::class)]])->validate();
        }

        return [...$answer, 'hours_requirement_met' => $met,
            'estimated_hours_remaining' => $met === false ? ($data['estimated_hours_remaining'] ?? null) : null];
    }

    private function notify(SupervisorEvaluation $evaluation, Affiliation $recipient, string $title, string $message, bool $internshipCompleted = false): void
    {
        DeliverSupervisorEvaluationNotice::dispatch($evaluation->id, $recipient->id, (string) Str::uuid(), $title, $message, $internshipCompleted)->afterCommit();
    }
}
