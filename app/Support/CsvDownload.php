<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvDownload
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public function response(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $file = fopen('php://output', 'wb');
            if ($file === false) {
                throw new RuntimeException('Não foi possível abrir a saída do CSV.');
            }

            try {
                fwrite($file, "\xEF\xBB\xBF");
                fputcsv($file, $columns, escape: '', eol: "\r\n");

                foreach ($rows as $row) {
                    fputcsv($file, array_map($this->escape(...), $row), escape: '', eol: "\r\n");
                }
            } finally {
                fclose($file);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function escape(string|int|float|null $value): string|int|float|null
    {
        if (is_string($value) && preg_match('/^(?:[\t\r\n]|[\p{Z}\p{Cc}\p{Cf}]*[=+\-@＝＋－＠])/u', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
