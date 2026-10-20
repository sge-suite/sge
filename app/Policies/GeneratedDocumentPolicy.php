<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Enums\GeneratedDocumentOrigin;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentType;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Support\GeneratedDocumentSnapshot;
use App\Support\StudentInternshipContext;

class GeneratedDocumentPolicy
{
    public function __construct(private InternshipPolicy $internships, private StudentInternshipContext $context,
        private GeneratedDocumentSnapshot $snapshots) {}

    public function view(User $user, GeneratedDocument $document): bool
    {
        $document->loadMissing('internship.course.campus');

        return $this->internships->view($user, $document->internship)
            && ($this->context->selected($user)->type === AffiliationType::InternshipOffice
                || $document->type === GeneratedDocumentType::OrientationCertificate);
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
        return $this->view($user, $document)
            && $this->context->selected($user)->type === AffiliationType::InternshipOffice
            && $this->context->selected($user)->campus->deactivated_at === null
            && $document->status === GeneratedDocumentStatus::Generated;
    }
}
