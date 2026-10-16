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
    private const int WRITE_LOCK_NAMESPACE = 74321;

    public function __construct(private ActiveAffiliationContext $context, private CauserResolver $causerResolver) {}

    /**
     * @template TResult of Model
     *
     * @param  Closure(?int): TResult  $write
     * @return TResult
     */
    public function handle(?User $actor, Closure $write, bool $allowCampusActor = false): Model
    {
        $selectedId = $actor === null ? null : $this->context->currentFor($actor, app('session.store'))?->id;

        /** @var TResult $result */
        $result = DB::transaction(function () use ($actor, $selectedId, $write, $allowCampusActor): Model {
            $this->lockWrites();
            Affiliation::query()->where('type', AffiliationType::SystemAdministrator)->orderBy('id')->lockForUpdate()->first();

            if ($actor === null) {
                return Context::scope(fn (): Model => $write($selectedId), ['audit_actor' => 'terminal']);
            }

            $current = $this->authorizeActor($actor, $selectedId, $allowCampusActor);
            if ($allowCampusActor && $current->type === AffiliationType::InternshipOffice) {
                $current = Affiliation::query()->lockForUpdate()->findOrFail($current->id);
                $current->campus()->lockForUpdate()->firstOrFail()->assertWritable();
                $this->authorizeActor($actor, $selectedId, $allowCampusActor);
            }

            return $this->causerResolver->withCauser($current, fn (): Model => $write($selectedId));
        });

        return $result;
    }

    /** Serializa alterações de contas e vínculos entre campi até o fim da transação. */
    public function lockWrites(): void
    {
        DB::select('select pg_advisory_xact_lock(?, ?)', [self::WRITE_LOCK_NAMESPACE, 1]);
    }

    public function authorizeActor(?User $actor, ?int $selectedId, bool $allowCampusActor = false): ?Affiliation
    {
        if ($actor === null) {
            return null;
        }
        $current = $this->context->currentFor($actor, app('session.store'));
        abort_unless($selectedId !== null && $current?->id === $selectedId, 403);
        Gate::forUser($actor)->authorize($allowCampusActor ? 'create' : 'viewAdministration', User::class);

        return $current;
    }

    public function assertNoActiveDuplicate(User $user, AffiliationType $type, ?int $campusId, ?int $exceptId = null): void
    {
        $existing = $user->affiliations()->active()->whereIn('type', $type->databaseValues())->where('campus_id', $campusId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->orderBy('id')->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'type' => "Já existe o vínculo ativo #{$existing->id} ({$existing->registration_number}) para este usuário, tipo e campus. Consulte os vínculos da pessoa.",
            ]);
        }
    }
}
