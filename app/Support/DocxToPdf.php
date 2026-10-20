<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DocxToPdf
{
    public function convert(string $docx): string
    {
        $pdf = dirname($docx).'/'.Str::uuid().'.pdf';
        $input = $output = null;
        try {
            $input = fopen($docx, 'rb');
            $output = fopen($pdf, 'x+b');
            if ($input === false || $output === false || ! chmod($pdf, 0600)) {
                throw new RuntimeException('pdf_temporary_file_unavailable');
            }

            $response = Http::connectTimeout(5)->timeout(config('documents.pdf_timeout'))
                ->withOptions(['sink' => $output, 'allow_redirects' => false])
                ->attach('files', $input, 'document.docx')
                ->post(rtrim(config('documents.pdf_converter_url'), '/').'/forms/libreoffice/convert');

            fflush($output);
            rewind($output);
            if (! $response->successful() || fread($output, 5) !== '%PDF-') {
                throw new RuntimeException('pdf_conversion_failed');
            }

            return $pdf;
        } catch (Throwable $exception) {
            if (is_file($pdf)) {
                unlink($pdf);
            }
            Log::error('Falha na conversão de DOCX para PDF.', ['error_type' => $exception::class]);
            abort(503, 'Não foi possível preparar o PDF. Tente novamente ou baixe em DOCX pelo histórico.');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }
}
