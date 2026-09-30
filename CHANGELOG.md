# Changelog

## 0.6.1

- The check-in's `app` section carries `repository`: the `owner/name` of the site's `origin` remote, read from `.git/config` (github.com only, https or ssh, with or without `.git`; null without a `.git` directory, an origin or a GitHub one). It never fails a check-in. The portal uses it to find the site's repository by itself.

## 0.6.0

- **Upgrades that finish themselves.** `php artisan gadya:upgrade` runs the installed packages' upgrade steps, each safe to run again: `--phase=code` changes files in the repository (Gadya's update workflow runs it in CI and commits the result, then `boost:update --discover` where Boost is set up); `--phase=server`, the default, runs on the live site - `migrate --force`, the server steps, `optimize:clear` and `filament:assets`. `--json` prints what ran, what had nothing to do and what failed, with the Gadya versions; `--dry-run` only lists. Other packages add steps by tagging a class with `key()`, `description()`, `phase()`, `shouldRun()` and `run()` as `gadya-connect.upgrade-steps`.
- **`upgrade.finish`**, an action the portal sends after a deploy: the server phase, with its outcome as the command's result. A handler that fails can now send a result too (`CommandFailed`).
- The check-in carries `upgrader`: this package's version and which update workflow template the repository holds (`workflow_template`, from the first line of `.github/workflows/gadya-update.yml`; null without one).
- **Error details.** The check-in's errors section keeps `last_day` and adds `recent`: the ten loudest errors of the last day, grouped by class, message (numbers and ids taken out), file and line, each with its count and when it was first and last seen. Messages are cut to 300 characters and redacted before anything is grouped or sent: email addresses, passwords in URLs, bearer tokens and JWTs, `key=`/`secret=`/`password=`/`token=` values and long hex or base64 strings. Only the top frame is reported; stack traces, context and request data stay on the server. Daily and single log files are read from the tail only, so a huge log costs no more than a small one.
- **Security section** in the check-in: failed sign-ins and lockouts (last hour, last day, the five busiest masked networks), admins and how many have two-factor sign-in (Filament app or email codes, Fortify; null when the users table cannot say), whether the site's `.env` can be downloaded (asked of itself at most every six hours, never from tests or localhost; `security.env_check`), debug mode in production, HTTPS and whether the application key is set.
- **Actions from the portal** (`gadya:commands`, every minute, even in maintenance mode): the site asks the portal for queued actions and runs only those signed with its secret and still in date, then reports how each went. Built in: `report.now`, `cache.clear`, `maintenance.down` (an optional bypass secret, never a view or a redirect), `maintenance.up` and `secret.rotate`. Other packages add actions by tagging a class with `type()` and `handle()` as `gadya-connect.remote-commands`. `remote_commands.enabled` (`GADYA_CONNECT_REMOTE_COMMANDS`) and `remote_commands.except` switch them off; the portal is told why nothing ran. A command the portal sends again is answered from the first run, not run twice.
- **Secret rotation**: `PortalClient::rotateSecret()` asks the portal for a new secret, signed with the current one, and stores it only once the portal has answered.
- The check-in now runs in maintenance mode too, so a site that is down for maintenance still checks in.

## 0.5.0

- The check-in carries everything Gadya CMS 0.9.0 knows about itself, from one `PortalSummary`: the last Lighthouse scores and what only code can fix, the accessibility record (score, pages checked, outstanding, remediated, the statement's address), what has drifted, unanswered enquiries with the oldest in hours, and the state of the site's backups. A site on an older CMS, or none, reports what it always did.

## 0.4.2

- The check-in carries a **quality** section on a site running Gadya CMS 0.8.1 or later: the last Lighthouse scores, how many things the CMS can put right by itself, and the failures that live in the templates - so the portal can see the whole fleet without opening each site.

## 0.4.1

- The **Get help** button links to its own panel's page and appears only on that panel. On a site with a second Filament panel without Get help (a client area, a second brand), it no longer points at a route that does not exist there.

## 0.4.0

- **Get help on sites without Filament**: plain pages at `/gadya-connect/help` for anyone signed in, the same requests, screenshots and conversation as the admin page. On by default only when Filament is not installed (`help_page`: `auto`, `true` or `false`).

## 0.3.0

- **One-click sign-in for the Gadya team.** The portal hands over a pass signed with the site's secret, good once, for one minute; `/gadya-connect/sso` signs the bearer in as the site's own **Gadya Support** account (created on first use, with Gadya CMS's administrator role where there is one). The client switches it off under Gadya Support; every sign-in is logged, and recorded in Gadya CMS's activity log.

## 0.2.0

- **Get help**, a page in the Filament admin for everyone who can sign in: ask the Gadya team for help with screenshots attached (the page you came from and your browser go along with it), see your requests and their status, and read and answer the conversation. A **Get help** button sits in the top bar.
- Requests signed as before; the query string is now part of the signature.

## 0.1.0

First release.

- Pairing with a one-time code (`gadya:connect`).
- Signed check-ins every five minutes (`gadya:report`): versions, health, the Gadya CMS audit, updates waiting, maintenance mode and the day's error count.
- A Gadya Support page for Filament: connect, check in now, and switch sign-in for the Gadya team.
