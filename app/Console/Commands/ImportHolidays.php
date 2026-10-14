<?php

namespace App\Console\Commands;

use App\Actions\GenerateMunicipalHolidays;
use App\Actions\ImportHolidayTerritory;
use App\Enums\BrazilianState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ImportHolidays extends Command
{
    protected $signature = 'holidays:import {year : Ano entre 1900 e 2199} {--uf= : Limita a consulta a uma UF} {--refresh : Consulta novamente períodos concluídos}';

    protected $description = 'Importa feriados do Brasil inteiro e gera ocorrências municipais sem sobrescrever ajustes locais.';

    public function handle(ImportHolidayTerritory $import, GenerateMunicipalHolidays $generate): int
    {
        $data = ['year' => $this->argument('year'), 'uf' => $this->option('uf') === null ? null : strtoupper(trim($this->option('uf')))];
        $validator = Validator::make($data, ['year' => ['required', 'integer', 'between:1900,2199'], 'uf' => ['nullable', Rule::enum(BrazilianState::class)]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $year = (int) $data['year'];
        $territories = $data['uf'] === null ? [null, ...BrazilianState::cases()] : [BrazilianState::from($data['uf'])];
        $failed = false;
        foreach ($territories as $state) {
            try {
                $run = $import->handle($year, $state, (bool) $this->option('refresh'));
                $this->line($year.' · '.($state === null ? 'Nacional' : $state->value).' · '.$run->statusLabel().($run->error ? ': '.$run->error : ''));
                $failed = $failed || $run->status === 'failed';
            } catch (Throwable $exception) {
                $failed = true;
                $this->error($exception->getMessage());
            }
        }
        $generate->handle([$year]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
