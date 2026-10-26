<?php

namespace App\Actions;

use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Enums\InternshipStatus;
use App\Models\Course;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\User;
use App\Support\InternshipRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Support\CauserResolver;

class ManageDocumentSignature
{
    public function __construct(private ManageDocumentTemplate $templates, private CauserResolver $causers,
        private InternshipRecipients $recipients, private RequestEmailDelivery $delivery, private ApplyInternshipWorkSchedule $schedules) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, int $expectedAffiliationId, GeneratedDocument $target, string $operation, array $data = []): void
    {
        abort_unless(in_array($operation, ['await_signature', 'confirm_signature', 'notify_signature', 'update_protocol'], true), 404);
        DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $operation, $data): void {
            $office = $this->templates->lockOffice($actor, $expectedAffiliationId);
            $internship = Internship::query()->lockForUpdate()->findOrFail($target->internship_id);
            $course = Course::query()->with('campus')->lockForUpdate()->findOrFail($internship->course_id);
            $internship->setRelation('course', $course);
            $document = $internship->generatedDocuments()->lockForUpdate()->findOrFail($target->id);
            $document->setRelation('internship', $internship);
            Gate::forUser($actor)->authorize(match ($operation) {
                'await_signature' => 'requestSignature', 'notify_signature' => 'notifySignature', 'update_protocol' => 'updateProtocol', default => 'confirmSignature'
            }, $document);
            $this->causers->withCauser($office, function () use ($actor, $document, $internship, $operation, $data): void {
                if (is_string($data['protocol_number'] ?? null)) {
                    $protocol = trim($data['protocol_number']);
                    $data['protocol_number'] = $protocol === '' ? null : $protocol;
                }
                if ($operation === 'update_protocol') {
                    $values = Validator::make($data, ['protocol_number' => ['nullable', 'string', 'max:255']])->validate();
                    $document->update(['protocol_number' => $values['protocol_number'] ?? null]);

                    return;
                }
                if ($operation === 'confirm_signature') {
                    Validator::make($data, ['confirm_signature' => ['accepted'],
                        'confirm_expired_date' => $document->signatureDateExpired() ? ['accepted'] : ['nullable', 'boolean']])->validate();
                    if ($document->workScheduleChange() !== null) {
                        Gate::forUser($actor)->authorize('applyWorkSchedule', $internship);
                    }
                    $document->update(['status' => GeneratedDocumentStatus::Signed]);
                    $this->schedules->handle($internship, $document);

                    if ($document->signatureDateExpired()) {
                        activity()->performedOn($document)->event('expired_document_signature_confirmed')
                            ->log('Assinatura conferida após análise da data vencida');
                    }

                    return;
                }
                $eligible = $this->recipients->forInternship($internship, lock: true);
                $data['signature_availability_location'] = is_string($data['signature_availability_location'] ?? null) ? trim($data['signature_availability_location']) : null;
                if (is_string($data['document_name'] ?? null)) {
                    $data['document_name'] = trim($data['document_name']);
                }
                $values = Validator::make($data, [
                    'document_name' => ['exclude_unless:send_notice,true', 'required', 'string', 'max:255'],
                    'protocol_number' => ['nullable', 'string', 'max:255'],
                    'signature_availability_location' => ['required', 'string', 'max:500'],
                    'send_notice' => $operation === 'notify_signature' ? ['required', 'boolean', 'accepted'] : ['required', 'boolean'],
                    'recipients' => ['exclude_unless:send_notice,true', 'required', 'array', 'min:1', 'max:4'],
                    'recipients.*' => ['required', 'string', 'distinct', Rule::in(array_keys($eligible))],
                    'messages' => ['exclude_unless:send_notice,true', 'nullable', 'array:student,advisor,supervisor,granting_party'],
                    'messages.*' => ['nullable', 'string', 'max:5000'],
                ])->validate();
                $document->signatureNoticeCategories = $operation === 'await_signature' ? ($values['recipients'] ?? []) : [];
                $document->update(['status' => GeneratedDocumentStatus::AwaitingSignature,
                    'signature_availability_location' => $values['signature_availability_location'],
                    'protocol_number' => $values['protocol_number'] ?? null]);
                if ($document->type === GeneratedDocumentType::Main && $internship->status === InternshipStatus::PendingFormalization) {
                    $internship->update(['status' => InternshipStatus::AwaitingSignatures]);
                }
                if ($operation === 'notify_signature') {
                    activity()->performedOn($document)->event('signature_notice_requested')
                        ->withProperties(['internship_id' => $internship->id, 'campus_id' => $internship->course->campus_id,
                            'signature_availability_location' => $values['signature_availability_location'], 'protocol_number' => $document->protocol_number,
                            'signature_recipient_categories' => $values['recipients'], 'signature_recipient_count' => count($values['recipients'])])
                        ->log('Avisos de assinatura solicitados');
                }
                foreach ($values['recipients'] ?? [] as $key) {
                    $this->delivery->documentSignature($document, $eligible[$key], $values['document_name'], $values['signature_availability_location'],
                        trim($values['messages'][$key] ?? ''), (string) Str::uuid());
                }
            });
        }, attempts: 3);
    }
}
