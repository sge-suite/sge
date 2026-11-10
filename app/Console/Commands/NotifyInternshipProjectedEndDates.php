<?php

namespace App\Console\Commands;

use App\Actions\NotifyInternshipProjectedEndDate;
use App\Models\Internship;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class NotifyInternshipProjectedEndDates extends Command
{
    protected $signature = 'internships:notify-projected-end';

    protected $description = 'Reserva avisos ao Discente sobre o término previsto do estágio nos próximos sete dias';

    public function handle(NotifyInternshipProjectedEndDate $notify): int
    {
        $date = CarbonImmutable::today(config('app.timezone'));
        $failed = false;
        $reserved = 0;

        $notify->eligibleInternships($date)
            ->whereHas('course.campus', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->with('course:id,campus_id')
            ->eachById(function (Internship $internship) use ($notify, $date, &$failed, &$reserved): void {
                try {
                    $reserved += (int) $notify->handle($internship, $date);
                } catch (Throwable $exception) {
                    $failed = true;
                    report($exception);
                    $this->error('Falha ao reservar o aviso de término previsto do estágio '.$internship->id.'. Consulte o log da aplicação.');
                }
            }, count: 100);

        $this->info($reserved.' aviso(s) de término previsto reservado(s).');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
