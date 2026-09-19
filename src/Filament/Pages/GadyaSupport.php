<?php

namespace Gadya\Connect\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\PortalClient;
use Illuminate\Support\Facades\Gate;
use Throwable;
use UnitEnum;

/**
 * Where a site is linked to the Gadya Media portal, and where the client
 * decides whether the Gadya team may sign in to help.
 */
class GadyaSupport extends Page
{
    protected string $view = 'gadya-connect::filament.gadya-support';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Gadya Support';

    protected static ?string $title = 'Gadya Support';

    protected static ?string $slug = 'gadya-support';

    protected static ?int $navigationSort = 90;

    public string $code = '';

    public function connect(PortalClient $portal): void
    {
        abort_unless(static::canManage(), 403);

        $this->validate(['code' => ['required', 'string', 'max:32']]);

        try {
            $connection = $portal->pair($this->code);
        } catch (Throwable $exception) {
            $this->addError('code', $exception->getMessage());

            return;
        }

        $this->code = '';

        Notification::make()->success()->title('Connected to Gadya Media')->body("This site is {$connection->site_name} in the portal.")->send();
    }

    public function sendNow(PortalClient $portal): void
    {
        abort_unless(static::canManage(), 403);

        try {
            $portal->report();
            Notification::make()->success()->title('Checked in')->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('The portal did not answer')->body($exception->getMessage())->send();
        }
    }

    public function toggleSignIn(): void
    {
        abort_unless(static::canManage(), 403);

        $connection = Connection::current();

        if ($connection === null) {
            return;
        }

        $connection->forceFill(['sso_enabled' => ! $connection->sso_enabled])->save();
        rescue(fn () => app(PortalClient::class)->report(), report: false);

        Notification::make()->success()->title($connection->sso_enabled ? 'The Gadya team can sign in to help' : 'Sign-in for the Gadya team is off')->send();
    }

    public function disconnect(): void
    {
        abort_unless(static::canManage(), 403);

        Connection::query()->delete();

        Notification::make()->success()->title('Disconnected from Gadya Media')->send();
    }

    public function getConnectionProperty(): ?Connection
    {
        return Connection::current();
    }

    public static function canManage(): bool
    {
        $gate = config('gadya-connect.gate');

        if (is_string($gate) && $gate !== '') {
            return Gate::allows($gate);
        }

        foreach (['gadya-cms.settings', 'manage-users'] as $fallback) {
            if (Gate::has($fallback)) {
                return Gate::allows($fallback);
            }
        }

        return auth()->check();
    }

    public static function canAccess(): bool
    {
        return static::canManage();
    }
}
