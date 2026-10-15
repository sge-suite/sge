<?php

namespace App\Actions;

use App\Models\GrantingParty;
use App\Models\User;
use App\Support\ActiveAffiliationContext;
use App\Support\GrantingPartyCsv;
use App\Support\GrantingPartyImportValidator;
use Illuminate\Support\Facades\Gate;

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
            'headers' => $parsed['headers'],
            'rows' => [], 'duplicates' => [],
        ];
        $documents = [];
        foreach ($parsed['rows'] as $record) {
            $validation = $record['blank'] ? ['payload' => [], 'errors' => []] : $this->validator->validate($record['values']);
            $errors = [...$record['errors'], ...$validation['errors']];
            $row = [
                ...$record, 'payload' => $validation['payload'],
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
            unset($row['values'], $row['blank']);
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
