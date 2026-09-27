<?php

namespace Gadya\Connect\Upgrade;

use Composer\InstalledVersions;
use Gadya\Connect\Upgrade\Steps\ClearCaches;
use Gadya\Connect\Upgrade\Steps\PublishFilamentAssets;
use Gadya\Connect\Upgrade\Steps\RefreshBoostSkills;
use Gadya\Connect\Upgrade\Steps\RunMigrations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Throwable;

/**
 * The mechanical part of an upgrade, as steps that are safe to run again:
 * each says whether it still has anything to do, so running the lot after
 * every release does exactly what that site still needs and nothing else.
 *
 * Two phases. `code` changes files in the repository and runs in CI, before
 * the tests (Gadya's update workflow commits what it changes). `server` runs
 * on the live site after the deploy: the migrations first, then the steps,
 * then clearing the caches and publishing Filament's assets.
 *
 * A step is any class tagged `gadya-connect.upgrade-steps` in the container
 * with five methods - nothing to extend or implement, so Gadya CMS and
 * others can add steps without depending on this version:
 *
 *     public function key(): string          // "cms.0.15.0.notifications-table", stable forever
 *     public function description(): string
 *     public function phase(): string        // "code" | "server"
 *     public function shouldRun(): bool      // false once done, or where it does not apply
 *     public function run(): string          // what it did, for a person; throw to fail
 */
class Upgrader
{
    public const TAG = 'gadya-connect.upgrade-steps';

    public const CODE = 'code';

    public const SERVER = 'server';

    /** Kept short: the outcome travels to the portal with every step's output in it. */
    private const OUTPUT_LENGTH = 2000;

    /**
     * Every step of one phase, in the order they run: for the server the
     * migrations, the tagged steps, then the caches and Filament's assets;
     * for the code the tagged steps, then Boost. When two classes claim one
     * key, whichever was tagged first wins.
     *
     * @return list<object>
     */
    public function steps(string $phase): array
    {
        $steps = [];

        foreach ($this->tagged() as $step) {
            if ($step->phase() === $phase) {
                $steps[$step->key()] ??= $step;
            }
        }

        $steps = array_values($steps);

        return $phase === self::SERVER
            ? [app(RunMigrations::class), ...$steps, app(ClearCaches::class), app(PublishFilamentAssets::class)]
            : [...$steps, app(RefreshBoostSkills::class)];
    }

    /**
     * Runs one phase. A step that fails does not stop the others - they do
     * not depend on each other - except the migrations: nothing runs on a
     * database that did not migrate.
     *
     * @return array{phase: string, dry_run: bool, ran: list<array{key: string, description: string, output: string}>, skipped: list<string>, failed: list<array{key: string, error: string}>, versions: array{gadya_cms: ?string, gadya_connect: ?string}, would_run?: list<array{key: string, description: string}>}
     */
    public function run(string $phase, bool $dryRun = false): array
    {
        $outcome = ['phase' => $phase, 'dry_run' => $dryRun, 'ran' => [], 'skipped' => [], 'failed' => []];
        $wouldRun = [];
        $stoppedBy = null;

        foreach ($this->steps($phase) as $step) {
            $key = (string) $step->key();

            if ($stoppedBy !== null) {
                $outcome['failed'][] = ['key' => $key, 'error' => "Not run: {$stoppedBy} failed."];

                continue;
            }

            try {
                if (! $step->shouldRun()) {
                    $outcome['skipped'][] = $key;

                    continue;
                }

                if ($dryRun) {
                    $wouldRun[] = ['key' => $key, 'description' => (string) $step->description()];

                    continue;
                }

                $outcome['ran'][] = ['key' => $key, 'description' => (string) $step->description(), 'output' => $this->limit((string) $step->run())];
            } catch (Throwable $exception) {
                $outcome['failed'][] = ['key' => $key, 'error' => $this->limit($exception->getMessage() ?: $exception::class)];

                if ($step instanceof RunMigrations) {
                    $stoppedBy = $key;
                }
            }
        }

        $outcome['versions'] = self::versions();

        if ($dryRun) {
            $outcome['would_run'] = $wouldRun;
        }

        return $outcome;
    }

    /**
     * @return array{gadya_cms: ?string, gadya_connect: ?string}
     */
    public static function versions(): array
    {
        return [
            'gadya_cms' => self::version('gadya/cms'),
            'gadya_connect' => self::version('gadya/connect'),
        ];
    }

    public static function version(string $package): ?string
    {
        if (! InstalledVersions::isInstalled($package)) {
            return null;
        }

        $version = InstalledVersions::getPrettyVersion($package);

        return $version === null ? null : ltrim($version, 'v');
    }

    public static function hasCommand(string $name): bool
    {
        return array_key_exists($name, app(Kernel::class)->all());
    }

    /**
     * The tagged steps that have every method a step needs and a phase
     * this runner knows; anything else is ignored rather than fatal.
     *
     * @return list<object>
     */
    private function tagged(): array
    {
        return collect(iterator_to_array(app()->tagged(self::TAG), false))
            ->filter(fn (object $step): bool => collect(['key', 'description', 'phase', 'shouldRun', 'run'])->every(fn (string $method): bool => method_exists($step, $method)))
            ->filter(fn (object $step): bool => in_array($step->phase(), [self::CODE, self::SERVER], true))
            ->values()
            ->all();
    }

    private function limit(string $output): string
    {
        return Str::limit(trim($output), self::OUTPUT_LENGTH);
    }
}
