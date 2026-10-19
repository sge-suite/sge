<?php

namespace App\Http\Controllers;

use App\Models\DocumentTemplate;
use App\Models\TemplateVersion;
use App\Support\DocumentTemplatePreview;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentTemplatePreviewController extends Controller
{
    public function __invoke(DocumentTemplate $documentTemplate, TemplateVersion $templateVersion, DocumentTemplatePreview $renderer): Response
    {
        Gate::authorize('view', $documentTemplate);
        abort_unless($templateVersion->document_template_id === $documentTemplate->id, 404);
        $preview = null;
        $error = null;
        try {
            $preview = $renderer->build($templateVersion);
        } catch (ValidationException $exception) {
            $error = Arr::flatten($exception->errors())[0] ?? 'Não foi possível visualizar este original. Use a opção de download.';
        }

        return response()->view('pages.document-templates.preview', compact('preview', 'error'), $error === null ? 200 : 422)
            ->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
                'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
