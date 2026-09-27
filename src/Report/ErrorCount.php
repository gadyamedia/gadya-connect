<?php

namespace Gadya\Connect\Report;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the application logged at error level or worse in the last day, read
 * from the tail of its log files: how many, and the ten errors behind them,
 * grouped so a thousand "No query results for model 12" lines show as one.
 *
 * Only what someone needs to recognise an error leaves the server: its
 * class, a redacted message and the top frame. No stack traces, no context
 * and nothing from the request. Anything that looks like an address, a
 * token, a password or a key is blanked before it is grouped, so it never
 * reaches the portal even inside a message.
 */
class ErrorCount
{
    /** Only the end of each file is read: a runaway log never costs more than this. */
    private const TAIL_BYTES = 4 * 1024 * 1024;

    private const RECENT = 10;

    private const MESSAGE_LENGTH = 300;

    private const LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /**
     * An entry starts with Laravel's (Monolog's) line header. Every level is
     * matched so each error's text ends where the next entry begins.
     */
    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] [\w.-]+\.([A-Z]+): /m';

    /**
     * How Laravel writes an exception into the context: the class, the
     * message, and where it was thrown.
     */
    private const EXCEPTION = '/"exception":"\[object\] \(([^\s(]+)\(code: [^)]*\): (.*) at (.+?):(\d+)\)\s*$/s';

    public function lastDay(): int
    {
        return $this->summary()['last_day'];
    }

    /**
     * @return list<array{level: string, class: ?string, message: string, file: ?string, line: ?int, count: int, first_seen: string, last_seen: string}>
     */
    public function recent(): array
    {
        return $this->summary()['recent'];
    }

    /**
     * Both, from one read of the logs: the check-in's errors section.
     *
     * @return array{last_day: int, recent: list<array{level: string, class: ?string, message: string, file: ?string, line: ?int, count: int, first_seen: string, last_seen: string}>}
     */
    public function summary(): array
    {
        $errors = $this->errorsSince(now()->subDay());

        return [
            'last_day' => $errors->count(),
            'recent' => $this->group($errors),
        ];
    }

    /**
     * @return Collection<int, array{level: string, moment: Carbon, class: ?string, message: string, file: ?string, line: ?int}>
     */
    private function errorsSince(Carbon $since): Collection
    {
        $errors = collect();

        foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
            if (! is_file($file) || ! is_readable($file) || (int) @filemtime($file) < $since->getTimestamp()) {
                continue;
            }

            $errors = $errors->concat($this->errorsIn($this->tail($file), $since));
        }

        return $errors;
    }

    private function tail(string $file): string
    {
        $handle = @fopen($file, 'r');

        if ($handle === false) {
            return '';
        }

        $size = (int) @filesize($file);
        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        return $tail;
    }

    /**
     * @return list<array{level: string, moment: Carbon, class: ?string, message: string, file: ?string, line: ?int}>
     */
    private function errorsIn(string $tail, Carbon $since): array
    {
        if (! preg_match_all(self::HEADER, $tail, $headers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $errors = [];

        foreach ($headers as $index => $header) {
            if (! in_array($header[2][0], self::LEVELS, true)) {
                continue;
            }

            $moment = rescue(fn (): Carbon => Carbon::parse($header[1][0]), null, report: false);

            if ($moment === null || $moment->lt($since)) {
                continue;
            }

            $start = $header[0][1] + strlen($header[0][0]);
            $end = isset($headers[$index + 1]) ? $headers[$index + 1][0][1] : strlen($tail);

            $errors[] = ['level' => strtolower($header[2][0]), 'moment' => $moment, ...$this->parse(substr($tail, $start, $end - $start))];
        }

        return $errors;
    }

    /**
     * The class, message and top frame of one entry. The message is the
     * entry's first line, cut before the context Laravel appends to it; the
     * stack trace below is never read beyond the frame it names.
     *
     * @return array{class: ?string, message: string, file: ?string, line: ?int}
     */
    private function parse(string $entry): array
    {
        $firstLine = (string) strtok($entry, "\n");
        $message = trim(Str::before($firstLine, ' {"'));

        /* An exception's message may run over several lines before its trace starts. */
        if (! preg_match(self::EXCEPTION, Str::before($entry, "\n[stacktrace]"), $exception)) {
            return ['class' => null, 'message' => $message, 'file' => null, 'line' => null];
        }

        return [
            'class' => $this->unescape($exception[1]),
            'message' => $message !== '' ? $message : $this->unescape($exception[2]),
            'file' => $this->relative($this->unescape($exception[3])),
            'line' => (int) $exception[4],
        ];
    }

    /**
     * @param  Collection<int, array{level: string, moment: Carbon, class: ?string, message: string, file: ?string, line: ?int}>  $errors
     * @return list<array{level: string, class: ?string, message: string, file: ?string, line: ?int, count: int, first_seen: string, last_seen: string}>
     */
    private function group(Collection $errors): array
    {
        return $errors
            ->map(fn (array $error): array => [...$error, 'message' => static::redact($error['message'])])
            ->groupBy(fn (array $error): string => implode('|', [$error['class'], static::normalise($error['message']), $error['file'], $error['line']]))
            ->map(function (Collection $occurrences): array {
                $sorted = $occurrences->sortBy(fn (array $error): int => $error['moment']->getTimestamp());
                $latest = $sorted->last();

                return [
                    'level' => $latest['level'],
                    'class' => $latest['class'],
                    'message' => Str::limit($latest['message'], self::MESSAGE_LENGTH - 1, '…'),
                    'file' => $latest['file'],
                    'line' => $latest['line'],
                    'count' => $occurrences->count(),
                    'first_seen' => $sorted->first()['moment']->toIso8601String(),
                    'last_seen' => $latest['moment']->toIso8601String(),
                    'last_seen_at' => $latest['moment']->getTimestamp(),
                ];
            })
            /* The loudest first; among equals, the latest. */
            ->sortBy([['count', 'desc'], ['last_seen_at', 'desc']])
            ->take(self::RECENT)
            ->map(fn (array $error): array => collect($error)->except('last_seen_at')->all())
            ->values()
            ->all();
    }

    /**
     * Blanks what must not leave the server: passwords in URLs, email
     * addresses, bearer tokens and JWTs, `key=value` pairs whose key names a
     * secret, and long hex or base64 strings that are probably keys.
     */
    public static function redact(string $message): string
    {
        $patterns = [
            /* user:password@ in any URL or DSN */
            '#(\b[a-z][a-z0-9+.-]*://[^\s:/@]*:)[^\s@/]+@#i' => '$1[redacted]@',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[email]',
            '/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=-]+/i' => '$1 [redacted]',
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/' => '[redacted]',
            /* password=…, "api_key":"…", secret: …, access_token=… */
            '/(["\']?\b[\w.-]*(?:key|secret|password|passwd|pwd|token)\b["\']?\s*(?:=>|[=:])\s*["\']?)(?!\[redacted\])[^\s"\'&,;)}\]]+/i' => '$1[redacted]',
            '/\bbase64:[A-Za-z0-9+\/]{16,}={0,2}/' => 'base64:[redacted]',
            '/\b[a-f0-9]{32,}\b/i' => '[redacted]',
            /* base64 or url-safe base64 runs of 32+ characters with letters and a digit or plus in them */
            '/(?<![\w\/.\\\\-])(?=[A-Za-z0-9+_-]*[\d+])(?=[A-Za-z0-9+_-]*[A-Za-z])[A-Za-z0-9+_-]{32,}={0,2}/' => '[redacted]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $message);
    }

    /**
     * The message with what changes between occurrences taken out, so the
     * same error about a different record groups as one.
     */
    public static function normalise(string $message): string
    {
        return (string) preg_replace(
            ['/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '/\b[0-9A-HJKMNP-TV-Z]{26}\b/', '/\b0x[0-9a-f]+\b/i', '/\d+(?:\.\d+)?/'],
            ['{uuid}', '{ulid}', '{hex}', '{n}'],
            $message,
        );
    }

    private function unescape(string $value): string
    {
        return str_replace(['\\\\', '\\/'], ['\\', '/'], $value);
    }

    /** Paths inside the project are reported from its root: the server's layout stays on the server. */
    private function relative(string $file): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
