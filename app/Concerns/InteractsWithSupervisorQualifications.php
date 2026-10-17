<?php

namespace App\Concerns;

use App\Enums\SupervisorQualificationType;
use App\Models\User;
use Illuminate\Support\Str;

trait InteractsWithSupervisorQualifications
{
    /** @var array<string, array<string, mixed>> */
    public array $qualifications = [];

    protected function loadSupervisorQualifications(?User $user): void
    {
        $stored = $user?->supervisorQualifications()->get()->map(fn ($qualification): array => $qualification->only([
            'id', 'type', 'training_course', 'education_level', 'experience_area', 'experience_time',
        ]))->all() ?? [];
        $rows = old('qualifications', $stored);
        $this->qualifications = [];
        foreach (is_array($rows) ? $rows : [] as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $field => $value) {
                if ($value instanceof \BackedEnum) {
                    $row[$field] = $value->value;
                }
            }
            $this->qualifications[is_string($key) && Str::isUuid($key) ? $key : (string) Str::uuid()] = $row;
        }
    }

    public function addSupervisorQualification(string $type): void
    {
        abort_unless(SupervisorQualificationType::tryFrom($type) !== null && count($this->qualifications) < 25, 422);
        $this->qualifications[(string) Str::uuid()] = ['id' => null, 'type' => $type, 'training_course' => '',
            'education_level' => '', 'experience_area' => '', 'experience_time' => ''];
    }

    public function removeSupervisorQualification(string $key): void
    {
        unset($this->qualifications[$key]);
        $this->resetValidation();
    }
}
