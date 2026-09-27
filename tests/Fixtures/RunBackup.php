<?php

namespace Gadya\Connect\Tests\Fixtures;

use RuntimeException;

/**
 * A remote action the way Gadya CMS adds one: a plain class with type() and
 * handle(), tagged in the container, depending on nothing from gadya/connect.
 */
class RunBackup
{
    public static int $runs = 0;

    public function type(): string
    {
        return 'backup.run';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{output: string, result: ?array}
     */
    public function handle(array $payload): array
    {
        if (($payload['disk'] ?? null) === 'broken') {
            throw new RuntimeException('The backup disk is not writable.');
        }

        static::$runs++;

        return ['output' => 'Backup finished.', 'result' => ['disk' => $payload['disk'] ?? 'local', 'size' => 1024]];
    }
}
