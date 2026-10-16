<?php

namespace App\Models;

use App\Casts\PhoneCast;
use App\Concerns\HasOwnedAddresses;
use App\Concerns\UserPersonalDataValidationRules;
use App\Enums\EducationLevel;
use Database\Factories\UserPersonalDataFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $rg
 * @property string|null $rg_issuer
 * @property Carbon|null $rg_issue_date
 * @property Carbon|null $birth_date
 * @property string|null $phone
 * @property string|null $job_role
 * @property string|null $training_course
 * @property EducationLevel|null $education_level
 * @property string|null $experience_time
 * @property int|null $address_id
 * @property Carbon|null $emancipation_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Address|null $address
 */
#[Fillable(['user_id', 'rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'job_role', 'training_course', 'education_level', 'experience_time', 'address_id'])]
#[Hidden(['rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'job_role', 'training_course', 'education_level', 'experience_time', 'emancipation_verified_at'])]
class UserPersonalData extends Model
{
    /** @use HasFactory<UserPersonalDataFactory> */
    use HasFactory;

    use HasOwnedAddresses;
    use LogsActivity;
    use UserPersonalDataValidationRules;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnly(['emancipation_verified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

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
            'training_course' => 'string',
            'education_level' => EducationLevel::class,
            'experience_time' => 'string',
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

    protected function nullifyBlankOptionalValues(): void
    {
        foreach (['rg', 'rg_issuer', 'rg_issue_date', 'birth_date', 'phone', 'job_role', 'training_course', 'education_level', 'experience_time', 'address_id'] as $attribute) {
            if (blank($this->getAttributes()[$attribute] ?? null)) {
                $this->{$attribute} = null;
            }
        }
    }

    protected static function booted(): void
    {
        static::saving(function (self $personalData): void {
            $personalData->nullifyBlankOptionalValues();

            Validator::make($personalData->getAttributes(), $personalData->userPersonalDataRules())->validate();
        });
    }
}
