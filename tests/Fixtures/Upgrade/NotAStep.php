<?php

namespace Gadya\Connect\Tests\Fixtures\Upgrade;

/** Tagged by mistake: it has no shouldRun() or phase(), so the runner leaves it out. */
class NotAStep
{
    public function key(): string
    {
        return 'cms.not-a-step';
    }

    public function run(): string
    {
        return 'Should never run.';
    }
}
