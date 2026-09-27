<?php

namespace Gadya\Connect\Tests\Fixtures\Upgrade;

use RuntimeException;

/** A server step that fails. */
class Breaks
{
    public function key(): string
    {
        return 'cms.breaks';
    }

    public function description(): string
    {
        return 'A step that fails';
    }

    public function phase(): string
    {
        return 'server';
    }

    public function shouldRun(): bool
    {
        return true;
    }

    public function run(): string
    {
        StepLog::$ran[] = $this->key();

        throw new RuntimeException('The disk is full.');
    }
}
