<?php

namespace App\Models;

use App\Casts\CnpjCast;
use App\Casts\PhoneCast;
use App\Concerns\CampusValidationRules;
use App\Enums\AffiliationType;
use Database\Factories\CampusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property string $cnpj
 * @property string $phone
 * @property int $address_id
 * @property string $legal_representative_name
 * @property string $legal_representative_position
 * @property string $insurance_company_name
 * @property string $insurance_policy_number
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Address $address
 * @property-read Collection<int, GrantingParty> $grantingParties
 * @property-read Collection<int, GrantingPartyRegistrationRequest> $grantingPartyRegistrationRequests
 */
#[Fillable([
    'name',
    'cnpj',
    'phone',
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
    use Searchable;
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
            'address_id' => 'integer',
            'legal_representative_name' => 'string',
            'legal_representative_position' => 'string',
            'insurance_company_name' => 'string',
            'insurance_policy_number' => 'string',
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return array{id: int, name: string, deactivated_at: string|null} */
    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'name' => $this->name,
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
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

    /** @return HasMany<DocumentTemplate, $this> */
    public function documentTemplates(): HasMany
    {
        return $this->hasMany(DocumentTemplate::class);
    }

    /** @return HasMany<GrantingParty, $this> */
    public function grantingParties(): HasMany
    {
        return $this->hasMany(GrantingParty::class);
    }

    /** @return HasMany<GrantingPartyRegistrationRequest, $this> */
    public function grantingPartyRegistrationRequests(): HasMany
    {
        return $this->hasMany(GrantingPartyRegistrationRequest::class);
    }

    public function hasLinkedRecords(): bool
    {
        return $this->affiliations()->exists()
            || $this->courses()->exists()
            || $this->documentTemplates()->exists()
            || $this->grantingParties()->withTrashed()->exists()
            || $this->grantingPartyRegistrationRequests()->exists()
            || $this->address->hasReferencesOutsideCampus($this);
    }

    /**
     * @param  Builder<Campus>  $query
     * @return Builder<Campus>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    /**
     * @param  Builder<Campus>  $query
     * @return Builder<Campus>
     */
    public function scopeVisibleTo(Builder $query, Affiliation $affiliation): Builder
    {
        if ($affiliation->deactivated_at !== null) {
            return $query->whereNull($query->getModel()->getQualifiedKeyName());
        }

        return match ($affiliation->type) {
            AffiliationType::SystemAdministrator => $query,
            AffiliationType::CampusAdministrator => $affiliation->campus_id === null
                ? $query->whereNull($query->getModel()->getQualifiedKeyName())
                : $query->whereKey($affiliation->campus_id),
            default => $query->whereNull($query->getModel()->getQualifiedKeyName()),
        };
    }

    public function assertWritable(): void
    {
        if (! $this->exists || $this->trashed() || $this->deactivated_at !== null) {
            throw ValidationException::withMessages([
                'campus' => 'Este campus está desativado e não pode ser alterado.',
            ]);
        }
    }

    public function deactivate(): bool
    {
        if (! $this->exists || $this->trashed()) {
            throw ValidationException::withMessages([
                'campus' => 'Somente um campus persistido pode ser desativado.',
            ]);
        }

        if ($this->deactivated_at !== null) {
            return false;
        }

        $this->assertWritable();
        $this->deactivated_at = now();

        return $this->save();
    }

    public function reactivate(): bool
    {
        if (! $this->exists || $this->trashed()) {
            throw ValidationException::withMessages([
                'campus' => 'Somente um campus persistido pode ser reativado.',
            ]);
        }

        if ($this->deactivated_at === null) {
            return false;
        }

        $this->deactivated_at = null;

        return $this->save();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::saving(function (self $campus): void {
            if ($campus->exists && $campus->getOriginal('deactivated_at') !== null) {
                $dirtyAttributes = array_diff(array_keys($campus->getDirty()), ['deactivated_at']);
                $isReactivation = $campus->isDirty('deactivated_at') && $campus->deactivated_at === null;

                if ($dirtyAttributes !== [] || ! $isReactivation) {
                    throw ValidationException::withMessages([
                        'campus' => 'Um campus desativado só pode ser reativado.',
                    ]);
                }
            }

            $campus->normalizeBlankCampusValues();

            Validator::make($campus->getAttributes(), $campus->campusRules())->validate();
        });
    }
}
