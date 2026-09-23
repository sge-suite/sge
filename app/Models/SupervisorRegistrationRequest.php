<?php

namespace App\Models;

use App\Casts\CpfCast;
use App\Casts\PhoneCast;
use App\Enums\AffiliationType;
use App\Enums\RegistrationRequestStatus;
use Database\Factories\SupervisorRegistrationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'name', 'cpf', 'phone', 'email', 'job_role', 'qualification',
    'training', 'professional_experience', 'status',
    'supervisor_affiliation_id', 'reviewed_at', 'decision_reason',
])]
#[Hidden(['cpf'])]
class SupervisorRegistrationRequest extends Model
{
    /** @use HasFactory<SupervisorRegistrationRequestFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phone' => PhoneCast::class,
            'cpf' => CpfCast::class,
            'status' => RegistrationRequestStatus::class,
            'supervisor_affiliation_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Affiliation, $this> */
    public function supervisorAffiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class, 'supervisor_affiliation_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $registrationRequest): void {
            foreach ([
                'name', 'cpf', 'phone', 'email', 'job_role', 'qualification', 'training',
                'professional_experience', 'decision_reason',
            ] as $attribute) {
                $value = $registrationRequest->getAttribute($attribute);

                if (is_string($value)) {
                    $value = trim($value);
                    $registrationRequest->{$attribute} = $value === '' ? null : $value;
                }
            }

            $statusValue = $registrationRequest->getAttributes()['status'] ?? null;
            $status = is_string($statusValue) ? RegistrationRequestStatus::tryFrom($statusValue) : null;
            $isDraft = $status === RegistrationRequestStatus::Draft;
            $isApproved = $status === RegistrationRequestStatus::Approved;
            $requiresDecisionReason = in_array($status, [
                RegistrationRequestStatus::Rejected,
                RegistrationRequestStatus::Cancelled,
            ], true);
            $requiredWhenSubmitted = $isDraft ? 'nullable' : 'required';

            $attributes = $registrationRequest->getAttributes();
            Validator::make($attributes, [
                'name' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'cpf' => [$requiredWhenSubmitted],
                'phone' => [$requiredWhenSubmitted, 'string', 'regex:/^\d{10,11}$/'],
                'email' => [$requiredWhenSubmitted, 'string', 'email', 'max:254'],
                'job_role' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'qualification' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'training' => ['nullable', 'string'],
                'professional_experience' => ['nullable', 'string'],
                'status' => ['required', Rule::enum(RegistrationRequestStatus::class)],
                'supervisor_affiliation_id' => [
                    'bail',
                    Rule::requiredIf($isApproved),
                    'nullable',
                    'integer',
                    Rule::exists('affiliations', 'id')->where('type', AffiliationType::Supervisor->value),
                ],
                'reviewed_at' => ['nullable', 'date'],
                'decision_reason' => [
                    Rule::requiredIf($requiresDecisionReason), 'nullable', 'string',
                ],
            ])->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'supervisor_registration_request' => 'Solicitações de cadastro não podem ser excluídas fisicamente.',
            ]);
        });
    }
}
