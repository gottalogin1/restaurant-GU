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
   `guam-restaurants-core` plugin and `guam-restaurants-theme` theme.

### 4. Open the site

- Site: http://localhost:8080
- Admin: http://localhost:8080/wp-admin (user `admin`, password `password`, unless you changed
  them in `.env`)
- Restaurants: **Restaurants** in the admin menu, or http://localhost:8080/restaurants/

### 5. Check your setup

```sh
make lint    # PHPCS: should print no errors
make test    # PHPUnit: should end with "OK"
```

## Daily commands

| Command | What it does |
|---|---|
| `make up` / `make down` | Start or stop the containers. Data is kept. |
| `make wp ARGS="plugin list"` | Run any WP-CLI command. |
| `make lint` / `make lint-fix` | Check or auto-fix coding standards. |
| `make test` | Run the plugin's PHPUnit tests (containers must be up). |
| `make logs` | Follow the WordPress container logs. PHP errors go to `wp-content/debug.log` inside the container. |
| `make reset` | Delete the database and WordPress volumes and start over (`make up install` afterwards). |

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

- **Port 8080 is in use:** set `WP_PORT=8081` in `.env`, then `make reset setup` (the site URL
  is stored in the database at install time).
- **`make test` says it cannot connect to the database:** run `make up` first.
- **The test database is missing** (for example after changing `DB_USER`): the test database is
  created only when the MariaDB volume is first created. Run `make reset setup`.
- **Plugin updates from wordpress.org fail locally:** the site still works; your network may
  block wordpress.org.

## More docs

- [docs/launch-checklist.md](docs/launch-checklist.md): everything left before launch, as a checklist.
- [docs/progress.md](docs/progress.md): what's done and what's next.
- [docs/hosting.md](docs/hosting.md): production hosting recommendation and costs.
- [docs/plugins.md](docs/plugins.md): third-party plugins to install and why.
- [docs/data-sources.md](docs/data-sources.md): where restaurant data may come from.
- [docs/legal-and-policy.md](docs/legal-and-policy.md): disclosures, reviews and privacy.
