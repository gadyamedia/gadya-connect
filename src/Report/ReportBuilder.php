<?php

namespace Gadya\Connect\Report;

use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Gadya\Cms\Support\InstallAudit;
use Gadya\Cms\Support\Maintenance;
use Gadya\Cms\Support\PortalSummary;
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
            ...$this->cms(),
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
     * What Gadya CMS knows about itself - the last Lighthouse scores, the
     * accessibility record, what has drifted, unanswered enquiries and the
     * state of the backups - so the portal can show every site at a glance
     * without opening any of them.
     *
     * @return array<string, mixed>
     */
    private function cms(): array
    {
        if (! class_exists(PortalSummary::class)) {
            return [];
        }

        return rescue(fn (): array => app(PortalSummary::class)->build(), [], report: false);
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
