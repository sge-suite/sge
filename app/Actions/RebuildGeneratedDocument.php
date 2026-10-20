<?php

namespace App\Actions;

use App\Models\GeneratedDocument;
use App\Models\User;
use App\Support\DocxRenderer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RebuildGeneratedDocument
{
    public function __construct(private DocxRenderer $renderer) {}

    public function handle(User $actor, GeneratedDocument $document): string
    {
        Gate::forUser($actor)->authorize('download', $document);
        $document->loadMissing('templateVersion');
        $snapshot = $document->snapshot;
        $revision = $snapshot['renderer_revision'] ?? null;
        if (! in_array($revision, DocxRenderer::SUPPORTED_REVISIONS, true) || ! is_array($snapshot['replacements'] ?? null)
            || $document->templateVersion === null || $document->templateVersion->file_sha256 !== $document->template_sha256) {
            throw ValidationException::withMessages(['document' => 'Esta emissão não possui dados compatíveis para reprodução.']);
        }
        $path = $this->renderer->render($document->templateVersion, $snapshot['replacements'], $revision);
        if (! hash_equals((string) ($snapshot['output_sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            unlink($path);
            throw ValidationException::withMessages(['document' => 'A reprodução não corresponde ao documento original. Nenhum arquivo foi entregue.']);
        }

        return $path;
    }
}
