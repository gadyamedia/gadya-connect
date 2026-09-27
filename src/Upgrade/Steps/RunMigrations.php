<?php

namespace Gadya\Connect\Upgrade\Steps;

use Gadya\Connect\Upgrade\Upgrader;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** `php artisan migrate --force`: first on the server, so every step after it sees the new tables. */
class RunMigrations
{
    public function key(): string
    {
        return 'connect.migrate';
    }

    public function description(): string
    {
        return 'Run the database migrations';
    }

    public function phase(): string
    {
        return Upgrader::SERVER;
    }

    public function shouldRun(): bool
    {
        return true;
    }

    public function run(): string
    {
        $exitCode = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException($output ?: 'migrate failed.');
        }

        return $output ?: 'Nothing to migrate.';
    }
}
