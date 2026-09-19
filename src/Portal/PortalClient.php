<?php

namespace Gadya\Connect\Portal;

use Gadya\Connect\Models\Connection;
use Gadya\Connect\Report\ReportBuilder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Everything this site says to the portal. */
class PortalClient
{
    public function __construct(private readonly ReportBuilder $reports) {}

    /**
     * Swaps a one-time pairing code for this site's secret, and remembers it.
     */
    public function pair(string $code, ?string $portalUrl = null): Connection
    {
        $portalUrl = rtrim($portalUrl ?: (string) config('gadya-connect.portal_url'), '/');

        $response = Http::acceptJson()
            ->timeout((int) config('gadya-connect.timeout', 15))
            ->post($portalUrl.'/api/connect/v1/pair', [
                'code' => strtoupper(trim($code)),
                'report' => $this->reports->build(),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('message') ?: 'The portal did not accept the code (HTTP '.$response->status().').'));
        }

        return DB::transaction(function () use ($response, $portalUrl): Connection {
            Connection::query()->delete();

            return Connection::query()->create([
                'site_id' => (int) $response->json('site_id'),
                /* The address that answered, not the one the portal believes it has. */
                'portal_url' => $portalUrl,
                'secret' => (string) $response->json('secret'),
                'site_name' => $response->json('name'),
                'client_name' => $response->json('client'),
                'paired_at' => now(),
                'last_report_at' => now(),
            ]);
        });
    }

    /**
     * Sends the check-in. Returns false when the site is not connected.
     */
    public function report(): bool
    {
        $connection = Connection::current();

        if ($connection === null) {
            return false;
        }

        $response = $this->send($connection, 'POST', '/api/connect/v1/report', $this->reports->build($connection));

        if (! $response->successful()) {
            $connection->forceFill(['last_error' => 'HTTP '.$response->status().': '.Str::limit((string) ($response->json('message') ?? $response->body()), 300)])->save();

            throw new RuntimeException('The portal refused the check-in (HTTP '.$response->status().').');
        }

        $connection->forceFill(['last_report_at' => now(), 'last_error' => null])->save();

        return true;
    }

    /**
     * The support requests this person has opened from the site.
     *
     * @return list<array<string, mixed>>
     */
    public function tickets(string $email): array
    {
        return (array) $this->portal('GET', '/api/connect/v1/tickets?'.http_build_query(['email' => $email]))->json('data', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function ticket(string $reference, string $email): array
    {
        return (array) $this->portal('GET', '/api/connect/v1/tickets/'.rawurlencode($reference).'?'.http_build_query(['email' => $email]))->json('data', []);
    }

    /**
     * @param  array<string, mixed>  $ticket  subject, body, type, priority, requester{name,email}, context, attachments
     * @return array<string, mixed>
     */
    public function openTicket(array $ticket): array
    {
        return (array) $this->portal('POST', '/api/connect/v1/tickets', $ticket)->json('data', []);
    }

    /**
     * @param  list<array{filename: string, content_type: string, content: string}>  $attachments
     */
    public function replyToTicket(string $reference, string $email, ?string $name, string $body, array $attachments = []): void
    {
        $this->portal('POST', '/api/connect/v1/tickets/'.rawurlencode($reference).'/replies', [
            'body' => $body,
            'author' => ['name' => $name, 'email' => $email],
            'attachments' => $attachments,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function portal(string $method, string $path, array $body = []): Response
    {
        $connection = Connection::current();

        if ($connection === null) {
            throw new RuntimeException('This site is not connected to Gadya Media yet.');
        }

        $response = $this->send($connection, $method, $path, $body);

        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('message') ?: 'Gadya Media did not answer (HTTP '.$response->status().'). Please email '.config('gadya-connect.help_address').'.'));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function send(Connection $connection, string $method, string $path, array $body = []): Response
    {
        $json = $body === [] ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();
        $nonce = Str::random(32);
        $url = $connection->portal_url.$path;
        $query = parse_url($url, PHP_URL_QUERY);
        $signedPath = (string) (parse_url($url, PHP_URL_PATH) ?: $path).($query ? '?'.$query : '');

        return Http::acceptJson()
            ->timeout((int) config('gadya-connect.timeout', 15))
            ->withHeaders([
                RequestSignature::SITE_HEADER => (string) $connection->site_id,
                RequestSignature::TIMESTAMP_HEADER => (string) $timestamp,
                RequestSignature::NONCE_HEADER => $nonce,
                RequestSignature::SIGNATURE_HEADER => RequestSignature::sign($connection->secret, $timestamp, $nonce, $method, $signedPath, $json),
            ])
            ->withBody($json, 'application/json')
            ->send($method, $url);
    }
}
