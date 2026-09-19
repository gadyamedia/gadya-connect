# Changelog

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
