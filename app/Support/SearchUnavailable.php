<?php

namespace App\Support;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class SearchUnavailable extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $category)
    {
        parent::__construct('Não foi possível buscar. Tente novamente.');
    }
}
