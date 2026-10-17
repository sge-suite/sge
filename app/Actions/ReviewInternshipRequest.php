<?php

namespace App\Actions;

use App\Concerns\InternshipRequestValidationRules;
use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\InternshipRequestCorrectionStatus;
use App\Enums\InternshipRequestStatus;
use App\Enums\InternshipStatus;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\SupervisorQualification;
use App\Models\User;
use App\Support\InternshipRequestSnapshots;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class ReviewInternshipRequest
{
    use InternshipRequestValidationRules;

    public function __construct(private StudentInternshipContext $context, private CauserResolver $causerResolver,
        private CopyAddress $copyAddress, private ProjectInternshipRequestEndDate $project,
        private InternshipRequestSnapshots $snapshots, private ManageStudentInternshipRequest $studentRequests,
        private NotifyInternshipRequestDecision $notify) {}

    /** @param list<string> $sections */
    public function handle(User $actor, InternshipRequest $target, string $operation, ?string $message = null, array $sections = [], ?int $advisorId = null, ?int $supervisorQualificationId = null, array $sectionMessages = []): InternshipRequest
    {
        abort_unless(in_array($operation, ['begin', 'return', 'reject', 'accept'], true), 404);

        return DB::transaction(function () use ($actor, $target, $operation, $message, $sections, $advisorId, $supervisorQualificationId, $sectionMessages): InternshipRequest {
            $selected = $this->context->selected($actor);
            abort_if($selected === null, 403);
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($selected->id);
            $campus = Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id);
            $request = InternshipRequest::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('review', $request);
            $campus->assertWritable();
            $internship = $request->internship_id === null ? null : Internship::query()->lockForUpdate()->findOrFail($request->internship_id);
            abort_if($internship?->released_at !== null, 409);
            abort_unless($internship === null || in_array($internship->status, [InternshipStatus::PendingFormalization, InternshipStatus::AwaitingSignatures, InternshipStatus::PendingCorrection], true), 409);
            abort_if($operation === 'reject' && $internship !== null, 409);
            abort_unless(in_array($request->status, [InternshipRequestStatus::Submitted, InternshipRequestStatus::UnderReview], true)
                || ($operation === 'return' && $request->status === InternshipRequestStatus::PendingCorrection)
                || ($operation === 'return' && $request->status === InternshipRequestStatus::Accepted && $internship !== null), 409);
            $sectionMessages = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $sectionMessages);
            $rules = [
                'message' => [$operation === 'reject' ? 'required' : 'nullable', 'string', 'max:5000'],
                'sections' => [$operation === 'return' ? 'required' : 'nullable', 'array', ...($operation === 'return' ? ['min:1'] : [])],
                'sections.*' => ['string', 'distinct', Rule::in(array_keys(self::internshipSections()))],
                'sectionMessages' => [$operation === 'return' ? 'required' : 'nullable', 'array'],
                'sectionMessages.*' => ['required', 'string', 'max:5000'],
            ];
            foreach ($sections as $section) {
                if (is_string($section) && array_key_exists($section, self::internshipSections())) {
                    $rules['sectionMessages.'.$section] = [$operation === 'return' ? 'required' : 'nullable', 'string', 'max:5000'];
                }
            }
            Validator::make(compact('message', 'sections', 'sectionMessages'), $rules)->validate();

            $sectionMessages = array_intersect_key($sectionMessages, array_flip($sections));
            $correctionMessage = implode("\n\n", array_values($sectionMessages));
            $generalMessage = filled($message) ? trim($message) : null;

            return $this->causerResolver->withCauser($affiliation, function () use ($request, $operation, $message, $sections, $sectionMessages, $correctionMessage, $generalMessage, $advisorId, $campus, $internship, $supervisorQualificationId): InternshipRequest {
                if ($operation === 'begin') {
                    $request->status = InternshipRequestStatus::UnderReview;
                } elseif ($operation === 'return') {
                    $open = $request->corrections()->where('status', InternshipRequestCorrectionStatus::Open)->lockForUpdate()->first();
                    if ($open !== null) {
                        $open->fill(['message' => $correctionMessage, 'general_message' => $generalMessage, 'affected_sections' => $sections, 'section_messages' => $sectionMessages]);
                        if (! $open->isDirty()) {
                            return $request;
                        }
                        $open->save();
                    } else {
                        $request->corrections()->create(['message' => $correctionMessage, 'general_message' => $generalMessage,
                            'affected_sections' => $sections, 'section_messages' => $sectionMessages]);
                    }
                    $request->status = InternshipRequestStatus::PendingCorrection;
                    if ($internship !== null) {
                        $internship->status = InternshipStatus::PendingCorrection;
                        $internship->save();
                        foreach ($internship->generatedDocuments()->whereIn('status', [GeneratedDocumentStatus::Generated, GeneratedDocumentStatus::AwaitingSignature])->lockForUpdate()->get() as $document) {
                            $document->update(['status' => GeneratedDocumentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $message]);
                        }
                    }
                } elseif ($operation === 'reject') {
                    $request->decision_reason = trim($message);
                    $request->status = InternshipRequestStatus::Rejected;
                } else {
                    $owner = $request->affiliation()->lockForUpdate()->firstOrFail();
                    $request->advisor_affiliation_id = $advisorId ?? $request->advisor_affiliation_id;
                    $this->studentRequests->assertRelations($request, $owner);
                    abort_if($request->granting_party_registration_request_id !== null || $request->supervisor_registration_request_id !== null, 422, 'Aprove e associe os cadastros pendentes antes do aceite.');
                    $advisor = Affiliation::query()->active()->where('campus_id', $campus->id)->where('type', AffiliationType::Advisor->value)->lockForUpdate()->find($request->advisor_affiliation_id);
                    if ($advisor === null) {
                        throw ValidationException::withMessages(['advisor_affiliation_id' => 'Selecione um orientador ativo do campus.']);
                    }
                    $request->advisor_affiliation_id = $advisor->id;
                    $party = $request->grantingParty()->with('address')->firstOrFail();
                    $supervisor = $request->supervisorAffiliation()->firstOrFail();
                    $attributes = $request->only(['course_id', 'internship_type_id', 'advisor_affiliation_id', 'supervisor_affiliation_id', 'granting_party_id',
                        'student_snapshot', 'internship_type_snapshot', 'weekly_hours', 'planned_pauses', 'activities', 'internship_sector', 'planned_start_date', 'projected_end_date',
                        'is_remunerated', 'grant_value', 'transportation_allowance', 'observations']);
                    $attributes['student_affiliation_id'] = $request->affiliation_id;
                    $corrected = $internship === null ? [] : $request->corrections()->where('status', InternshipRequestCorrectionStatus::Responded)->get()
                        ->flatMap(fn ($correction) => $correction->affected_sections)->unique()->all();
                    $attributes['student_address_id'] = $internship !== null && ! in_array('personal_data', $corrected, true) ? $internship->student_address_id
                        : ($request->studentAddress()->first() === null ? null : $this->copyAddress->handle($request->studentAddress()->firstOrFail())->id);
                    $attributes['workplace_address_id'] = $internship !== null && ! in_array('granting_party', $corrected, true) ? $internship->workplace_address_id : $this->copyAddress->handle($party->address)->id;
                    $attributes['granting_party_snapshot'] = $this->snapshots->party($party);
                    if ($internship === null || in_array('supervisor', $corrected, true)) {
                        $qualification = $supervisorQualificationId === null ? null : SupervisorQualification::query()
                            ->where('user_id', $supervisor->user_id)->lockForUpdate()->find($supervisorQualificationId);
                        if ($qualification === null) {
                            throw ValidationException::withMessages(['supervisorQualificationId' => 'Escolha uma qualificação cadastrada deste supervisor para o estágio.']);
                        }
                        $professional = $qualification->snapshot() + ['job_role' => $request->supervisor_snapshot['job_role'] ?? null];
                        $attributes['supervisor_snapshot'] = $this->snapshots->supervisor($supervisor, $professional);
                    } else {
                        $attributes['supervisor_snapshot'] = $internship->supervisor_snapshot;
                    }
                    $stage = $internship ?? new Internship;
                    abort_unless(! $stage->exists || $stage->student_affiliation_id === $request->affiliation_id, 409);
                    if ($stage->exists) {
                        $fields = ['advisor_affiliation_id'];
                        foreach ($corrected as $section) {
                            $fields = [...$fields, ...match ($section) {
                                'personal_data' => ['student_snapshot', 'student_address_id'],
                                'internship' => ['internship_type_id', 'internship_type_snapshot', 'student_snapshot'],
                                'granting_party' => ['granting_party_id', 'granting_party_snapshot', 'workplace_address_id'],
                                'supervisor' => ['supervisor_affiliation_id', 'supervisor_snapshot'],
                                'legal_capacity' => ['student_snapshot'],
                                default => self::internshipSections()[$section] ?? [],
                            }];
                        }
                        $attributes = array_intersect_key($attributes, array_flip($fields));
                    }
                    $projection = clone $request;
                    $projection->workplace_address_id = $attributes['workplace_address_id'] ?? $stage->workplace_address_id;
                    $request->projected_end_date = $this->project->handle($projection);
                    $attributes['projected_end_date'] = $request->projected_end_date;
                    $attributes['status'] = InternshipStatus::PendingFormalization;
                    $stage->fill($attributes)->save();
                    $request->internship_id = $stage->id;
                    $request->status = InternshipRequestStatus::Accepted;
                    foreach ($request->corrections()->where('status', InternshipRequestCorrectionStatus::Responded)->get() as $correction) {
                        $correction->update(['status' => InternshipRequestCorrectionStatus::Resolved, 'resolved_at' => now()]);
                    }
                }
                $request->save();
                if ($operation !== 'begin') {
                    $this->notify->handle($request);
                }

                return $request;
            });
        }, attempts: 3);
    }
}
