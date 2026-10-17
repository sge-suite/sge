<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Session\Store;
use Spatie\Activitylog\Models\Activity;

class ActivityAccess
{
    public function __construct(
        private ActiveAffiliationContext $context,
        private AdministrativeActivityScope $administrativeScope,
        private CampusActivityScope $campusScope,
    ) {}

    /**
     * Return the activity query permitted by the currently selected affiliation.
     * Add future affiliation-specific visibility rules here, without bypassing policies.
     *
     * @return Builder<Activity>|null
     */
    public function forCurrentContext(User $user, Store $session): ?Builder
    {
        $affiliation = $this->context->currentFor($user, $session);

        if ($affiliation === null) {
            return null;
        }

        $query = Activity::query();

        return match ($affiliation->type) {
            AffiliationType::SystemAdministrator => $this->administrativeScope->apply($query),
            AffiliationType::InternshipOffice => $affiliation->campus_id !== null && $affiliation->campus()->exists()
                ? $this->campusScope->apply($query, $affiliation->campus_id, $user)
                : null,
            default => null,
        };
    }

    /** @return array<string, string> */
    public function entityOptionsForCurrentContext(User $user, Store $session): array
    {
        return match ($this->context->currentFor($user, $session)?->type) {
            AffiliationType::SystemAdministrator => ['campus' => 'Campi', 'address' => 'Endereços de campi', 'user' => 'Contas de usuários', 'affiliation' => 'Vínculos administrativos', 'holiday' => 'Feriados', 'holiday_recurrence' => 'Recorrências municipais'],
            AffiliationType::InternshipOffice => ['internship_request' => 'Solicitações de estágio', 'internship_request_correction' => 'Correções de solicitações', 'supervisor_registration_request' => 'Pedidos de supervisor', 'granting_party_registration_request' => 'Pedidos de concedente', 'emancipation_evidence' => 'Análises de emancipação', 'campus' => 'Meu campus', 'course' => 'Cursos', 'internship_type' => 'Tipos de estágio', 'granting_party' => 'Partes concedentes', 'address' => 'Endereços de cadastros', 'user' => 'Contas de usuários', 'affiliation' => 'Vínculos no campus'],
            default => [],
        };
    }
}
