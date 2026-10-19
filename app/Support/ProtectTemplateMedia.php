<?php

namespace App\Support;

use App\Models\TemplateVersion;
use Closure;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProtectTemplateMedia
{
    /** @var array<int, Closure(Media): void> */
    private static array $uploadCallbacks = [];

    public function created(Media $media): void
    {
        if ($media->model_type === (new TemplateVersion)->getMorphClass()
            && isset(self::$uploadCallbacks[$media->model_id])) {
            self::$uploadCallbacks[$media->model_id]($media);
        }
    }

    /** @param Closure(): Media $store */
    public static function captureUpload(TemplateVersion $version, Closure $store, ?Media &$createdMedia): Media
    {
        self::$uploadCallbacks[$version->id] = static function (Media $media) use (&$createdMedia): void {
            $createdMedia = $media;
        };
        try {
            return $store();
        } finally {
            unset(self::$uploadCallbacks[$version->id]);
        }
    }

    public function saving(Media $media): void
    {
        if ($media->exists) {
            $this->updating($media);
        }
    }

    public function creating(Media $media): void
    {
        if ($media->model_type !== (new TemplateVersion)->getMorphClass()) {
            return;
        }
        $version = TemplateVersion::query()->lockForUpdate()->findOrFail($media->model_id);
        if ($media->collection_name !== 'template_file' || $media->disk !== 'local'
            || ($version->validation_report['status'] ?? null) !== 'validated' || $version->generatedDocuments()->exists()
            || $version->media()->exists() || $media->size !== $version->file_size) {
            $this->deny();
        }
    }

    public function updating(Media $media): void
    {
        if ($media->getRawOriginal('model_type') === (new TemplateVersion)->getMorphClass()
            || $media->model_type === (new TemplateVersion)->getMorphClass()) {
            $this->deny();
        }
    }

    public function deleting(Media $media): void
    {
        if ($media->model_type === (new TemplateVersion)->getMorphClass()) {
            $this->deny();
        }
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['version' => 'O arquivo da versão é imutável. Remova a versão sem uso ou envie outro DOCX como nova versão.']);
    }
}
