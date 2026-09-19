<?php

namespace Gadya\Connect\Checks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Queued work that gave up in the last day. A queue that fails quietly is
 * an enquiry email nobody receives.
 */
class FailedJobsCheck extends Check
{
    protected int $warnAt = 1;

    protected int $failAt = 10;

    public function run(): Result
    {
        $result = Result::make();

        if (! Schema::hasTable('failed_jobs')) {
            return $result->ok()->shortSummary('No failed jobs table');
        }

        $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $result->shortSummary($count.' in 24h')->meta(['count' => $count]);

        return match (true) {
            $count >= $this->failAt => $result->failed("{$count} queued jobs failed in the last day."),
            $count >= $this->warnAt => $result->warning("{$count} queued ".str('job')->plural($count).' failed in the last day.'),
            default => $result->ok(),
        };
    }
}
