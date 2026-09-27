<?php

namespace Gadya\Connect\Remote\Handlers;

use Gadya\Connect\Portal\PortalClient;

/**
 * secret.rotate: swap the secret this site signs with for a new one from
 * the portal. The new secret never appears in the output.
 */
class RotateSecret
{
    public function __construct(private readonly PortalClient $portal) {}

    public function type(): string
    {
        return 'secret.rotate';
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{output: string, result: null}
     */
    public function handle(array $payload): array
    {
        $this->portal->rotateSecret();

        return ['output' => 'The site now signs with a new secret.', 'result' => null];
    }
}
