<?php

namespace App\Actions;

use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\InternshipRequestStatus;
use App\Enums\InternshipStatus;
use App\Enums\RegistrationRequestStatus;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\GeneratedDocument;
use App\Models\GrantingPartyRegistrationRequest;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\SupervisorRegistrationRequest;
use App\Models\User;

class GetAdministrativeDashboardMetrics
{
    /**
     * @return array{
     *     usersCount: int,
     *     affiliationsCount: int,
     *     activeAffiliationsCount: int,
     *     campusesCount: int,
     *     activeCampusesCount: int,
     *     internshipsCount: int,
     *     pendingInternshipRequestsCount: int,
     *     pendingRegistrationRequestsCount: int,
     *     maxPendingRequestsCount: int,
     *     affiliationTypes: array<int, array{label: string, value: int, active: int}>,
     *     maxAffiliationTypeCount: int,
     *     internshipsByStatus: array<int, array{label: string, value: int}>,
     *     maxInternshipStatusCount: int,
     *     documentsCount: int,
     *     documentsByStatus: array<int, array{label: string, value: int, color: string}>,
     *     maxDocumentStatusCount: int
     * }
     */
    public function __invoke(): array
    {
        $affiliationCounts = Affiliation::query()
            ->select('type')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(CASE WHEN deactivated_at IS NULL THEN 1 END) AS active')
            ->groupBy('type')
            ->toBase()
            ->get()
            ->keyBy('type');

        $affiliationTypes = collect(AffiliationType::cases())
            ->map(static function (AffiliationType $type) use ($affiliationCounts): array {
                $counts = $affiliationCounts->get($type->value);

                return [
                    'label' => $type->label(),
                    'value' => (int) ($counts->total ?? 0),
                    'active' => (int) ($counts->active ?? 0),
                ];
            })
            ->all();

        $internshipCountsByStatus = Internship::query()
            ->select('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->toBase()
            ->pluck('total', 'status');

        $internshipsByStatus = collect(InternshipStatus::cases())
            ->map(static fn (InternshipStatus $status): array => [
                'label' => $status->label(),
                'value' => (int) $internshipCountsByStatus->get($status->value, 0),
            ])
            ->all();

        $documentCountsByStatus = GeneratedDocument::query()
            ->select('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->toBase()
            ->pluck('total', 'status');

        $documentsByStatus = collect(GeneratedDocumentStatus::cases())
            ->map(static fn (GeneratedDocumentStatus $status): array => [
                'label' => $status->label(),
                'value' => (int) $documentCountsByStatus->get($status->value, 0),
                'color' => match ($status) {
                    GeneratedDocumentStatus::Generated => 'teal',
                    GeneratedDocumentStatus::AwaitingSignature => 'amber',
                    GeneratedDocumentStatus::Signed => 'green',
                    GeneratedDocumentStatus::Cancelled => 'red',
                },
            ])
            ->all();

        $campusCounts = Campus::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(CASE WHEN deactivated_at IS NULL THEN 1 END) AS active')
            ->toBase()
            ->first();

        $registrationStatuses = [
            RegistrationRequestStatus::Submitted->value,
            RegistrationRequestStatus::UnderReview->value,
        ];

        $pendingRegistrationRequestsCount = SupervisorRegistrationRequest::query()
            ->whereIn('status', $registrationStatuses)
            ->count()
            + GrantingPartyRegistrationRequest::query()
                ->whereIn('status', $registrationStatuses)
                ->count();

        $pendingInternshipRequestsCount = InternshipRequest::query()
            ->whereIn('status', [InternshipRequestStatus::Submitted->value, InternshipRequestStatus::UnderReview->value])
            ->count();

        return [
            'usersCount' => User::query()->count(),
            'affiliationsCount' => array_sum(array_column($affiliationTypes, 'value')),
            'activeAffiliationsCount' => array_sum(array_column($affiliationTypes, 'active')),
            'campusesCount' => (int) ($campusCounts->total ?? 0),
            'activeCampusesCount' => (int) ($campusCounts->active ?? 0),
            'internshipsCount' => (int) $internshipCountsByStatus->sum(),
            'pendingInternshipRequestsCount' => $pendingInternshipRequestsCount,
            'pendingRegistrationRequestsCount' => $pendingRegistrationRequestsCount,
            'maxPendingRequestsCount' => $this->maximumValue([
                ['value' => $pendingInternshipRequestsCount],
                ['value' => $pendingRegistrationRequestsCount],
            ]),
            'affiliationTypes' => $affiliationTypes,
            'maxAffiliationTypeCount' => $this->maximumValue($affiliationTypes),
            'internshipsByStatus' => $internshipsByStatus,
            'maxInternshipStatusCount' => $this->maximumValue($internshipsByStatus),
            'documentsCount' => array_sum(array_column($documentsByStatus, 'value')),
            'documentsByStatus' => $documentsByStatus,
            'maxDocumentStatusCount' => $this->maximumValue($documentsByStatus),
        ];
    }

    /**
     * @param  array<int, array{value: int}>  $items
     */
    private function maximumValue(array $items): int
    {
        return max(1, (int) max(array_column($items, 'value') ?: [0]));
    }
}
