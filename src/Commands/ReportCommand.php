<?php

namespace Gadya\Connect\Commands;

use Gadya\Connect\Portal\PortalClient;
use Illuminate\Console\Command;
use Throwable;

class ReportCommand extends Command
{
    protected $signature = 'gadya:report';

    protected $description = 'Send this site\'s check-in to the Gadya Media portal';

    public function handle(PortalClient $portal): int
    {
        try {
            $sent = $portal->report();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $sent
            ? $this->components->info('Checked in with the portal.')
            : $this->components->info('Not connected to the portal: nothing sent. Run gadya:connect with a pairing code.');

        return self::SUCCESS;
    }
}
