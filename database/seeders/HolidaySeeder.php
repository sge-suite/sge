<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $status = Holiday::withoutSyncingToSearch(fn (): int => Artisan::call('holidays:prepare'));
        Holiday::makeAllSearchable();
        $this->command->line(Artisan::output());
        if ($status !== 0) {
            $this->command->warn('Algumas consultas de feriados falharam. A rotina diária tentará novamente; use holidays:import para consultar falhas ou tentar novamente.');
        }
    }
}
