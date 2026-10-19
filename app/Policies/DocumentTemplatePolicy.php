<?php

namespace App\Policies;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Support\ActiveAffiliationContext;

class DocumentTemplatePolicy
{
    public function __construct(private ActiveAffiliationContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->office($user) !== null;
    }

    public function view(User $user, DocumentTemplate $template): bool
    {
        $office = $this->office($user);

        return $office !== null && $template->exists
            && $template->campus_id === $office->campus_id;
    }

    public function create(User $user): bool
    {
        $office = $this->office($user);

        return $office !== null && $office->campus->deactivated_at === null;
    }

    public function update(User $user, DocumentTemplate $template): bool
    {
        $office = $this->office($user);

        return $office !== null && $office->campus->deactivated_at === null
            && $template->exists && $template->campus_id === $office->campus_id;
    }

    public function upload(User $user, DocumentTemplate $template): bool
    {
        return $this->update($user, $template) && $template->deactivated_at === null;
    }

    public function delete(User $user, DocumentTemplate $template): bool
    {
        return $this->update($user, $template) && ! $template->hasLinkedRecords();
    }

    private function office(User $user): ?Affiliation
    {
        $office = $this->context->currentFor($user, app('session.store'));
        if ($office?->type !== AffiliationType::InternshipOffice || $office->campus_id === null) {
            return null;
        }
        $office->load('campus');

        return $office->campus === null ? null : $office;
    }
}
