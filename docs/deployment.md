# Deployment

How code gets from this repository to staging and production, 2026-10-10. Host:
Kinsta single-site, Tokyo, is the recommendation (docs/hosting.md); the steps below
work on any host with SSH, rsync and WP-CLI.

Only two folders are ours: `wp-content/plugins/guam-restaurants-core` and
`wp-content/themes/guam-restaurants-theme`. WordPress core, owned plugins and
uploads are managed on the server (wp-admin or WP-CLI), never from git.

## 1. First-time server setup (staging, then production)

1. Create the site on the host with PHP 8.3, an SSL certificate and a staging copy.
   Staging must not be indexed: Settings > Reading > "Discourage search engines" (the
   host usually does this for staging; preflight warns if it is wrong either way).
2. Fill in `wp-config.php` from §2. Use a long random database password and fresh salts
   (`wp config shuffle-salts`).
3. Install the owned plugins from docs/plugins.md plus Two Factor (docs/security.md §4).
4. Copy `deploy/example.env` to `deploy/staging.env` and `deploy/production.env` on your
   own machine (git-ignored) and fill them in. Test SSH with
   `ssh -p <port> <user@host> wp --info`.
5. First deploy: `bin/deploy.sh staging`. The script activates the plugin, which creates
   the custom tables and the default pages.
6. Set the site up once: `wp guam-restaurants site-pages` (default pages), then the data
   imports in docs/data-sources.md (OSM, awards) from the server shell.
7. Install the crontab (docs/scheduling.md) and backups (docs/backups.md).
8. Run `wp guam-restaurants preflight` and fix every `fail`.

## 2. `wp-config.php` constants

Add above `/* That's all, stop editing! */`. Values in angle brackets are per site;
never commit the filled-in file.

```php
// Environment. 'staging' or 'production'.
define( 'WP_ENVIRONMENT_TYPE', 'production' );

// Hardening.
define( 'DISALLOW_FILE_EDIT', true );        // no theme/plugin editor in wp-admin
define( 'FORCE_SSL_ADMIN', true );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );

// Errors: logged to a file outside the web root, never shown.
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', '/<home>/logs/wp-errors.log' );
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', '0' );

// Cron: run by the server crontab (docs/scheduling.md).
define( 'DISABLE_WP_CRON', true );
define( 'GUAM_RESTAURANTS_CRON_MODE', 'server' );

// Custom tables are upgraded by the deploy script, not by a page view (§5).
define( 'GUAM_RESTAURANTS_DB_UPGRADE', 'cli' );

// Alerts and the monthly spend report (docs/monitoring.md).
define( 'GUAM_RESTAURANTS_ALERT_EMAIL', '<you@example.com>' );

// Security (docs/security.md).
define( 'GUAM_RESTAURANTS_SECURITY_HEADERS', true );
define( 'GUAM_RESTAURANTS_HSTS', false );           // true once HTTPS works everywhere
define( 'GUAM_RESTAURANTS_CSP', 'report-only' );     // 'enforce' after a clean week
define( 'GUAM_RESTAURANTS_CLIENT_IP_HEADER', '' );   // 'HTTP_CF_CONNECTING_IP' behind Cloudflare
define( 'GUAM_RESTAURANTS_REQUIRE_ADMIN_2FA', false ); // true once admins enrolled
define( 'GUAM_RESTAURANTS_MAX_UPLOAD_MB', 20 );
define( 'GUAM_RESTAURANTS_ALLOW_APP_PASSWORDS', false ); // off: they skip two-factor
define( 'GUAM_RESTAURANTS_LEGAL_NAME', '' ); // TO DO: formal company name for the photo upload agreement.

// API keys and budgets (docs/data-sources.md, .env.example has the full list).
define( 'GUAM_RESTAURANTS_GOOGLE_PLACES_KEY', '<key>' );
define( 'GUAM_RESTAURANTS_RATINGS_MONTHLY_CAP_USD', 0 );
define( 'GUAM_RESTAURANTS_MAP_STYLE_URL', '<style URL>' );
// ... the rest from .env.example as needed.
```

Staging uses the same file with `WP_ENVIRONMENT_TYPE` set to `staging`, its own API
keys (or none), `GUAM_RESTAURANTS_ALERT_EMAIL` pointing at a test inbox, and newsletter
and SMTP sending off.

## 3. Releasing

```
git switch main && git pull
bin/deploy.sh staging --dry-run   # lists files that would change
bin/deploy.sh staging
# check staging: home, directory, a restaurant, map, claim form, checkout in sandbox
bin/deploy.sh production
```

`bin/deploy.sh`:

1. Refuses uncommitted changes in the plugin or theme. Production only deploys commits
   already on `origin/main` (merged PRs, CI green).
2. Builds the release from `git archive` (tests left out).
3. On the server: dumps the database (`wp db export`, one transaction) and copies the
   current plugin and theme to `DEPLOY_BACKUP_DIR/<release>/` (last 10 kept).
4. Copies the new code with `rsync --delete --delay-updates` (files swap in at the end).
5. Activates the plugin if needed and runs `wp guam-restaurants db upgrade`.
6. Flushes the object cache and rewrite rules, and runs `DEPLOY_PURGE_CMD`.
7. Runs `wp guam-restaurants preflight` (`--strict` on production: any `fail` stops it).
8. Smoke test: the health endpoint must answer `ok` or `degraded`, and the home page and
   `/restaurants/` must return 200.

If a step after the copy fails, it prints the rollback command (§6). Tested on
2026-10-10 against a local WordPress (`DEPLOY_SSH=local`): release, upgrade,
preflight, smoke test and rollback all worked.

**Owned plugin and core updates:** apply on staging first (wp-admin or
`wp plugin update <slug>`), click through the site, then repeat on production after the
nightly backup. Keep WooCommerce and PayPal Payments updates for a quiet day; test a
sandbox checkout after each.

## 4. Preflight

`wp guam-restaurants preflight` checks the live configuration and prints ok, warn or
fail per line. `--strict` exits non-zero on any fail; `--format=json` for scripts.

| Check | Fails when | Warns when |
|---|---|---|
| Environment | | `WP_ENVIRONMENT_TYPE` not set |
| HTTPS | production site URL is not `https://` | |
| Error display | | errors shown to visitors |
| File editor | | `DISALLOW_FILE_EDIT` not set |
| Custom tables | schema older than the code | |
| Default role | new users would get more than subscriber/customer | |
| User "admin" | | an account named `admin` exists |
| Two-factor | | plugin missing, an admin not enrolled, or enforcement off |
| Login limiter | | neither LoginPress Pro nor MalCare active |
| XML-RPC | | turned back on |
| Security headers | | turned off |
| Object cache | | no persistent cache (docs/performance.md §1) |
| Cron | | WP-Cron mode on production |
| Alert email | | not set |
| Search engines | | production hidden, or staging visible |
| Privacy policy / Terms | | page not published |

Warnings are allowed to launch with; each one is an open item in docs/progress.md.

## 5. Custom tables: migration and activation

The plugin keeps its own tables (`gr_change_log`, `gr_open_hours`, ads, leads,
billing and others). `Database\Schema::VERSION` (now `7`) is bumped whenever a table
changes, and `Schema::install()` runs `dbDelta`, which only adds tables, columns and
indexes. Rules for future changes:

- **Additive only** in a normal release: new tables, columns with defaults, indexes.
  Older code must keep working on the newer schema, so a code rollback never needs a
  database rollback.
- Renames or drops happen in two releases: first stop using the column, then drop it in
  a later release once the first one is settled.
- Data backfills go in a WP-CLI command that is idempotent (CLAUDE.md rule 9) and is run
  by hand after the deploy, not inside `install()`.

**How the upgrade runs.**

- On activation, `install()` creates everything.
- With `GUAM_RESTAURANTS_DB_UPGRADE=cli` (production), only `wp guam-restaurants db
  upgrade` upgrades, and the deploy script runs it. Until then wp-admin shows a notice
  and preflight fails.
- With the default `auto` (local, staging), the first request after a deploy upgrades.
- Either way, the upgrade takes a lock (an option, 10 minutes) so two requests or a
  request and the CLI never run `dbDelta` at the same time.
- `wp guam-restaurants db status` shows the installed and code versions.

## 6. Rollback

**Code only (normal case):**

```
bin/rollback.sh production                  # code from before the last release
bin/rollback.sh production <release>        # a specific saved release
```

It restores the saved plugin and theme, flushes caches, purges the page cache and prints
the health check. Because schema changes are additive (§5), old code runs on the new
schema.

**Code and database (only for a bad data change):**

```
bin/rollback.sh production <release> --database
```

This imports the dump taken just before that release; reviews, leads, claims, orders
and imports since then are lost. Before doing it, export what you can
(`wp db export` of the current state) so it can be merged by hand.

**Further back:** restore from the nightly off-site backup (docs/backups.md §3).

After any rollback, open a fix PR and deploy forward; do not leave production on a
rolled-back release for long.

## 7. Web server notes

- `.mjs` files (MapLibre, docs/security.md §6) must be served as `text/javascript`.
  Apache and nginx do this by default; check with
  `curl -I https://<site>/wp-content/plugins/guam-restaurants-core/assets/vendor/maplibre-gl/maplibre-gl.mjs`.
- Headers and the `xmlrpc.php` block: docs/security.md §3.
- PHP: `upload_max_filesize` at least 20M and `post_max_size` at least 24M (visitor photos); `memory_limit` 256M;
  `expose_php = Off`.
