<?php

namespace Gadya\Connect\Remote\Handlers;

use Gadya\Connect\Remote\CommandFailed;
use Gadya\Connect\Upgrade\Upgrader;

/**
 * upgrade.finish: the server phase of `gadya:upgrade` once the new release
 * is deployed - migrations, the packages' server steps, clearing the caches
 * and Filament's assets. The outcome goes back to the portal as the result,
 * whether it worked or not.
 */
class FinishUpgrade
{
    public function __construct(private readonly Upgrader $upgrader) {}

    public function type(): string
    {
        return 'upgrade.finish';
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{output: string, result: array<string, mixed>}
     */
    public function handle(array $payload): array
    {
        $outcome = $this->upgrader->run(Upgrader::SERVER);

        $summary = collect([
            ...array_map(fn (array $step): string => "Done: {$step['key']}", $outcome['ran']),
            ...array_map(fn (array $step): string => "Failed: {$step['key']}: {$step['error']}", $outcome['failed']),
        ])->implode("\n");

        if ($outcome['failed'] !== []) {
            throw new CommandFailed($summary, $outcome);
        }

        return ['output' => $summary ?: 'Nothing to do.', 'result' => $outcome];
    }
}
