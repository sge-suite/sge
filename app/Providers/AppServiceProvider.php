<?php

namespace App\Providers;

use App\Models\Affiliation;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Support\AuditInfrastructureModel;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        Gate::policy(Activity::class, ActivityPolicy::class);
        DatabaseNotification::observe(AuditInfrastructureModel::class);
        Media::observe(AuditInfrastructureModel::class);
        LogActivityAction::beforeLogging(function (Activity $activity): void {
            $causer = $activity->causer;
            $actor = Context::get('audit_actor') ?? match (true) {
                $causer instanceof Affiliation => 'affiliation',
                $causer instanceof User => 'account',
                default => 'system',
            };

            $activity->properties = ($activity->properties ?? collect())
                ->put('actor', $actor)
                ->put('user_id', $causer instanceof Affiliation ? $causer->user_id : ($causer instanceof User ? $causer->id : null))
                ->put('affiliation_id', $causer instanceof Affiliation ? $causer->id : null);
        });
        Activity::updating(static function (): never {
            throw new RuntimeException('O histórico de atividades não pode ser alterado.');
        });
        Activity::deleting(static function (): never {
            throw new RuntimeException('O histórico de atividades não pode ser excluído.');
        });

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
            if ($event->command === 'activitylog:clean') {
                throw new RuntimeException('A limpeza do histórico de atividades está desabilitada.');
            }

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
