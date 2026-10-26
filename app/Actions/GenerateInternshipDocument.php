<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentOrigin;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\User;
use App\Support\DocxRenderer;
use App\Support\GeneratedDocumentFilename;
use App\Support\GeneratedDocumentSnapshot;
use App\Support\StudentInternshipContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class GenerateInternshipDocument
{
    public function __construct(private StudentInternshipContext $context, private GeneratedDocumentSnapshot $snapshots,
        private DocxRenderer $renderer, private CauserResolver $causers, private GeneratedDocumentFilename $filenames,
        private ApplyInternshipWorkSchedule $schedules) {}

    /** @param array<string, mixed>|null $workScheduleChange */
    public function handle(User $actor, int $expectedAffiliationId, Internship $target, int $templateId, int $versionId, string $token, ?array $workScheduleChange = null): GeneratedDocument
    {
        Validator::make(['generation_token' => $token], ['generation_token' => ['required', 'uuid']])->validate();
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $templateId, $versionId, $token, $workScheduleChange, &$path): GeneratedDocument {
                abort_unless($this->context->selected($actor)?->id === $expectedAffiliationId, 403);
                $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($expectedAffiliationId);
                abort_unless($affiliation->user_id === $actor->id && $affiliation->deactivated_at === null, 403);
                $campus = Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id);
                $campus->assertWritable();
                $stage = Internship::query()->lockForUpdate()->findOrFail($target->id);
                $course = Course::query()->lockForUpdate()->findOrFail($stage->course_id);
                $stage->setRelation('course', $course->setRelation('campus', $campus));
                Gate::forUser($actor)->authorize('view', $stage);
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sge-document-generation:'.$token]);
                $existing = GeneratedDocument::query()->where('generation_token', $token)->first();
                if ($existing !== null) {
                    abort_unless($existing->internship_id === $stage->id
                        && (int) ($existing->snapshot['template_id'] ?? 0) === $templateId
                        && $existing->template_version_id === $versionId
                        && (int) data_get($existing->snapshot, 'actor.affiliation_id') === $affiliation->id, 409);
                    Gate::forUser($actor)->authorize('download', $existing);
                    $saved = $existing->workScheduleChange();
                    abort_unless(($saved === null && $workScheduleChange === null)
                        || ($saved !== null && $workScheduleChange !== null && $saved['starts_on'] === ($workScheduleChange['starts_on'] ?? null)
                            && $saved['weekly_hours'] == ($workScheduleChange['weekly_hours'] ?? null)), 409);

                    return $existing;
                }
                $template = DocumentTemplate::query()->active()->availableToCampus($campus->id)
                    ->matchingRemuneration($stage->is_remunerated)->lockForUpdate()->findOrFail($templateId);
                Gate::forUser($actor)->authorize('generateDocument', [$stage, $template->document_type]);
                if ($template->current_version_id !== $versionId) {
                    throw ValidationException::withMessages(['document' => $affiliation->type === AffiliationType::Coordinator
                        ? 'A versão vigente do atestado mudou. Clique em Gerar atestado de orientação novamente para usar a nova versão.'
                        : 'A versão vigente mudou. Selecione o template novamente para confirmar a nova versão.']);
                }
                $version = $template->templateVersions()->lockForUpdate()->findOrFail($versionId);
                $coordinator = $affiliation->type === AffiliationType::Coordinator;
                $stage->load($coordinator ? ['course.campus.address.city', 'advisorAffiliation.user']
                    : ['course.campus.address.city', 'studentAddress.city', 'workplaceAddress.city', 'advisorAffiliation.user', 'workSchedules']);
                $affiliation->load('user');
                $generatedAt = now();
                if ($workScheduleChange !== null) {
                    abort_unless($template->document_type === GeneratedDocumentType::Addendum, 422);
                    Gate::forUser($actor)->authorize('applyWorkSchedule', $stage);
                    $stage->proposedWorkSchedule = $this->schedules->proposal($stage, $workScheduleChange);
                }
                $markers = $this->renderer->markers($version);
                $snapshot = $this->snapshots->build($stage, $template, $affiliation, $markers, $generatedAt,
                    $coordinator);
                if ($stage->proposedWorkSchedule !== null) {
                    $snapshot['work_schedule_change'] = $stage->proposedWorkSchedule;
                }
                $snapshot['signature_start_date'] = $stage->proposedWorkSchedule['starts_on']
                    ?? ($template->document_type === GeneratedDocumentType::Main ? $stage->planned_start_date->toDateString() : null);
                $path = $this->renderer->render($version, $snapshot['replacements']);
                $snapshot['output_sha256'] = hash_file('sha256', $path);

                return $this->causers->withCauser($affiliation, fn (): GeneratedDocument => $stage->generatedDocuments()->create([
                    'template_version_id' => $version->id, 'origin' => GeneratedDocumentOrigin::SGE,
                    'type' => $template->document_type, 'status' => GeneratedDocumentStatus::Generated, 'snapshot' => $snapshot,
                    'generation_token' => $token, 'output_filename' => $this->filenames->make($template->document_type,
                        $template->name, (string) ($stage->student_snapshot['name'] ?? ''), $generatedAt),
                    'template_sha256' => $version->file_sha256, 'generated_at' => $generatedAt,
                ]));
            });
        } finally {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
        }
    }
}
