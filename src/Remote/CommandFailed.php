<?php

namespace Gadya\Connect\Remote;

use RuntimeException;

/**
 * Thrown by a handler that failed but has something to show for it: the
 * command is reported as failed, with this result alongside the message.
 * Any other exception fails the command with its message alone.
 */
class CommandFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(string $message, public readonly array $result)
    {
        parent::__construct($message);
    }
}
