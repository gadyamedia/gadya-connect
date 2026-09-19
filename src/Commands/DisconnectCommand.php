<?php

namespace Gadya\Connect\Commands;

use Gadya\Connect\Models\Connection;
use Illuminate\Console\Command;

class DisconnectCommand extends Command
{
    protected $signature = 'gadya:disconnect';

    protected $description = 'Forget this site\'s link to the Gadya Media portal';

    public function handle(): int
    {
        Connection::query()->delete();

        $this->components->info('Disconnected. The site no longer checks in; the portal will show it as silent.');

        return self::SUCCESS;
    }
}
