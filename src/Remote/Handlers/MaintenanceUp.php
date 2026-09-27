<?php

namespace Gadya\Connect\Remote\Handlers;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** maintenance.up: `php artisan up`. */
class MaintenanceUp
{
    public function type(): string
    {
        return 'maintenance.up';
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{output: string, result: array{down: bool}}
     */
    public function handle(array $payload): array
    {
        $exitCode = Artisan::call('up');

        if ($exitCode !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: 'The site could not be brought back up.');
        }

        return ['output' => 'The site is live again.', 'result' => ['down' => false]];
    }
}
