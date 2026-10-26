<?php

namespace App\Support;

use App\Models\Affiliation;
use App\Models\User;

class EmailRecipientIdentity
{
    /** @return array{name: string, role: string, context: list<string>} */
    public function forAffiliation(Affiliation $affiliation): array
    {
        $affiliation->loadMissing(['user', 'campus', 'course']);

        return ['name' => $affiliation->user->name, 'role' => $affiliation->type->label(),
            'context' => array_values(array_filter([
                $affiliation->campus_id === null ? 'Escopo global' : $affiliation->campus?->name,
                $affiliation->course?->name,
                $affiliation->registration_number === null ? null : 'Matrícula '.$affiliation->registration_number,
            ], fn (?string $value): bool => filled($value)))];
    }

    /** @return array{name: string, role: string, context: list<string>} */
    public function forAccount(User $account): array
    {
        $account->loadMissing('affiliations.campus', 'affiliations.course');
        $context = $account->affiliations->sortBy('id')->map(function (Affiliation $affiliation) use ($account): string {
            $affiliation->setRelation('user', $account);
            $identity = $this->forAffiliation($affiliation);

            return implode(' · ', [$identity['role'], ...$identity['context']]);
        })->values()->all();

        return ['name' => $account->name, 'role' => 'Conta de acesso',
            'context' => $context === [] ? ['Sem vínculo institucional associado'] : array_values($context)];
    }

    /** @return array{name: string, role: string, context: list<string>} */
    public function forRecord(User|Affiliation $record): array
    {
        return $record instanceof Affiliation ? $this->forAffiliation($record) : $this->forAccount($record);
    }
}
