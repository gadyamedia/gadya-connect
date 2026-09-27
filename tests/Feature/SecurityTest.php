<?php

namespace Gadya\Connect\Tests\Feature;

use Gadya\Connect\Report\ReportBuilder;
use Gadya\Connect\Report\SecurityReport;
use Gadya\Connect\Security\FailedLogins;
use Gadya\Connect\Tests\Fixtures\User;
use Gadya\Connect\Tests\TestCase;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class SecurityTest extends TestCase
{
    private function failFrom(string $ip): void
    {
        $this->app->instance('request', Request::create('/admin/login', 'POST', server: ['REMOTE_ADDR' => $ip]));

        event(new Failed('web', null, ['email' => 'ada@example.com', 'password' => 'wrong']));
    }

    private function security(): array
    {
        return app(SecurityReport::class)->build();
    }

    public function test_failed_sign_ins_are_counted_by_hour_and_day_per_masked_address(): void
    {
        $this->travelTo('2026-09-27 12:00:00');

        $this->travelTo('2026-09-26 11:00:00');
        $this->failFrom('192.0.2.1');

        $this->travelTo('2026-09-27 09:00:00');
        $this->failFrom('198.51.100.7');
        $this->failFrom('198.51.100.8');

        $this->travelTo('2026-09-27 11:40:00');
        $this->failFrom('203.0.113.42');
        $this->failFrom('203.0.113.43');
        $this->failFrom('203.0.113.44');
        event(new Lockout(Request::create('/admin/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.42'])));

        $this->travelTo('2026-09-27 12:00:00');

        $this->assertSame([
            'last_hour' => 4,
            'last_day' => 6,
            'top_ips' => [
                ['ip_masked' => '203.0.113.x', 'count' => 4],
                ['ip_masked' => '198.51.100.x', 'count' => 2],
            ],
        ], $this->security()['failed_logins']);
    }

    public function test_only_the_five_busiest_networks_are_named(): void
    {
        foreach (range(1, 7) as $network) {
            foreach (range(1, $network) as $attempt) {
                $this->failFrom("10.0.{$network}.{$attempt}");
            }
        }

        $failedLogins = $this->security()['failed_logins'];

        $this->assertSame(28, $failedLogins['last_day']);
        $this->assertSame(['10.0.7.x', '10.0.6.x', '10.0.5.x', '10.0.4.x', '10.0.3.x'], array_column($failedLogins['top_ips'], 'ip_masked'));
    }

    public function test_addresses_are_masked(): void
    {
        $this->assertSame('203.0.113.x', FailedLogins::mask('203.0.113.42'));
        $this->assertSame('2001:db8:85a3::x', FailedLogins::mask('2001:0db8:85a3:0000:0000:8a2e:0370:7334'));
        $this->assertSame('unknown', FailedLogins::mask('not-an-address'));
    }

    public function test_admins_are_counted_and_two_factor_is_null_when_the_users_table_cannot_say(): void
    {
        $this->admin();
        User::query()->create(['name' => 'Grace', 'email' => 'grace@example.com', 'password' => 'secret', 'role' => 'admin']);
        User::query()->create(['name' => 'Shopper', 'email' => 'shopper@example.com', 'password' => 'secret', 'role' => 'customer']);
        User::query()->create(['name' => 'Gadya Support', 'email' => 'support@gadya.media', 'password' => 'secret', 'role' => 'admin']);

        $this->assertSame(['total' => 2, 'with_2fa' => null], $this->security()['admins']);
    }

    public function test_two_factor_is_read_from_filament_and_fortify_columns(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('app_authentication_secret')->nullable();
            $table->boolean('has_email_authentication')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        $admin = fn (string $name, array $columns = []): int => User::query()->insertGetId([
            'name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'secret', 'role' => 'admin', ...$columns,
        ]);

        $admin('App', ['app_authentication_secret' => 'encrypted-totp-secret']);
        $admin('Email', ['has_email_authentication' => true]);
        $admin('Fortify', ['two_factor_secret' => 'encrypted', 'two_factor_confirmed_at' => now()]);
        $admin('Unconfirmed', ['two_factor_secret' => 'encrypted']);
        $admin('Nothing');

        $this->assertSame(['total' => 5, 'with_2fa' => 3], $this->security()['admins']);
    }

    public function test_the_env_file_is_never_fetched_from_a_test_run(): void
    {
        Http::fake();

        $this->assertNull($this->security()['env_exposed']);

        Http::assertNothingSent();
    }

    public function test_an_env_file_anyone_can_download_is_reported_and_the_answer_kept_for_six_hours(): void
    {
        $this->app['env'] = 'production';
        Http::fake(['client-site.test/.env' => Http::response("APP_NAME=Site\nAPP_KEY=base64:abc\n")]);

        $this->assertTrue($this->security()['env_exposed']);
        $this->assertTrue($this->security()['env_exposed']);

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://client-site.test/.env');

        $this->travel(6)->hours();
        $this->travel(1)->minute();
        $this->security();

        Http::assertSentCount(2);
    }

    public function test_a_protected_env_file_is_not_exposed(): void
    {
        $this->app['env'] = 'production';
        Http::fake([
            'client-site.test/.env' => Http::sequence()
                ->push('Not Found', 404)
                ->push('<html>Welcome</html>'),
        ]);

        $this->assertFalse($this->security()['env_exposed']);

        cache()->flush();

        $this->assertFalse($this->security()['env_exposed'], 'A page that is not the .env is not exposure.');
    }

    public function test_the_env_check_is_skipped_for_localhost_and_when_switched_off(): void
    {
        $this->app['env'] = 'production';
        Http::fake();

        config(['app.url' => 'http://localhost']);
        $this->assertNull($this->security()['env_exposed']);

        config(['app.url' => 'https://client-site.test', 'gadya-connect.security.env_check' => false]);
        $this->assertNull($this->security()['env_exposed']);

        Http::assertNothingSent();
    }

    public function test_debug_https_and_the_app_key_are_reported(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'http://client-site.test', 'app.key' => '', 'gadya-connect.security.env_check' => false]);

        $security = $this->security();

        $this->assertTrue($security['debug_in_production']);
        $this->assertFalse($security['https']);
        $this->assertFalse($security['app_key_set']);

        config(['app.debug' => false, 'app.url' => 'https://client-site.test', 'app.key' => 'base64:key']);

        $security = $this->security();

        $this->assertFalse($security['debug_in_production']);
        $this->assertTrue($security['https']);
        $this->assertTrue($security['app_key_set']);
    }

    public function test_debug_outside_production_is_not_flagged(): void
    {
        config(['app.debug' => true]);

        $this->assertFalse($this->security()['debug_in_production']);
    }

    public function test_the_check_in_carries_the_security_section(): void
    {
        $this->travelTo('2026-09-27 12:00:00');
        Http::fake(['repo.packagist.org/*' => Http::response(['packages' => []])]);

        $security = app(ReportBuilder::class)->build()['security'];

        $this->assertSame(['failed_logins', 'admins', 'env_exposed', 'debug_in_production', 'https', 'app_key_set', 'checked_at'], array_keys($security));
        $this->assertSame('2026-09-27T12:00:00+00:00', $security['checked_at']);
    }
}
