<?php

namespace App\Actions;

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\GrantingParty;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use App\Support\GrantingPartyImportValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProcessGrantingPartyImport
{
    public function __construct(
        private ActiveAffiliationContext $context,
        private GrantingPartyImportValidator $validator,
        private ManageGrantingParty $manage,
    ) {}

    /** @param array<string, mixed> $import */
    public function authorize(User $actor, array $import, bool $write = false): void
    {
        abort_if($import === [], 404);
        Gate::forUser($actor)->authorize($write ? 'create' : 'viewAny', GrantingParty::class);
        $affiliation = $this->context->currentFor($actor, app('session.store'));
        abort_unless($actor->id === $import['user_id'] && $affiliation?->campus_id === $import['campus_id'], 403);
        if ($write) {
            abort_unless($affiliation?->id === $import['affiliation_id'], 403);
        }
    }

    /**
     * @param  array<string, mixed>  $import
     * @return array<string, mixed>
     */
    public function handle(User $actor, array $import): array
    {
        $this->authorize($actor, $import, true);
        abort_unless($import['status'] === 'running', 409);
        $import['interruption'] = null;
        $processed = 0;
        $started = microtime(true);
        foreach ($import['rows'] as $position => $row) {
            if ($row['status'] !== 'pending') {
                continue;
            }
            if ($processed >= 25 || ($processed > 0 && microtime(true) - $started >= 5)) {
                break;
            }
            $processed++;
            try {
                $import['rows'][$position] = DB::transaction(function () use ($actor, $import, $row): array {
                    $affiliation = Affiliation::query()->whereKey($import['affiliation_id'])->lockForUpdate()->firstOrFail();
                    Campus::query()->whereKey($import['campus_id'])->lockForUpdate()->firstOrFail();
                    $this->authorize($actor, $import, true);
                    abort_unless($affiliation->deactivated_at === null, 403);
                    $values = [];
                    foreach ($row['original'] as $header => $value) {
                        $values[mb_strtolower(trim($header))] = $value;
                    }
                    $validation = $this->validator->validate($values);
                    if ($validation['errors'] !== []) {
                        return [...$row, 'status' => 'error', 'errors' => $validation['errors']];
                    }
                    if ($validation['payload']['address']['city_id'] !== $row['payload']['address']['city_id']) {
                        return [...$row, 'status' => 'error', 'errors' => ['cidade' => ['O catálogo mudou após a prévia. Revise e reenvie esta linha.']]];
                    }
                    $createdIds = [];
                    foreach ($import['rows'] as $previous) {
                        if ($previous['status'] === 'imported') {
                            $createdIds[] = $previous['granting_party_id'];
                        }
                    }
                    $current = $this->validator->duplicates($validation['payload'], $import['campus_id'], $createdIds);
                    if (array_column($current, 'id') !== $row['duplicate_ids']) {
                        return [...$row, 'status' => 'error', 'errors' => ['documento' => ['Os cadastros com este documento mudaram após a prévia. Revise e reenvie para confirmar a duplicidade.']]];
                    }
                    if ($row['duplicate_required'] && ! $row['duplicate_approved']) {
                        return [...$row, 'status' => 'ignored', 'reason' => 'Duplicidade não autorizada.'];
                    }
                    $party = $this->manage->handle($actor, null, 'create', $validation['payload']);

                    return [...$row, 'status' => 'imported', 'granting_party_id' => $party->id];
                }, 3);
            } catch (AuthorizationException|HttpExceptionInterface $exception) {
                throw $exception;
            } catch (ValidationException $exception) {
                $import['rows'][$position] = [...$row, 'status' => 'error', 'errors' => $exception->errors()];
            } catch (InvalidArgumentException) {
                $import['rows'][$position] = [...$row, 'status' => 'error', 'errors' => ['documento' => ['Não foi possível validar documento ou telefone. Confira os valores.']]];
            } catch (QueryException $exception) {
                $this->logFailure($exception, $import, $row);
                $state = (string) ($exception->errorInfo[0] ?? '');
                if (str_starts_with($state, '23') || str_starts_with($state, '22')) {
                    $import['rows'][$position] = [...$row, 'status' => 'error', 'errors' => ['linha' => ['A gravação encontrou um conflito de integridade. Revise e reenvie esta linha.']]];
                } else {
                    $import['interruption'] = 'O banco está indisponível ou não concluiu a operação. O processamento foi pausado. Confira os cadastros antes de tentar novamente.';
                    break;
                }
            } catch (Throwable $exception) {
                $this->logFailure($exception, $import, $row);
                $import['interruption'] = 'O processamento foi pausado por uma falha inesperada. As linhas concluídas foram preservadas.';
                break;
            }
        }

        if (self::counts($import)['pending'] === 0) {
            $import['status'] = 'completed';
        }

        return $import;
    }

    /**
     * @param  array<string, mixed>  $import
     * @return array<string, int>
     */
    public static function counts(array $import): array
    {
        $counts = ['ready' => 0, 'pending' => 0, 'imported' => 0, 'ignored' => 0, 'error' => 0];
        foreach ($import['rows'] ?? [] as $row) {
            $counts[$row['status']]++;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $import
     * @param  array<string, mixed>  $row
     */
    private function logFailure(Throwable $exception, array $import, array $row): void
    {
        Log::error('Falha na importação de concedente.', [
            'campus_id' => $import['campus_id'], 'line' => $row['line'], 'exception' => $exception::class,
            'sql_state' => $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null,
        ]);
    }
}
