<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\BrazilianState;
use App\Enums\HolidayScope;
use App\Models\Affiliation;
use App\Models\Holiday;
use App\Models\HolidayImportRun;
use App\Models\HolidaySource;
use App\Support\BrasilApiHolidayLookup;
use App\Support\HolidayCalendar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;
use Throwable;

class ImportHolidayTerritory
{
    public function __construct(private BrasilApiHolidayLookup $lookup, private CauserResolver $causers) {}

    public function handle(int $year, ?BrazilianState $state = null, bool $refresh = false): HolidayImportRun
    {
        abort_unless($year >= 1900 && $year <= 2199, 422, 'Ano fora do intervalo 1900–2199.');
        $territory = $state === null ? 'national' : $state->value;

        return Cache::lock('holidays:'.$year.':'.$territory, 90)->block(2, function () use ($year, $state, $refresh, $territory): HolidayImportRun {
            $run = DB::transaction(function () use ($year, $territory): HolidayImportRun {
                HolidayCalendar::lock();

                return HolidayImportRun::firstOrCreate(['year' => $year, 'territory' => $territory]);
            });
            if (! $refresh && $run->completed_at !== null && $run->status === 'completed') {
                return $run;
            }
            $run->fill(['status' => 'running', 'attempts' => $run->attempts + 1, 'queried_at' => now(), 'error' => null, 'source_url' => $this->lookup->url($year, $state)])->save();
            try {
                $payload = Cache::lock('holidays:api-request', 60)->block(2, fn (): array => $this->lookup->fetch($year, $state));

                return DB::transaction(function () use ($run, $payload, $state): HolidayImportRun {
                    HolidayCalendar::lock();
                    $actor = $this->causers->resolve();
                    if ($actor instanceof Affiliation) {
                        abort_unless(Affiliation::query()->active()->where('type', AffiliationType::SystemAdministrator)->lockForUpdate()->find($actor->id) !== null, 403);
                    }
                    $run = HolidayImportRun::query()->lockForUpdate()->findOrFail($run->id);
                    $previousSources = $run->sources()->whereNotNull('holiday_id')->get();
                    $nationalRun = $state === null ? null : HolidayImportRun::query()->where('year', $run->year)->where('territory', 'national')->first();
                    $nationalPayload = $nationalRun === null ? [] : ($nationalRun->payload ?? []);
                    $seen = [];
                    $created = 0;
                    $pending = 0;
                    foreach ($payload as $item) {
                        $scope = $this->lookup->scope($item, $state);
                        if ($scope === null && in_array($item['type'], ['facultative', 'optional'], true) && ! isset($item['state'])) {
                            foreach ($nationalPayload as $nationalItem) {
                                if ($nationalItem['date'] === $item['date'] && trim($nationalItem['name']) === trim($item['name'])) {
                                    $scope = HolidayScope::National;
                                    break;
                                }
                            }
                        }
                        if ($state !== null && $scope === HolidayScope::National) {
                            continue;
                        }
                        if ($state === null && $scope === HolidayScope::State) {
                            throw ValidationException::withMessages(['payload' => 'Feriado estadual sem UF solicitada.']);
                        }
                        $key = hash('sha256', json_encode([$run->year, $run->territory, $item['date'], mb_strtolower(trim($item['name']))], JSON_THROW_ON_ERROR));
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $source = HolidaySource::query()->where('source_key', $key)->first();
                        if ($source !== null) {
                            if ($source->status === 'linked' && $scope !== $this->lookup->scope($source->payload, $state)) {
                                $source->fill(['status' => 'pending', 'candidate_ids' => [$source->holiday_id], 'reason' => 'Classificação territorial alterada na fonte.']);
                            }
                            $source->fill(['payload' => $item, 'last_seen_at' => now(), 'missing_from_source' => false])->save();
                            $pending += (int) ($source->status === 'pending');

                            continue;
                        }
                        $holiday = null;
                        $candidates = [];
                        if ($scope !== null) {
                            $base = Holiday::withTrashed()->where('scope', $scope)->where('state_code', $scope === HolidayScope::State ? $state->value : null)->whereNull('city_id');
                            $holiday = (clone $base)->where('date', $item['date'])->where('name', trim($item['name']))->first();
                            $candidates = $run->completed_at === null ? [] : $previousSources
                                ->filter(fn (HolidaySource $old): bool => ($old->payload['date'] ?? null) === $item['date'] || mb_strtolower(trim($old->payload['name'] ?? '')) === mb_strtolower(trim($item['name'])))
                                ->pluck('holiday_id')->unique()->values()->all();
                            if ($holiday === null && $candidates === []) {
                                $holiday = Holiday::create(['date' => $item['date'], 'name' => trim($item['name']), 'scope' => $scope, 'state_code' => $scope === HolidayScope::State ? $state->value : null, 'origin' => 'brasil_api']);
                                $created++;
                            }
                        }
                        $isPending = $holiday === null;
                        HolidaySource::create([
                            'source_key' => $key, 'holiday_import_run_id' => $run->id, 'holiday_id' => $holiday?->id,
                            'status' => $isPending ? 'pending' : 'linked', 'payload' => $item, 'candidate_ids' => $candidates,
                            'reason' => $isPending ? ($scope === null ? 'Escopo da fonte não reconhecido.' : 'Possível alteração de nome ou data na fonte.') : null,
                            'last_seen_at' => now(),
                        ]);
                        $pending += (int) $isPending;
                    }
                    foreach ($run->sources()->whereNotIn('source_key', array_keys($seen))->get() as $absent) {
                        $absent->missing_from_source = true;
                        $absent->save();
                    }
                    $run->fill(['status' => 'completed', 'completed_at' => now(), 'payload' => $payload, 'summary' => ['created' => $created, 'pending' => $pending, 'received' => count($payload)], 'error' => null])->save();

                    return $run;
                });
            } catch (Throwable $exception) {
                $run->refresh()->fill(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 1000)])->save();

                return $run;
            }
        });
    }
}
