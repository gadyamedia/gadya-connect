# Gadya Connect

Connects a Laravel site to the [Gadya Media](https://gadya.media) portal at app.gadya.media, so the team looking after it knows the site is up, healthy and up to date.

- **Check-ins.** Every five minutes the site sends one signed report:
  - its PHP, Laravel, Filament and Gadya CMS versions
  - its health, from [spatie/laravel-health](https://github.com/spatie/laravel-health): your own checks, or a sensible set if you have none
  - the Gadya CMS audit
  - updates waiting on Packagist
  - maintenance mode
  - how many errors it logged in the last day, and the ten loudest of them: class, message, file and line, how often and when. Messages are redacted first (email addresses, tokens, passwords, keys and anything that looks like a secret are blanked); no stack traces, context or request data leave the server
  - security: failed sign-ins and lockouts in the last hour and day with the busiest networks (addresses masked to `203.0.113.x`), how many admins have two-factor sign-in (from Filament's or Fortify's columns, when the users table has them), whether the site's own `.env` can be downloaded (checked at most every six hours), debug mode in production, HTTPS and the application key

  - the GitHub repository the site was deployed from (`app.repository`, `owner/name`, read from the `origin` remote in `.git/config`; nothing is sent when there is none)
  - which update workflow the repository holds (`upgrader.workflow_template`), so the portal can offer a newer one

  If the check-ins stop, the portal notices: that usually means the scheduler has stopped. Check-ins carry on in maintenance mode.
- **Nothing to open up.** The site talks to the portal; the portal never reaches into the site. Every request is signed with a secret handed over once at pairing, stamped with the time, and never accepted twice.
- **Actions from the portal.** Every minute the site asks the portal whether the Gadya team has queued anything: check in now, clear the caches, maintenance mode on (with an optional bypass secret, nothing else) or off, a new secret, or finishing an upgrade after a deploy (`upgrade.finish`). A command runs only if it is signed with the site's secret and still in date; the outcome goes back to the portal. Switch them off, or refuse single types, in the config.
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
| `gadya:commands` | Run the actions the portal has queued (scheduled every minute, also in maintenance mode) |
| `gadya:upgrade {--phase=server} {--json} {--dry-run}` | Run the installed packages' upgrade steps (see below) |
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
| `security.env_check` | `true` | `GADYA_CONNECT_ENV_CHECK`. Ask for `{app.url}/.env` every six hours to be sure nobody else can |
| `remote_commands.enabled` | `true` | `GADYA_CONNECT_REMOTE_COMMANDS`. Switched off, the site still asks and tells the portal each action was refused |
| `remote_commands.except` | `[]` | Types to refuse, e.g. `['maintenance.down']` |

## Adding actions

Another package can add an action without depending on this one: any class with these two methods, tagged in the container.

```php
class RunBackup
{
    public function type(): string
    {
        return 'backup.run';
    }

    /** @return array{output: string, result: array|null} Throw to report the action failed. */
    public function handle(array $payload): array
    {
        // ...

        return ['output' => 'Backup finished.', 'result' => null];
    }
}

$this->app->tag([RunBackup::class], 'gadya-connect.remote-commands');
```

The portal only queues types it knows about; the site runs only types it has a handler for.

## Upgrade steps

`php artisan gadya:upgrade` does the mechanical part of an upgrade, after `composer update`, in two phases:

- `--phase=code` changes files in the repository. Gadya's update workflow runs it in CI before the tests and commits what it changed. Last, it runs `boost:update --discover` where Laravel Boost is set up.
- `--phase=server` (the default) runs on the live site after the deploy: `migrate --force`, the packages' server steps, `optimize:clear`, and `filament:assets` on a Filament site. If the migrations fail, nothing else runs. The portal asks for it with the `upgrade.finish` action.

Every step is safe to run again: it checks whether it still has anything to do, and is skipped if not. A failed step fails the command (exit code 1) but not the steps after it. `--dry-run` lists what would run. `--json` prints:

```json
{
    "phase": "server",
    "dry_run": false,
    "ran": [{"key": "connect.migrate", "description": "Run the database migrations", "output": "..."}],
    "skipped": ["cms.storage-link"],
    "failed": [{"key": "cms.caches", "error": "..."}],
    "versions": {"gadya_cms": "0.15.0", "gadya_connect": "0.6.0"}
}
```

(and `would_run`, `[{key, description}]`, on a dry run). `upgrade.finish` sends the same object back to the portal as its result, with the command failed when any step failed.

Another package adds steps the same way it adds actions: any class with these methods, tagged in the container.

```php
class CreateNotificationsTable
{
    public function key(): string { return 'cms.0.15.0.notifications-table'; } // stable forever
    public function description(): string { return 'Add the notifications table migration'; }
    public function phase(): string { return 'code'; }                          // or 'server'
    public function shouldRun(): bool { /* false once done, or where it does not apply */ }
    public function run(): string { /* what it did, for a person; throw to fail */ }
}

$this->app->tag([CreateNotificationsTable::class], 'gadya-connect.upgrade-steps');
```

## Licence

MIT
