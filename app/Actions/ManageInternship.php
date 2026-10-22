<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Internship;
use App\Models\User;
use App\Support\StudentInternshipContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class ManageInternship
{
    public function __construct(private StudentInternshipContext $context, private CauserResolver $causerResolver, private ProjectInternshipEndDate $project, private SyncInternshipExecutionStatus $sync) {}

    /** @param array{observations?: ?string, starts_at?: string, ends_at?: string, reason?: string, date?: string, is_working_day?: bool} $data */
    public function handle(User $actor, Internship $target, int $expectedAffiliationId, string $operation, array $data = [], ?int $recordId = null): Internship
    {
        $ability = match ($operation) {
            'update' => 'update',
            'save_pause' => $recordId === null ? 'createPause' : 'managePauses',
            'remove_pause' => 'managePauses',
            'save_override', 'recalculate' => 'manageCalendar',
            default => abort(404),
        };
        Gate::forUser($actor)->authorize($ability, $target);

        return DB::transaction(function () use ($actor, $target, $expectedAffiliationId, $operation, $ability, $data, $recordId): Internship {
            abort_unless($this->context->selected($actor)?->id === $expectedAffiliationId, 403, 'O vínculo selecionado mudou. Reabra o estágio.');
            $affiliation = Affiliation::query()->lockForUpdate()->findOrFail($expectedAffiliationId);
            abort_unless($affiliation->user_id === $actor->id && $affiliation->deactivated_at === null && $affiliation->type === AffiliationType::InternshipOffice, 403);
            $campus = Campus::query()->lockForUpdate()->findOrFail($affiliation->campus_id);
            $campus->assertWritable();
            $internship = Internship::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize($ability, $internship);

            return $this->causerResolver->withCauser($affiliation, function () use ($internship, $operation, $data, $recordId): Internship {
                if ($operation === 'update') {
                    $values = Validator::make($data, ['observations' => ['nullable', 'string', 'max:10000']])->validate();
                    $internship->fill($values)->save();

                    return $internship;
                }
                if ($operation === 'save_pause') {
                    $values = Validator::make($data, ['starts_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$internship->planned_start_date->toDateString()],
                        'ends_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_at', 'before_or_equal:'.$internship->planned_start_date->copy()->addYears(10)->toDateString()],
                        'reason' => ['required', 'string', 'max:1000']])->validate();
                    foreach ($internship->planned_pauses ?? [] as $plannedPause) {
                        if ($values['starts_at'] <= $plannedPause['ends_at'] && $values['ends_at'] >= $plannedPause['starts_at']) {
                            throw ValidationException::withMessages(['starts_at' => 'O período se sobrepõe a uma pausa prevista na abertura.']);
                        }
                    }
                    $pause = $recordId === null ? $internship->pauses()->make() : $internship->pauses()->lockForUpdate()->findOrFail($recordId);
                    $pause->fill($values)->save();
                } elseif ($operation === 'remove_pause') {
                    $internship->pauses()->lockForUpdate()->findOrFail($recordId)->delete();
                } elseif ($operation === 'save_override') {
                    $values = Validator::make($data, ['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$internship->planned_start_date->toDateString(),
                        'before_or_equal:'.$internship->planned_start_date->copy()->addYears(10)->toDateString()],
                        'is_working_day' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']])->validate();
                    $override = $recordId === null ? $internship->calendarOverrides()->make() : $internship->calendarOverrides()->lockForUpdate()->findOrFail($recordId);
                    $override->fill($values)->save();
                }
                $internship->projected_end_date = $this->project->handle($internship);
                if ($operation === 'save_override') {
                    $internship->setUpdatedAt($internship->freshTimestamp());
                }
                $internship->save();
                if (in_array($operation, ['save_pause', 'remove_pause'], true)) {
                    $this->sync->handle($internship, CarbonImmutable::today(config('app.timezone')));
                }

                return $internship;
            });
        }, attempts: 3);
    }
}
