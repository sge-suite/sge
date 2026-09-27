<?php

namespace App\Models;

use App\Concerns\AddressValidationRules;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $city_id
 * @property string $street
 * @property string $number
 * @property string $neighborhood
 * @property string|null $zip_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read City $city
 */
#[Fillable(['city_id', 'street', 'number', 'neighborhood', 'zip_code'])]
class Address extends Model
{
    use AddressValidationRules;

    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'city_id' => 'integer',
            'street' => 'string',
            'number' => 'string',
            'neighborhood' => 'string',
            'zip_code' => 'string',
        ];
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return HasMany<Campus, $this> */
    public function campuses(): HasMany
    {
        return $this->hasMany(Campus::class);
    }

    public function hasReferencesOutsideCampus(Campus $campus): bool
    {
        $addressId = $this->getKey();

        return Campus::withTrashed()
            ->where('address_id', $addressId)
            ->where('id', '!=', $campus->getKey())
            ->exists()
            || UserPersonalData::query()->where('address_id', $addressId)->exists()
            || GrantingParty::withTrashed()->where('address_id', $addressId)->exists()
            || Internship::query()
                ->where(function ($query) use ($addressId): void {
                    $query->where('student_address_id', $addressId)
                        ->orWhere('workplace_address_id', $addressId);
                })
                ->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (self $address): void {
            Validator::make($address->getAttributes(), $address->addressRules())->validate();

            if (blank($address->zip_code)) {
                $address->zip_code = null;
            }
        });
    }
}
