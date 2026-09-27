<?php

namespace Gadya\Connect\Commands;

use Gadya\Connect\Upgrade\Upgrader;
use Illuminate\Console\Command;

/**
 * The mechanical part of an upgrade, after `composer update`. Gadya's update
 * workflow runs `--phase=code` in CI and commits what changed; the portal
 * runs the server phase on the live site after the deploy (`upgrade.finish`).
 * Safe to run again at any time: a step that is done is skipped.
 */
class UpgradeCommand extends Command
{
    protected $signature = 'gadya:upgrade
        {--phase=server : "code" (files in the repository, in CI) or "server" (the live site)}
        {--json : Print the outcome as JSON}
        {--dry-run : List what would run without running it}';

    protected $description = 'Run the upgrade steps of the installed Gadya packages';

    public function handle(Upgrader $upgrader): int
    {
        $phase = (string) $this->option('phase');

        if (! in_array($phase, [Upgrader::CODE, Upgrader::SERVER], true)) {
            $this->components->error("Unknown phase \"{$phase}\": use --phase=code or --phase=server.");

            return self::INVALID;
        }

        $outcome = $upgrader->run($phase, (bool) $this->option('dry-run'));

        if ($this->option('json')) {
            $this->line((string) json_encode($outcome, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->describe($outcome);
        }

        return $outcome['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function describe(array $outcome): void
    {
        $versions = collect($outcome['versions'])->filter()->map(fn (string $version, string $package): string => str_replace('_', '/', $package).' '.$version)->implode(', ');

        $this->components->info("Upgrade, {$outcome['phase']} phase".($versions === '' ? '' : " ({$versions})").($outcome['dry_run'] ? ': dry run, nothing changed' : ''));

        foreach ($outcome['would_run'] ?? [] as $step) {
            $this->components->twoColumnDetail("{$step['key']} <fg=gray>{$step['description']}</>", '<fg=yellow>WOULD RUN</>');
        }

        foreach ($outcome['ran'] as $step) {
            $this->components->twoColumnDetail("{$step['key']} <fg=gray>{$step['description']}</>", '<fg=green>DONE</>');

            if ($this->output->isVerbose()) {
                $this->line($step['output']);
            }
        }

        foreach ($outcome['skipped'] as $key) {
            $this->components->twoColumnDetail($key, '<fg=gray>NOTHING TO DO</>');
        }

        foreach ($outcome['failed'] as $step) {
            $this->components->twoColumnDetail($step['key'], '<fg=red>FAILED</>');
            $this->components->error($step['error']);
        }
    }
}
