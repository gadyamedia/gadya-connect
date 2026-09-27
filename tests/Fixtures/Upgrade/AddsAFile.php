<?php

namespace Gadya\Connect\Tests\Fixtures\Upgrade;

/** A code step the way Gadya CMS adds one: a plain tagged class that depends on nothing from gadya/connect. */
class AddsAFile
{
    public function key(): string
    {
        return 'cms.0.15.0.adds-a-file';
    }

    public function description(): string
    {
        return 'Add a file the release needs';
    }

    public function phase(): string
    {
        return 'code';
    }

    public function shouldRun(): bool
    {
        return StepLog::$shouldRunAnswer;
    }

    public function run(): string
    {
        StepLog::$ran[] = $this->key();

        return 'Wrote database/migrations/create_things.php';
    }
}
