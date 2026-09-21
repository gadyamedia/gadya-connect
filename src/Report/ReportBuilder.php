<?php

namespace Gadya\Connect\Report;

use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Gadya\Cms\Models\PageScore;
use Gadya\Cms\Quality\Failures;
use Gadya\Cms\Support\InstallAudit;
use Gadya\Cms\Support\Maintenance;
use Gadya\Connect\Models\Connection;

/**
 * The check-in: what this site is, what it runs, and how it is doing.
 * Version 1 of the shape the portal's SiteReportRecorder reads.
 */
class ReportBuilder
{
    /** @var array<string, string> */
    private const PACKAGES = [
        'laravel' => 'laravel/framework',
        'filament' => 'filament/filament',
        'livewire' => 'livewire/livewire',
        'gadya_cms' => 'gadya/cms',
        'gadya_connect' => 'gadya/connect',
    ];

    public function __construct(
        private readonly HealthResults $health,
        private readonly OutdatedPackages $outdated,
        private readonly ErrorCount $errors,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?Connection $connection = null): array
    {
        return array_filter([
            'app' => [
                'name' => (string) config('app.name'),
                'url' => (string) config('app.url'),
                'admin_url' => $this->adminUrl(),
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'timezone' => (string) config('app.timezone'),
            ],
            'versions' => $this->versions(),
            'health' => ['checks' => $this->health->collect()],
            'audit' => $this->audit(),
            'outdated' => rescue(fn (): array => $this->outdated->find(), [], report: false),
            'maintenance' => [
                'down' => app()->isDownForMaintenance(),
                'coming_soon' => $this->comingSoon(),
            ],
            'sso' => ['enabled' => $connection?->sso_enabled ?? true],
            'errors' => ['last_day' => rescue(fn (): int => $this->errors->lastDay(), 0, report: false)],
            'quality' => $this->quality(),
        ], fn ($section): bool => $section !== null);
    }

    /**
     * @return array<string, string>
     */
    private function versions(): array
    {
        $versions = ['php' => PHP_VERSION];

        foreach (self::PACKAGES as $key => $package) {
            if (InstalledVersions::isInstalled($package)) {
                $versions[$key] = ltrim((string) InstalledVersions::getPrettyVersion($package), 'v');
            }
        }

        return $versions;
    }

    /**
     * What gadya-cms:audit would list, when Gadya CMS is installed.
     *
     * @return array{todo: int, checks: list<array<string, string>>}|null
     */
    private function audit(): ?array
    {
        if (! class_exists(InstallAudit::class)) {
            return null;
        }

        return rescue(function (): array {
            $checks = collect(app(InstallAudit::class)->checks());

            return [
                'todo' => $checks->where('status', 'todo')->count(),
                'checks' => $checks->where('status', '!=', 'ok')->values()->all(),
            ];
        }, null, report: false);
    }

    /**
     * How Google's last check went, and what it found that only a
     * developer can put right - so the portal can see the whole fleet
     * without opening each site.
     *
     * @return array{checked_at: string|null, scores: array<string, int|null>, to_fix: int, for_developers: list<array<string, mixed>>}|null
     */
    private function quality(): ?array
    {
        if (! class_exists(Failures::class)) {
            return null;
        }

        return rescue(function (): ?array {
            $latest = PageScore::query()->latest('checked_at')->first();

            if ($latest === null) {
                return null;
            }

            $failures = app(Failures::class);

            return [
                'checked_at' => $latest->checked_at?->toIso8601String(),
                'scores' => [
                    'performance' => $latest->performance,
                    'accessibility' => $latest->accessibility,
                    'best_practices' => $latest->best_practices,
                    'seo' => $latest->seo,
                ],
                'to_fix' => $failures->fixable()->count(),
                'for_developers' => $failures->forDevelopers()
                    ->map(fn (array $failure): array => [
                        'id' => $failure['id'],
                        'title' => $failure['title'],
                        'path' => $failure['path'],
                    ])
                    ->take(20)
                    ->values()
                    ->all(),
            ];
        }, null, report: false);
    }

    private function comingSoon(): bool
    {
        return class_exists(Maintenance::class)
            && (bool) rescue(fn (): bool => app(Maintenance::class)->isOn(), false, report: false);
    }

    private function adminUrl(): ?string
    {
        if (! class_exists(Filament::class)) {
            return null;
        }

        return rescue(fn (): ?string => Filament::getDefaultPanel()->getUrl(), null, report: false);
    }
}
