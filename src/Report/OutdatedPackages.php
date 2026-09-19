<?php

namespace Gadya\Connect\Report;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The site's own dependencies that have a newer stable release on
 * Packagist. Checked once a day at most: it is one request per package.
 */
class OutdatedPackages
{
    private const MAX_PACKAGES = 60;

    /**
     * @return list<array{name: string, current: string, latest: string}>
     */
    public function find(): array
    {
        return Cache::remember('gadya-connect.outdated', now()->addDay(), fn (): array => $this->look());
    }

    /**
     * @return list<array{name: string, current: string, latest: string}>
     */
    private function look(): array
    {
        $composer = json_decode((string) @file_get_contents(base_path('composer.json')), true);

        return collect(array_keys((array) ($composer['require'] ?? [])))
            ->filter(fn (string $name): bool => str_contains($name, '/') && InstalledVersions::isInstalled($name))
            ->take(self::MAX_PACKAGES)
            ->map(function (string $name): ?array {
                $current = ltrim((string) InstalledVersions::getPrettyVersion($name), 'v');
                $latest = $this->latestStable($name);

                if ($latest === null || str_starts_with($current, 'dev-') || version_compare($latest, $current, '<=')) {
                    return null;
                }

                return ['name' => $name, 'current' => $current, 'latest' => $latest];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function latestStable(string $name): ?string
    {
        try {
            $versions = (array) Http::timeout(5)->get("https://repo.packagist.org/p2/{$name}.json")->json("packages.{$name}", []);
        } catch (Throwable) {
            return null;
        }

        foreach ($versions as $version) {
            $number = ltrim((string) ($version['version'] ?? ''), 'v');

            if ($number !== '' && preg_match('/^\d+(\.\d+)*$/', $number) === 1) {
                return $number;
            }
        }

        return null;
    }
}
