<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\City;
use App\Models\Holiday;
use App\Models\HolidayRecurrence;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

class HolidayAccess
{
    public function __construct(private ActiveAffiliationContext $context, private CauserResolver $causers) {}

    public function asAdministrator(User $actor, Closure $callback): mixed
    {
        return DB::transaction(function () use ($actor, $callback): mixed {
            HolidayCalendar::lock();
            $selected = $this->context->currentFor($actor, app('session.store'));
            abort_if($selected === null, 403);
            $affiliation = Affiliation::query()->active()->lockForUpdate()->find($selected->id);
            abort_unless($affiliation?->type === AffiliationType::SystemAdministrator, 403);

            return $this->causers->withCauser($affiliation, $callback);
        });
    }

    public function canManage(User $user): bool
    {
        return $this->context->currentFor($user, app('session.store'))?->type === AffiliationType::SystemAdministrator;
    }

    public function canView(User $user): bool
    {
        $affiliation = $this->context->currentFor($user, app('session.store'));

        return $affiliation?->type === AffiliationType::SystemAdministrator
            || ($affiliation?->type === AffiliationType::InternshipOffice && $affiliation->campus_id !== null && $affiliation->campus()->exists());
    }

    /** @return Builder<City> */
    public function cities(User $user): Builder
    {
        return City::query()->when(! $this->canView($user), fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    /** @return Builder<Holiday> */
    public function query(User $user, bool $withDeleted = false): Builder
    {
        $query = $withDeleted ? Holiday::withTrashed() : Holiday::query();

        return $query->when(! $this->canView($user), fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    /** @return Builder<HolidayRecurrence> */
    public function recurrences(User $user, bool $withDeleted = false): Builder
    {
        $query = $withDeleted ? HolidayRecurrence::withTrashed() : HolidayRecurrence::query();

        return $query->when(! $this->canView($user), fn (Builder $query) => $query->whereRaw('1 = 0'));
    }
}
