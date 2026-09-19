<?php

namespace Gadya\Connect\Tests\Feature;

use Gadya\Connect\Filament\Pages\GadyaSupport;
use Gadya\Connect\Models\Connection;
use Gadya\Connect\Portal\RequestSignature;
use Gadya\Connect\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class ConnectTest extends TestCase
{
    private function fakePortal(): void
    {
        Http::fake([
            'portal.test/api/connect/v1/pair' => Http::response([
                'site_id' => 17,
                'secret' => str_repeat('s', 64),
                'name' => 'Fun On Us website',
                'client' => 'Fun On Us',
                'portal_url' => 'https://portal.test',
            ], 201),
            'portal.test/api/connect/v1/report' => Http::response(['received_at' => now()->toIso8601String()]),
            'repo.packagist.org/*' => Http::response(['packages' => []]),
        ]);
    }

    public function test_a_pairing_code_is_swapped_for_a_secret_that_is_stored_encrypted(): void
    {
        $this->fakePortal();

        $this->artisan('gadya:connect', ['code' => 'gdy-abcd-efgh'])
            ->expectsOutputToContain('Connected: Fun On Us website for Fun On Us')
            ->assertSuccessful();

        $connection = Connection::current();

        $this->assertSame(17, $connection->site_id);
        $this->assertSame(str_repeat('s', 64), $connection->secret);
        $this->assertNotSame(str_repeat('s', 64), DB::table('gadya_connect_connections')->value('secret'), 'The secret is encrypted at rest.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://portal.test/api/connect/v1/pair'
            && $request['code'] === 'GDY-ABCD-EFGH'
            && $request['report']['versions']['php'] === PHP_VERSION
            && $request['report']['app']['url'] === 'https://client-site.test');
    }

    public function test_a_refused_code_says_why(): void
    {
        Http::fake(['portal.test/*' => Http::response(['message' => 'That code is not valid or has expired.'], 422)]);

        $this->artisan('gadya:connect', ['code' => 'GDY-NOPE-NOPE'])
            ->expectsOutputToContain('That code is not valid or has expired.')
            ->assertFailed();

        $this->assertNull(Connection::current());
    }

    public function test_the_check_in_is_signed_the_way_the_portal_checks_it(): void
    {
        $this->fakePortal();
        $this->artisan('gadya:connect', ['code' => 'GDY-ABCD-EFGH']);

        $this->artisan('gadya:report')->expectsOutputToContain('Checked in')->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://portal.test/api/connect/v1/report') {
                return false;
            }

            $expected = RequestSignature::sign(
                str_repeat('s', 64),
                (int) $request->header(RequestSignature::TIMESTAMP_HEADER)[0],
                $request->header(RequestSignature::NONCE_HEADER)[0],
                'POST',
                '/api/connect/v1/report',
                $request->body(),
            );

            return $request->header(RequestSignature::SITE_HEADER)[0] === '17'
                && hash_equals($expected, $request->header(RequestSignature::SIGNATURE_HEADER)[0])
                && collect($request['health']['checks'])->pluck('name')->contains('Database');
        });

        $this->assertNotNull(Connection::current()->last_report_at);
    }

    public function test_an_unconnected_site_sends_nothing(): void
    {
        Http::fake();

        $this->artisan('gadya:report')->expectsOutputToContain('Not connected')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_refused_check_in_is_remembered_for_the_admin_page(): void
    {
        Connection::query()->create(['site_id' => 17, 'portal_url' => 'https://portal.test', 'secret' => 'wrong-secret']);
        Http::fake([
            'portal.test/*' => Http::response(['message' => 'This request is not signed by a connected site.'], 401),
            'repo.packagist.org/*' => Http::response(['packages' => []]),
        ]);

        $this->artisan('gadya:report')->assertFailed();

        $this->assertStringContainsString('HTTP 401', (string) Connection::current()->last_error);
    }

    public function test_the_check_in_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

        $this->assertTrue($events->contains(fn (string $command): bool => str_contains($command, 'gadya:report')));
    }

    public function test_the_admin_page_connects_the_site_and_switches_team_sign_in(): void
    {
        $this->fakePortal();
        $this->actingAs($this->admin());

        $this->get('/admin/gadya-support')->assertOk()->assertSee('Connect to Gadya Media');

        Livewire::test(GadyaSupport::class)
            ->set('code', 'GDY-ABCD-EFGH')
            ->call('connect')
            ->assertHasNoErrors()
            ->assertSee('Connected to Gadya Media')
            ->call('toggleSignIn');

        $this->assertFalse(Connection::current()->sso_enabled);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/report') && $request['sso']['enabled'] === false);
    }
}
