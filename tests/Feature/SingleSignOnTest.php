<?php

namespace Gadya\Connect\Tests\Feature;

use Gadya\Connect\Models\Connection;
use Gadya\Connect\Tests\Fixtures\User;
use Gadya\Connect\Tests\TestCase;
use Illuminate\Support\Str;

class SingleSignOnTest extends TestCase
{
    private const SECRET = 'site-secret-that-the-portal-also-holds-000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        Connection::query()->create(['site_id' => 17, 'portal_url' => 'https://portal.test', 'secret' => self::SECRET]);
    }

    /** The same pass App\Support\Connect\SignInToken mints in the portal. */
    private function pass(array $overrides = [], string $secret = self::SECRET): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'site' => 17,
            'staff' => 'Antony',
            'staff_email' => 'antony@gadyamedia.com',
            'exp' => now()->addMinute()->getTimestamp(),
            'nonce' => Str::random(32),
            ...$overrides,
        ])), '+/', '-_'), '=');

        return $payload.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
    }

    public function test_a_valid_pass_signs_in_as_the_gadya_support_account_once(): void
    {
        config(['gadya-cms.users.admin_role' => 'admin']);
        $token = $this->pass();

        $this->get('/gadya-connect/sso?token='.$token)->assertRedirect('http://localhost/admin');

        $this->assertAuthenticated();
        $user = User::query()->where('email', 'support@gadya.media')->sole();
        $this->assertSame('Gadya Support', $user->name);
        $this->assertSame('admin', $user->role, 'Gadya CMS\'s administrator role, because the users table has a role column.');

        auth()->logout();

        $this->get('/gadya-connect/sso?token='.$token)->assertForbidden();
        $this->assertGuest();
    }

    public function test_forged_expired_and_foreign_passes_are_refused(): void
    {
        config(['gadya-connect.sso.role' => 'admin']);

        $this->get('/gadya-connect/sso?token='.$this->pass(secret: 'not-the-secret'))->assertForbidden();
        $this->get('/gadya-connect/sso?token='.$this->pass(['exp' => now()->subSecond()->getTimestamp()]))->assertForbidden();
        $this->get('/gadya-connect/sso?token='.$this->pass(['site' => 99]))->assertForbidden();
        $this->get('/gadya-connect/sso?token=garbage')->assertForbidden();

        $this->assertGuest();
    }

    public function test_the_client_can_switch_it_off(): void
    {
        Connection::current()->forceFill(['sso_enabled' => false])->save();

        $this->get('/gadya-connect/sso?token='.$this->pass())->assertForbidden()->assertSee('switched off');
        $this->assertGuest();
    }
}
