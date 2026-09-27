<?php

namespace Gadya\Connect\Tests\Fixtures\Upgrade;

/** A server step whose work is done: skipped. */
class AlreadyDone
{
    public function key(): string
    {
        return 'cms.0.14.0.already-done';
    }

    public function description(): string
    {
        return 'Something an earlier upgrade did';
    }

    public function phase(): string
    {
        return 'server';
    }

    public function shouldRun(): bool
    {
        return false;
    }

    public function run(): string
    {
        StepLog::$ran[] = $this->key();

        return 'Should never run.';
    }
}
