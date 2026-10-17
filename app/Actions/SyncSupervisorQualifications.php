<?php

namespace App\Actions;

use App\Concerns\SupervisorProfessionalDataValidationRules;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SyncSupervisorQualifications
{
    use SupervisorProfessionalDataValidationRules;

    /** @param array<array-key, array<string, mixed>> $qualifications */
    public function handle(User $user, array $qualifications): void
    {
        $normalized = $this->normalizeSupervisorProfessionalReference(['qualifications' => $qualifications]);
        $data = Validator::make($normalized, $this->supervisorProfileQualificationRules(), attributes: $this->supervisorQualificationAttributes())->validate();
        DB::transaction(function () use ($user, $data): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = $user->supervisorQualifications()->lockForUpdate()->get()->keyBy('id');
            $retained = [];
            foreach ($data['qualifications'] as $key => $input) {
                $id = $input['id'] ?? null;
                if ($id !== null && ! $existing->has($id)) {
                    throw ValidationException::withMessages(['qualifications.'.$key.'.id' => 'Esta qualificação não pertence ao supervisor.']);
                }
                $qualification = $id === null ? $user->supervisorQualifications()->make() : $existing->get($id);
                $qualification->fill(Arr::except($input, ['id']));
                $qualification->save();
                $retained[] = $qualification->id;
            }
            foreach ($existing->except($retained) as $qualification) {
                $qualification->delete();
            }
        });
    }
}
