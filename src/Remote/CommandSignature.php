<?php

namespace Gadya\Connect\Remote;

/**
 * The signature on every action the portal queues for this site, keyed with
 * the site's secret. The portal (App\Support\Connect\CommandSignature) signs
 * exactly this string, so a command cannot be forged, altered or given more
 * time without the secret:
 *
 *     id \n type \n json_encode(payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) \n expires_at
 *
 * expires_at is the string exactly as the portal sent it, and the payload is
 * encoded from the decoded array, so both sides produce the same bytes.
 */
class CommandSignature
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function sign(string $secret, int $id, string $type, array $payload, string $expiresAt): string
    {
        $canonical = implode("\n", [
            $id,
            $type,
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $expiresAt,
        ]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function verify(string $secret, int $id, string $type, array $payload, string $expiresAt, string $signature): bool
    {
        return hash_equals(static::sign($secret, $id, $type, $payload, $expiresAt), $signature);
    }
}
