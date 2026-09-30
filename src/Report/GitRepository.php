<?php

namespace Gadya\Connect\Report;

use Throwable;

/**
 * Which GitHub repository the site was deployed from, read from the
 * `origin` remote in `.git/config`, so the portal can find it without
 * anybody typing it in. Servers that deploy from git (Forge) have one;
 * a site built into an image or uploaded does not.
 */
class GitRepository
{
    /** "owner/name" of the origin remote when it is on github.com, otherwise null. Never throws. */
    public static function origin(?string $configPath = null): ?string
    {
        try {
            $path = $configPath ?? base_path('.git/config');

            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $inOrigin = false;

            foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
                $line = trim($line);

                if (str_starts_with($line, '[')) {
                    $inOrigin = preg_match('/^\[remote\s+"origin"\]$/', $line) === 1;

                    continue;
                }

                if ($inOrigin && preg_match('/^url\s*=\s*(.+)$/', $line, $matches) === 1) {
                    return self::normalise($matches[1]);
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * "owner/name" from a github.com remote in https, ssh or scp form, with
     * or without `.git`; null for any other host or a shape that is not a
     * repository. Credentials in an https url are dropped.
     */
    public static function normalise(string $url): ?string
    {
        $url = trim($url);

        $pattern = '~^(?:(?:https?|ssh|git)://(?:[^@/]+@)?|[^@/\s]+@)github\.com(?::\d+)?[:/]+([\w.-]+)/([\w.-]+?)(?:\.git)?/?$~i';

        if (preg_match($pattern, $url, $matches) !== 1 || $matches[2] === '') {
            return null;
        }

        return $matches[1].'/'.$matches[2];
    }
}
