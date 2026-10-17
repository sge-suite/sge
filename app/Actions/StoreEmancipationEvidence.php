<?php

namespace App\Actions;

use App\Models\EmancipationEvidence;
use App\Models\InternshipRequest;
use App\Models\User;
use App\Support\StudentInternshipContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class StoreEmancipationEvidence
{
    public function __construct(private StudentInternshipContext $context, private CauserResolver $causerResolver) {}

    /** @return array<string, list<string>> */
    public static function validationRules(): array
    {
        return ['evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:10240']];
    }

    public function handle(User $actor, int $expectedAffiliationId, InternshipRequest $target, UploadedFile $file): EmancipationEvidence
    {
        $media = null;
        try {
            return DB::transaction(function () use ($actor, $expectedAffiliationId, $target, $file, &$media): EmancipationEvidence {
                $owner = $this->context->lock($actor, $expectedAffiliationId);
                $request = InternshipRequest::query()->lockForUpdate()->findOrFail($target->id);
                Gate::forUser($actor)->authorize('upload', [EmancipationEvidence::class, $request]);
                Validator::make(['evidence' => $file], self::validationRules(), attributes: ['evidence' => 'comprovante de emancipação'])->validate();

                return $this->causerResolver->withCauser($owner, function () use ($request, $file, &$media): EmancipationEvidence {
                    $evidence = EmancipationEvidence::create(['internship_request_id' => $request->id]);
                    $media = $evidence->addMedia($file)->preservingOriginal()->usingName('Comprovante de emancipação')
                        ->usingFileName(Str::uuid().'.'.$file->extension())->toMediaCollection('emancipation_evidence', 'local');

                    return $evidence;
                });
            });
        } catch (Throwable $exception) {
            if ($media instanceof Media) {
                Storage::disk('local')->deleteDirectory((string) $media->id);
            }
            if ($exception instanceof ValidationException
                || $exception instanceof AuthorizationException
                || $exception instanceof HttpExceptionInterface
                || $exception instanceof ModelNotFoundException) {
                throw $exception;
            }
            Log::error('Falha ao armazenar comprovante privado.', ['internship_request_id' => $target->id, 'error_type' => $exception::class]);
            throw ValidationException::withMessages(['evidence' => 'Não foi possível armazenar o comprovante. Seu preenchimento continua disponível. Tente enviar novamente.']);
        }
    }
}
