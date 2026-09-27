<?php

namespace Gadya\Connect\Tests\Fixtures\Upgrade;

/** A server step that always has something to do. */
class WarmsACache
{
    public function key(): string
    {
        return 'cms.warms-a-cache';
    }

    public function description(): string
    {
        return 'Warm a cache';
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

        return 'Cache warmed.';
    }
}
