<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentType;
use App\Enums\TemplateRemuneration;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class ManageDocumentTemplate
{
    public function __construct(private ActiveAffiliationContext $context, private CauserResolver $causers,
        private DeleteUnusedTemplateVersion $deleteUnusedVersion) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, int $expectedAffiliationId, ?DocumentTemplate $target, string $operation, array $data = []): DocumentTemplate
    {
        abort_unless(in_array($operation, ['create', 'update', 'deactivate', 'reactivate', 'delete'], true), 404);
        abort_unless(($operation === 'create') === ($target === null), 404);

        return DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $operation, $data): DocumentTemplate {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['sge-document-template-catalog']);
            $office = $this->lockOffice($actor, $expectedAffiliationId);
            $template = $target === null ? new DocumentTemplate : DocumentTemplate::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize($operation === 'delete' ? 'delete' : ($target === null ? 'create' : 'update'), $target === null ? DocumentTemplate::class : $template);
            $values = in_array($operation, ['create', 'update'], true) ? Validator::make($data, [
                'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'],
                'document_type' => ['required', Rule::enum(GeneratedDocumentType::class)],
                'remuneration' => ['required', Rule::enum(TemplateRemuneration::class)],
            ])->validate() : [];

            if (in_array($operation, ['deactivate', 'delete'], true) && (! is_string($data['current_password'] ?? null)
                || ! Hash::check($data['current_password'], $actor->fresh()->password))) {
                throw ValidationException::withMessages([$operation === 'delete' ? 'deletionPassword' : 'currentPassword' => 'Informe sua senha atual corretamente.']);
            }

            return $this->causers->withCauser($office, function () use ($template, $operation, $values, $office): DocumentTemplate {
                if ($operation === 'delete') {
                    foreach ($template->templateVersions()->orderBy('id')->lockForUpdate()->get() as $version) {
                        $this->deleteUnusedVersion->handle($version);
                    }
                    $template->delete();

                    return $template;
                }
                if (in_array($operation, ['create', 'update'], true)) {
                    $template->fill($values);
                    if (! $template->exists) {
                        $template->campus_id = $office->campus_id;
                    }
                } else {
                    $template->deactivated_at = $operation === 'deactivate' ? now() : null;
                }
                $template->save();

                return $template;
            });
        });
    }

    public function lockOffice(User $actor, int $expectedAffiliationId): Affiliation
    {
        $selected = $this->context->currentFor($actor, app('session.store'));
        abort_unless($selected?->id === $expectedAffiliationId, 403);
        $office = Affiliation::query()->lockForUpdate()->findOrFail($expectedAffiliationId);
        abort_unless($office->user_id === $actor->id && $office->deactivated_at === null
            && $office->type === AffiliationType::InternshipOffice && $office->campus_id !== null, 403);
        Campus::query()->lockForUpdate()->findOrFail($office->campus_id)->assertWritable();

        return $office;
    }
}
