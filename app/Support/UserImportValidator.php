<?php

namespace App\Support;

use App\Concerns\CampusAffiliationValidationRules;
use App\Enums\AffiliationType;
use App\Helpers\DigitsHelper;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LaravelLegends\PtBrValidator\Rules\Cpf;

class UserImportValidator
{
    use CampusAffiliationValidationRules;

    /** @param array<string, string> $values
     * @return array<string, mixed>
     */
    public function normalize(array $values): array
    {
        $types = [];
        foreach (AffiliationType::cases() as $type) {
            if ($type !== AffiliationType::SystemAdministrator) {
                $types[mb_strtolower($type->label())] = $type->value;
            }
        }

        $loginEmail = mb_strtolower(trim($values['email_login'] ?? ''));
        $registrationNumber = trim($values['matricula'] ?? '');

        return [
            'cpf' => DigitsHelper::only($values['cpf'] ?? ''),
            'name' => trim($values['nome'] ?? ''),
            'login_email' => $loginEmail,
            'email' => mb_strtolower(trim($values['email_vinculo'] ?? '')) ?: $loginEmail,
            'type' => $types[mb_strtolower(trim($values['tipo_vinculo'] ?? ''))] ?? '',
            'registration_number' => $registrationNumber === '' ? null : $registrationNumber,
            'course' => trim($values['curso'] ?? ''),
        ];
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function validate(array $rows, int $campusId): array
    {
        $first = $rows[0]['payload'];
        $user = User::query()->where('cpf', $first['cpf'])->first();
        $account = $user === null ? null : ['id' => $user->id, 'cpf' => $user->cpf, 'name' => $user->name, 'email' => $user->email];
        $warnings = [];
        $seen = [];
        $requiresApproval = false;
        foreach ($rows as &$row) {
            $data = $row['payload'];
            $data['campus_id'] = $campusId;
            $data['course_id'] = null;
            $row['operation'] = 'create';
            $row['affiliation_id'] = null;
            $row['inactive_ids'] = [];
            $row['errors'] = $row['source_errors'];
            $row['reason'] = null;

            foreach (['cpf', 'name', 'login_email'] as $field) {
                if ($data[$field] !== $first[$field]) {
                    $row['errors'][array_search($field, UserCsv::FIELDS, true) ?: $field] = ['Os dados da conta devem ser iguais em todas as linhas do mesmo CPF.'];
                }
            }
            $input = $row['values']['cpf'] ?? '';
            if (preg_match('/^[0-9.\s-]+$/u', $input) !== 1) {
                $row['errors']['cpf'] = ['Informe o CPF como texto, sem notação científica.'];
            }
            $courseName = $data['course'];
            if ($data['type'] === AffiliationType::Student->value && $courseName !== '') {
                $courses = Course::query()->active()->where('campus_id', $campusId)->where('name', $courseName)->get(['id']);
                if ($courses->count() !== 1) {
                    $row['errors']['curso'] = ['Informe o nome exato de um único curso ativo deste campus. Nomes inexistentes ou ambíguos não são aceitos.'];
                } else {
                    $data['course_id'] = $courses->first()->id;
                }
            }
            $type = AffiliationType::tryFrom($data['type']);
            $existing = $user === null || $type === null ? null : $user->affiliations()->active()
                ->where('campus_id', $campusId)->whereIn('type', $type->databaseValues())->first();
            $rules = [
                ...$this->campusAffiliationRules($campusId, $user),
                'cpf' => ['required', 'string', new Cpf],
                'name' => ['required', 'string', 'max:255'],
                'login_email' => $this->administrativeAffiliationEditableRules($user)['email'],
                'course' => ['prohibited_unless:type,student', 'nullable', 'string', 'max:255'],
                'type' => ['required', Rule::in(array_values(array_diff(AffiliationType::values(), [AffiliationType::SystemAdministrator->value])))],
            ];
            if ($existing !== null) {
                if ($existing->course_id === $data['course_id']
                    && $existing->registration_number === $data['registration_number']
                    && mb_strtolower(trim($existing->email)) === $data['email']) {
                    $row['operation'] = 'existing';
                    $row['affiliation_id'] = $existing->id;
                    $row['reason'] = 'Vínculo ativo já existente; nenhum dado será alterado.';
                    $rules['registration_number'] = $this->campusAffiliationEditableRules($existing, $user)['registration_number'];
                } else {
                    $row['errors']['tipo_vinculo'] = ['Já existe vínculo ativo deste tipo no campus com dados diferentes. Corrija o arquivo ou use a edição autorizada.'];
                }
            } elseif ($user !== null && $type !== null) {
                $row['inactive_ids'] = $user->affiliations()->whereNotNull('deactivated_at')
                    ->where('campus_id', $campusId)->whereIn('type', $type->databaseValues())->orderBy('id')->pluck('id')->all();
                $requiresApproval = $requiresApproval || $row['inactive_ids'] !== [];
            }
            $validation = Validator::make($data, $rules, attributes: [
                'name' => 'nome', 'login_email' => 'e-mail de login', 'email' => 'e-mail do vínculo',
                'type' => 'tipo de vínculo', 'course_id' => 'curso', 'registration_number' => 'matrícula',
            ]);
            foreach ($validation->errors()->messages() as $field => $messages) {
                $header = array_search($field, UserCsv::FIELDS, true) ?: $field;
                $row['errors'][$header] = $messages;
            }
            $loginOwner = User::query()->whereRaw('LOWER(email) = ?', [$data['login_email']])
                ->when($user !== null, fn ($query) => $query->whereKeyNot($user->id))->exists();
            if ($loginOwner) {
                $row['errors']['email_login'] = ['O CPF e o e-mail de login identificam contas diferentes. Nenhuma conta será alterada.'];
            }
            $identity = array_diff_key($data, array_flip(['course', 'cpf', 'name', 'login_email']));
            if (isset($seen[$data['type']])) {
                if ($seen[$data['type']] === $identity) {
                    $row['operation'] = 'repeated';
                    $row['reason'] = 'Repetição exata no CSV; será considerada uma única vez.';
                } else {
                    $row['errors']['tipo_vinculo'] = ['O mesmo CPF possui linhas diferentes para o mesmo tipo de vínculo e campus.'];
                }
            } else {
                $seen[$data['type']] = $identity;
            }
            unset($data['course']);
            $row['payload'] = $data;
        }
        unset($row);
        if ($user !== null && ($user->name !== $first['name'] || mb_strtolower(trim($user->email)) !== $first['login_email'])) {
            $warnings[] = 'A conta encontrada pelo CPF será preservada, inclusive nome, e-mail de login e senha. Os dados de conta informados no CSV não serão atualizados.';
        }
        $group = [
            'positions' => array_column($rows, 'position'), 'cpf' => $first['cpf'], 'account' => $account,
            'warnings' => $warnings, 'requires_approval' => $requiresApproval, 'approved' => false,
            'status' => 'ready', 'account_created' => false, 'account_id' => $user?->id,
            'created_count' => 0, 'reconciled_count' => 0,
            'review' => ['account' => $account, 'rows' => array_map(fn (array $row): array => [
                'payload' => $row['payload'], 'operation' => $row['operation'],
                'affiliation_id' => $row['affiliation_id'], 'inactive_ids' => $row['inactive_ids'],
                'errors' => $row['errors'],
            ], $rows)],
        ];
        $hasErrors = count(array_filter($rows, fn (array $row): bool => $row['errors'] !== [])) > 0;
        $group['status'] = $hasErrors ? 'error' : ($requiresApproval ? 'decision' : 'ready');
        foreach ($rows as &$row) {
            $row['status'] = $group['status'];
            if ($hasErrors && $row['errors'] === []) {
                $row['errors'] = ['grupo' => ['Pessoa não processada porque outra linha do mesmo CPF contém erro.']];
            }
        }
        unset($row);

        return ['group' => $group, 'rows' => $rows];
    }
}
