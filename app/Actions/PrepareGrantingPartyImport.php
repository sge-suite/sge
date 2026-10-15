<?php

namespace App\Actions;

use App\Models\GrantingParty;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use App\Support\GrantingPartyCsv;
use App\Support\GrantingPartyImportValidator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PrepareGrantingPartyImport
{
    public function __construct(
        private ActiveAffiliationContext $context,
        private GrantingPartyCsv $csv,
        private GrantingPartyImportValidator $validator,
    ) {}

    /** @return array<string, mixed> */
    public function handle(User $actor, string $contents, string $separator): array
    {
        Gate::forUser($actor)->authorize('create', GrantingParty::class);
        $affiliation = $this->context->currentFor($actor, app('session.store'));
        abort_if($affiliation === null, 403);
        $affiliation->load('campus');
        $parsed = $this->csv->parse($contents, $separator);
        $import = [
            'user_id' => $actor->id, 'affiliation_id' => $affiliation->id,
            'campus_id' => $affiliation->campus_id, 'campus_name' => $affiliation->campus->name,
            'separator' => $separator, 'status' => 'preview', 'interruption' => null,
            'headers' => array_values(array_filter($parsed['headers'], fn (string $header): bool => ! in_array(Str::lower(trim($header)), GrantingPartyCsv::METADATA, true))),
            'rows' => [], 'duplicates' => [],
        ];
        $documents = [];
        foreach ($parsed['rows'] as $position => $record) {
            $original = [];
            foreach ($import['headers'] as $header) {
                $original[$header] = $record['values'][Str::lower(trim($header))] ?? '';
            }
            $validation = $record['blank'] ? ['payload' => [], 'errors' => []] : $this->validator->validate($record['values']);
            $errors = [...$record['errors'], ...$validation['errors']];
            $line = $record['line_start'] === $record['line_end'] ? (string) $record['line_start'] : $record['line_start'].'–'.$record['line_end'];
            $originalLine = $record['values']['__sge_linha_original'] ?? $line;
            if (preg_match('/^[1-9][0-9]{0,8}(?:–[1-9][0-9]{0,8})?$/u', $originalLine) !== 1) {
                $errors['linha'] = ['A numeração da linha original é inválida.'];
                $originalLine = $line;
            }
            $row = [
                'position' => $position, 'line' => $line, 'original_line' => $originalLine,
                'original' => $original, 'extra' => $record['extra'], 'payload' => $validation['payload'],
                'errors' => $errors, 'status' => $record['blank'] ? 'ignored' : ($errors === [] ? 'ready' : 'error'),
                'reason' => $record['blank'] ? 'Linha vazia.' : null, 'duplicate_required' => false,
                'duplicate_approved' => false, 'duplicate_ids' => [], 'document_key' => '', 'granting_party_id' => null,
            ];
            if ($row['status'] === 'ready') {
                $key = $row['payload']['document_type'].':'.$row['payload']['document_number'];
                $documents[$key] = ($documents[$key] ?? 0) + 1;
                $import['duplicates'][$key] ??= $this->validator->duplicates($row['payload'], $affiliation->campus_id);
                $row['duplicate_ids'] = array_column($import['duplicates'][$key], 'id');
                $row['document_key'] = $key;
            }
            $import['rows'][] = $row;
        }
        foreach ($import['rows'] as &$row) {
            if ($row['status'] === 'ready') {
                $key = $row['payload']['document_type'].':'.$row['payload']['document_number'];
                $row['duplicate_required'] = $documents[$key] > 1 || $row['duplicate_ids'] !== [];
            }
        }
        unset($row);

        return $import;
    }
}
