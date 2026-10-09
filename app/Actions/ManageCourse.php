<?php

namespace App\Actions;

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class ManageCourse
{
    public function __construct(private ActiveAffiliationContext $context, private CauserResolver $causerResolver) {}

    /** @param array{name?: string, primary_coordinator_affiliation_id?: int|string|null, secondary_coordinator_affiliation_id?: int|string|null, current_password?: string} $data */
    public function handle(User $actor, ?Course $target, string $operation, array $data = []): Course
    {
        abort_unless(in_array($operation, ['create', 'update', 'deactivate', 'reactivate', 'delete'], true), 404);
        Gate::forUser($actor)->authorize($operation, $target ?? Course::class);

        return DB::transaction(function () use ($actor, $target, $operation, $data): Course {
            $affiliation = $this->context->currentFor($actor, app('session.store'));
            abort_if($affiliation === null, 403);
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($affiliation->id);
            $campus = Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id);
            $course = $target === null ? new Course(['campus_id' => $campus->id]) : Course::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize($operation, $target === null ? Course::class : $course);
            $campus->assertWritable();

            $coordinatorIds = collect([
                $data['primary_coordinator_affiliation_id'] ?? null,
                $data['secondary_coordinator_affiliation_id'] ?? null,
            ])->filter()->unique()->sort()->values();
            if ($coordinatorIds->isNotEmpty()) {
                Affiliation::query()->whereKey($coordinatorIds)->orderBy('id')->lockForUpdate()->get();
            }

            return $this->causerResolver->withCauser($affiliation, function () use ($actor, $course, $operation, $data): Course {
                if ($operation === 'deactivate' && ! Hash::check($data['current_password'] ?? '', $actor->fresh()->password)) {
                    throw ValidationException::withMessages(['current_password' => 'A senha informada está incorreta.']);
                }

                if ($operation === 'delete') {
                    if (! Hash::check($data['current_password'] ?? '', $actor->fresh()->password)) {
                        throw ValidationException::withMessages(['deletionPassword' => 'A senha informada está incorreta.']);
                    }

                    if ($course->hasLinkedRecords()) {
                        throw ValidationException::withMessages(['deletion' => 'Não é possível apagar este curso enquanto houver vínculos ou registros associados.']);
                    }

                    $course->delete();

                    return $course;
                }

                if (in_array($operation, ['create', 'update'], true)) {
                    $course->fill($data);
                } else {
                    $course->deactivated_at = $operation === 'deactivate' ? now() : null;
                }
                $course->save();

                return $course;
            });
        });
    }
}
