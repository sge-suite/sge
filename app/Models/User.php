<?php

namespace App\Models;

use App\Casts\CpfCast;
use App\Enums\AffiliationType;
use App\Helpers\BrazilianDocumentHelper;
use App\Notifications\QueuedPasswordReset;
use App\Support\EmailRecipientIdentity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property string $name
 * @property string $cpf
 * @property string $email
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'cpf', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable, Searchable;

    public function shouldBeSearchable(): bool
    {
        return $this->affiliations()->exists();
    }

    public static function affiliationSearchScope(string $scope, string $role = 'all', string $status = 'all'): string
    {
        return implode(':', [$scope, $role, $status]);
    }

    /** @return array{id: int, name: string, cpf: string, formatted_cpf: string, email: string, affiliation_scopes: list<string>} */
    public function toSearchableArray(): array
    {
        $this->loadMissing('affiliations');
        $scopes = [];
        foreach ($this->affiliations as $affiliation) {
            $contexts = $affiliation->type === AffiliationType::SystemAdministrator ? [] : ['campus-'.$affiliation->campus_id];
            if (in_array($affiliation->type, [AffiliationType::SystemAdministrator, AffiliationType::InternshipOffice], true)) {
                $contexts[] = 'administrative';
            }
            foreach ($contexts as $context) {
                foreach (['all', $affiliation->type->value] as $role) {
                    foreach (['all', $affiliation->deactivated_at === null ? 'active' : 'inactive'] as $status) {
                        $scopes[] = self::affiliationSearchScope($context, $role, $status);
                    }
                }
            }
        }

        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'cpf' => $this->cpf,
            'formatted_cpf' => BrazilianDocumentHelper::formatCpf($this->cpf),
            'email' => $this->email,
            'affiliation_scopes' => array_values(array_unique($scopes)),
        ];
    }

    /**
     * @param  Collection<int, self>  $models
     * @return Collection<int, self>
     */
    public function makeSearchableUsing(Collection $models): Collection
    {
        return $models->load('affiliations');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->has('affiliations')->with('affiliations');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['password'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::updated(function (self $user): void {
            if ($user->wasChanged('name')) {
                $userId = $user->id;
                DB::afterCommit(function () use ($userId): void {
                    Affiliation::query()->where('user_id', $userId)->eachById(function (Affiliation $affiliation): void {
                        $affiliation->searchable();
                    });
                });
            }
            if ($user->wasChanged('password')) {
                activity()
                    ->performedOn($user)
                    ->event('password_changed')
                    ->log('Senha alterada');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cpf' => CpfCast::class,
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function sendPasswordResetNotification($token): void
    {
        $identity = app(EmailRecipientIdentity::class)->forAccount($this);
        $this->notify((new QueuedPasswordReset($token, $identity))->afterCommit());
    }

    /** @return HasOne<UserPersonalData, $this> */
    public function personalData(): HasOne
    {
        return $this->hasOne(UserPersonalData::class);
    }

    /** @return HasMany<Affiliation, $this> */
    public function affiliations(): HasMany
    {
        return $this->hasMany(Affiliation::class);
    }

    /** @return HasMany<SupervisorQualification, $this> */
    public function supervisorQualifications(): HasMany
    {
        return $this->hasMany(SupervisorQualification::class)->orderBy('id');
    }

    public function hasLinkedRecords(): bool
    {
        return $this->personalData()->exists()
            || $this->notifications()->exists()
            || Media::query()->where('model_type', $this->getMorphClass())->where('model_id', $this->id)->exists()
            || $this->affiliations()->whereNotIn('type', AffiliationType::administrativeValues())->exists()
            || $this->affiliations()->get()->contains(fn (Affiliation $affiliation): bool => $affiliation->hasLinkedRecords());
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            $this->personalData()->first()?->delete();

            return parent::delete();
        });
    }
}
