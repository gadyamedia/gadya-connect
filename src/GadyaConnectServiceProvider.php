<?php

namespace Gadya\Connect;

use Gadya\Connect\Commands\ConnectCommand;
use Gadya\Connect\Commands\DisconnectCommand;
use Gadya\Connect\Commands\ReportCommand;
use Gadya\Connect\Security\FailedLogins;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
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
         * Counted for the check-in's security section. A sign-in must never
         * fail because the cache did, so a broken cache just loses a count.
         */
        Event::listen(Failed::class, fn (): mixed => rescue(fn () => app(FailedLogins::class)->record($this->app->bound('request') ? request()->ip() : null), report: false));
        Event::listen(Lockout::class, fn (Lockout $event): mixed => rescue(fn () => app(FailedLogins::class)->record($event->request->ip()), report: false));

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
