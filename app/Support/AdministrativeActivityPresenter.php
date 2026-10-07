<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class AdministrativeActivityPresenter
{
    /** @return array{actor: string, subject: string, event: string, occurred_at: string, is_creation: bool, changes: list<array{field: string, before: string, after: string}>} */
    public function present(Activity $activity): array
    {
        return [
            ...$this->summary($activity),
            'is_creation' => $activity->event === 'created',
            'changes' => $this->changes($activity),
        ];
    }

    /** @return array{actor: string, subject: string, event: string, occurred_at: string} */
    public function summary(Activity $activity): array
    {
        return [
            'actor' => $this->actor($activity),
            'subject' => $this->subject($activity),
            'event' => match ($activity->event) {
                'created' => 'Criação',
                'updated' => 'Alteração',
                'deleted' => 'Exclusão',
                'restored' => 'Restauração',
                'password_changed' => 'Senha alterada',
                default => 'Evento registrado',
            },
            'occurred_at' => formatDateTime($activity->created_at),
        ];
    }

    private function actor(Activity $activity): string
    {
        $causer = $activity->causer;

        if ($causer instanceof Affiliation) {
            return $causer->user->name
                .' · '.$causer->type->label().' · Vínculo #'.$activity->causer_id;
        }

        if ($causer instanceof User) {
            return $causer->name.' · Conta #'.$activity->causer_id;
        }

        if ($activity->causer_type === (new Affiliation)->getMorphClass()) {
            $userId = $activity->properties?->get('user_id');

            return 'Vínculo #'.$activity->causer_id.' (indisponível)'
                .(is_int($userId) ? ' · Conta #'.$userId : '');
        }

        if ($activity->causer_type === (new User)->getMorphClass()) {
            return 'Conta #'.$activity->causer_id.' (indisponível)';
        }

        return match ($activity->properties?->get('actor')) {
            'terminal' => 'Terminal',
            'system' => 'Sistema',
            default => 'Autor não registrado',
        };
    }

    private function subject(Activity $activity): string
    {
        $label = match ($activity->subject_type) {
            (new Campus)->getMorphClass() => 'Campus',
            (new User)->getMorphClass() => 'Conta',
            (new Affiliation)->getMorphClass() => 'Vínculo',
            default => 'Registro',
        };
        $subject = $activity->subject;
        $recorded = $activity->attribute_changes;
        $name = data_get($recorded, 'attributes.name') ?? data_get($recorded, 'old.name');

        if (is_string($name) && $name !== '') {
            return $label.' #'.$activity->subject_id.' · '.$name.' (nome registrado)';
        }

        if ($subject instanceof Campus || $subject instanceof User) {
            return $label.' #'.$activity->subject_id.' · '.$subject->name.' (nome atual)';
        }

        if ($subject instanceof Affiliation) {
            return $label.' #'.$activity->subject_id.' · '.$subject->type->label()
                .' · '.$subject->user->name.' (identificação atual)';
        }

        return $label.' #'.$activity->subject_id.' (indisponível)';
    }

    /** @return list<array{field: string, before: string, after: string}> */
    private function changes(Activity $activity): array
    {
        $fields = match ($activity->subject_type) {
            (new Campus)->getMorphClass() => [
                'name' => 'Nome', 'cnpj' => 'CNPJ', 'phone' => 'Telefone', 'address_id' => 'ID do endereço',
                'legal_representative_name' => 'Representante legal', 'legal_representative_position' => 'Cargo do representante',
                'insurance_company_name' => 'Seguradora', 'insurance_policy_number' => 'Apólice de seguro',
                'deactivated_at' => 'Desativação',
            ],
            (new User)->getMorphClass() => ['name' => 'Nome', 'cpf' => 'CPF', 'email' => 'E-mail de login'],
            (new Affiliation)->getMorphClass() => [
                'user_id' => 'ID da conta', 'campus_id' => 'ID do campus', 'course_id' => 'ID do curso',
                'type' => 'Tipo de vínculo', 'registration_number' => 'Registro institucional',
                'email' => 'E-mail do vínculo', 'deactivated_at' => 'Desativação',
            ],
            default => [],
        };
        $excluded = config('activitylog.default_except_attributes', []);
        $old = $activity->attribute_changes?->get('old', []) ?? [];
        $new = $activity->attribute_changes?->get('attributes', []) ?? [];
        $changes = [];

        foreach ($fields as $field => $label) {
            if (in_array($field, $excluded, true) || (! array_key_exists($field, $old) && ! array_key_exists($field, $new))) {
                continue;
            }

            if (array_key_exists($field, $old) && array_key_exists($field, $new) && $old[$field] === $new[$field]) {
                continue;
            }

            $changes[] = [
                'field' => $label,
                'before' => array_key_exists($field, $old) ? $this->value($field, $old[$field]) : 'Não registrado',
                'after' => array_key_exists($field, $new) ? $this->value($field, $new[$field]) : 'Não registrado',
            ];
        }

        return $changes;
    }

    private function value(string $field, mixed $value): string
    {
        if ($value === null) {
            return 'Sem valor';
        }

        if ($value === '') {
            return 'Vazio';
        }

        if (! is_scalar($value)) {
            return 'Valor não exibido';
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        if ($field === 'deactivated_at') {
            return formatDateTime((string) $value);
        }

        if ($field === 'type') {
            return AffiliationType::tryFrom((string) $value)?->label() ?? 'Tipo não reconhecido';
        }

        return (string) $value;
    }
}
