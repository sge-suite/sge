<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Internship;

class InternshipRecipients
{
    /** @return array<string, array{name: string, email: string, role: string, affiliation: Affiliation|null}> */
    public function forInternship(Internship $internship, bool $lock = false): array
    {
        $ids = [$internship->student_affiliation_id, $internship->advisor_affiliation_id, $internship->supervisor_affiliation_id];
        $affiliations = Affiliation::query()->active()->with('user')->whereIn('id', $ids)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $recipients = [];
        foreach (['student' => [$internship->student_affiliation_id, AffiliationType::Student],
            'advisor' => [$internship->advisor_affiliation_id, AffiliationType::Advisor],
            'supervisor' => [$internship->supervisor_affiliation_id, AffiliationType::Supervisor]] as $key => [$id, $type]) {
            $affiliation = $affiliations->get($id);
            if ($affiliation === null || $affiliation->type !== $type
                || ($type !== AffiliationType::Supervisor && $affiliation->campus_id !== $internship->course->campus_id)
                || ! filter_var($affiliation->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $recipients[$key] = ['name' => $affiliation->user->name, 'email' => $affiliation->email,
                'role' => $type->label(), 'affiliation' => $affiliation];
        }
        $party = $internship->grantingParty()->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($party !== null && filter_var($party->email, FILTER_VALIDATE_EMAIL)) {
            $recipients['granting_party'] = ['name' => $party->representative_name ?: $party->name,
                'email' => $party->email, 'role' => 'Representante da concedente', 'affiliation' => null];
        }

        return $recipients;
    }
}
