<?php

namespace Gadya\Connect\Tests;

use Gadya\Connect\GadyaConnectServiceProvider;
use Gadya\Connect\Tests\Fixtures\AdminPanelProvider;
use Gadya\Connect\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * gadya/connect on its own, in a minimal application with one Filament
 * panel. The portal is always faked: nothing leaves the machine.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        $installed = json_decode((string) file_get_contents(__DIR__.'/../vendor/composer/installed.json'), true);

        $discovered = collect($installed['packages'] ?? $installed)
            ->flatMap(fn (array $package): array => $package['extra']['laravel']['providers'] ?? [])
            ->filter(fn (string $provider): bool => class_exists($provider))
            ->values()
            ->all();

        return [...$discovered, GadyaConnectServiceProvider::class, AdminPanelProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://client-site.test');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('gadya-connect.portal_url', 'https://portal.test');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function admin(): User
    {
        return User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret', 'role' => 'admin']);
    }
}
