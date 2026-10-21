<?php

namespace App\Support;

use App\Models\Affiliation;
use App\Models\GeneratedDocument;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\Activitylog\Support\CauserResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TemporaryDocumentDownload
{
    public function __construct(private CauserResolver $causers, private GeneratedDocumentFilename $filenames) {}

    public function recordFailure(GeneratedDocument $document, Affiliation $actor, string $format = 'docx'): void
    {
        $this->audit($document, $actor, 'document_download_failed', $format);
    }

    public function response(string $path, GeneratedDocument $document, Affiliation $actor, string $format = 'docx'): StreamedResponse
    {
        return response()->streamDownload(function () use ($path, $document, $actor, $format): void {
            $input = $output = null;
            try {
                $input = fopen($path, 'rb');
                $output = fopen('php://output', 'wb');
                if ($input === false || $output === false || ! flock($input, LOCK_SH)) {
                    throw new RuntimeException('document_stream_unavailable');
                }
                ignore_user_abort(true);
                while (! feof($input)) {
                    $chunk = fread($input, 65536);
                    if ($chunk === false) {
                        throw new RuntimeException('document_read_failed');
                    }
                    while ($chunk !== '') {
                        $written = fwrite($output, $chunk);
                        if ($written === false || $written === 0 || connection_aborted()) {
                            throw new RuntimeException('document_transmission_interrupted');
                        }
                        $chunk = substr($chunk, $written);
                    }
                }
                $this->audit($document, $actor, 'document_download_transmitted', $format);
            } catch (Throwable $exception) {
                Log::error('Falha na transmissão de documento.', ['document_id' => $document->id, 'error_type' => $exception::class]);
                $this->audit($document, $actor, 'document_download_failed', $format);
                throw new RuntimeException('Não foi possível concluir o download. Baixe novamente pelo histórico.');
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                if (is_file($path) && ! unlink($path)) {
                    Log::error('Falha na limpeza de documento temporário.', ['document_id' => $document->id]);
                }
            }
        }, $this->filenames->forDocument($document, $format), ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Length' => (string) filesize($path), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function audit(GeneratedDocument $document, Affiliation $actor, string $event, string $format): void
    {
        $this->causers->withCauser($actor, fn () => activity()->performedOn($document)->event($event)
            ->withProperties([
                'internship_id' => $document->internship_id,
                'campus_id' => $actor->campus_id,
                'format' => $format,
                'document_type' => $document->type->value,
                'document_name' => data_get($document->snapshot, 'template_name', $document->type->label()),
                'output_filename' => $document->output_filename === null ? null : $this->filenames->forDocument($document, $format),
                'template_version_id' => $document->template_version_id,
                'generated_at' => $document->generated_at?->toIso8601String(),
            ])
            ->log($event === 'document_download_failed' ? 'Falha no download do documento' : strtoupper($format).' transmitido pelo servidor'));
    }
}
