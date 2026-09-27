<?php

namespace Gadya\Connect\Report;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Whether anyone can download this site's .env: the site asks for its own
 * {app.url}/.env, the way an attacker would. A 200 with an APP_KEY= line in
 * it means every secret the site has is public.
 *
 * Asked at most every six hours, with a short timeout, and never from a
 * test run or a site whose address is still localhost - there is nothing to
 * ask, and a check-in must not wait on it.
 */
class EnvExposure
{
    private const CACHE_KEY = 'gadya-connect.security.env-exposed';

    private const CACHE_SECONDS = 6 * 60 * 60;

    private const TIMEOUT_SECONDS = 3;

    /**
     * True when exposed, false when not, null when it was not (or could not
     * be) checked.
     */
    public function check(): ?bool
    {
        $url = rtrim((string) config('app.url'), '/');

        if (! config('gadya-connect.security.env_check', true) || app()->runningUnitTests() || ! $this->isPublic($url)) {
            return null;
        }

        /* Cached inside an array so "could not tell" is remembered too and not retried every check-in. */
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => ['exposed' => $this->probe($url)])['exposed'] ?? null;
    }

    private function probe(string $url): ?bool
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withUserAgent('Gadya Connect')
                ->get($url.'/.env');
        } catch (Throwable) {
            return null;
        }

        return $response->status() === 200 && str_contains($response->body(), 'APP_KEY=');
    }

    private function isPublic(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
