<?php

namespace App\Actions;

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\Course;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use App\Support\UserCsv;
use App\Support\UserImportValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProcessUserImport
{
    public function __construct(
        private ActiveAffiliationContext $context,
        private PrepareUserImport $prepare,
        private UserImportValidator $validator,
        private CreateCampusAffiliation $create,
        private AdministrativeAffiliationTransaction $transaction,
    ) {}

    /** @param array<string, mixed> $import */
    public function authorize(User $actor, array $import): void
    {
        Gate::forUser($actor)->authorize('import', User::class);
        abort_if($import === [], 404);
        $selected = $this->context->currentFor($actor, app('session.store'));
        abort_unless($actor->id === $import['user_id']
            && $selected?->id === $import['affiliation_id']
            && $selected->campus_id === $import['campus_id'], 403);
    }

    /** @param array<string, mixed> $import
     * @param  list<int|string>  $approvals
     * @return array<string, mixed>
     */
    public function confirm(User $actor, array $import, array $approvals): array
    {
        $this->authorize($actor, $import);
        abort_unless($import['status'] === 'preview', 409);
        $current = $this->prepare->refresh($import);
        foreach ($import['groups'] as $id => $group) {
            if ($current['groups'][$id]['review'] !== $group['review']
                || $current['groups'][$id]['status'] !== $group['status']) {
                $current['interruption'] = 'Os cadastros mudaram após a prévia. Revise os dados atualizados e confirme novamente.';

                return $current;
            }
        }
        $approved = array_map('strval', $approvals);
        foreach ($current['groups'] as $id => &$group) {
            if ($group['status'] === 'error') {
                continue;
            }
            $group['approved'] = in_array((string) $id, $approved, true);
            $group['status'] = $group['requires_approval'] && ! $group['approved'] ? 'ignored' : 'pending';
            foreach ($group['positions'] as $position) {
                $current['rows'][$position]['status'] = $group['status'];
                if ($group['status'] === 'ignored') {
                    $current['rows'][$position]['reason'] = 'Pessoa ignorada: criação de novo vínculo diante de histórico desativado não autorizada.';
                }
            }
        }
        unset($group);
        $current['status'] = 'running';
        $current['interruption'] = null;

        return $current;
    }

    /** @param array<string, mixed> $import
     * @return array<string, mixed>
     */
    public function handle(User $actor, array $import): array
    {
        $this->authorize($actor, $import);
        abort_unless($import['status'] === 'running', 409);
        $import['interruption'] = null;
        $processed = 0;
        $started = microtime(true);
        foreach ($import['groups'] as $groupId => $group) {
            if ($group['status'] !== 'pending') {
                continue;
            }
            if ($processed >= 25 || ($processed > 0 && microtime(true) - $started >= 5)) {
                break;
            }
            $processed++;
            $committed = false;
            try {
                $result = DB::transaction(function () use ($actor, $import, $groupId, &$committed): array {
                    DB::afterCommit(function () use (&$committed): void {
                        $committed = true;
                    });

                    return $this->processPerson($actor, $import, $groupId);
                }, 3);
                $import['groups'][$groupId] = $result['group'];
                foreach ($result['rows'] as $row) {
                    $import['rows'][$row['position']] = $row;
                }
            } catch (AuthorizationException|HttpExceptionInterface $exception) {
                throw $exception;
            } catch (ValidationException $exception) {
                if ($committed) {
                    $import['interruption'] = 'Os cadastros da última pessoa foram confirmados, mas uma etapa posterior falhou. Continue para conferir os vínculos sem recriá-los.';
                    break;
                }
                $import = $this->failPerson($import, $groupId, $exception->errors());
            } catch (QueryException $exception) {
                $state = (string) ($exception->errorInfo[0] ?? '');
                $this->logFailure($exception, $import, $groupId);
                if (! $committed && (str_starts_with($state, '23') || str_starts_with($state, '22'))) {
                    $import = $this->failPerson($import, $groupId, ['grupo' => ['A pessoa não foi processada por conflito de integridade. Revise e reenvie todas as suas linhas.']]);
                } else {
                    $import['interruption'] = 'O banco não concluiu uma etapa. O processamento foi pausado. Continue para conferir os vínculos definitivos antes de tentar novamente.';
                    break;
                }
            } catch (Throwable $exception) {
                $this->logFailure($exception, $import, $groupId);
                $import['interruption'] = 'O processamento foi pausado. A última pessoa pode ter sido confirmada antes da falha de resposta ou de enfileiramento. Ao continuar, os cadastros serão conferidos sem recriar vínculos coincidentes.';
                break;
            }
        }
        if (count(array_filter($import['groups'], fn (array $group): bool => $group['status'] === 'pending')) === 0) {
            $this->authorize($actor, $import);
            $import['status'] = 'completed';
        }

        return $import;
    }

    /** @param array<string, mixed> $import
     * @param  array<string, list<string>>  $messages
     * @return array<string, mixed>
     */
    private function failPerson(array $import, int $groupId, array $messages): array
    {
        $import['groups'][$groupId]['status'] = 'error';
        foreach ($import['groups'][$groupId]['positions'] as $position) {
            $errors = [];
            foreach ($messages as $field => $fieldMessages) {
                if (str_starts_with($field, 'rows.')) {
                    $parts = explode('.', $field, 3);
                    if ((int) $parts[1] !== $position) {
                        continue;
                    }
                    $field = $parts[2];
                }
                $errors[$field] = $fieldMessages;
            }
            $import['rows'][$position]['status'] = 'error';
            $import['rows'][$position]['errors'] = $errors ?: ['grupo' => ['Pessoa não processada porque outra linha do mesmo CPF contém erro.']];
        }

        return $import;
    }

    /** @param array<string, mixed> $import */
    private function logFailure(Throwable $exception, array $import, int $groupId): void
    {
        Log::error('Falha na importação de usuários.', [
            'campus_id' => $import['campus_id'], 'group' => $groupId, 'exception' => $exception::class,
            'sql_state' => $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null,
        ]);
    }

    /** @param array<string, mixed> $import
     * @return array<string, mixed>
     */
    private function processPerson(User $actor, array $import, int $groupId): array
    {
        $this->transaction->lockWrites();
        $selected = Affiliation::query()->lockForUpdate()->findOrFail((int) $import['affiliation_id']);
        $campus = Campus::query()->lockForUpdate()->findOrFail((int) $import['campus_id']);
        $this->authorize($actor, $import);
        abort_unless($selected->deactivated_at === null, 403);
        $campus->assertWritable();
        $expected = $import['groups'][$groupId];
        User::query()->where('cpf', $expected['cpf'])->lockForUpdate()->first();
        $rows = [];
        foreach ($expected['positions'] as $position) {
            $row = $import['rows'][$position];
            $row['payload'] = $this->validator->normalize($row['values']);
            $rows[] = $row;
        }
        $courseIds = array_filter(array_column(array_column($expected['review']['rows'], 'payload'), 'course_id'));
        Course::query()->whereIn('id', $courseIds)->orderBy('id')->lockForUpdate()->get();
        $result = $this->validator->validate($rows, $import['campus_id']);
        if ($result['group']['status'] === 'error') {
            return $result;
        }
        $account = $result['group']['account'];
        if ($expected['account'] !== null && $expected['account'] !== $account) {
            throw ValidationException::withMessages(['conta' => 'Os dados da conta mudaram após a prévia. Revise e reenvie esta pessoa.']);
        }
        if ($expected['account'] === null && $account !== null
            && ($account['name'] !== $rows[0]['payload']['name'] || mb_strtolower(trim($account['email'])) !== $rows[0]['payload']['email'])) {
            throw ValidationException::withMessages(['conta' => 'Uma conta foi cadastrada após a prévia com dados diferentes. Revise e reenvie esta pessoa.']);
        }
        foreach ($result['rows'] as $index => $row) {
            $before = $expected['review']['rows'][$index];
            if ($row['payload'] !== $before['payload']
                || ($row['operation'] === 'create' && ($before['operation'] !== 'create' || $row['inactive_ids'] !== $before['inactive_ids']))
                || ($row['inactive_ids'] !== [] && ! $expected['approved'])) {
                throw ValidationException::withMessages(['rows.'.$row['position'].'.vinculo' => 'O vínculo ou o catálogo mudou após a prévia. Revise e reenvie o grupo.']);
            }
        }
        $group = [...$result['group'], 'approved' => $expected['approved']];
        $newAccount = $account === null;
        foreach ($result['rows'] as &$row) {
            if ($row['operation'] !== 'create') {
                $row['status'] = 'ignored';
                if ($row['operation'] === 'existing' && $expected['review']['rows'][array_search($row['position'], $expected['positions'], true)]['operation'] === 'create') {
                    $row['reason'] = 'Vínculo coincidente encontrado na reconciliação; nenhum novo aviso foi gerado.';
                    $group['reconciled_count']++;
                }

                continue;
            }
            try {
                $affiliation = $this->create->handle($actor, $row['payload']);
            } catch (ValidationException $exception) {
                $errors = [];
                foreach ($exception->errors() as $field => $messages) {
                    $errors['rows.'.$row['position'].'.'.(array_search($field, UserCsv::FIELDS, true) ?: $field)] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }
            $row['affiliation_id'] = $affiliation->id;
            $row['status'] = 'imported';
            $row['reason'] = 'Vínculo criado. Os avisos foram preparados para envio.';
            $group['account_id'] = $affiliation->user_id;
            $group['created_count']++;
        }
        unset($row);
        $group['account_created'] = $newAccount && $group['created_count'] > 0;
        $group['status'] = $group['created_count'] > 0 ? 'imported' : 'ignored';

        return ['group' => $group, 'rows' => $result['rows']];
    }

    /** @param array<string, mixed> $import
     * @param  list<int|string>  $approvals
     * @return array<string, int>
     */
    public static function counts(array $import, array $approvals = []): array
    {
        $counts = array_fill_keys(['ready', 'decision', 'pending', 'imported', 'ignored', 'error', 'people', 'eligible_people', 'new_accounts', 'reused_accounts', 'new_links', 'existing_links', 'created_accounts', 'created_links', 'reconciled_links', 'accounts_reused', 'blocked_people'], 0);
        foreach ($import['rows'] ?? [] as $row) {
            $counts[$row['status']]++;
        }
        $approved = array_map('strval', $approvals);
        foreach ($import['groups'] ?? [] as $id => $group) {
            $counts['people']++;
            $counts['created_accounts'] += (int) $group['account_created'];
            $counts['created_links'] += $group['created_count'];
            $counts['reconciled_links'] += $group['reconciled_count'];
            if (in_array($group['status'], ['imported', 'ignored'], true)
                && ($group['status'] === 'imported' || ! $group['requires_approval'] || $group['approved'])) {
                $counts['accounts_reused'] += (int) (! $group['account_created'] && $group['account_id'] !== null);
            }
            if ($group['status'] === 'error') {
                $counts['blocked_people']++;
            }
            if (in_array($group['status'], ['ready', 'decision'], true)
                && (! $group['requires_approval'] || in_array((string) $id, $approved, true))) {
                $counts['eligible_people']++;
                $counts[$group['account'] === null ? 'new_accounts' : 'reused_accounts']++;
                foreach ($group['positions'] as $position) {
                    $counts['new_links'] += (int) ($import['rows'][$position]['operation'] === 'create');
                    $counts['existing_links'] += (int) ($import['rows'][$position]['operation'] !== 'create');
                }
            }
        }

        return $counts;
    }
}
