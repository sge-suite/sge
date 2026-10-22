<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentOrigin;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Enums\InternshipStatus;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Support\GeneratedDocumentSnapshot;
use App\Support\StudentInternshipContext;

class GeneratedDocumentPolicy
{
    public function __construct(private InternshipPolicy $internships, private StudentInternshipContext $context,
        private GeneratedDocumentSnapshot $snapshots) {}

    public function viewAny(User $user): bool
    {
        return in_array($this->context->selected($user)?->type, [AffiliationType::InternshipOffice, AffiliationType::Coordinator], true);
    }

    public function view(User $user, GeneratedDocument $document): bool
    {
        $document->loadMissing('internship.course.campus');

        return $this->internships->viewDocuments($user, $document->internship)
            && ($this->context->selected($user)->type === AffiliationType::InternshipOffice
                || ($this->context->selected($user)->type === AffiliationType::Coordinator
                    && $document->type === GeneratedDocumentType::OrientationCertificate));
    }

    public function download(User $user, GeneratedDocument $document): bool
    {
        if (! $this->view($user, $document) || $document->origin !== GeneratedDocumentOrigin::SGE) {
            return false;
        }
        if ($this->context->selected($user)->type === AffiliationType::Coordinator) {
            foreach (array_keys($document->snapshot['values'] ?? []) as $name) {
                if (! is_string($name) || ! $this->snapshots->allowedForCoordinator($name)) {
                    return false;
                }
            }
        }

        return ($document->snapshot['schema_revision'] ?? null) === 1;
    }

    public function cancel(User $user, GeneratedDocument $document): bool
    {
        return $this->manage($user, $document)
            && in_array($document->status, [GeneratedDocumentStatus::Generated, GeneratedDocumentStatus::AwaitingSignature], true);
    }

    public function updateProtocol(User $user, GeneratedDocument $document): bool
    {
        return $this->view($user, $document)
            && $this->context->selected($user)->type === AffiliationType::InternshipOffice
            && $document->internship->course->campus->deactivated_at === null
            && $document->internship->course->deactivated_at === null
            && $document->status !== GeneratedDocumentStatus::Cancelled;
    }

    public function requestSignature(User $user, GeneratedDocument $document): bool
    {
        return $this->manage($user, $document) && $document->type !== GeneratedDocumentType::OrientationCertificate
            && $document->status === GeneratedDocumentStatus::Generated;
    }

    public function notifySignature(User $user, GeneratedDocument $document): bool
    {
        return $this->confirmSignature($user, $document);
    }

    public function confirmSignature(User $user, GeneratedDocument $document): bool
    {
        return $this->manage($user, $document) && $document->type !== GeneratedDocumentType::OrientationCertificate
            && $document->status === GeneratedDocumentStatus::AwaitingSignature;
    }

    private function manage(User $user, GeneratedDocument $document): bool
    {
        return $this->view($user, $document)
            && $this->context->selected($user)->type === AffiliationType::InternshipOffice
            && $document->internship->course->campus->deactivated_at === null
            && $document->internship->course->deactivated_at === null
            && ! in_array($document->internship->status, [InternshipStatus::Completed, InternshipStatus::Cancelled, InternshipStatus::PendingCorrection], true);
    }
}
