<?php

namespace App\Support;

use App\Enums\GeneratedDocumentType;
use App\Helpers\DateHelper;
use App\Models\GeneratedDocument;
use DateTimeInterface;
use Illuminate\Support\Str;

class GeneratedDocumentFilename
{
    public function make(GeneratedDocumentType $type, string $templateName, string $studentName, ?DateTimeInterface $generatedAt): string
    {
        $date = $this->date($generatedAt);
        if ($type === GeneratedDocumentType::OrientationCertificate) {
            $student = trim(preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($studentName)) ?? '', '-');
            $student = $this->part($student, 160);
            $name = 'Atestado de Orientacao'.($student !== '' ? ' - '.$student : '').($date !== '' ? ' - '.$date : '');
        } else {
            $title = $this->part($templateName, 100);
            $student = $this->part($studentName, 100);
            $name = implode(' - ', array_filter([$title !== '' ? $title : $type->label(), $student, $date], fn (string $part): bool => $part !== ''));
        }

        return $this->part($name, 240).'.docx';
    }

    public function forDocument(GeneratedDocument $document, string $format = 'docx'): string
    {
        $filename = $this->docxFilename($document);

        return $format === 'pdf' ? pathinfo($filename, PATHINFO_FILENAME).'.pdf' : $filename;
    }

    private function docxFilename(GeneratedDocument $document): string
    {
        if ($document->output_filename !== $document->type->value.'-'.$document->generation_token.'.docx') {
            $date = $this->date($document->generated_at);
            if ($document->type === GeneratedDocumentType::OrientationCertificate && $date !== ''
                && ! str_ends_with($document->output_filename, ' - '.$date.'.docx')) {
                return $this->part(pathinfo($document->output_filename, PATHINFO_FILENAME), 200).' - '.$date.'.docx';
            }

            return $document->output_filename;
        }

        return $this->make($document->type, (string) data_get($document->snapshot, 'template_name', $document->type->label()),
            (string) data_get($document->snapshot, 'values.DISCENTE_NOME', ''), $document->generated_at);
    }

    private function date(?DateTimeInterface $date): string
    {
        return $date === null ? '' : str_replace(['/', ':', ' às '], ['-', '-', ' '], DateHelper::formatDateTime($date));
    }

    private function part(string $value, int $maxBytes): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim(mb_strcut($value, 0, $maxBytes, 'UTF-8'), ' .');
    }
}
