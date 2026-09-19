<?php

namespace Gadya\Connect;

use Gadya\Connect\Commands\ConnectCommand;
use Gadya\Connect\Commands\DisconnectCommand;
use Gadya\Connect\Commands\ReportCommand;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GadyaConnectServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('gadya-connect')
            ->hasConfigFile()
            ->hasViews('gadya-connect')
            ->hasCommands([
                ConnectCommand::class,
                ReportCommand::class,
                DisconnectCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        /*
         * The check-in rides the site's own scheduler: if the scheduler
         * stops, the portal notices the silence, which is the point.
         */
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('gadya-connect.schedule', true)) {
                return;
            }

            $every = max(1, min(15, (int) config('gadya-connect.report_every_minutes', 5)));

            $schedule->command('gadya:report')
                ->cron("*/{$every} * * * *")
                ->withoutOverlapping(10)
                ->runInBackground();
        });
    }
}
