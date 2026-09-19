<?php

namespace Gadya\Connect\Http\Controllers;

use Filament\Facades\Filament;
use Gadya\Cms\Activity\Activity;
use Gadya\Connect\Models\Connection;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The Gadya team's one-click way in. The portal hands over a pass signed
 * with this site's secret; it is good for one minute and once. The bearer
 * is signed in as the site's Gadya Support account, never as a client's
 * own user, and the site can switch the whole thing off.
 */
class SingleSignOnController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $connection = Connection::current();
        $pass = $this->verify($connection, (string) $request->query('token'));

        if ($connection === null || $pass === null) {
            abort(403, 'This sign-in link is not valid. Ask for a new one from the Gadya portal.');
        }

        if (! $connection->sso_enabled) {
            abort(403, 'Sign-in for the Gadya team is switched off on this site. It can be switched on under Gadya Support.');
        }

        $user = $this->supportUser();

        Auth::login($user);
        $request->session()->regenerate();

        Log::info('Gadya Support signed in from the portal.', ['staff' => $pass['staff'] ?? null, 'staff_email' => $pass['staff_email'] ?? null]);

        /* Gadya CMS keeps its own activity log; the sign-in belongs in it. */
        if (class_exists(Activity::class)) {
            rescue(fn () => app(Activity::class)->record('gadya-support.signed-in', 'Gadya Support, for '.($pass['staff'] ?? 'the Gadya team')), report: false);
        }

        return redirect()->to($this->adminUrl());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verify(?Connection $connection, string $token): ?array
    {
        if ($connection === null || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $connection->secret, true)), '+/', '-_'), '=');

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $pass = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        if (! is_array($pass) || (int) ($pass['site'] ?? 0) !== $connection->site_id || (int) ($pass['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        /* Once only: the nonce is remembered for longer than the pass lives. */
        if (! Cache::add('gadya-connect.sso.'.($pass['nonce'] ?? ''), true, 300)) {
            return null;
        }

        return $pass;
    }

    private function supportUser(): Authenticatable
    {
        /** @var class-string<Model&Authenticatable> $model */
        $model = config('auth.providers.users.model');
        $email = (string) config('gadya-connect.sso.email', 'support@gadya.media');

        $user = $model::query()->where('email', $email)->first() ?? new $model;

        $attributes = ['name' => (string) config('gadya-connect.sso.name', 'Gadya Support'), 'email' => $email];

        if (! $user->exists) {
            $attributes['password'] = bcrypt(Str::random(48));
        }

        $role = config('gadya-connect.sso.role') ?? config('gadya-cms.users.admin_role');

        if (is_string($role) && $role !== '' && Schema::hasColumn($user->getTable(), 'role')) {
            $attributes['role'] = $role;
        }

        $user->forceFill($attributes)->save();

        return $user;
    }

    private function adminUrl(): string
    {
        if (class_exists(Filament::class)) {
            return (string) rescue(fn () => Filament::getDefaultPanel()->getUrl(), url('/'), report: false);
        }

        return url('/');
    }
}
