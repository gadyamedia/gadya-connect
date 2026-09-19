<?php

namespace Gadya\Connect\Commands;

use Gadya\Connect\Portal\PortalClient;
use Illuminate\Console\Command;
use Throwable;

class ConnectCommand extends Command
{
    protected $signature = 'gadya:connect {code : The pairing code from the Gadya portal} {--portal= : The portal address, if not app.gadya.media}';

    protected $description = 'Connect this site to the Gadya Media portal';

    public function handle(PortalClient $portal): int
    {
        try {
            $connection = $portal->pair((string) $this->argument('code'), $this->option('portal') ?: null);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Connected: {$connection->site_name} for {$connection->client_name}. It checks in with {$connection->portal_url} every few minutes from now on.");

        return self::SUCCESS;
    }
}
