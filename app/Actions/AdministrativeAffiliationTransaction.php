<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class AdministrativeAffiliationTransaction
{
    public function __construct(private ActiveAffiliationContext $context, private CauserResolver $causerResolver) {}

    /**
     * @template TResult of Model
     *
     * @param  Closure(?int): TResult  $write
     * @return TResult
     */
    public function handle(?User $actor, Closure $write): Model
    {
        $selectedId = $actor === null ? null : $this->context->currentFor($actor, app('session.store'))?->id;

        return DB::transaction(function () use ($actor, $selectedId, $write): Model {
            Affiliation::query()->where('type', AffiliationType::SystemAdministrator)->orderBy('id')->lockForUpdate()->first();

            if ($actor === null) {
                return Context::scope(fn (): Model => $write($selectedId), ['audit_actor' => 'terminal']);
            }

            $current = $this->authorizeActor($actor, $selectedId);

            return $this->causerResolver->withCauser($current, fn (): Model => $write($selectedId));
        });
    }

    public function authorizeActor(?User $actor, ?int $selectedId): ?Affiliation
    {
        if ($actor === null) {
            return null;
        }
        $current = $this->context->currentFor($actor, app('session.store'));
        abort_unless($selectedId !== null && $current?->id === $selectedId, 403);
        Gate::forUser($actor)->authorize('create', User::class);

        return $current;
    }

    public function assertNoActiveDuplicate(User $user, AffiliationType $type, ?int $campusId, ?int $exceptId = null): void
    {
        $existing = $user->affiliations()->active()->where('type', $type)->where('campus_id', $campusId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->orderBy('id')->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'type' => "Já existe o vínculo ativo #{$existing->id} ({$existing->registration_number}) para este usuário, tipo e campus. Consulte os vínculos da pessoa.",
            ]);
        }
    }
}
