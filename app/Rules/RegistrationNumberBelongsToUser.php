<?php

namespace App\Rules;

use App\Models\Affiliation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class RegistrationNumberBelongsToUser implements ValidationRule
{
    public function __construct(private ?int $userId, private ?int $affiliationId = null) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            return;
        }

        $query = Affiliation::query()->where('registration_number', trim((string) $value));

        if ($this->userId !== null) {
            $query->where('user_id', '!=', $this->userId);
        }

        if ($this->affiliationId !== null) {
            $query->whereKeyNot($this->affiliationId);
        }

        if ($query->exists()) {
            $fail('Esta matrícula já pertence a outra pessoa.');
        }
    }
}
