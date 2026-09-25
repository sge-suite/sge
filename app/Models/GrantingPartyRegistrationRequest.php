<?php

namespace App\Models;

use App\Casts\CnpjCast;
use App\Casts\CpfCast;
use App\Casts\PhoneCast;
use App\Enums\BrazilianState;
use App\Enums\PartyDocumentType;
use App\Enums\RegistrationRequestStatus;
use App\Helpers\DigitsHelper;
use Database\Factories\GrantingPartyRegistrationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'document_type', 'document_number', 'name', 'street', 'number', 'neighborhood',
    'city', 'uf', 'zip_code', 'representative_name', 'representative_role',
    'phone', 'email', 'field_of_activity', 'professional_council',
    'council_registration_number', 'credentialing_process_number', 'status',
    'granting_party_id', 'reviewed_at', 'decision_reason',
])]
#[Hidden(['document_number'])]
class GrantingPartyRegistrationRequest extends Model
{
    /** @use HasFactory<GrantingPartyRegistrationRequestFactory> */
    use HasFactory;

    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_type' => PartyDocumentType::class,
            'uf' => BrazilianState::class,
            'phone' => PhoneCast::class,
            'status' => RegistrationRequestStatus::class,
            'granting_party_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GrantingParty, $this> */
    public function grantingParty(): BelongsTo
    {
        return $this->belongsTo(GrantingParty::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $registrationRequest): void {
            foreach ([
                'document_number', 'name', 'street', 'number', 'neighborhood',
                'city', 'zip_code', 'representative_name', 'representative_role',
                'phone', 'email', 'field_of_activity', 'professional_council',
                'council_registration_number', 'credentialing_process_number',
                'decision_reason',
            ] as $attribute) {
                $value = $registrationRequest->getAttribute($attribute);

                if (is_string($value)) {
                    $value = trim($value);
                    $registrationRequest->{$attribute} = $value === '' ? null : $value;
                }
            }

            $attributes = $registrationRequest->getAttributes();
            $status = RegistrationRequestStatus::tryFrom($attributes['status'] ?? '');
            $isDraft = $status === RegistrationRequestStatus::Draft;
            $isApproved = $status === RegistrationRequestStatus::Approved;
            $requiresDecisionReason = in_array($status, [
                RegistrationRequestStatus::Rejected,
                RegistrationRequestStatus::Cancelled,
            ], true);
            $requiredWhenSubmitted = $isDraft ? 'nullable' : 'required';

            Validator::make($attributes, [
                'document_type' => [$requiredWhenSubmitted, 'required_with:document_number', Rule::in(PartyDocumentType::values())],
                'status' => ['required', Rule::enum(RegistrationRequestStatus::class)],
            ])->validate();

            if (filled($attributes['document_number'] ?? null)) {
                $type = PartyDocumentType::from($attributes['document_type']);
                $cast = $type === PartyDocumentType::CPF ? new CpfCast : new CnpjCast;
                $registrationRequest->document_number = $cast->set($registrationRequest, 'document_number', $attributes['document_number'], $attributes);
            }

            if (filled($registrationRequest->zip_code)) {
                $zipCode = DigitsHelper::only($registrationRequest->zip_code);
                $registrationRequest->zip_code = $zipCode === '' ? null : $zipCode;
            }

            Validator::make($registrationRequest->getAttributes(), [
                'document_type' => [$requiredWhenSubmitted, Rule::in(PartyDocumentType::values())],
                'document_number' => [$requiredWhenSubmitted, 'string'],
                'name' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'street' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'number' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'neighborhood' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'city' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'uf' => [$requiredWhenSubmitted, Rule::in(BrazilianState::values())],
                'zip_code' => [$requiredWhenSubmitted, 'digits:8'],
                'representative_name' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'representative_role' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                'email' => ['nullable', 'string', 'email', 'max:254'],
                'field_of_activity' => [$requiredWhenSubmitted, 'string', 'max:255'],
                'professional_council' => ['nullable', 'string', 'max:120'],
                'council_registration_number' => ['nullable', 'string', 'max:64'],
                'credentialing_process_number' => ['nullable', 'string', 'max:100'],
                'status' => ['required', Rule::enum(RegistrationRequestStatus::class)],
                'granting_party_id' => [
                    'bail',
                    Rule::requiredIf($isApproved),
                    'nullable',
                    'integer',
                    Rule::exists(GrantingParty::class, 'id'),
                ],
                'reviewed_at' => ['nullable', 'date'],
                'decision_reason' => [
                    Rule::requiredIf($requiresDecisionReason), 'nullable', 'string',
                ],
            ])->validate();
        });

        static::deleting(function (): void {
            throw ValidationException::withMessages([
                'granting_party_registration_request' => 'Solicitações de cadastro não podem ser excluídas fisicamente.',
            ]);
        });
    }
}
