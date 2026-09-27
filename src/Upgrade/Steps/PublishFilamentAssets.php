<?php

namespace Gadya\Connect\Upgrade\Steps;

use Filament\FilamentServiceProvider;
use Gadya\Connect\Upgrade\Upgrader;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** `php artisan filament:assets`, on a site with Filament: the panel's scripts and styles for the new release. */
class PublishFilamentAssets
{
    public function key(): string
    {
        return 'connect.filament-assets';
    }

    public function description(): string
    {
        return 'Publish Filament\'s scripts and styles';
    }

    public function phase(): string
    {
        return Upgrader::SERVER;
    }

    public function shouldRun(): bool
    {
        return class_exists(FilamentServiceProvider::class) && Upgrader::hasCommand('filament:assets');
    }

    public function run(): string
    {
        $exitCode = Artisan::call('filament:assets', ['--no-interaction' => true]);
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException($output ?: 'filament:assets failed.');
        }

        return $output ?: 'Filament assets published.';
    }
}
