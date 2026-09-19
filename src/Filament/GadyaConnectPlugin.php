<?php

namespace Gadya\Connect\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Gadya\Connect\Filament\Pages\GadyaSupport;

/**
 * Adds the Gadya Support page to a Filament panel. Gadya CMS registers the
 * page itself, so only a site without Gadya CMS needs this plugin.
 */
class GadyaConnectPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'gadya-connect';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([GadyaSupport::class]);
    }

    public function boot(Panel $panel): void {}
}
