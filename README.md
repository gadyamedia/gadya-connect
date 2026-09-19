# Gadya Connect

Connects a Laravel site to the [Gadya Media](https://gadya.media) portal at app.gadya.media, so the team looking after it knows the site is up, healthy and up to date.

- **Check-ins.** Every five minutes the site sends one signed report:
  - its PHP, Laravel, Filament and Gadya CMS versions
  - its health, from [spatie/laravel-health](https://github.com/spatie/laravel-health): your own checks, or a sensible set if you have none
  - the Gadya CMS audit
  - updates waiting on Packagist
  - maintenance mode
  - how many errors it logged in the last day

  If the check-ins stop, the portal notices: that usually means the scheduler has stopped.
- **Nothing to open up.** The site talks to the portal; the portal never reaches into the site. Every request is signed with a secret handed over once at pairing, stamped with the time, and never accepted twice.
- **Get help** in the Filament admin, for everyone who can sign in: ask the Gadya team for help with screenshots attached, see each request's status, and carry on the conversation. Replies also arrive by email, and answering the email works too.
- **One-click sign-in** from the portal as the site's own Gadya Support account: a signed pass, good once for a minute, that the client can switch off.
- **Gadya Support page** in the Filament admin. From there you connect the site with a pairing code, check in on demand, and decide whether the Gadya team may sign in to help.

Gadya CMS 0.5 and later include it; on any other Laravel 11–13 site:

```bash
composer require gadya/connect
php artisan migrate
php artisan gadya:connect GDY-XXXX-XXXX
```

The pairing code comes from the portal (**Sites → Connect a site**). It works once, for 48 hours.

## Requirements

- PHP 8.3+, Laravel 11, 12 or 13
- The scheduler running (`* * * * * php artisan schedule:run`), which sends the check-ins
- Filament 4 or 5 for the admin pages (optional: without Filament, Get help is a plain page at `/gadya-connect/help` for signed-in users)

## On a Filament site without Gadya CMS

```php
use Gadya\Connect\Filament\GadyaConnectPlugin;

$panel->plugins([GadyaConnectPlugin::make()]);
```

## Commands

| Command | Does |
| --- | --- |
| `gadya:connect {code} {--portal=}` | Pair with the portal |
| `gadya:report` | Send a check-in now (scheduled every 5 minutes) |
| `gadya:disconnect` | Forget the link |

## Configuration

```bash
php artisan vendor:publish --tag=gadya-connect-config
```

| Key | Default | |
| --- | --- | --- |
| `portal_url` | `https://app.gadya.media` | `GADYA_CONNECT_PORTAL` |
| `report_every_minutes` | `5` | |
| `schedule` | `true` | Set false to schedule `gadya:report` yourself |
| `gate` | `null` | Who may connect and switch sign-in; falls back to `gadya-cms.settings`, then `manage-users` |

## Licence

MIT
