<?php

namespace Gadya\Connect\Tests\Feature;

use Gadya\Connect\Models\Connection;
use Gadya\Connect\Remote\CommandSignature;
use Gadya\Connect\Remote\RemoteCommands;
use Gadya\Connect\Report\ReportBuilder;
use Gadya\Connect\Tests\Fixtures\Upgrade\AddsAFile;
use Gadya\Connect\Tests\Fixtures\Upgrade\AlreadyDone;
use Gadya\Connect\Tests\Fixtures\Upgrade\Breaks;
use Gadya\Connect\Tests\Fixtures\Upgrade\NotAStep;
use Gadya\Connect\Tests\Fixtures\Upgrade\StepLog;
use Gadya\Connect\Tests\Fixtures\Upgrade\WarmsACache;
use Gadya\Connect\Tests\TestCase;
use Gadya\Connect\Upgrade\Upgrader;
use Gadya\Connect\Upgrade\WorkflowTemplate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

class UpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StepLog::reset();
        $this->app->tag([AddsAFile::class, WarmsACache::class, AlreadyDone::class, NotAStep::class], Upgrader::TAG);
    }

    protected function tearDown(): void
    {
        File::delete(base_path(WorkflowTemplate::PATH));

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function upgradeJson(string $phase = 'server', array $options = []): array
    {
        /* Its own buffer: the commands it calls in turn replace Artisan::output(). */
        Artisan::call('gadya:upgrade', ['--phase' => $phase, '--json' => true, ...$options], $output = new BufferedOutput);

        return json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_server_phase_migrates_runs_its_steps_then_clears_the_caches(): void
    {
        cache()->put('stale', true);

        $outcome = $this->upgradeJson();

        $this->assertSame(
            ['connect.migrate', 'cms.warms-a-cache', 'connect.optimize-clear', 'connect.filament-assets'],
            array_column($outcome['ran'], 'key'),
        );
        $this->assertSame(['cms.0.14.0.already-done'], $outcome['skipped'], 'A step with nothing left to do is skipped.');
        $this->assertSame([], $outcome['failed']);
        $this->assertSame(['cms.warms-a-cache'], StepLog::$ran, 'Code steps never run on the server.');
        $this->assertSame(['key' => 'cms.warms-a-cache', 'description' => 'Warm a cache', 'output' => 'Cache warmed.'], $outcome['ran'][1]);
        $this->assertSame('server', $outcome['phase']);
        $this->assertFalse($outcome['dry_run']);
        $this->assertSame(['gadya_cms', 'gadya_connect'], array_keys($outcome['versions']));
        $this->assertNull($outcome['versions']['gadya_cms'], 'Gadya CMS is not installed here.');
        $this->assertFalse(cache()->has('stale'));
    }

    public function test_the_server_phase_is_the_default(): void
    {
        $this->artisan('gadya:upgrade')
            ->expectsOutputToContain('server phase')
            ->expectsOutputToContain('cms.warms-a-cache')
            ->assertSuccessful();
    }

    public function test_the_code_phase_runs_only_code_steps(): void
    {
        $outcome = $this->upgradeJson('code');

        $this->assertSame(['cms.0.15.0.adds-a-file'], array_column($outcome['ran'], 'key'));
        $this->assertSame('Wrote database/migrations/create_things.php', $outcome['ran'][0]['output']);
        $this->assertSame(['connect.boost-update'], $outcome['skipped'], 'Boost is not installed here.');
        $this->assertSame(['cms.0.15.0.adds-a-file'], StepLog::$ran);
    }

    public function test_running_it_again_does_only_what_is_left(): void
    {
        $this->upgradeJson('code');
        StepLog::$shouldRunAnswer = false;

        $again = $this->upgradeJson('code');

        $this->assertSame([], $again['ran']);
        $this->assertContains('cms.0.15.0.adds-a-file', $again['skipped']);
        $this->assertSame(['cms.0.15.0.adds-a-file'], StepLog::$ran, 'It ran once.');
    }

    public function test_a_failing_step_fails_the_command_but_not_the_other_steps(): void
    {
        $this->app->tag([Breaks::class], Upgrader::TAG);

        $exitCode = Artisan::call('gadya:upgrade', ['--json' => true], $output = new BufferedOutput);
        $outcome = json_decode($output->fetch(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame([['key' => 'cms.breaks', 'error' => 'The disk is full.']], $outcome['failed']);
        $this->assertContains('connect.optimize-clear', array_column($outcome['ran'], 'key'), 'The caches are still cleared.');
        $this->assertSame(['cms.warms-a-cache', 'cms.breaks'], StepLog::$ran);

        $this->artisan('gadya:upgrade')->expectsOutputToContain('The disk is full.')->assertFailed();
    }

    public function test_nothing_else_runs_when_the_migrations_fail(): void
    {
        $this->app['migrator']->path(__DIR__.'/../Fixtures/broken-migrations');

        $outcome = $this->upgradeJson();

        $this->assertSame([], $outcome['ran']);
        $this->assertSame('connect.migrate', $outcome['failed'][0]['key']);
        $this->assertStringContainsString('Column already exists.', $outcome['failed'][0]['error']);
        $this->assertSame(
            ['key' => 'cms.warms-a-cache', 'error' => 'Not run: connect.migrate failed.'],
            $outcome['failed'][1],
        );
        $this->assertSame([], StepLog::$ran);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        cache()->put('stale', true);

        $outcome = $this->upgradeJson(options: ['--dry-run' => true]);

        $this->assertTrue($outcome['dry_run']);
        $this->assertSame([], $outcome['ran']);
        $this->assertSame(['connect.migrate', 'cms.warms-a-cache', 'connect.optimize-clear', 'connect.filament-assets'], array_column($outcome['would_run'], 'key'));
        $this->assertSame(['cms.0.14.0.already-done'], $outcome['skipped']);
        $this->assertSame([], StepLog::$ran);
        $this->assertTrue(cache()->has('stale'));
    }

    public function test_an_unknown_phase_is_refused(): void
    {
        $this->artisan('gadya:upgrade', ['--phase' => 'deploy'])->expectsOutputToContain('Unknown phase')->assertExitCode(2);

        $this->assertSame([], StepLog::$ran);
    }

    public function test_a_step_is_found_once_and_a_class_that_is_not_a_step_is_ignored(): void
    {
        $this->app->tag([AddsAFile::class], Upgrader::TAG);

        $keys = array_map(fn (object $step): string => $step->key(), app(Upgrader::class)->steps('code'));

        $this->assertSame(['cms.0.15.0.adds-a-file', 'connect.boost-update'], $keys);
        $this->assertNotContains('cms.not-a-step', array_map(fn (object $step): string => $step->key(), app(Upgrader::class)->steps('server')));
    }

    public function test_the_portal_can_finish_an_upgrade_and_gets_the_outcome_back(): void
    {
        $outcome = app(RemoteCommands::class)->run($this->command(40), $this->secret());

        $this->assertSame('succeeded', $outcome['status']);
        $this->assertStringContainsString('Done: cms.warms-a-cache', $outcome['output']);
        $this->assertSame(['connect.migrate', 'cms.warms-a-cache', 'connect.optimize-clear', 'connect.filament-assets'], array_column($outcome['result']['ran'], 'key'));
        $this->assertSame(['cms.0.14.0.already-done'], $outcome['result']['skipped']);
        $this->assertSame([], $outcome['result']['failed']);
    }

    public function test_a_failed_finish_is_reported_as_failed_with_the_outcome(): void
    {
        $this->app->tag([Breaks::class], Upgrader::TAG);

        $outcome = app(RemoteCommands::class)->run($this->command(41), $this->secret());

        $this->assertSame('failed', $outcome['status']);
        $this->assertStringContainsString('Failed: cms.breaks: The disk is full.', $outcome['output']);
        $this->assertSame([['key' => 'cms.breaks', 'error' => 'The disk is full.']], $outcome['result']['failed']);
        $this->assertContains('cms.warms-a-cache', array_column($outcome['result']['ran'], 'key'));
    }

    public function test_the_check_in_says_which_updater_the_site_has(): void
    {
        $this->assertSame(['version' => Upgrader::version('gadya/connect'), 'workflow_template' => null], app(ReportBuilder::class)->build()['upgrader']);

        File::ensureDirectoryExists(dirname(base_path(WorkflowTemplate::PATH)));
        File::put(base_path(WorkflowTemplate::PATH), "# Updates the Gadya packages.\nname: Gadya update\n");

        $this->assertSame('1', app(ReportBuilder::class)->build()['upgrader']['workflow_template'], 'The first template carried no number.');

        File::put(base_path(WorkflowTemplate::PATH), "# gadya-update-template: 12\n# Updates the Gadya packages.\n");

        $this->assertSame('12', app(ReportBuilder::class)->build()['upgrader']['workflow_template']);
    }

    private function secret(): string
    {
        return str_repeat('s', 64);
    }

    /**
     * @return array<string, mixed>
     */
    private function command(int $id): array
    {
        Connection::query()->firstOrCreate(['site_id' => 17], ['portal_url' => 'https://portal.test', 'secret' => $this->secret()]);
        $expiresAt = now()->addDay()->toIso8601String();

        return [
            'id' => $id,
            'type' => 'upgrade.finish',
            'payload' => [],
            'expires_at' => $expiresAt,
            'signature' => CommandSignature::sign($this->secret(), $id, 'upgrade.finish', [], $expiresAt),
        ];
    }
}
