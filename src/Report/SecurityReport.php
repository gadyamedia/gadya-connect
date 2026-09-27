<?php

namespace Gadya\Connect\Report;

use Gadya\Connect\Security\FailedLogins;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The check-in's security section: someone trying passwords, admins without
 * a second factor, a .env anyone can download, debug mode left on in
 * production, no HTTPS, no application key. Each is something a client
 * would never spot and the portal can raise before it matters.
 */
class SecurityReport
{
    public function __construct(
        private readonly FailedLogins $failedLogins,
        private readonly EnvExposure $envExposure,
    ) {}

    /**
     * @return array{failed_logins: array{last_hour: int, last_day: int, top_ips: list<array{ip_masked: string, count: int}>}, admins: array{total: int, with_2fa: ?int}, env_exposed: ?bool, debug_in_production: bool, https: bool, app_key_set: bool, checked_at: string}
     */
    public function build(): array
    {
        return [
            'failed_logins' => rescue(fn (): array => $this->failedLogins->summary(), ['last_hour' => 0, 'last_day' => 0, 'top_ips' => []], report: false),
            'admins' => rescue(fn (): array => $this->admins(), ['total' => 0, 'with_2fa' => null], report: false),
            'env_exposed' => rescue(fn (): ?bool => $this->envExposure->check(), null, report: false),
            'debug_in_production' => (bool) config('app.debug') && app()->isProduction(),
            'https' => strtolower((string) parse_url((string) config('app.url'), PHP_URL_SCHEME)) === 'https',
            'app_key_set' => filled(config('app.key')),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Who can administer the site, and how many of them have a second factor.
     *
     * With a role column, admins are the users with the administrator role
     * (Gadya CMS's, or `sso.role`); without one, every user is counted,
     * since on these sites signing in means the admin. The Gadya Support
     * account is left out: it only ever signs in with the portal's pass.
     *
     * Two-factor is read from whichever columns the users table has:
     * Filament's app and email authentication, then Fortify's. When it has
     * none of them the site cannot say, so with_2fa is null.
     *
     * @return array{total: int, with_2fa: ?int}
     */
    private function admins(): array
    {
        [$connection, $table] = $this->usersTable();
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table)) {
            return ['total' => 0, 'with_2fa' => null];
        }

        $columns = array_map('strtolower', $schema->getColumnListing($table));
        $admins = DB::connection($connection)->table($table);

        if (in_array('role', $columns, true)) {
            $admins->where('role', (string) (config('gadya-connect.sso.role') ?? config('gadya-cms.users.admin_role') ?? 'admin'));
        }

        if (in_array('email', $columns, true) && filled(config('gadya-connect.sso.email'))) {
            $admins->where('email', '!=', (string) config('gadya-connect.sso.email'));
        }

        $secondFactors = $this->secondFactors($columns);

        return [
            'total' => (clone $admins)->count(),
            'with_2fa' => $secondFactors === [] ? null : (clone $admins)->where(function (Builder $query) use ($secondFactors): void {
                foreach ($secondFactors as $secondFactor) {
                    $query->orWhere($secondFactor);
                }
            })->count(),
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return list<callable(Builder): void>
     */
    private function secondFactors(array $columns): array
    {
        $has = fn (string $column): bool => in_array($column, $columns, true);
        $set = fn (string $column): callable => fn (Builder $query) => $query->whereNotNull($column)->where($column, '!=', '');

        return array_values(array_filter([
            /* Filament 4+: app (TOTP) and email codes. */
            $has('app_authentication_secret') ? $set('app_authentication_secret') : null,
            $has('has_email_authentication') ? fn (Builder $query) => $query->where('has_email_authentication', true) : null,
            /* Fortify: a secret alone is set up but not confirmed, when the confirmation column exists. */
            match (true) {
                $has('two_factor_confirmed_at') => fn (Builder $query) => $query->whereNotNull('two_factor_confirmed_at'),
                $has('two_factor_secret') => $set('two_factor_secret'),
                default => null,
            },
        ]));
    }

    /**
     * @return array{?string, string}
     */
    private function usersTable(): array
    {
        $model = config('auth.providers.users.model');

        if (is_string($model) && is_subclass_of($model, Model::class)) {
            $user = new $model;

            return [$user->getConnectionName(), $user->getTable()];
        }

        return [null, 'users'];
    }
}
