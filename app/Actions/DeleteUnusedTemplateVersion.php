<?php

namespace App\Actions;

use App\Models\TemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteUnusedTemplateVersion
{
    public function handle(TemplateVersion $version): void
    {
        if ($version->generatedDocuments()->exists()) {
            throw ValidationException::withMessages(['version' => 'Versões usadas por documentos não podem ser excluídas.']);
        }
        $media = $version->getFirstMedia('template_file');
        $directory = $media === null ? null : (string) $media->id;
        $version->deletePreservingMedia();
        $version->media()->delete();

        if ($directory !== null) {
            DB::afterCommit(function () use ($directory): void {
                if (! Storage::disk('local')->deleteDirectory($directory)) {
                    Log::error('Falha na limpeza de mídia de template.', ['media_id' => (int) $directory]);
                    throw ValidationException::withMessages(['version' => 'A versão foi removida, mas houve falha ao limpar a mídia privada. Informe o responsável pelo sistema.']);
                }
            });
        }
    }
}
