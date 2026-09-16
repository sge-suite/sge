<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->requirePostgreSqlForMigrations();

        // Previne N+1 Queries em desenvolvimento
        Model::preventLazyLoading(! app()->isProduction());

        // Configuração de Locales e Moeda
        setlocale(LC_ALL, config('app.locale').'.UTF-8');
        date_default_timezone_set(config('app.timezone'));
        Number::useCurrency(config('app.currency'));
        Number::useLocale(config('app.locale'));
    }

    /**
     * Block migration commands when the configured connection is not PostgreSQL.
     */
    private function requirePostgreSqlForMigrations(): void
    {
        if (! app()->runningInConsole()) {
            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! in_array($event->command, [
                'migrate',
                'migrate:fresh',
                'migrate:install',
                'migrate:refresh',
                'migrate:reset',
                'migrate:rollback',
            ], true)) {
                return;
            }

            if (DB::getDriverName() !== 'pgsql') {
                throw new RuntimeException('As migrations requerem PostgreSQL.');
            }
        });
    }
}
