<?php

namespace App\Support;

use App\Concerns\CampusAffiliationValidationRules;
use App\Enums\AffiliationType;
use App\Helpers\DigitsHelper;
use App\Models\Affiliation;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LaravelLegends\PtBrValidator\Rules\Cpf;

class UserImportValidator
{
    use CampusAffiliationValidationRules;

    /** @var array<int, array<string, list<int>>> */
    private array $campusCourses = [];

    /** @var array<string, User|null>|null */
    private ?array $prefetchedUsersByCpf = null;

    /** @var array<string, bool>|null */
    private ?array $prefetchedLoginEmails = null;

    private ?int $prefetchedCampusId = null;

    /** @param array<string, list<array<string, mixed>>> $buckets */
    public function warmCache(array $buckets, int $campusId): void
    {
        $this->prefetchedCampusId = $campusId;
        unset($this->campusCourses[$campusId]);
        $this->loadCampusCourses($campusId);

        $cpfs = [];
        $emails = [];
        foreach ($buckets as $rows) {
            $first = $rows[0]['payload'] ?? null;
            if (! is_array($first)) {
                continue;
            }
            if (is_string($first['cpf'] ?? null) && $first['cpf'] !== '') {
                $cpfs[] = $first['cpf'];
            }
            if (is_string($first['email'] ?? null) && $first['email'] !== '') {
                $emails[] = mb_strtolower($first['email']);
            }
        }

        $cpfs = array_values(array_unique($cpfs));
        $emails = array_values(array_unique($emails));

        $this->prefetchedUsersByCpf = [];
        foreach ($cpfs as $cpf) {
            $this->prefetchedUsersByCpf[$cpf] = null;
        }
        if ($cpfs !== []) {
            $users = User::query()
                ->whereIn('cpf', $cpfs)
                ->with(['affiliations' => fn ($query) => $query->where('campus_id', $campusId)->orderBy('id')])
                ->get();
            foreach ($users as $user) {
                $this->prefetchedUsersByCpf[$user->cpf] = $user;
            }
        }

        $this->prefetchedLoginEmails = [];
        foreach ($emails as $email) {
            $this->prefetchedLoginEmails[$email] = false;
        }
        if ($emails !== []) {
            $placeholders = implode(', ', array_fill(0, count($emails), '?'));
            $taken = User::query()
                ->whereRaw('LOWER(email) IN ('.$placeholders.')', $emails)
                ->pluck('email');
            foreach ($taken as $email) {
                $this->prefetchedLoginEmails[mb_strtolower((string) $email)] = true;
            }
        }
    }

    /** @return array<string, list<int>> */
    private function loadCampusCourses(int $campusId): array
    {
        if (! array_key_exists($campusId, $this->campusCourses)) {
            $grouped = [];
            foreach (Course::query()->active()->where('campus_id', $campusId)->get(['id', 'name']) as $course) {
                $grouped[$course->name][] = $course->id;
            }
            $this->campusCourses[$campusId] = $grouped;
        }

        return $this->campusCourses[$campusId];
    }

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

        $registrationNumber = trim($values['matricula'] ?? '');

        return [
            'cpf' => DigitsHelper::only($values['cpf'] ?? ''),
            'name' => trim($values['nome'] ?? ''),
            'email' => mb_strtolower(trim($values['email'] ?? '')),
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
        $usePrefetch = $this->prefetchedCampusId === $campusId
            && $this->prefetchedUsersByCpf !== null
            && array_key_exists($first['cpf'], $this->prefetchedUsersByCpf);
        $user = $usePrefetch
            ? $this->prefetchedUsersByCpf[$first['cpf']]
            : User::query()->where('cpf', $first['cpf'])->first();
        $account = $user === null ? null : ['id' => $user->id, 'cpf' => $user->cpf, 'name' => $user->name, 'email' => $user->email];
        $warnings = [];
        $seen = [];
        $requiresApproval = false;
        $loginTaken = $user === null && ($this->prefetchedLoginEmails !== null && $this->prefetchedCampusId === $campusId && array_key_exists($first['email'], $this->prefetchedLoginEmails)
            ? $this->prefetchedLoginEmails[$first['email']]
            : User::query()->whereRaw('LOWER(email) = ?', [$first['email']])->exists());
        $campusCourses = $this->loadCampusCourses($campusId);
        /** @var Collection<int, Affiliation>|null $prefetchedAffiliations */
        $prefetchedAffiliations = $usePrefetch && $user !== null && $user->relationLoaded('affiliations') ? $user->affiliations : null;
        foreach ($rows as &$row) {
            $data = $row['payload'];
            $data['campus_id'] = $campusId;
            $data['course_id'] = null;
            $row['operation'] = 'create';
            $row['affiliation_id'] = null;
            $row['inactive_ids'] = [];
            $row['errors'] = $row['source_errors'];
            $row['reason'] = null;

            foreach (['cpf', 'name'] as $field) {
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
                $courseIds = $campusCourses[$courseName] ?? [];
                if (count($courseIds) !== 1) {
                    $row['errors']['curso'] = ['Informe o nome exato de um único curso ativo deste campus. Nomes inexistentes ou ambíguos não são aceitos.'];
                } else {
                    $data['course_id'] = $courseIds[0];
                }
            }
            $type = AffiliationType::tryFrom($data['type']);
            $existing = null;
            if ($user !== null && $type !== null) {
                $existing = $prefetchedAffiliations !== null
                    ? $prefetchedAffiliations->first(fn (Affiliation $affiliation): bool => $affiliation->deactivated_at === null && $affiliation->type === $type)
                    : $user->affiliations()->active()->where('campus_id', $campusId)->whereIn('type', $type->databaseValues())->first();
            }
            $rules = [
                ...$this->campusAffiliationRules($campusId, $user),
                'cpf' => ['required', 'string', new Cpf],
                'name' => ['required', 'string', 'max:255'],
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
                $row['inactive_ids'] = $prefetchedAffiliations !== null
                    ? $prefetchedAffiliations
                        ->filter(fn (Affiliation $affiliation): bool => $affiliation->deactivated_at !== null && $affiliation->type === $type)
                        ->pluck('id')
                        ->values()
                        ->all()
                    : $user->affiliations()->whereNotNull('deactivated_at')
                        ->where('campus_id', $campusId)->whereIn('type', $type->databaseValues())->orderBy('id')->pluck('id')->all();
                $requiresApproval = $requiresApproval || $row['inactive_ids'] !== [];
            }
            $validation = Validator::make($data, $rules, attributes: [
                'name' => 'nome', 'email' => 'e-mail',
                'type' => 'tipo de vínculo', 'course_id' => 'curso', 'registration_number' => 'matrícula',
            ]);
            foreach ($validation->errors()->messages() as $field => $messages) {
                $header = array_search($field, UserCsv::FIELDS, true) ?: $field;
                $row['errors'][$header] = $messages;
            }
            if ($loginTaken && $row['position'] === $rows[0]['position']) {
                $row['errors']['email'] = ['O e-mail da primeira linha já é usado para acesso por outra conta. Informe outro e-mail nesta linha.'];
            }
            $identity = array_diff_key($data, array_flip(['course', 'cpf', 'name']));
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
        if ($user !== null && ($user->name !== $first['name'] || mb_strtolower(trim($user->email)) !== $first['email'])) {
            $warnings[] = 'A conta encontrada pelo CPF manterá nome, e-mail de acesso e senha. Os e-mails do CSV serão usados somente nos vínculos.';
        }
        $group = [
            'positions' => array_column($rows, 'position'), 'cpf' => $first['cpf'], 'account' => $account,
            'login_email' => $user->email ?? $first['email'],
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
