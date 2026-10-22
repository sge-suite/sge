<?php

namespace App\Actions;

use App\Enums\EvaluationStatus;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Enums\InternshipCancellationRequestStatus;
use App\Enums\InternshipStatus;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Internship;
use App\Models\InternshipCancellationRequest;
use App\Models\User;
use App\Support\StudentInternshipContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class ManageInternshipLifecycle
{
    public function __construct(private StudentInternshipContext $context, private CauserResolver $causers,
        private SyncInternshipExecutionStatus $sync, private NotifyInternshipLifecycle $notify) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, Internship $target, int $expectedAffiliationId, string $operation, array $data = [], ?int $requestId = null): Internship
    {
        $ability = match ($operation) {
            'release' => 'release', 'complete', 'complete_from_evaluation' => 'complete', 'cancel' => 'cancel',
            'request_cancellation' => 'requestCancellation', 'withdraw_cancellation' => 'withdrawCancellation',
            'begin_cancellation', 'approve_cancellation', 'reject_cancellation' => 'reviewCancellation',
            default => abort(404),
        };

        return DB::transaction(function () use ($actor, $target, $expectedAffiliationId, $operation, $data, $requestId, $ability): Internship {
            abort_unless($this->context->selected($actor)?->id === $expectedAffiliationId, 403, 'O vínculo selecionado mudou. Reabra o estágio.');
            $selected = Affiliation::query()->lockForUpdate()->findOrFail($expectedAffiliationId);
            abort_unless($selected->user_id === $actor->id && $selected->deactivated_at === null, 403);
            $internship = Internship::query()->lockForUpdate()->findOrFail($target->id);
            $course = Course::query()->lockForUpdate()->findOrFail($internship->course_id);
            $campus = Campus::query()->lockForUpdate()->findOrFail($course->campus_id);
            $internship->setRelation('course', $course->setRelation('campus', $campus));
            Gate::forUser($actor)->authorize($ability, $internship);
            $campus->assertWritable();
            $data = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $data);

            return $this->causers->withCauser($selected, function () use ($internship, $selected, $operation, $data, $requestId): Internship {
                $pending = $internship->cancellationRequests()->whereIn('status', [InternshipCancellationRequestStatus::Submitted, InternshipCancellationRequestStatus::UnderReview])->lockForUpdate()->first();
                if (in_array($operation, ['release', 'complete', 'complete_from_evaluation'], true)) {
                    if ($pending !== null) {
                        throw ValidationException::withMessages(['lifecycle' => 'Analise o pedido de cancelamento pendente antes de liberar ou concluir o estágio.']);
                    }
                    if ($operation === 'release') {
                        $documents = $internship->generatedDocuments()->where('type', GeneratedDocumentType::Main)
                            ->where('status', '!=', GeneratedDocumentStatus::Cancelled)->lockForUpdate()->get();
                        if ($documents->isEmpty() || $documents->contains(fn ($document) => $document->status !== GeneratedDocumentStatus::Signed)) {
                            throw ValidationException::withMessages(['lifecycle' => 'Confira as assinaturas de todos os documentos principais vigentes antes de liberar o estágio.']);
                        }
                        $internship->update(['status' => InternshipStatus::Released, 'released_at' => now(), 'released_by_affiliation_id' => $selected->id]);
                        $this->notify->handle($internship, 'Estágio liberado', 'O Setor de Estágio conferiu os documentos e liberou a execução.');
                        $this->sync->handle($internship, CarbonImmutable::today(config('app.timezone')));
                    } else {
                        if ($operation === 'complete_from_evaluation') {
                            $evaluation = $internship->currentSupervisorEvaluation()->lockForUpdate()->first();
                            abort_unless($evaluation?->status === EvaluationStatus::Approved && $evaluation->hours_requirement_met === true
                                && $evaluation->internship_id === $internship->id, 422);
                            $internship->managementChangeReason = 'Conclusão pela aprovação da avaliação do supervisor com carga horária cumprida.';
                        } else {
                            Validator::make($data, ['confirm_hours' => ['accepted']])->validate();
                            $internship->managementChangeReason = 'Carga horária integral confirmada manualmente pelo Setor de Estágio.';
                        }
                        $internship->update(['status' => InternshipStatus::Completed]);
                        $this->notify->handle($internship, 'Estágio concluído', 'O cumprimento integral da carga horária foi confirmado e o estágio foi concluído.',
                            supervisorEvaluationApproved: $operation === 'complete_from_evaluation');
                    }

                    return $internship;
                }
                if ($operation === 'request_cancellation') {
                    abort_if($pending !== null, 409, 'Já há um pedido de cancelamento pendente.');
                    $values = Validator::make($data, ['reason' => ['required', 'string', 'max:5000']])->validate();
                    $internship->cancellationRequests()->create(['reason' => $values['reason']]);
                    $this->notify->handle($internship, 'Pedido de cancelamento recebido', 'Seu pedido foi registrado e aguarda a análise do Setor de Estágio.', ['student']);

                    return $internship;
                }
                if ($operation !== 'cancel') {
                    abort_unless($pending !== null && $pending->id === $requestId, 409, 'O pedido já foi decidido ou não está disponível.');
                }
                if ($operation === 'withdraw_cancellation') {
                    $pending->update(['status' => InternshipCancellationRequestStatus::Withdrawn]);
                } elseif ($operation === 'begin_cancellation') {
                    abort_unless($pending->status === InternshipCancellationRequestStatus::Submitted, 409);
                    $pending->update(['status' => InternshipCancellationRequestStatus::UnderReview]);
                } elseif ($operation === 'reject_cancellation') {
                    $values = Validator::make($data, ['reason' => ['required', 'string', 'max:5000']])->validate();
                    $pending->update(['status' => InternshipCancellationRequestStatus::Rejected, 'reviewed_at' => now(), 'decision_reason' => $values['reason']]);
                    $this->notify->handle($internship, 'Pedido de cancelamento recusado', 'O Setor de Estágio recusou seu pedido. Justificativa: '.$values['reason'], ['student']);
                } else {
                    $values = Validator::make($data, ['reason' => ['required', 'string', 'max:5000'],
                        'effective_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])->validate();
                    $cancellation = $pending ?? new InternshipCancellationRequest(['internship_id' => $internship->id, 'reason' => $values['reason']]);
                    $cancellation->fill(['status' => InternshipCancellationRequestStatus::Approved, 'reviewed_at' => now(),
                        'decision_reason' => $values['reason'], 'effective_date' => $values['effective_date']])->save();
                    foreach ($internship->generatedDocuments()->whereIn('status', [GeneratedDocumentStatus::Generated, GeneratedDocumentStatus::AwaitingSignature])->lockForUpdate()->get() as $document) {
                        $document->update(['status' => GeneratedDocumentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $values['reason']]);
                    }
                    foreach ($internship->supervisorEvaluations()->whereIn('status', [EvaluationStatus::Draft, EvaluationStatus::Submitted, EvaluationStatus::Returned])->lockForUpdate()->get() as $evaluation) {
                        $evaluation->update(['status' => EvaluationStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $values['reason']]);
                    }
                    $internship->managementChangeReason = $values['reason'];
                    $internship->update(['status' => InternshipStatus::Cancelled]);
                    $this->notify->handle($internship, 'Estágio cancelado', 'O Setor de Estágio cancelou o estágio com efeito em '.formatShort($values['effective_date']).'. Motivo: '.$values['reason']);
                }

                return $internship;
            });
        }, attempts: 3);
    }
}
