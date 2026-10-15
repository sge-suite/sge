<?php

namespace App\Support;

use Generator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

abstract class ImportCsv
{
    public const int MAX_ROWS = 500;

    public const int MAX_BYTES = 2097152;

    public const int MAX_CORRECTION_BYTES = 8388608;

    /** @var array<string, string> */
    public const array FIELDS = [];

    /** @var list<string> */
    public const array REQUIRED = [];

    /** @var list<string> */
    public const array METADATA = [
        '__sge_formato', '__sge_linha_original', '__sge_erros',
        '__sge_excedentes', '__sge_escape',
    ];

    /**
     * @return array{headers: list<string>, rows: list<array{position: int, line: string, original_line: string, original: array<string, string>, values: array<string, string>, extra: list<string>, blank: bool, errors: array<string, list<string>>}>}
     */
    public function parse(string $contents, string $separator): array
    {
        if (! in_array($separator, [';', ','], true) || strlen($contents) > static::MAX_CORRECTION_BYTES
            || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            $this->fail('Envie um CSV textual em UTF-8, com separador válido e até 8 MB.');
        }

        $contents = Str::startsWith($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        $headers = [];
        $dataHeaders = [];
        $keys = [];
        $rows = [];
        $nonEmpty = 0;
        $correction = false;

        foreach ($this->records($contents, $separator) as $record) {
            $cells = $record['cells'];
            $blank = count(array_filter($cells, fn (string $value): bool => trim($value) !== '')) === 0;
            if ($headers === []) {
                if ($blank) {
                    continue;
                }
                $headers = $cells;
                $keys = array_map(fn (string $header): string => Str::lower(trim($header)), $headers);
                $problems = [];
                $unknown = array_diff($keys, array_keys(static::FIELDS), static::METADATA);
                if ($unknown !== []) {
                    $problems[] = 'Cabeçalhos desconhecidos: '.implode(', ', $unknown).'.';
                }
                if (count(array_unique($keys)) !== count($keys)) {
                    $problems[] = 'Existem cabeçalhos repetidos.';
                }
                $missing = array_diff(static::REQUIRED, $keys);
                if ($missing !== []) {
                    $problems[] = 'Colunas obrigatórias ausentes: '.implode(', ', $missing).'.';
                }
                $metadata = array_intersect($keys, static::METADATA);
                $correction = $metadata !== [];
                if ($correction && count($metadata) !== count(static::METADATA)) {
                    $problems[] = 'As colunas de controle do CSV de erros devem ser preservadas.';
                }
                if (! $correction && strlen($contents) > static::MAX_BYTES) {
                    $problems[] = 'O CSV original deve ter até 2 MB.';
                }
                if ($problems !== []) {
                    throw ValidationException::withMessages(['csv' => $problems]);
                }
                $dataHeaders = array_values(array_filter($headers, fn (string $header): bool => ! in_array(Str::lower(trim($header)), static::METADATA, true)));

                continue;
            }

            if (! $blank && ++$nonEmpty > static::MAX_ROWS) {
                $this->fail('O arquivo deve ter até 500 registros não vazios.');
            }
            $errors = [];
            if (! $blank && count($cells) !== count($keys)) {
                $errors['linha'] = ['A quantidade de células difere do cabeçalho. Corrija as colunas desta linha.'];
            }
            $values = array_combine($keys, array_slice(array_pad($cells, count($keys), ''), 0, count($keys)));
            $extra = array_slice($cells, count($keys));
            if ($correction && ! $blank) {
                if (($values['__sge_formato'] ?? '') !== 'erros-v1') {
                    $errors['linha'] = ['Formato de correção inválido. Preserve as colunas de controle do arquivo de erros.'];
                }
                try {
                    $escaped = json_decode($values['__sge_escape'], true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($escaped) || ! array_is_list($escaped)) {
                        throw new JsonException;
                    }
                    foreach ($escaped as $header) {
                        if (! is_string($header) || ! array_key_exists($header, static::FIELDS)) {
                            throw new JsonException;
                        }
                        $value = $values[$header] ?? '';
                        if (str_starts_with($value, "'") && $this->needsEscape(substr($value, 1))) {
                            $values[$header] = substr($value, 1);
                        }
                    }
                } catch (JsonException) {
                    $errors['linha'] = ['Controle de escape inválido. Preserve as colunas de controle do arquivo de erros.'];
                }
                try {
                    $storedExtra = trim($values['__sge_excedentes']) === '' ? [] : json_decode($values['__sge_excedentes'], true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($storedExtra) || ! array_is_list($storedExtra)) {
                        throw new JsonException;
                    }
                    $storedExtra = array_map(function (mixed $cell): string {
                        if (! is_string($cell)) {
                            throw new JsonException;
                        }

                        return $cell;
                    }, $storedExtra);
                    $extra = [...$extra, ...$storedExtra];
                    if ($storedExtra !== []) {
                        $errors['linha'] = ['Redistribua os valores excedentes nas colunas corretas e limpe a célula __sge_excedentes após revisar.'];
                    }
                } catch (JsonException) {
                    $extra[] = $values['__sge_excedentes'];
                    $errors['linha'] = ['O controle de células excedentes é inválido. Confira __sge_excedentes.'];
                }
            }

            $line = $record['line_start'] === $record['line_end'] ? (string) $record['line_start'] : $record['line_start'].'–'.$record['line_end'];
            $originalLine = $values['__sge_linha_original'] ?? $line;
            if (preg_match('/^[1-9][0-9]{0,8}(?:–[1-9][0-9]{0,8})?$/u', $originalLine) !== 1) {
                $errors['linha'] = ['A numeração da linha original é inválida.'];
                $originalLine = $line;
            }
            $original = [];
            foreach ($dataHeaders as $header) {
                $original[$header] = $values[Str::lower(trim($header))] ?? '';
            }
            $rows[] = [
                'position' => count($rows), 'line' => $line, 'original_line' => $originalLine,
                'original' => $original, 'values' => array_intersect_key($values, static::FIELDS),
                'extra' => $extra, 'blank' => $blank, 'errors' => $errors,
            ];
        }

        if ($headers === [] || $nonEmpty === 0) {
            $this->fail('O CSV deve conter cabeçalho e pelo menos um registro.');
        }

        return ['headers' => $dataHeaders, 'rows' => $rows];
    }

    /** @return Generator<int, array{line_start: int, line_end: int, cells: list<string>}> */
    private function records(string $contents, string $separator): Generator
    {
        $length = strlen($contents);
        $start = 0;
        $line = 1;
        $startLine = 1;
        $state = 'start';
        $count = 0;
        for ($index = 0; $index < $length; $index++) {
            $character = $contents[$index];
            if ($state === 'quoted') {
                if ($character === '"') {
                    if (($contents[$index + 1] ?? '') === '"') {
                        $index++;
                    } else {
                        $state = 'closed';
                    }
                } elseif ($character === "\n") {
                    $line++;
                }

                continue;
            }
            if ($character === "\r" && ($contents[$index + 1] ?? '') !== "\n") {
                $this->fail('Use finais de linha LF ou CRLF.');
            }
            if ($character === "\n" || $character === "\r") {
                if ($character === "\r") {
                    $index++;
                }
                if (++$count > 2001) {
                    $this->fail('O arquivo contém linhas vazias em excesso (máximo de 2.000 registros).');
                }
                yield ['line_start' => $startLine, 'line_end' => $line, 'cells' => $this->cells(substr($contents, $start, $index - $start + 1), $separator)];
                $line++;
                $startLine = $line;
                $start = $index + 1;
                $state = 'start';
            } elseif ($character === $separator) {
                $state = 'start';
            } elseif ($character === '"' && $state === 'start') {
                $state = 'quoted';
            } elseif ($character === '"' || $state === 'closed') {
                $this->fail('Há aspas malformadas no CSV, próximo à linha '.$line.'. Corrija o arquivo antes de importar.');
            } else {
                $state = 'unquoted';
            }
        }
        if ($state === 'quoted') {
            $this->fail('Há um campo com aspas não fechadas, iniciado próximo à linha '.$startLine.'.');
        }
        if ($start < $length) {
            if (++$count > 2001) {
                $this->fail('O arquivo contém linhas vazias em excesso (máximo de 2.000 registros).');
            }
            yield ['line_start' => $startLine, 'line_end' => $line, 'cells' => $this->cells(substr($contents, $start), $separator)];
        }
    }

    /** @return list<string> */
    private function cells(string $record, string $separator): array
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            $this->fail('Não foi possível ler o CSV. Tente novamente.');
        }
        try {
            fwrite($stream, $record);
            rewind($stream);
            $cells = fgetcsv($stream, null, $separator, '"', '');

            return array_map(fn (?string $cell): string => $cell ?? '', $cells === false ? [] : $cells);
        } finally {
            fclose($stream);
        }
    }

    public function needsEscape(string $value): bool
    {
        return preg_match('/^(?:[ \t\r\n]*[=+@-]|[\t\r\n])/u', $value) === 1;
    }

    /** @param array{headers: list<string>, rows: list<array<string, mixed>>, separator: string} $import */
    public function errorsCsv(array $import): string
    {
        $records = [];
        foreach ($import['rows'] as $row) {
            if ($row['status'] !== 'error') {
                continue;
            }
            $cells = [];
            $escaped = [];
            foreach ($import['headers'] as $header) {
                $value = $row['original'][$header] ?? '';
                if ($this->needsEscape($value)) {
                    $value = "'".$value;
                    $escaped[] = Str::lower(trim($header));
                }
                $cells[] = $value;
            }
            $messages = [];
            foreach ($row['errors'] as $field => $errors) {
                foreach ($errors as $message) {
                    $messages[] = $field.': '.$message;
                }
            }
            $records[] = [...$cells, 'erros-v1', $row['original_line'], implode(' | ', $messages),
                json_encode($row['extra'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), json_encode($escaped, JSON_THROW_ON_ERROR)];
        }

        return $this->render([...$import['headers'], ...static::METADATA], $records, $import['separator']);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $records
     */
    public function render(array $headers, array $records, string $separator = ';'): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            $this->fail('Não foi possível gerar o CSV. Tente novamente.');
        }
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ([$headers, ...$records] as $record) {
                fputcsv($stream, $record, $separator, '"', '', "\r\n");
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            if ($contents === false) {
                $this->fail('Não foi possível gerar o CSV. Tente novamente.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['csv' => $message]);
    }
}
