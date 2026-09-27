<?php

namespace Gadya\Connect\Tests\Feature;

use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\RequestSignature;
use Gadya\Connect\Remote\CommandSignature;
use Gadya\Connect\Remote\RemoteCommands;
use Gadya\Connect\Tests\Fixtures\RunBackup;
use Gadya\Connect\Tests\TestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class RemoteCommandsTest extends TestCase
{
    private const SECRET = 'ssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssss';

    protected function setUp(): void
    {
        parent::setUp();

        RunBackup::$runs = 0;
        Connection::query()->create(['site_id' => 17, 'portal_url' => 'https://portal.test', 'secret' => self::SECRET]);
    }

    protected function tearDown(): void
    {
        if ($this->app?->isDownForMaintenance()) {
            Artisan::call('up');
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function command(int $id, string $type, array $payload = [], ?string $expiresAt = null, string $secret = self::SECRET): array
    {
        $expiresAt ??= now()->addDay()->toIso8601String();

        return [
            'id' => $id,
            'type' => $type,
            'payload' => $payload,
            'expires_at' => $expiresAt,
            'signature' => CommandSignature::sign($secret, $id, $type, $payload, $expiresAt),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     */
    private function portalQueues(array $commands, array $more = []): void
    {
        Http::fake([
            ...$more,
            'portal.test/api/connect/v1/commands' => Http::response(['data' => $commands]),
            'portal.test/api/connect/v1/commands/*' => Http::response(['data' => ['ok' => true]]),
            'repo.packagist.org/*' => Http::response(['packages' => []]),
        ]);
    }

    /**
     * @return array{status: string, output: string, result: ?array}
     */
    private function outcomeOf(int $id): array
    {
        $sent = Http::recorded(fn (Request $request): bool => $request->url() === "https://portal.test/api/connect/v1/commands/{$id}");

        $this->assertCount(1, $sent, "The outcome of command {$id} was sent once.");

        return json_decode($sent->first()[0]->body(), true);
    }

    private function signedWith(Request $request, string $secret): bool
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return hash_equals(
            RequestSignature::sign($secret, (int) $request->header(RequestSignature::TIMESTAMP_HEADER)[0], $request->header(RequestSignature::NONCE_HEADER)[0], $request->method(), $path, $request->body()),
            $request->header(RequestSignature::SIGNATURE_HEADER)[0],
        );
    }

    public function test_a_signed_command_runs_and_the_outcome_goes_back_to_the_portal(): void
    {
        $this->portalQueues([$this->command(5, 'maintenance.down', ['secret' => 'let-gadya-in'])]);

        $this->artisan('gadya:commands')->expectsOutputToContain('maintenance.down: The site is down')->assertSuccessful();

        $this->assertTrue($this->app->isDownForMaintenance());

        $this->assertSame([
            'status' => 'succeeded',
            'output' => 'The site is down for maintenance. The bypass link lets the team in.',
            'result' => ['down' => true, 'bypass_url' => 'https://client-site.test/let-gadya-in'],
        ], $this->outcomeOf(5));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://portal.test/api/connect/v1/commands'
            && $this->signedWith($request, self::SECRET));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/commands/5') && $this->signedWith($request, self::SECRET));
    }

    public function test_the_site_is_brought_back_up(): void
    {
        Artisan::call('down');
        $this->portalQueues([$this->command(6, 'maintenance.up')]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertSame('succeeded', $this->outcomeOf(6)['status']);
    }

    public function test_maintenance_mode_takes_nothing_but_a_plain_bypass_secret(): void
    {
        $this->portalQueues([$this->command(7, 'maintenance.down', ['secret' => '../../evil.example.com'])]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertSame('failed', $this->outcomeOf(7)['status']);
        $this->assertStringContainsString('letters, numbers', $this->outcomeOf(7)['output']);
    }

    public function test_a_command_with_a_bad_signature_is_not_run(): void
    {
        $forged = $this->command(8, 'maintenance.down', secret: 'not-the-secret');
        $tampered = [...$this->command(9, 'maintenance.down'), 'payload' => ['secret' => 'added-later']];

        $this->portalQueues([$forged, $tampered]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertFalse($this->app->isDownForMaintenance());

        foreach ([8, 9] as $id) {
            $this->assertSame('failed', $this->outcomeOf($id)['status']);
            $this->assertStringContainsString('signature did not match', $this->outcomeOf($id)['output']);
        }
    }

    public function test_an_expired_command_is_not_run(): void
    {
        $this->portalQueues([$this->command(10, 'maintenance.down', expiresAt: now()->subMinute()->toIso8601String())]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertSame('failed', $this->outcomeOf(10)['status']);
        $this->assertStringContainsString('expired', $this->outcomeOf(10)['output']);
    }

    public function test_an_unknown_type_fails_with_a_clear_message(): void
    {
        $this->portalQueues([$this->command(11, 'server.reboot')]);

        $this->artisan('gadya:commands')->expectsOutputToContain('server.reboot failed')->assertSuccessful();

        $this->assertSame([
            'status' => 'failed',
            'output' => 'This site does not know how to run "server.reboot". It may need a newer gadya/connect or gadya/cms.',
            'result' => null,
        ], $this->outcomeOf(11));
    }

    public function test_switched_off_nothing_runs_and_the_portal_is_told_why(): void
    {
        config(['gadya-connect.remote_commands.enabled' => false]);
        $this->portalQueues([$this->command(12, 'maintenance.down')]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertStringContainsString('switched off', $this->outcomeOf(12)['output']);
    }

    public function test_single_types_can_be_refused(): void
    {
        config(['gadya-connect.remote_commands.except' => ['maintenance.down']]);
        $this->portalQueues([$this->command(13, 'maintenance.down')]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertStringContainsString('does not allow "maintenance.down"', $this->outcomeOf(13)['output']);
    }

    public function test_a_handler_tagged_by_another_package_is_picked_up(): void
    {
        $this->app->tag([RunBackup::class], RemoteCommands::TAG);
        $this->portalQueues([
            $this->command(14, 'backup.run', ['disk' => 's3']),
            $this->command(15, 'backup.run', ['disk' => 'broken']),
        ]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertSame(['status' => 'succeeded', 'output' => 'Backup finished.', 'result' => ['disk' => 's3', 'size' => 1024]], $this->outcomeOf(14));
        $this->assertSame(['status' => 'failed', 'output' => 'The backup disk is not writable.', 'result' => null], $this->outcomeOf(15));
        $this->assertContains('backup.run', array_keys(app(RemoteCommands::class)->handlers()));
    }

    public function test_a_command_sent_again_is_not_run_twice(): void
    {
        $this->app->tag([RunBackup::class], RemoteCommands::TAG);
        $command = $this->command(16, 'backup.run');

        $first = app(RemoteCommands::class)->run($command, self::SECRET);
        $again = app(RemoteCommands::class)->run($command, self::SECRET);

        $this->assertSame(1, RunBackup::$runs);
        $this->assertSame($first, $again);
    }

    public function test_the_built_in_actions_are_registered(): void
    {
        $this->assertSame(
            ['report.now', 'cache.clear', 'maintenance.down', 'maintenance.up', 'secret.rotate'],
            array_keys(app(RemoteCommands::class)->handlers()),
        );
    }

    public function test_a_check_in_can_be_asked_for(): void
    {
        $this->portalQueues([$this->command(17, 'report.now')], [
            'portal.test/api/connect/v1/report' => Http::response(['data' => []]),
        ]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertSame('succeeded', $this->outcomeOf(17)['status']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://portal.test/api/connect/v1/report');
    }

    public function test_the_caches_can_be_cleared(): void
    {
        cache()->put('stale', true);
        $this->portalQueues([$this->command(18, 'cache.clear')]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertSame('succeeded', $this->outcomeOf(18)['status']);
        $this->assertFalse(cache()->has('stale'));
    }

    public function test_the_secret_is_rotated_and_used_from_then_on(): void
    {
        $newSecret = str_repeat('n', 64);

        $this->portalQueues([$this->command(19, 'secret.rotate')], [
            'portal.test/api/connect/v1/secret' => Http::response(['data' => ['secret' => $newSecret]]),
        ]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertSame($newSecret, Connection::current()->secret);
        $this->assertSame(['status' => 'succeeded', 'output' => 'The site now signs with a new secret.', 'result' => null], $this->outcomeOf(19));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://portal.test/api/connect/v1/secret'
            && $this->signedWith($request, self::SECRET));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/commands/19') && $this->signedWith($request, $newSecret));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), $newSecret));
    }

    public function test_a_refused_rotation_keeps_the_old_secret(): void
    {
        $this->portalQueues([$this->command(20, 'secret.rotate')], [
            'portal.test/api/connect/v1/secret' => Http::response(['message' => 'Rotation is not available.'], 503),
        ]);

        $this->artisan('gadya:commands')->assertSuccessful();

        $this->assertSame(self::SECRET, Connection::current()->secret);
        $this->assertSame(['status' => 'failed', 'output' => 'Rotation is not available.', 'result' => null], $this->outcomeOf(20));
    }

    public function test_a_rotation_without_a_usable_secret_keeps_the_old_one(): void
    {
        Http::fake(['portal.test/api/connect/v1/secret' => Http::response(['data' => ['secret' => '']])]);

        $this->assertSame('failed', app(RemoteCommands::class)->run($this->command(21, 'secret.rotate'), self::SECRET)['status']);
        $this->assertSame(self::SECRET, Connection::current()->secret);
    }

    public function test_an_unconnected_site_asks_for_nothing(): void
    {
        Connection::query()->delete();
        Http::fake();

        $this->artisan('gadya:commands')->expectsOutputToContain('Not connected')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_refused_request_for_actions_fails_the_command(): void
    {
        Http::fake(['portal.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->artisan('gadya:commands')->expectsOutputToContain('HTTP 401')->assertFailed();
    }

    public function test_actions_and_check_ins_are_scheduled_even_in_maintenance_mode(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn (Event $event): string => str_contains((string) $event->command, 'gadya:commands') ? 'commands' : (str_contains((string) $event->command, 'gadya:report') ? 'report' : 'other'));

        $this->assertSame('* * * * *', $events['commands']->expression);

        foreach (['commands', 'report'] as $command) {
            $this->assertTrue($events[$command]->evenInMaintenanceMode, "gadya:{$command} runs in maintenance mode.");
            $this->assertTrue($events[$command]->withoutOverlapping);
            $this->assertTrue($events[$command]->runInBackground);
        }

        $this->assertTrue($events['commands']->filtersPass($this->app));

        Connection::query()->delete();

        $this->assertFalse($events['commands']->filtersPass($this->app), 'Nothing is asked for until the site is connected.');
    }
}
