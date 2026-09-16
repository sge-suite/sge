<?php

namespace App\Models;

use App\Concerns\AddressValidationRules;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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
