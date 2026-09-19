<?php

namespace Gadya\Connect\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Gadya\Connect\Filament\Pages\GadyaSupport;
use Gadya\Connect\Filament\Pages\GetHelp;

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
        $panel->pages([GadyaSupport::class, GetHelp::class]);

        static::addHelpButton($panel);
    }

    public function boot(Panel $panel): void {}

    /**
     * A "Get help" link in the top bar, so asking is always one click away.
     * Gadya CMS calls this for its own panel too.
     */
    public static function addHelpButton(Panel $panel): void
    {
        $panel->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('gadya-connect::filament.help-button', ['url' => GetHelp::getUrl()])->render());
    }
}
