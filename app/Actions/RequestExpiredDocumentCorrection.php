<?php

namespace App\Actions;

use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Support\CauserResolver;

class RequestExpiredDocumentCorrection
{
    public function __construct(private ManageDocumentTemplate $templates, private ReviewInternshipRequest $review, private CauserResolver $causers) {}

    public function handle(User $actor, int $expectedAffiliationId, GeneratedDocument $target, string $reason): void
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:5000']])->validate();
        DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $reason): void {
            $office = $this->templates->lockOffice($actor, $expectedAffiliationId);
            $reference = Internship::query()->findOrFail($target->internship_id);
            $request = $reference->internshipRequest()->lockForUpdate()->firstOrFail();
            $internship = Internship::query()->with('course.campus')->lockForUpdate()->findOrFail($reference->id);
            $document = $internship->generatedDocuments()->lockForUpdate()->findOrFail($target->id);
            $document->setRelation('internship', $internship);
            Gate::forUser($actor)->authorize('requestDateCorrection', $document);
            $this->causers->withCauser($office, function () use ($actor, $request, $document, $reason): void {
                $this->review->handle($actor, $request, 'return', sections: ['internship'], sectionMessages: ['internship' => $reason]);
                activity()->performedOn($document)->event('document_date_correction_requested')
                    ->withProperties(['internship_request_id' => $request->id])->log('Correção solicitada após vencimento da data inicial');
            });
        }, attempts: 3);
    }
}
