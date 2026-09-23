<?php

namespace App\Models;

use App\Casts\CnpjCast;
use App\Casts\CpfCast;
use App\Casts\PhoneCast;
use App\Enums\PartyDocumentType;
use Database\Factories\GrantingPartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'document_type', 'document_number', 'name', 'address_id',
    'representative_name', 'representative_role', 'phone', 'email',
    'field_of_activity', 'professional_council', 'council_registration_number',
    'credentialing_process_number',
])]
class GrantingParty extends Model
{
    /** @use HasFactory<GrantingPartyFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (self $party): void {
            foreach ([
                'phone', 'email', 'professional_council', 'council_registration_number',
                'credentialing_process_number',
            ] as $attribute) {
                if (blank($party->getAttributes()[$attribute] ?? null)) {
                    $party->{$attribute} = null;
                }
            }

            Validator::make($party->getAttributes(), [
                'document_type' => ['required', Rule::in(PartyDocumentType::values())],
            ])->validate();

            $number = $party->getAttributes()['document_number'] ?? null;
            if (filled($number)) {
                $type = PartyDocumentType::from($party->getAttributes()['document_type']);
                $cast = $type === PartyDocumentType::CPF ? new CpfCast : new CnpjCast;
                $party->document_number = $cast->set($party, 'document_number', $number, $party->getAttributes());
            }

            Validator::make($party->getAttributes(), [
                'document_type' => ['required', Rule::in(PartyDocumentType::values())],
                'document_number' => ['required', 'string', 'max:14'],
                'name' => ['required', 'string', 'max:255'],
                'address_id' => ['bail', 'required', 'integer', Rule::exists(Address::class, 'id')],
                'representative_name' => ['required', 'string', 'max:255'],
                'representative_role' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                'email' => ['nullable', 'string', 'email', 'max:254'],
                'field_of_activity' => ['required', 'string', 'max:255'],
                'professional_council' => ['nullable', 'string', 'max:120'],
                'council_registration_number' => ['nullable', 'string', 'max:64'],
                'credentialing_process_number' => ['nullable', 'string', 'max:100'],
            ])->validate();
        });
    }
}
