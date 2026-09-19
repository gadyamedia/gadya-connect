<?php

namespace Gadya\Connect\Filament;

use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
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
        /*
         * The link is built for this panel by name: a site with a second
         * panel that has no Get help page must not get a button pointing
         * at a route that does not exist there.
         */
        $panelId = $panel->getId();

        $panel->renderHook(PanelsRenderHook::USER_MENU_BEFORE, function () use ($panelId): string {
            if (Filament::getCurrentPanel()?->getId() !== $panelId) {
                return '';
            }

            return view('gadya-connect::filament.help-button', ['url' => GetHelp::getUrl(panel: $panelId)])->render();
        });
    }
}
