<?php

namespace App\Http\Controllers;

use App\Actions\RebuildGeneratedDocument;
use App\Models\GeneratedDocument;
use App\Models\Internship;
use App\Support\DocxToPdf;
use App\Support\StudentInternshipContext;
use App\Support\TemporaryDocumentDownload;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class GeneratedDocumentDownloadController extends Controller
{
    public function __invoke(Request $request, Internship $internship, GeneratedDocument $generatedDocument,
        RebuildGeneratedDocument $rebuild, TemporaryDocumentDownload $download, StudentInternshipContext $context, DocxToPdf $converter): StreamedResponse
    {
        abort_unless($generatedDocument->internship_id === $internship->id, 404);
        $actor = $context->selected($request->user());
        abort_if($actor === null, 403);
        $format = $request->query('format', 'docx');
        abort_unless(in_array($format, ['docx', 'pdf'], true), 422);
        $path = null;
        try {
            $path = $rebuild->handle($request->user(), $generatedDocument);
            if ($format === 'pdf') {
                $docx = $path;
                try {
                    $path = $converter->convert($docx);
                } finally {
                    if (is_file($docx)) {
                        unlink($docx);
                    }
                }
            }

            return $download->response($path, $generatedDocument, $actor, $format);
        } catch (Throwable $exception) {
            $download->recordFailure($generatedDocument, $actor, $format);
            if ($path !== null && is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
    }
}
