<?php

namespace App\Models;

use App\Casts\CpfCast;
use App\Notifications\QueuedPasswordReset;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

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
    use HasFactory, LogsActivity, Notifiable;

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
        $this->notify((new QueuedPasswordReset($token))->afterCommit());
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

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            $this->personalData()->first()?->delete();

            return parent::delete();
        });
    }
}
