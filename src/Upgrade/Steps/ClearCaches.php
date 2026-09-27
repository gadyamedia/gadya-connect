<?php

namespace Gadya\Connect\Upgrade\Steps;

use Gadya\Connect\Upgrade\Upgrader;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** `php artisan optimize:clear`, after everything else, so nothing is served from before the upgrade. */
class ClearCaches
{
    public function key(): string
    {
        return 'connect.optimize-clear';
    }

    public function description(): string
    {
        return 'Clear the config, route, view, event and application caches';
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
        $exitCode = Artisan::call('optimize:clear');
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException($output ?: 'optimize:clear failed.');
        }

        return $output ?: 'Caches cleared.';
    }
}
