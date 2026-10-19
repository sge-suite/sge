<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

class TemplateTextFormatter
{
    public const MODIFIERS = ['MAIUSCULAS', 'MINUSCULAS', 'PRIMEIRA_MAIUSCULA', 'INICIAIS_MAIUSCULAS'];

    private const PARTICLES = ['a', 'as', 'o', 'os', 'de', 'da', 'das', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos', 'com', 'sem', 'por', 'para', 'ao', 'aos', 'à', 'às'];

    private const ACRONYMS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO', 'BR', 'CPF', 'CNPJ', 'RG', 'CEP', 'SGE', 'SIGAA', 'MEC', 'SEDUC', 'EMATER', 'IFRS'];

    /** @return array{name: string, modifier: string|null}|null */
    public static function parse(string $expression): ?array
    {
        if (preg_match('/\A([A-Z][A-Z0-9_]*)(?:\|([A-Z_]+))?\z/D', $expression, $matches) !== 1) {
            return null;
        }
        $modifier = $matches[2] ?? null;
        if ($modifier !== null && ! in_array($modifier, self::MODIFIERS, true)) {
            return null;
        }

        return ['name' => $matches[1], 'modifier' => $modifier];
    }

    public function format(string $value, ?string $modifier = null): string
    {
        return match ($modifier) {
            null => $value,
            'MAIUSCULAS' => Str::upper($value),
            'MINUSCULAS' => Str::lower($value),
            'PRIMEIRA_MAIUSCULA' => preg_replace_callback('/\p{L}/u', fn (array $match): string => Str::upper($match[0]), Str::lower($value), 1) ?? $value,
            'INICIAIS_MAIUSCULAS' => $this->initials($value),
            default => throw new InvalidArgumentException('Modificador de texto não permitido.'),
        };
    }

    /** @return list<array{text: string, italic: bool}> */
    public function campusIdentification(string $city, ?string $modifier = null): array
    {
        $city = trim($city);
        if ($city === '') {
            throw new InvalidArgumentException('A identificação do campus exige o nome da cidade.');
        }
        $value = $this->format('Campus '.$city, $modifier);

        return [
            ['text' => Str::before($value, ' '), 'italic' => true],
            ['text' => ' '.Str::after($value, ' '), 'italic' => false],
        ];
    }

    private function initials(string $value): string
    {
        $position = 0;

        return preg_replace_callback('/[\p{L}\p{M}]+/u', function (array $match) use (&$position): string {
            $word = Str::lower($match[0]);
            $isFirst = $position++ === 0;
            if (! $isFirst && in_array($word, self::PARTICLES, true)) {
                return $word;
            }
            $upper = Str::upper($word);
            if ($upper === 'IFFAR') {
                return 'IFFar';
            }

            return in_array($upper, self::ACRONYMS, true) ? $upper : Str::ucfirst($word);
        }, $value) ?? $value;
    }
}
