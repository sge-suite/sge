<?php

namespace App\Actions;

use App\Concerns\InternshipTypeManagementValidationRules;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\InternshipType;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;
use Spatie\Activitylog\Support\CauserResolver;

class ManageInternshipType
{
    use InternshipTypeManagementValidationRules;

    public function __construct(private ActiveAffiliationContext $context, private CauserResolver $causerResolver) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, ?InternshipType $target, string $operation, array $data = []): InternshipType
    {
        abort_unless(in_array($operation, ['create', 'update', 'deactivate', 'reactivate', 'delete'], true), 404);
        abort_unless(($operation === 'create') === ($target === null), 404);
        Gate::forUser($actor)->authorize($operation, $target ?? InternshipType::class);

        return DB::transaction(function () use ($actor, $target, $operation, $data): InternshipType {
            $affiliation = $this->context->currentFor($actor, app('session.store'));
            abort_if($affiliation === null, 403);
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($affiliation->id);
            $campus = Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id);
            $internshipType = $target === null ? new InternshipType : InternshipType::query()->lockForUpdate()->findOrFail($target->id);
            $courseIds = collect([$internshipType->course_id, $data['course_id'] ?? null])
                ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false)
                ->unique()->sort()->values();
            Course::query()->whereKey($courseIds)->orderBy('id')->lockForUpdate()->get();
            Gate::forUser($actor)->authorize($operation, $target === null ? InternshipType::class : $internshipType);
            $campus->assertWritable();

            $isForm = in_array($operation, ['create', 'update'], true);
            $rules = $isForm
                ? $this->managementRules($target === null ? null : $internshipType, $campus->id, $data)
                : (in_array($operation, ['deactivate', 'delete'], true) ? ['current_password' => ['required', 'string']] : []);
            $validator = Validator::make($data, $rules, $this->internshipTypeValidationMessages(), $this->internshipTypeValidationAttributes());
            $validator->after(function (LaravelValidator $validator) use ($data, $rules, $isForm): void {
                $this->validateAllowedFields($validator, $data, array_keys($rules));
                if ($isForm) {
                    $this->validateInternshipTypeValues($validator, $data);
                }
            });
            $validated = $validator->validate();

            if ($operation === 'delete' && $internshipType->hasLinkedRecords()) {
                throw ValidationException::withMessages(['internship_type' => 'Não é possível excluir um tipo de estágio que possui registros vinculados.']);
            }

            if (in_array($operation, ['deactivate', 'delete'], true)
                && ! Hash::check($validated['current_password'], $actor->fresh()->password)) {
                throw ValidationException::withMessages(['current_password' => 'A senha informada está incorreta.']);
            }

            return $this->causerResolver->withCauser($affiliation, function () use ($internshipType, $operation, $validated, $isForm): InternshipType {
                if ($operation === 'delete') {
                    $internshipType->delete();

                    return $internshipType;
                }

                if ($isForm) {
                    $internshipType->fill($validated);
                } else {
                    $internshipType->deactivated_at = $operation === 'deactivate' ? now() : null;
                }
                $internshipType->save();

                return $internshipType;
            });
        });
    }
}
