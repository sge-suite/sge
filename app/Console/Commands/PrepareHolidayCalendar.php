<?php

namespace App\Console\Commands;

use App\Support\HolidayCalendar;
use Illuminate\Console\Command;

class PrepareHolidayCalendar extends Command
{
    protected $signature = 'holidays:prepare';

    protected $description = 'Prepara o calendário do ano atual e dos dois seguintes, recuperando importações incompletas.';

    public function handle(HolidayCalendar $calendar): int
    {
        $failed = false;
        foreach ($calendar->years() as $year) {
            $failed = $this->call('holidays:import', ['year' => $year]) !== self::SUCCESS || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
