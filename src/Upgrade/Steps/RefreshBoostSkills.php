<?php

namespace Gadya\Connect\Upgrade\Steps;

use Composer\InstalledVersions;
use Gadya\Connect\Upgrade\Upgrader;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * `php artisan boost:update --discover`, in the repository only: the AI
 * guidelines and skills the new releases ship, including skills a release
 * added. Files in git, so never on the live server. Only where Boost is
 * set up (boost.json): otherwise it would only ask to be installed.
 */
class RefreshBoostSkills
{
    public function key(): string
    {
        return 'connect.boost-update';
    }

    public function description(): string
    {
        return 'Refresh the Laravel Boost guidelines and skills';
    }

    public function phase(): string
    {
        return Upgrader::CODE;
    }

    public function shouldRun(): bool
    {
        return InstalledVersions::isInstalled('laravel/boost')
            && Upgrader::hasCommand('boost:update')
            && is_file(base_path('boost.json'));
    }

    public function run(): string
    {
        $exitCode = Artisan::call('boost:update', ['--discover' => true, '--no-interaction' => true]);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException($output ?: 'boost:update failed.');
        }

        return $output ?: 'Boost guidelines and skills refreshed.';
    }
}
