<?php

namespace Gadya\Connect\Commands;

use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\PortalClient;
use Gadya\Connect\Remote\RemoteCommands;
use Illuminate\Console\Command;
use Throwable;

/**
 * Asks the portal for actions queued for this site, runs the ones that are
 * signed and still in date, and tells the portal how each went. Scheduled
 * every minute, and even in maintenance mode: that is when "bring the site
 * back up" matters most.
 */
class CommandsCommand extends Command
{
    protected $signature = 'gadya:commands';

    protected $description = 'Run the actions the Gadya Media portal has queued for this site';

    public function handle(PortalClient $portal, RemoteCommands $commands): int
    {
        $connection = Connection::current();

        if ($connection === null) {
            $this->components->info('Not connected to the portal: nothing to run.');

            return self::SUCCESS;
        }

        /* Commands in this batch were signed with the secret the site holds now, even if one of them replaces it. */
        $secret = $connection->secret;

        try {
            $response = $portal->send($connection, 'GET', '/api/connect/v1/commands');
        } catch (Throwable $exception) {
            $this->components->error('Could not reach the portal: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error('The portal refused the request for actions (HTTP '.$response->status().').');

            return self::FAILURE;
        }

        $queued = array_filter((array) $response->json('data', []), fn ($command): bool => is_array($command) && (int) ($command['id'] ?? 0) > 0);

        foreach ($queued as $command) {
            $outcome = $commands->run($command, $secret);
            $type = is_string($command['type'] ?? null) ? $command['type'] : 'unknown';

            $outcome['status'] === 'succeeded'
                ? $this->components->info("{$type}: {$outcome['output']}")
                : $this->components->warn("{$type} failed: {$outcome['output']}");

            $this->reportOutcome($portal, (int) $command['id'], $outcome);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{status: string, output: string, result: ?array}  $outcome
     */
    private function reportOutcome(PortalClient $portal, int $id, array $outcome): void
    {
        /* Read again: secret.rotate may have just replaced the secret. */
        $connection = Connection::current();

        if ($connection === null) {
            return;
        }

        try {
            $response = $portal->send($connection, 'POST', '/api/connect/v1/commands/'.$id, $outcome);

            if (! $response->successful()) {
                $this->components->warn("The portal did not take the outcome of command {$id} (HTTP {$response->status()}); it will send it again.");
            }
        } catch (Throwable $exception) {
            $this->components->warn("Could not tell the portal how command {$id} went: {$exception->getMessage()}");
        }
    }
}
