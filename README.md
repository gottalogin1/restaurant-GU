# restaurant-GU

A WordPress-based Guam restaurant directory. Restaurants are tagged by village, malls, and
cuisine. The site shows awards history, third-party ratings, and first-party highlights, and
earns revenue from restaurant advertising.

Project rules are in [CLAUDE.md](CLAUDE.md). Research and decisions are in [docs/](docs/). What's left before going live is in [docs/launch-checklist.md](docs/launch-checklist.md).

## What's in the repo

| Path | What it is |
|---|---|
| `wp-content/plugins/guam-restaurants-core/` | All business logic: restaurant post type, village/mall/cuisine taxonomies, and later imports and ad serving. |
| `wp-content/themes/guam-restaurants-theme/` | Block theme. Presentation only. |
| `docker-compose.yml` | Local stack: WordPress 7.x on PHP 8.3 (Apache), MariaDB 11.4, WP-CLI, Composer. |
| `Makefile` | Short commands for everything below. Run `make help`. |
| `phpcs.xml.dist` | PHPCS with the WordPress Coding Standards and PHP 8.1+ compatibility checks. |
| `.githooks/pre-commit` | Runs PHPCS on staged PHP files before each commit. |
| `.github/workflows/ci.yml` | Runs PHPCS, starts the site, activates the plugin, and runs PHPUnit on every pull request. |

Only the plugin and theme are in git. WordPress core lives in a Docker volume.

## Local setup (about 10 minutes)

### 1. Install the prerequisites

- **Docker Desktop** (macOS or Windows) or **Docker Engine with the Compose plugin** (Linux).
  Check with `docker compose version`.
- **Git** and **make**. macOS: `xcode-select --install`. Windows: use WSL 2 (Ubuntu) and run
  everything inside it.
- PHP and Composer on your machine are optional. Without them, `make` runs them in Docker.

### 2. Clone and configure

```sh
git clone https://github.com/gottalogin1/restaurant-gu.git
cd restaurant-gu
cp .env.example .env    # optional: change the port, passwords or admin login
```

### 3. Start everything

```sh
make setup
```

This runs three steps you can also run on their own:

1. `make composer` installs PHPCS and PHPUnit into `vendor/` and turns on the pre-commit hook.
2. `make up` downloads the Docker images (first run only) and starts WordPress and MariaDB.
3. `make install` installs WordPress, sets pretty permalinks, and activates the
   `guam-restaurants-core` plugin and `guam-restaurants-theme` theme. Permalinks, theme and
   WooCommerce's "coming soon" are set only on that first install; running it again changes
   none of your settings.

### 4. Open the site

- Site: http://localhost:8080
- Admin: http://localhost:8080/wp-admin (user `admin`, password `password`, unless you changed
  them in `.env`)
- Restaurants: **Restaurants** in the admin menu, or http://localhost:8080/restaurants/

Use `http://` locally. `https://localhost:8080` gives Firefox's "Secure Connection Failed"
(`SSL_ERROR_RX_RECORD_TOO_LONG`) because that port only speaks plain HTTP. For HTTPS, see
[Local HTTPS](#local-https-optional).

### Local HTTPS (optional)

`make https` serves the site on https://localhost:8443 as well, with a certificate from an
authority created on your machine. It is limited to `localhost` and `127.0.0.1`, so it cannot
vouch for any real website. Firefox shows no warning once you trust it, which you do once:

1. Run `make https`. It writes `.cache/certs/local-ca.crt` (in a Windows checkout such as
   `/mnt/c/docker/restaurant-GU`, that is `C:\docker\restaurant-GU\.cache\certs\local-ca.crt`).
2. In Firefox: **Settings → Privacy & Security → Certificates → View Certificates →
   Authorities → Import**, pick `local-ca.crt`, tick **Trust this CA to identify websites**, OK.
3. Open https://localhost:8443.

Running `make https` again renews the server certificate and keeps the authority, so Firefox
keeps trusting it. Set `WP_HTTPS_PORT` in `.env` if 8443 is taken.

Links on every page follow the address you opened (http on 8080, https on 8443) whatever site
address the database holds; this applies to the local Docker stack only.

### 5. Check your setup

```sh
make lint    # PHPCS: should print no errors
make test    # PHPUnit: should end with "OK"
```

## Daily commands

| Command | What it does |
|---|---|
| `make update` | After `git pull`: starts the containers, turns the site's own plugin and WooCommerce back on if they are off, applies database and setting updates, then updates plugins (see below). Your settings and theme stay as they are. |
| `make plugins` | Lists each plugin's version and any available update, then installs the updates. `make update` runs it. |
| `make up` / `make down` | Start or stop the containers. Data is kept. |
| `make wp ARGS="plugin list"` | Run any WP-CLI command. |
| `make lint` / `make lint-fix` | Check or auto-fix coding standards. |
| `make test` | Run the plugin's PHPUnit tests (containers must be up). |
| `make logs` | Follow the WordPress container logs. PHP errors go to `wp-content/debug.log` inside the container. |
| `make reset` | Delete the database and WordPress volumes and start over (`make up install` afterwards). |

## Updating after `git pull`

Run `make update`. Settings you change in wp-admin, and plugins you install there, are kept:
they live in the `restaurant-gu_db` and `restaurant-gu_wordpress` Docker volumes, which only
`make reset` (or `docker compose down -v`) deletes. When an update has to fix a stored setting
on purpose, it ships as a one-time migration in
`wp-content/plugins/guam-restaurants-core/includes/database/class-migrations.php`; it runs once,
and if you change that setting again afterwards your value stays. `wp guam-restaurants db status`
lists any that have not run yet.

`make update` finishes by updating plugins (`make plugins` on its own). It prints every
installed plugin with its version and the version available, installs the updates, and shows
each plugin's old and new version. Two plugins are left alone because they change through
this repository: `guam-restaurants-core` (with `git pull`) and WooCommerce, whose version is
pinned by `WOOCOMMERCE_VERSION` in the Makefile; a newer WooCommerce is reported, not
installed. Pro plugins update only once their license key is entered. If wordpress.org cannot
be reached, the step says so and `make update` still succeeds.

## Working on the code

- Edit files under `wp-content/`. They are mounted into the container, so changes show up
  on refresh.
- New plugin classes go in `wp-content/plugins/guam-restaurants-core/includes/`. A class
  `GuamRestaurants\Core\Imports\Osm_Importer` lives in `includes/imports/class-osm-importer.php`
  and is loaded automatically.
- Tests go in `wp-content/plugins/guam-restaurants-core/tests/integration/` with names ending in
  `-test.php`. They run against a separate `wordpress_test` database that is wiped on every run.
- The pre-commit hook blocks commits that fail PHPCS. Run `make lint-fix`, then fix the rest by
  hand.
- Secrets go in `.env` locally (git-ignored) and in `wp-config.php` constants or host
  environment variables in production. Never commit them (CLAUDE.md rule 8).

## Troubleshooting

- **Port 8080 is in use:** set `WP_PORT=8081` in `.env`, then `make update`. Locally the site
  address follows the address you browse with, so nothing needs reinstalling.
- **wp-admin works but the public site does not (links, search, map or directory fail, or
  Firefox shows a secure-connection error):** the site address saved in **Settings → General**
  was `https://` while the local server speaks plain HTTP. `make update` fixes it: the local
  stack now ignores the saved address. For HTTPS, use [Local HTTPS](#local-https-optional).
- **`make test` says it cannot connect to the database:** run `make up` first.
- **The test database is missing** (for example after changing `DB_USER`): the test database is
  created only when the MariaDB volume is first created. Run `make reset setup`.
- **WooCommerce keeps switching itself off:** WordPress turns a plugin off when its files are
  missing. Run `make update` rather than `docker compose up`: it downloads WooCommerce into
  `.cache/plugins/woocommerce` first (it needs `unzip`; on Ubuntu/WSL: `sudo apt install unzip`).
  The first download unpacks about 5,500 files, which takes several minutes when the checkout is
  on a Windows drive (`/mnt/c`); it prints a line when it starts and when it is done.
- **Plugin updates from wordpress.org fail locally:** the site still works; your network may
  block wordpress.org.

## More docs

- [docs/launch-checklist.md](docs/launch-checklist.md): everything left before launch, as a checklist.
- [docs/progress.md](docs/progress.md): what's done and what's next.
- [docs/hosting.md](docs/hosting.md): production hosting recommendation and costs.
- [docs/plugins.md](docs/plugins.md): third-party plugins to install and why.
- [docs/data-sources.md](docs/data-sources.md): where restaurant data may come from.
- [docs/legal-and-policy.md](docs/legal-and-policy.md): disclosures, reviews and privacy.
- [docs/monetization.md](docs/monetization.md): ad products, launch rate card and launch order.
- [docs/market-rates.md](docs/market-rates.md): rate benchmarks, Guam quotes and the repricing rule.
- [docs/editorial-policy.md](docs/editorial-policy.md): editorial statement, moderation, corrections and sponsored features.
- [docs/media-kit-outline.md](docs/media-kit-outline.md): media kit outline for advertisers.
