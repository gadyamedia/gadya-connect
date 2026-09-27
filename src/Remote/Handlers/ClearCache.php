<?php

namespace Gadya\Connect\Remote\Handlers;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/** cache.clear: `php artisan optimize:clear` - config, routes, views, events and the application cache. */
class ClearCache
{
    public function type(): string
    {
        return 'cache.clear';
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{output: string, result: null}
     */
    public function handle(array $payload): array
    {
        $exitCode = Artisan::call('optimize:clear');
        $output = trim(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException($output ?: 'optimize:clear failed.');
        }

        return ['output' => $output ?: 'Caches cleared.', 'result' => null];
    }
}
