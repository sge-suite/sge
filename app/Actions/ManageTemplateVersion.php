<?php

namespace App\Actions;

use App\Models\Affiliation;
use App\Models\DocumentTemplate;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Support\ProtectTemplateMedia;
use App\Support\TemplateInspector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ManageTemplateVersion
{
    public function __construct(private ManageDocumentTemplate $templates, private TemplateInspector $inspector,
        private CauserResolver $causers, private DeleteUnusedTemplateVersion $deleteUnusedVersion) {}

    public function upload(User $actor, int $expectedAffiliationId, DocumentTemplate $target, UploadedFile $file): TemplateVersion
    {
        return $this->storeUpload($actor, $expectedAffiliationId, $target, $file);
    }

    /** @param array<string, mixed> $data */
    public function createWithUpload(User $actor, int $expectedAffiliationId, array $data, UploadedFile $file): DocumentTemplate
    {
        return $this->storeUpload($actor, $expectedAffiliationId, null, $file, $data)->documentTemplate;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeUpload(User $actor, int $expectedAffiliationId, ?DocumentTemplate $target, UploadedFile $file, array $data = []): TemplateVersion
    {
        $media = null;
        try {
            return DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $file, $data, &$media): TemplateVersion {
                $template = $target ?? $this->templates->handle($actor, $expectedAffiliationId, null, 'create', $data);
                $office = $this->templates->lockOffice($actor, $expectedAffiliationId);
                $template = DocumentTemplate::query()->lockForUpdate()->findOrFail($template->id);
                Gate::forUser($actor)->authorize('upload', $template);
                Validator::make(['file' => $file], [
                    'file' => ['required', 'file', 'extensions:docx', 'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'max:10240'],
                ])->validate();
                $report = $this->inspector->inspect($file->getPathname());
                if ($report['errors'] !== []) {
                    $messages = array_map(TemplateInspector::errorLabel(...), $report['errors']);
                    throw ValidationException::withMessages(['file' => $messages]);
                }
                $hash = hash_file('sha256', $file->getPathname());
                if ($template->templateVersions()->where('file_sha256', $hash)->exists()) {
                    throw ValidationException::withMessages(['file' => 'Este arquivo já existe no histórico deste template.']);
                }
                $lastUpload = Activity::query()->forSubject($template)->where('event', 'template_version_uploaded')->latest('id')->first();
                $number = max((int) $template->templateVersions()->max('version'), (int) ($lastUpload?->properties?->get('version') ?? 0)) + 1;

                return $this->causers->withCauser($office, function () use ($template, $file, $hash, $number, $report, $office, &$media): TemplateVersion {
                    $version = $template->templateVersions()->create([
                        'version' => $number, 'file_sha256' => $hash, 'file_size' => $file->getSize(),
                        'detected_variables' => $report['detected_variables'], 'validation_report' => $report,
                        'uploaded_by_affiliation_id' => $office->id,
                    ]);
                    ProtectTemplateMedia::captureUpload($version, fn (): Media => $version->addMedia($file)
                        ->preservingOriginal()->usingName('Template DOCX · versão '.$number)
                        ->usingFileName(Str::uuid().'.docx')->toMediaCollection('template_file', 'local'), $media);
                    activity()->performedOn($template)->causedBy($office)->event('template_version_uploaded')
                        ->withProperties(['campus_id' => $template->campus_id, 'version' => $number, 'template_version_id' => $version->id])
                        ->log('Nova versão de template recebida');
                    $this->setCurrentVersion($template, $version, $office, 'upload');

                    return $version->setRelation('documentTemplate', $template);
                });
            });
        } catch (Throwable $exception) {
            if ($media instanceof Media) {
                Storage::disk('local')->deleteDirectory((string) $media->id);
            }
            if ($exception instanceof ValidationException || $exception instanceof AuthorizationException
                || $exception instanceof HttpExceptionInterface
                || $exception instanceof ModelNotFoundException) {
                throw $exception;
            }
            Log::error('Falha ao armazenar template privado.', ['template_id' => $target?->id, 'error_type' => $exception::class]);
            throw ValidationException::withMessages(['file' => 'Não foi possível armazenar o template. Tente novamente.']);
        }
    }

    public function makeCurrent(User $actor, int $expectedAffiliationId, DocumentTemplate $target, int $versionId): void
    {
        DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $versionId): void {
            $office = $this->templates->lockOffice($actor, $expectedAffiliationId);
            $template = DocumentTemplate::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('upload', $template);
            $version = $template->templateVersions()->lockForUpdate()->findOrFail($versionId);
            $media = $version->getFirstMedia('template_file');
            if (($version->validation_report['status'] ?? null) !== 'validated'
                || $media === null || $media->disk !== 'local' || ! is_file($media->getPath())
                || filesize($media->getPath()) !== $version->file_size
                || hash_file('sha256', $media->getPath()) !== $version->file_sha256) {
                throw ValidationException::withMessages(['version' => 'Não foi possível selecionar esta versão. O arquivo precisa estar íntegro e ter sido aprovado na inspeção do upload.']);
            }
            $this->causers->withCauser($office, fn () => $this->setCurrentVersion($template, $version, $office, 'selection'));
        });
    }

    private function setCurrentVersion(DocumentTemplate $template, ?TemplateVersion $version, Affiliation $office, string $reason): void
    {
        $previousId = $template->current_version_id;
        if ($previousId === $version?->id) {
            return;
        }
        $template->current_version_id = $version?->id;
        $template->save();
        activity()->performedOn($template)->causedBy($office)->event('template_current_version_changed')
            ->withProperties(['campus_id' => $template->campus_id, 'previous_version_id' => $previousId,
                'template_version_id' => $version?->id, 'version' => $version?->version, 'reason' => $reason])
            ->log('Versão vigente do template alterada');
    }

    public function delete(User $actor, int $expectedAffiliationId, DocumentTemplate $target, int $versionId, #[\SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $versionId, $password): void {
            $office = $this->templates->lockOffice($actor, $expectedAffiliationId);
            $template = DocumentTemplate::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('update', $template);
            if (! Hash::check($password, $actor->fresh()->password)) {
                throw ValidationException::withMessages(['versionDeletionPassword' => 'A senha informada está incorreta.']);
            }
            $version = $template->templateVersions()->lockForUpdate()->findOrFail($versionId);
            $this->causers->withCauser($office, function () use ($version, $template, $office): void {
                $this->deleteUnusedVersion->handle($version);
                if ($template->current_version_id === $version->id) {
                    $fallback = $template->templateVersions()->where('validation_report->status', 'validated')->orderByDesc('version')->first();
                    $this->setCurrentVersion($template, $fallback, $office, 'deletion');
                }
            });
        });
    }
}
