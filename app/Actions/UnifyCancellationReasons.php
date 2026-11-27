<?php

namespace App\Actions;

use App\Enums\InternshipCancellationRequestStatus;
use App\Enums\InternshipStatus;
use App\Models\Internship;
use App\Models\InternshipCancellationRequest;
use App\Models\User;
use App\Support\InternshipIndicators;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

class UnifyCancellationReasons
{
    public function __construct(
        private ManageDocumentTemplate $templates,
        private InternshipIndicators $indicators,
        private CauserResolver $causers,
    ) {}

    /**
     * @param  list<string>  $groupKeys
     * @param  array<string, mixed>  $filters
     */
    public function handle(User $actor, int $expectedAffiliationId, array $groupKeys, string $reportReason, array $filters = []): int
    {
        $reportReason = trim(preg_replace('/\s+/u', ' ', $reportReason) ?? '');
        $validated = Validator::make([
            'group_keys' => array_values(array_unique(array_filter($groupKeys, is_string(...)))),
            'report_reason' => $reportReason,
        ], [
            'group_keys' => ['required', 'array', 'min:1', 'max:50'],
            'group_keys.*' => ['required', 'string', 'max:100', 'not_in:missing'],
            'report_reason' => ['required', 'string', 'max:255'],
        ], [
            'group_keys.required' => 'Selecione ao menos um motivo para unificar.',
            'group_keys.min' => 'Selecione ao menos um motivo para unificar.',
            'group_keys.*.not_in' => 'Cancelamentos sem justificativa registrada não podem ser unificados em lote.',
            'report_reason.required' => 'Informe o motivo unificado.',
            'report_reason.max' => 'O motivo unificado deve ter no máximo 255 caracteres.',
        ])->validate();

        return DB::transaction(function () use ($actor, $expectedAffiliationId, $validated, $filters): int {
            $office = $this->templates->lockOffice($actor, $expectedAffiliationId);
            Gate::forUser($actor)->authorize('unifyCancellationReasons', Internship::class);

            $filterValidator = $this->indicators->validator($office, ['metric' => 'cancellations'] + $filters);
            if ($filterValidator->fails()) {
                throw ValidationException::withMessages($filterValidator->errors()->toArray());
            }

            $dataset = $this->indicators->dataset($office, $filterValidator->validated());
            $requestIds = (clone $dataset)
                ->whereIn('group_key', $validated['group_keys'])
                ->whereNotNull('cancellation_request_id')
                ->pluck('cancellation_request_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($requestIds === []) {
                throw ValidationException::withMessages([
                    'group_keys' => 'Nenhum pedido de cancelamento elegível foi encontrado no recorte atual.',
                ]);
            }

            $requests = InternshipCancellationRequest::query()
                ->whereIn('id', $requestIds)
                ->where('status', InternshipCancellationRequestStatus::Approved)
                ->whereHas('internship', fn ($query) => $query->where('status', InternshipStatus::Cancelled))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            return $this->causers->withCauser($office, function () use ($requests, $validated): int {
                $updated = 0;
                foreach ($requests as $cancellation) {
                    if ($cancellation->report_reason === $validated['report_reason']) {
                        continue;
                    }
                    $cancellation->update(['report_reason' => $validated['report_reason']]);
                    $updated++;
                }

                return $updated;
            });
        }, attempts: 3);
    }
}
