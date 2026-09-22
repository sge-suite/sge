<?php

namespace App\Models;

use App\Casts\CnpjCast;
use App\Casts\PhoneCast;
use App\Concerns\CampusValidationRules;
use Database\Factories\CampusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property string|null $cnpj
 * @property string|null $phone
 * @property string|null $email
 * @property int $address_id
 * @property string|null $legal_representative_name
 * @property string|null $legal_representative_position
 * @property string|null $insurance_company_name
 * @property string|null $insurance_policy_number
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Address $address
 */
#[Fillable([
    'name',
    'cnpj',
    'phone',
    'email',
    'address_id',
    'legal_representative_name',
    'legal_representative_position',
    'insurance_company_name',
    'insurance_policy_number',
    'deactivated_at',
])]
class Campus extends Model
{
    use CampusValidationRules;

    /** @use HasFactory<CampusFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'string',
            'cnpj' => CnpjCast::class,
            'phone' => PhoneCast::class,
            'email' => 'string',
            'address_id' => 'integer',
            'legal_representative_name' => 'string',
            'legal_representative_position' => 'string',
            'insurance_company_name' => 'string',
            'insurance_policy_number' => 'string',
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Address, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /** @return HasMany<Affiliation, $this> */
    public function affiliations(): HasMany
    {
        return $this->hasMany(Affiliation::class);
    }

    /** @return HasMany<Course, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * @param  Builder<Campus>  $query
     * @return Builder<Campus>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (self $campus): void {
            $campus->nullifyBlankOptionalCampusValues();

            Validator::make($campus->getAttributes(), $campus->campusRules())->validate();
        });
    }
}
