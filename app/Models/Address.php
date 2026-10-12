<?php

namespace App\Models;

use App\Concerns\AddressValidationRules;
use App\Helpers\BrazilianAddressHelper;
use App\Helpers\DigitsHelper;
use App\Support\AddressActivityContext;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $city_id
 * @property string $street
 * @property string $number
 * @property string $neighborhood
 * @property string $zip_code
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

    /** @var list<array{type: string, id: int, name: string, campus_id: int|null}>|null */
    public ?array $auditOwners = null;

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

        return Campus::query()
            ->where('address_id', $addressId)
            ->where('id', '!=', $campus->getKey())
            ->exists()
            || UserPersonalData::query()->where('address_id', $addressId)->exists()
            || GrantingParty::query()->where('address_id', $addressId)->exists()
            || Internship::query()
                ->where(function ($query) use ($addressId): void {
                    $query->where('student_address_id', $addressId)
                        ->orWhere('workplace_address_id', $addressId);
                })
                ->exists();
    }

    public function hasReferences(): bool
    {
        return Campus::query()->where('address_id', $this->id)->exists()
            || GrantingParty::query()->where('address_id', $this->id)->exists()
            || UserPersonalData::query()->where('address_id', $this->id)->exists()
            || Internship::query()->where(fn ($query) => $query->where('student_address_id', $this->id)
                ->orWhere('workplace_address_id', $this->id))->exists();
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        app(AddressActivityContext::class)->record($activity, $this);
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
            $attributes = $address->getAttributes();
            $attributes['zip_code'] = BrazilianAddressHelper::cepForValidation($attributes['zip_code'] ?? null);
            Validator::make($attributes, $address->addressRules())->validate();
            $address->zip_code = DigitsHelper::only($attributes['zip_code']);
        });
    }
}
