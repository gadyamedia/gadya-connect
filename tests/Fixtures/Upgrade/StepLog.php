<?php

namespace Gadya\Connect\Tests\Fixtures\Upgrade;

/** What the fixture steps did, in order. */
class StepLog
{
    /** @var list<string> */
    public static array $ran = [];

    public static bool $shouldRunAnswer = true;

    public static function reset(): void
    {
        static::$ran = [];
        static::$shouldRunAnswer = true;
    }
}
