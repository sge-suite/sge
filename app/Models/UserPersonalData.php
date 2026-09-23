<?php

namespace App\Models;

use App\Casts\PhoneCast;
use App\Concerns\UserPersonalDataValidationRules;
use Database\Factories\UserPersonalDataFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $rg
 * @property string|null $rg_issuer
 * @property Carbon|null $rg_issue_date
 * @property Carbon|null $birth_date
 * @property string|null $phone
 * @property string|null $job_role
 * @property string|null $qualification
 * @property string|null $training
 * @property string|null $professional_experience
 * @property int|null $address_id
 * @property Carbon|null $emancipation_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Address|null $address
 */
#[Fillable(['user_id', 'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'job_role', 'qualification', 'training', 'professional_experience', 'address_id'])]
#[Hidden(['rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'job_role', 'qualification', 'training', 'professional_experience', 'emancipation_verified_at'])]
class UserPersonalData extends Model
{
    /** @use HasFactory<UserPersonalDataFactory> */
    use HasFactory;

    use UserPersonalDataValidationRules;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'address_id' => 'integer',
            'rg' => 'string',
            'rg_issuer' => 'string',
            'rg_issue_date' => 'date',
            'birth_date' => 'date',
            'phone' => PhoneCast::class,
            'job_role' => 'string',
            'qualification' => 'string',
            'training' => 'string',
            'professional_experience' => 'string',
            'emancipation_verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Address, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $personalData): void {
            $personalData->nullifyBlankOptionalValues();

            Validator::make($personalData->getAttributes(), $personalData->userPersonalDataRules())->validate();
        });
    }
}
