<?php

namespace Gadya\Connect\Portal;

/**
 * The signature every request to the portal carries: an HMAC of when it was
 * sent, a one-off nonce, the method, the path and a hash of the body, keyed
 * with the secret the portal handed over at pairing. The portal
 * (App\Support\Connect\RequestSignature) checks exactly this.
 */
class RequestSignature
{
    public const SITE_HEADER = 'X-Gadya-Site';

    public const TIMESTAMP_HEADER = 'X-Gadya-Timestamp';

    public const NONCE_HEADER = 'X-Gadya-Nonce';

    public const SIGNATURE_HEADER = 'X-Gadya-Signature';

    public static function sign(string $secret, int $timestamp, string $nonce, string $method, string $path, string $body): string
    {
        $canonical = implode("\n", [$timestamp, $nonce, strtoupper($method), '/'.ltrim($path, '/'), hash('sha256', $body)]);

        return hash_hmac('sha256', $canonical, $secret);
    }
}
