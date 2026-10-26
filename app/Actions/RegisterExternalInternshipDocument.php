<?php

namespace App\Actions;

use App\Enums\GeneratedDocumentOrigin;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\Course;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Support\CauserResolver;

class RegisterExternalInternshipDocument
{
    public function __construct(private ManageDocumentTemplate $templates, private CauserResolver $causers,
        private ApplyInternshipWorkSchedule $schedules) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, int $expectedAffiliationId, Internship $target, string $token, array $data): GeneratedDocument
    {
        $values = Validator::make([...$data, 'generation_token' => $token], [
            'generation_token' => ['required', 'uuid'], 'document_name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([GeneratedDocumentType::Main->value, GeneratedDocumentType::Addendum->value])],
            'protocol_number' => ['nullable', 'string', 'max:255'], 'work_schedule_change' => ['nullable', 'array:starts_on,weekly_hours'],
        ])->validate();

        return DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $token, $values): GeneratedDocument {
            $office = $this->templates->lockOffice($actor, $expectedAffiliationId);
            $internship = Internship::query()->lockForUpdate()->findOrFail($target->id);
            $internship->setRelation('course', Course::query()->with('campus')->lockForUpdate()->findOrFail($internship->course_id));
            Gate::forUser($actor)->authorize('registerExternalDocument', $internship);
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sge-document-generation:'.$token]);
            $existing = GeneratedDocument::query()->where('generation_token', $token)->first();
            if ($existing !== null) {
                $registration = $existing->externalRegistration;
                $saved = $existing->workScheduleChange();
                $incoming = $values['work_schedule_change'] ?? null;
                abort_unless($existing->internship_id === $internship->id && $existing->origin === GeneratedDocumentOrigin::GrantingParty
                    && $existing->type->value === $values['type'] && $existing->protocol_number === ($values['protocol_number'] ?? null)
                    && $registration?->properties->get('document_name') === trim($values['document_name'])
                    && (($saved === null && $incoming === null) || ($saved !== null && $incoming !== null
                        && $saved['starts_on'] === ($incoming['starts_on'] ?? null) && $saved['weekly_hours'] == ($incoming['weekly_hours'] ?? null))), 409);

                return $existing;
            }
            $proposal = null;
            if (isset($values['work_schedule_change'])) {
                abort_unless($values['type'] === GeneratedDocumentType::Addendum->value, 422);
                Gate::forUser($actor)->authorize('applyWorkSchedule', $internship);
                $proposal = $this->schedules->proposal($internship, $values['work_schedule_change']);
            }

            return $this->causers->withCauser($office, function () use ($internship, $values, $token, $proposal): GeneratedDocument {
                $document = $internship->generatedDocuments()->create(['origin' => GeneratedDocumentOrigin::GrantingParty,
                    'type' => $values['type'], 'status' => GeneratedDocumentStatus::Generated, 'generation_token' => $token,
                    'protocol_number' => $values['protocol_number'] ?? null]);
                activity()->performedOn($document)->event('external_document_registered')
                    ->withProperties(['document_name' => trim($values['document_name']), 'work_schedule_change' => $proposal,
                        'signature_start_date' => $proposal['starts_on'] ?? ($document->type === GeneratedDocumentType::Main ? $internship->planned_start_date->toDateString() : null)])
                    ->log('Documento externo registrado');

                return $document;
            });
        }, attempts: 3);
    }
}
