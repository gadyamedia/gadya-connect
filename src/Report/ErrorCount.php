<?php

namespace Gadya\Connect\Report;

use Illuminate\Support\Carbon;

/**
 * How many errors the application logged in the last day, read from the
 * tail of its log files. A count, not the messages: those stay on the
 * server, and the portal reads them from Forge or Cloud when asked.
 */
class ErrorCount
{
    private const TAIL_BYTES = 4 * 1024 * 1024;

    public function lastDay(): int
    {
        $since = now()->subDay();
        $count = 0;

        foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
            if (filemtime($file) < $since->getTimestamp()) {
                continue;
            }

            $count += $this->countIn($file, $since);
        }

        return $count;
    }

    private function countIn(string $file, Carbon $since): int
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return 0;
        }

        $size = filesize($file) ?: 0;
        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/m', $tail, $matches);

        return collect($matches[1])
            ->filter(fn (string $moment): bool => rescue(fn (): bool => Carbon::parse($moment)->gte($since), false, report: false))
            ->count();
    }
}
