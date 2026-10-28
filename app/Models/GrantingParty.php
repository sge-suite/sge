<?php

namespace App\Models;

use App\Casts\CnpjCast;
use App\Casts\CpfCast;
use App\Casts\PhoneCast;
use App\Concerns\GrantingPartyValidationRules;
use App\Concerns\HasOwnedAddresses;
use App\Enums\PartyDocumentType;
use App\Helpers\BrazilianDocumentHelper;
use Database\Factories\GrantingPartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $campus_id
 * @property int $address_id
 * @property Carbon|null $deactivated_at
 */
#[Fillable([
    'campus_id', 'document_type', 'document_number', 'name', 'address_id',
    'representative_name', 'representative_role', 'phone', 'email',
    'field_of_activity', 'professional_council', 'council_registration_number',
    'credentialing_process_number', 'deactivated_at',
])]
class GrantingParty extends Model
{
    use GrantingPartyValidationRules;

    /** @use HasFactory<GrantingPartyFactory> */
    use HasFactory;

    use HasOwnedAddresses;
    use LogsActivity;
    use Searchable;

    /** @return array{id: int, campus_id: int, city_id: int, state: string, is_active: int, name: string, document_number: string, normalized_document_number: string, selection_label: string} */
    public function toSearchableArray(): array
    {
        $this->loadMissing('address.city');

        return [
            'id' => (int) $this->getKey(),
            'campus_id' => (int) $this->campus_id,
            'city_id' => (int) $this->address->city_id,
            'state' => $this->address->city->state->value,
            'is_active' => (int) ($this->deactivated_at === null),
            'name' => $this->name,
            'document_number' => BrazilianDocumentHelper::formatCpfCnpj($this->document_number),
            'normalized_document_number' => BrazilianDocumentHelper::normalizeCnpj($this->document_number),
            'selection_label' => $this->selectionLabel(),
        ];
    }

    /**
     * @param  Collection<int, self>  $models
     * @return Collection<int, self>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->load('address.city');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with('address.city');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
            'campus_id' => 'integer',
            'document_type' => PartyDocumentType::class,
            'document_number' => 'string',
            'name' => 'string',
            'address_id' => 'integer',
            'representative_name' => 'string',
            'representative_role' => 'string',
            'phone' => PhoneCast::class,
            'email' => 'string',
            'field_of_activity' => 'string',
            'professional_council' => 'string',
            'council_registration_number' => 'string',
            'credentialing_process_number' => 'string',
        ];
    }

    /** @return BelongsTo<Address, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function selectionLabel(): string
    {
        $city = $this->address?->city;

        return implode(' · ', array_filter([
            $this->name,
            BrazilianDocumentHelper::formatCpfCnpj($this->document_number),
            $city === null ? 'Cidade não informada' : $city->name.'/'.$city->state->value,
            filled($this->address?->street) ? $this->address->street.', '.$this->address->number : 'Endereço não informado',
            'Unidade nº '.$this->id,
        ], fn (?string $value): bool => filled($value)));
    }

    /** @return BelongsTo<Campus, $this> */
    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * @param  Builder<GrantingParty>  $query
     * @return Builder<GrantingParty>
     */
    public function scopeForCampus(Builder $query, Campus|int $campus): Builder
    {
        return $query->where('campus_id', $campus instanceof Campus ? $campus->getKey() : $campus);
    }

    /** @return HasMany<GrantingPartyRegistrationRequest, $this> */
    public function grantingPartyRegistrationRequests(): HasMany
    {
        return $this->hasMany(GrantingPartyRegistrationRequest::class);
    }

    /** @return HasMany<Internship, $this> */
    public function internships(): HasMany
    {
        return $this->hasMany(Internship::class);
    }

    /** @return HasMany<InternshipRequest, $this> */
    public function internshipRequests(): HasMany
    {
        return $this->hasMany(InternshipRequest::class);
    }

    public function hasLinkedRecords(): bool
    {
        return $this->internships()->exists() || $this->internshipRequests()->exists()
            || $this->grantingPartyRegistrationRequests()->exists();
    }

    /**
     * @param  Builder<GrantingParty>  $query
     * @return Builder<GrantingParty>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::deleting(function (self $party): void {
            if ($party->hasLinkedRecords()) {
                throw ValidationException::withMessages(['granting_party' => 'Não é possível excluir uma concedente com registros vinculados.']);
            }
        });
        static::saving(function (self $party): void {
            if ($party->exists && $party->isDirty('campus_id')) {
                throw ValidationException::withMessages([
                    'campus_id' => 'O campus de uma parte concedente não pode ser alterado depois do cadastro.',
                ]);
            }

            foreach ([
                'phone', 'email', 'professional_council', 'council_registration_number',
                'credentialing_process_number',
            ] as $attribute) {
                if (blank($party->getAttributes()[$attribute] ?? null)) {
                    $party->{$attribute} = null;
                }
            }

            Validator::make($party->getAttributes(), [
                'campus_id' => ['required', 'integer', Rule::exists(Campus::class, 'id')],
                'document_type' => ['required', Rule::in(PartyDocumentType::values())],
            ])->validate();

            $number = $party->getAttributes()['document_number'] ?? null;
            if (filled($number)) {
                $type = PartyDocumentType::from($party->getAttributes()['document_type']);
                $cast = $type === PartyDocumentType::CPF ? new CpfCast : new CnpjCast;
                $party->document_number = $cast->set($party, 'document_number', $number, $party->getAttributes());
            }

            Validator::make($party->getAttributes(), [
                ...$party->grantingPartyRules(),
                'campus_id' => ['required', 'integer', Rule::exists(Campus::class, 'id')],
                'address_id' => ['required', 'integer', Rule::exists(Address::class, 'id')],
                'deactivated_at' => ['nullable', 'date'],
            ])->validate();
        });
    }
}
