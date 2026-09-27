<?php

namespace Gadya\Connect\Remote\Handlers;

use Gadya\Connect\Portal\PortalClient;
use RuntimeException;

/** report.now: check in straight away rather than at the next five minutes. */
class ReportNow
{
    public function __construct(private readonly PortalClient $portal) {}

    public function type(): string
    {
        return 'report.now';
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{output: string, result: null}
     */
    public function handle(array $payload): array
    {
        if (! $this->portal->report()) {
            throw new RuntimeException('This site is not connected to the portal.');
        }

        return ['output' => 'Checked in with the portal.', 'result' => null];
    }
}
