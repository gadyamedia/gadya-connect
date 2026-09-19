<?php

namespace Gadya\Connect\Report;

use Gadya\Connect\Checks\FailedJobsCheck;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Enums\Status;
use Spatie\Health\Facades\Health;
use Throwable;

/**
 * Runs the site's spatie/laravel-health checks - its own if it registered
 * any, a sensible set if not - and returns what each found.
 */
class HealthResults
{
    /**
     * @return list<array{name: string, label: string, status: string, summary: string}>
     */
    public function collect(): array
    {
        return collect($this->checks())
            ->filter(fn (Check $check): bool => rescue(fn (): bool => $check->shouldRun(), true, report: false))
            ->map(function (Check $check): array {
                try {
                    $result = $check->run();
                    $status = $result->status instanceof Status ? $result->status->value : (string) $result->status;
                    $summary = $result->getShortSummary() ?: $result->getNotificationMessage();
                } catch (Throwable $exception) {
                    $status = 'crashed';
                    $summary = mb_substr($exception->getMessage(), 0, 200);
                }

                return [
                    'name' => $check->getName(),
                    'label' => $check->getLabel(),
                    'status' => $status,
                    'summary' => (string) $summary,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<Check>
     */
    private function checks(): array
    {
        $registered = Health::registeredChecks()->all();

        if ($registered !== []) {
            return $registered;
        }

        $checks = [
            DatabaseCheck::new(),
            CacheCheck::new(),
            UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(80)->failWhenUsedSpaceIsAbovePercentage(92),
            FailedJobsCheck::new(),
        ];

        if (app()->isProduction()) {
            $checks[] = DebugModeCheck::new();
            $checks[] = EnvironmentCheck::new();
        }

        return $checks;
    }
}
