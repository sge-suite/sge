<?php

namespace App\Actions;

use App\Models\User;
use App\Support\ActiveAffiliationContext;
use App\Support\UserCsv;
use App\Support\UserImportValidator;
use Illuminate\Support\Facades\Gate;

class PrepareUserImport
{
    public function __construct(
        private ActiveAffiliationContext $context,
        private UserCsv $csv,
        private UserImportValidator $validator,
    ) {}

    /** @return array<string, mixed> */
    public function handle(User $actor, string $contents, string $separator): array
    {
        Gate::forUser($actor)->authorize('import', User::class);
        $selected = $this->context->currentFor($actor, app('session.store'));
        $selected->load('campus');
        $parsed = $this->csv->parse($contents, $separator);
        $import = [
            'user_id' => $actor->id, 'affiliation_id' => $selected->id,
            'campus_id' => $selected->campus_id, 'campus_name' => $selected->campus->name,
            'separator' => $separator, 'headers' => $parsed['headers'], 'rows' => [], 'groups' => [],
            'status' => 'preview', 'interruption' => null,
        ];
        foreach ($parsed['rows'] as $position => $record) {
            if ($record['blank']) {
                continue;
            }
            unset($record['blank']);
            $import['rows'][$position] = [
                ...$record, 'source_errors' => $record['errors'],
            ];
        }

        return $this->refresh($import);
    }

    /** @param array<string, mixed> $import
     * @return array<string, mixed>
     */
    public function refresh(array $import): array
    {
        $buckets = [];
        foreach ($import['rows'] as $position => $row) {
            $row['payload'] = $this->validator->normalize($row['values']);
            $cpf = $row['payload']['cpf'];
            $key = strlen($cpf) === 11 ? 'cpf:'.$cpf : 'linha:'.$position;
            $buckets[$key][] = $row;
        }
        $import['groups'] = [];
        foreach ($buckets as $rows) {
            $result = $this->validator->validate($rows, $import['campus_id']);
            $groupId = count($import['groups']);
            $import['groups'][$groupId] = $result['group'];
            foreach ($result['rows'] as $row) {
                $import['rows'][$row['position']] = [...$row, 'group_id' => $groupId];
            }
        }
        $owners = [];
        foreach ($import['rows'] as $row) {
            foreach (['login_email', 'registration_number'] as $field) {
                $value = $row['payload'][$field];
                if ($value === null || $value === '') {
                    continue;
                }
                $owners[$field.':'.$value][$row['group_id']] = true;
            }
        }
        foreach ($owners as $key => $groups) {
            if (count($groups) < 2) {
                continue;
            }
            $header = str_starts_with($key, 'login_email:') ? 'email_login' : 'matricula';
            foreach (array_keys($groups) as $groupId) {
                $import['groups'][$groupId]['status'] = 'error';
                foreach ($import['groups'][$groupId]['positions'] as $position) {
                    $import['rows'][$position]['status'] = 'error';
                    $import['rows'][$position]['errors'][$header] = ['Este identificador foi informado para pessoas diferentes no arquivo.'];
                }
            }
        }

        return $import;
    }
}
