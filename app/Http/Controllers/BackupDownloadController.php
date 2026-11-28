<?php

namespace App\Http\Controllers;

use App\Enums\AffiliationType;
use App\Support\ActiveAffiliationContext;
use App\Support\BackupManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        string $filename,
        BackupManager $manager,
        ActiveAffiliationContext $context,
    ): StreamedResponse {
        Gate::authorize('manage-backups');

        $actor = $context->currentFor($request->user(), $request->session());
        abort_unless($actor?->type === AffiliationType::SystemAdministrator, 403);

        $backup = $manager->find($filename);
        abort_if($backup === null, 404);

        $manager->recordAudit($actor, 'backup_downloaded', 'Download de backup realizado pelo administrador.', [
            'filename' => $filename,
            'disk' => $manager->primaryDisk(),
            'size_bytes' => (int) $backup->sizeInBytes(),
        ]);

        return response()->streamDownload(function () use ($backup): void {
            $stream = $backup->stream();

            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, $filename, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }
}
