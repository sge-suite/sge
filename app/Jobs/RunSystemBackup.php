<?php

namespace App\Jobs;

use App\Models\Affiliation;
use App\Support\BackupManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunSystemBackup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(public int $actorAffiliationId) {}

    public function uniqueId(): string
    {
        return 'sge-system-backup';
    }

    public function handle(BackupManager $manager): void
    {
        $actor = Affiliation::find($this->actorAffiliationId);
        $manager->markRunning('full');

        try {
            $exitCode = Artisan::call('backup:run');

            if ($exitCode === 0) {
                $manager->recordAudit($actor, 'backup_created', 'Backup gerado manualmente pelo administrador.', [
                    'mode' => 'full',
                    'disks' => $manager->disks(),
                ]);
            } else {
                $manager->recordAudit($actor, 'backup_failed', 'Falha ao gerar backup manual.', [
                    'mode' => 'full',
                    'exit_code' => $exitCode,
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Manual backup failed', [
                'mode' => 'full',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $manager->recordAudit($actor, 'backup_failed', 'Falha ao gerar backup manual.', [
                'mode' => 'full',
            ]);

            throw $exception;
        } finally {
            $manager->clearRunning();
        }
    }
}
