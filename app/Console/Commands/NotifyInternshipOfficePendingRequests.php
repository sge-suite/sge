<?php

namespace App\Console\Commands;

use App\Enums\AffiliationType;
use App\Enums\InternshipCancellationRequestStatus;
use App\Models\Affiliation;
use App\Models\InternshipCancellationRequest;
use App\Models\InternshipRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class NotifyInternshipOfficePendingRequests extends Command
{
    protected $signature = 'internship-requests:notify-office';

    protected $description = 'Agrupa solicitações e cancelamentos pendentes em avisos internos diários por vínculo do Setor';

    public function handle(): int
    {
        $day = now(config('app.timezone'))->toDateString();
        Affiliation::query()->active()->whereIn('type', AffiliationType::InternshipOffice->databaseValues())
            ->whereHas('campus', fn ($query) => $query->whereNull('deactivated_at'))->eachById(function (Affiliation $recipient) use ($day): void {
                DB::transaction(function () use ($recipient, $day): void {
                    $recipient = Affiliation::query()->active()->lockForUpdate()->find($recipient->id);
                    if ($recipient === null || $recipient->type !== AffiliationType::InternshipOffice
                        || $recipient->campus_id === null || ! $recipient->campus()->whereNull('deactivated_at')->exists()) {
                        return;
                    }
                    $count = InternshipRequest::query()->whereIn('status', ['submitted', 'under_review'])
                        ->whereHas('affiliation', fn ($query) => $query->where('campus_id', $recipient->campus_id))->count();
                    $cancellations = InternshipCancellationRequest::query()->whereIn('status', [InternshipCancellationRequestStatus::Submitted, InternshipCancellationRequestStatus::UnderReview])
                        ->whereHas('internship.course', fn ($query) => $query->where('campus_id', $recipient->campus_id))->count();
                    if ($count === 0 && $cancellations === 0) {
                        return;
                    }
                    if ($count > 0) {
                        $recipient->notifications()->firstOrCreate(['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'sge:internship-requests:office:'.$recipient->id.':'.$day)->toString()], [
                            'type' => self::class,
                            'data' => ['title' => 'Solicitações de estágio aguardando análise', 'message' => $count.' solicitação(ões) aguardando análise no campus.',
                                'date' => $day, 'campus_id' => $recipient->campus_id, 'count' => $count, 'route' => 'internship-review.index'],
                        ]);
                    }
                    if ($cancellations > 0) {
                        $recipient->notifications()->firstOrCreate(['id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'sge:internship-cancellations:office:'.$recipient->id.':'.$day)->toString()], [
                            'type' => self::class,
                            'data' => ['title' => 'Pedidos de cancelamento aguardando análise', 'message' => $cancellations.' pedido(s) de cancelamento aguardando análise no campus.',
                                'date' => $day, 'campus_id' => $recipient->campus_id, 'count' => $cancellations, 'route' => 'internships.index'],
                        ]);
                    }
                });
            });

        return self::SUCCESS;
    }
}
