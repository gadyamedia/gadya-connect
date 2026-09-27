<?php

namespace Gadya\Connect\Security;

use Illuminate\Support\Facades\Cache;

/**
 * Failed sign-ins and lockouts, counted in the site's cache so the portal can
 * see someone trying passwords before they get in. Nothing about the person
 * is kept: only how many, and from which network (the last part of the
 * address is masked).
 *
 * Counts go into ten-minute buckets that expire after a day, so the last
 * hour and the last day are sums of buckets and nothing needs cleaning up.
 * Two sign-ins failing at the same instant may count once: this is a gauge,
 * not an audit log.
 */
class FailedLogins
{
    private const BUCKET_SECONDS = 600;

    private const BUCKETS_PER_HOUR = 6;

    private const BUCKETS_PER_DAY = 144;

    /** A flood from thousands of addresses keeps the busiest few, not all of them. */
    private const ADDRESSES_PER_BUCKET = 100;

    private const TOP_ADDRESSES = 5;

    public function record(?string $ip): void
    {
        $key = $this->key($this->bucket());
        $bucket = Cache::get($key, ['count' => 0, 'ips' => []]);

        $bucket['count']++;

        if ($ip !== null && $ip !== '') {
            $masked = static::mask($ip);
            $bucket['ips'][$masked] = ($bucket['ips'][$masked] ?? 0) + 1;

            if (count($bucket['ips']) > self::ADDRESSES_PER_BUCKET) {
                arsort($bucket['ips']);
                $bucket['ips'] = array_slice($bucket['ips'], 0, self::ADDRESSES_PER_BUCKET, true);
            }
        }

        Cache::put($key, $bucket, now()->addDay()->addHour());
    }

    /**
     * @return array{last_hour: int, last_day: int, top_ips: list<array{ip_masked: string, count: int}>}
     */
    public function summary(): array
    {
        $current = $this->bucket();
        $keys = array_map(fn (int $ago): string => $this->key($current - $ago), range(0, self::BUCKETS_PER_DAY - 1));
        $buckets = array_values(Cache::many($keys));

        $lastHour = 0;
        $lastDay = 0;
        $addresses = [];

        foreach ($buckets as $ago => $bucket) {
            if (! is_array($bucket)) {
                continue;
            }

            $lastDay += (int) ($bucket['count'] ?? 0);
            $lastHour += $ago < self::BUCKETS_PER_HOUR ? (int) ($bucket['count'] ?? 0) : 0;

            foreach ((array) ($bucket['ips'] ?? []) as $masked => $count) {
                $addresses[$masked] = ($addresses[$masked] ?? 0) + (int) $count;
            }
        }

        arsort($addresses);

        return [
            'last_hour' => $lastHour,
            'last_day' => $lastDay,
            'top_ips' => collect(array_slice($addresses, 0, self::TOP_ADDRESSES, true))
                ->map(fn (int $count, string|int $masked): array => ['ip_masked' => (string) $masked, 'count' => $count])
                ->values()
                ->all(),
        ];
    }

    /**
     * 203.0.113.42 becomes 203.0.113.x; an IPv6 address keeps its /48
     * network (2001:db8:85a3::x). Enough to spot one network hammering the
     * sign-in page, not enough to name anyone.
     */
    public static function mask(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.x';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $groups = str_split(bin2hex((string) inet_pton($ip)), 4);

            return implode(':', array_map(fn (string $group): string => ltrim($group, '0') ?: '0', array_slice($groups, 0, 3))).'::x';
        }

        return 'unknown';
    }

    private function bucket(): int
    {
        return intdiv(now()->getTimestamp(), self::BUCKET_SECONDS);
    }

    private function key(int $bucket): string
    {
        return 'gadya-connect.failed-logins.'.$bucket;
    }
}
