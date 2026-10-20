<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneTemporaryDocuments extends Command
{
    protected $signature = 'documents:prune-temporary';

    protected $description = 'Remove documentos temporários órfãos com mais de uma hora';

    public function handle(): int
    {
        $removed = 0;
        foreach (glob(storage_path('app/private/generated-documents-tmp/*.{docx,pdf}'), GLOB_BRACE) ?: [] as $path) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                if (flock($handle, LOCK_EX | LOCK_NB) && filemtime($path) < time() - 3600 && unlink($path)) {
                    $removed++;
                }
            } finally {
                fclose($handle);
            }
        }
        $this->info($removed.' documento(s) temporário(s) removido(s).');

        return self::SUCCESS;
    }
}
