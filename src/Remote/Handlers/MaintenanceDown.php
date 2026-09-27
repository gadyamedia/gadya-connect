<?php

namespace Gadya\Connect\Remote\Handlers;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use RuntimeException;

/**
 * maintenance.down: `php artisan down`, with an optional bypass secret. The
 * portal chooses nothing else: no view to render and no address to send
 * visitors to, so an action from the portal can never point a client's
 * visitors somewhere else.
 */
class MaintenanceDown
{
    public function type(): string
    {
        return 'maintenance.down';
    }

    /**
     * @param  array{secret?: ?string}  $payload
     * @return array{output: string, result: array{down: bool, bypass_url: ?string}}
     */
    public function handle(array $payload): array
    {
        $secret = $payload['secret'] ?? null;

        if ($secret !== null && (! is_string($secret) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $secret))) {
            throw new InvalidArgumentException('The bypass secret may only use letters, numbers, dashes and underscores (64 at most).');
        }

        $exitCode = Artisan::call('down', array_filter(['--secret' => $secret]));

        if ($exitCode !== 0) {
            throw new RuntimeException(trim(Artisan::output()) ?: 'The site could not be put into maintenance mode.');
        }

        return [
            'output' => $secret === null ? 'The site is down for maintenance.' : 'The site is down for maintenance. The bypass link lets the team in.',
            'result' => ['down' => true, 'bypass_url' => $secret === null ? null : rtrim((string) config('app.url'), '/').'/'.$secret],
        ];
    }
}
