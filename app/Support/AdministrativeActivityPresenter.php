<?php

namespace App\Support;

use App\Enums\AffiliationType;
use App\Enums\PartyDocumentType;
use App\Models\Address;
use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\City;
use App\Models\Course;
use App\Models\GrantingParty;
use App\Models\InternshipType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class AdministrativeActivityPresenter
{
    public function __construct(private ActiveAffiliationContext $context, private AddressActivityContext $addresses) {}

    /** @param Collection<int, Activity> $activities */
    public function prepare(Collection $activities): void
    {
        $this->addresses->prepare($activities);
    }

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
        if ($activity->subject_type === (new Address)->getMorphClass()) {
            $owners = collect($this->addresses->forActivity($activity))->map(function (array $owner): string {
                $label = match ($owner['type']) {
                    (new Campus)->getMorphClass() => 'Campus',
                    (new GrantingParty)->getMorphClass() => 'Parte concedente',
                    default => 'Cadastro',
                };

                return $label.' #'.$owner['id'].($owner['name'] !== '' ? ' · '.$owner['name'].' (nome registrado)' : '');
            })->implode('; ');

            return 'Endereço #'.$activity->subject_id.($owners !== '' ? ' · '.$owners : ' (cadastro indisponível)');
        }

        $label = match ($activity->subject_type) {
            (new Campus)->getMorphClass() => 'Campus',
            (new Course)->getMorphClass() => 'Curso',
            (new InternshipType)->getMorphClass() => 'Tipo de estágio',
            (new GrantingParty)->getMorphClass() => 'Parte concedente',
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

        if ($subject instanceof Campus || $subject instanceof Course || $subject instanceof User || $subject instanceof GrantingParty || $subject instanceof InternshipType) {
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
                'name' => 'Nome', 'cnpj' => 'CNPJ', 'phone' => 'Telefone', 'address_id' => 'Endereço',
                'legal_representative_name' => 'Representante legal', 'legal_representative_position' => 'Cargo do representante',
                'insurance_company_name' => 'Seguradora', 'insurance_policy_number' => 'Apólice de seguro',
                'deactivated_at' => 'Desativação',
            ],
            (new Address)->getMorphClass() => [
                'city_id' => 'Cidade', 'street' => 'Logradouro', 'number' => 'Número', 'neighborhood' => 'Bairro', 'zip_code' => 'CEP',
            ],
            (new GrantingParty)->getMorphClass() => [
                'campus_id' => 'Campus', 'document_type' => 'Tipo de documento', 'document_number' => 'CPF/CNPJ',
                'name' => 'Nome / razão social', 'address_id' => 'Endereço', 'representative_name' => 'Representante',
                'representative_role' => 'Cargo do representante', 'phone' => 'Telefone', 'email' => 'E-mail',
                'field_of_activity' => 'Área de atuação', 'professional_council' => 'Conselho profissional',
                'council_registration_number' => 'Registro no conselho', 'credentialing_process_number' => 'Processo de credenciamento',
                'deactivated_at' => 'Desativação',
            ],
            (new InternshipType)->getMorphClass() => [
                'course_id' => 'Curso', 'name' => 'Nome', 'required_hours' => 'Carga horária obrigatória',
                'supervisor_evaluation_weight' => 'Peso da avaliação do supervisor', 'report_weight' => 'Peso do relatório',
                'presentation_weight' => 'Peso da apresentação', 'very_good_value' => 'Conceito muito bom', 'good_value' => 'Conceito bom',
                'satisfactory_value' => 'Conceito satisfatório', 'unsatisfactory_value' => 'Conceito insatisfatório',
                'max_daily_hours' => 'Limite de horas diárias', 'max_weekly_hours' => 'Limite de horas semanais',
                'safety_margin_days' => 'Margem de segurança em dias', 'deactivated_at' => 'Desativação',
            ],
            (new User)->getMorphClass() => ['name' => 'Nome', 'cpf' => 'CPF', 'email' => 'E-mail de login'],
            (new Course)->getMorphClass() => [
                'campus_id' => 'Campus', 'name' => 'Nome',
                'primary_coordinator_affiliation_id' => 'Coordenador titular',
                'secondary_coordinator_affiliation_id' => 'Coordenador substituto',
                'deactivated_at' => 'Desativação',
            ],
            (new Affiliation)->getMorphClass() => [
                'user_id' => 'Usuário', 'campus_id' => 'Campus', 'course_id' => 'Curso',
                'type' => 'Tipo de vínculo', 'registration_number' => 'Matrícula',
                'email' => 'E-mail do vínculo', 'deactivated_at' => 'Desativação',
            ],
            default => [],
        };
        $excluded = config('activitylog.default_except_attributes', []);
        $old = $activity->attribute_changes?->get('old', []) ?? [];
        $new = $activity->attribute_changes?->get('attributes', []) ?? [];
        $references = $this->referenceValues(array_diff(array_keys($fields), $excluded), $old, $new);
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
                'before' => array_key_exists($field, $old) ? $this->value($field, $old[$field], $references) : 'Não registrado',
                'after' => array_key_exists($field, $new) ? $this->value($field, $new[$field], $references) : 'Não registrado',
            ];
        }

        return $changes;
    }

    /** @return array<string, array{model: class-string<Model>, label: string}> */
    private function referenceFields(): array
    {
        return [
            'user_id' => ['model' => User::class, 'label' => 'Conta'],
            'campus_id' => ['model' => Campus::class, 'label' => 'Campus'],
            'course_id' => ['model' => Course::class, 'label' => 'Curso'],
            'address_id' => ['model' => Address::class, 'label' => 'Endereço'],
            'city_id' => ['model' => City::class, 'label' => 'Cidade'],
            'primary_coordinator_affiliation_id' => ['model' => Affiliation::class, 'label' => 'Vínculo'],
            'secondary_coordinator_affiliation_id' => ['model' => Affiliation::class, 'label' => 'Vínculo'],
        ];
    }

    /**
     * Resolve both sides together so repeated references share one query per model.
     * Only identify references inside the viewer's selected campus.
     *
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<class-string<Model>, array<int, string>>
     */
    private function referenceValues(array $fields, array $old, array $new): array
    {
        $referenceFields = $this->referenceFields();
        $ids = [];
        foreach ($fields as $field) {
            if (! isset($referenceFields[$field])) {
                continue;
            }
            foreach ([$old[$field] ?? null, $new[$field] ?? null] as $value) {
                $id = $this->referenceId($value);
                if ($id !== null) {
                    $ids[$referenceFields[$field]['model']][$id] = $id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $actor = auth()->user();
        $selected = $actor === null ? null : $this->context->currentFor($actor, app('session.store'));
        $values = [];
        foreach ($ids as $model => $modelIds) {
            /** @var Builder<Model> $query */
            $query = $model::query();
            $query->whereKey(array_values($modelIds))->select(match ($model) {
                Affiliation::class => ['id', 'user_id'],
                City::class => ['id', 'name', 'state'],
                Address::class => ['id', 'street', 'number', 'neighborhood', 'city_id'],
                default => ['id', 'name'],
            });
            if ($model === Affiliation::class) {
                $query->with('user:id,name');
            } elseif ($model === Address::class) {
                $query->with('city');
            }
            if ($selected?->type === AffiliationType::InternshipOffice) {
                match ($model) {
                    Campus::class => $query->whereKey($selected->campus_id),
                    Course::class => $query->where('campus_id', $selected->campus_id),
                    Affiliation::class => $query->where('campus_id', $selected->campus_id)->where('type', '!=', AffiliationType::SystemAdministrator->value),
                    User::class => $query->whereHas('affiliations', fn (Builder $query): Builder => $query
                        ->where('campus_id', $selected->campus_id)->where('type', '!=', AffiliationType::SystemAdministrator->value)),
                    Address::class => $query->whereIn('id', $this->visibleAddressIds($selected->campus_id)),
                    City::class => $query,
                    default => $query->whereRaw('1 = 0'),
                };
            }
            foreach ($query->get() as $reference) {
                $name = match (true) {
                    $reference instanceof Affiliation => $reference->user->name,
                    $reference instanceof Address => $reference->street.', '.$reference->number.' · '.$reference->neighborhood
                        .' · '.$reference->city->name.'/'.$reference->city->state->value,
                    $reference instanceof City => $reference->name.'/'.$reference->state->value,
                    default => $reference->getAttribute('name'),
                };
                if (is_string($name) && $name !== '') {
                    $values[$model][$reference->getKey()] = $name;
                }
            }
        }

        return $values;
    }

    /** @return Builder<Activity> */
    private function visibleAddressIds(int $campusId): Builder
    {
        $query = Activity::query();
        $this->addresses->apply($query, $campusId);

        return $query->select('subject_id');
    }

    private function referenceId(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /** @param array<class-string<Model>, array<int, string>> $references */
    private function value(string $field, mixed $value, array $references): string
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

        $reference = $this->referenceFields()[$field] ?? null;
        if ($reference !== null) {
            $id = $this->referenceId($value);
            if ($id === null) {
                return 'Valor não exibido';
            }
            $name = $references[$reference['model']][$id] ?? null;

            return $name === null
                ? $reference['label'].' #'.$id.' (identificação indisponível)'
                : $name.' · '.$reference['label'].' #'.$id.' (identificação atual)';
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        if ($field === 'deactivated_at') {
            return formatDateTime((string) $value);
        }

        if ($field === 'document_type') {
            return PartyDocumentType::tryFrom((string) $value)?->label() ?? 'Tipo não reconhecido';
        }

        if ($field === 'type') {
            return AffiliationType::tryFrom($value === AffiliationType::LEGACY_CAMPUS_ADMINISTRATOR ? AffiliationType::InternshipOffice->value : (string) $value)?->label() ?? 'Tipo não reconhecido';
        }

        return (string) $value;
    }
}
