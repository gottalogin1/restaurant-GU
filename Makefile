# Common tasks. Run `make help` for the list.
# Composer and PHP run on your machine if installed, otherwise inside Docker.

# Docker Compose reads .env itself; make reads it only for the admin login below.
-include .env

WP_PORT           ?= 8080
WP_ADMIN_USER     ?= admin
WP_ADMIN_PASSWORD ?= password
WP_ADMIN_EMAIL    ?= admin@example.com
WP_HTTPS_PORT     ?= 8443
SITE_URL          := http://localhost:$(WP_PORT)

COMPOSE  := docker compose
WP       := $(COMPOSE) run --rm cli wp
COMPOSER_CMD := $(if $(shell command -v composer 2>/dev/null),composer,$(COMPOSE) run --rm --user $(shell id -u):$(shell id -g) composer)
PHP_RUN  := $(if $(shell command -v php 2>/dev/null),,$(COMPOSE) run --rm --user $(shell id -u):$(shell id -g) -w /project cli)

.PHONY: help setup update up down reset install composer woocommerce https lint lint-fix test wp logs fsq-extract

# WooCommerce for local development and tests (premium profile checkout). Pinned and checked;
# downloaded from the GitHub release because wordpress.org is not reachable from every machine.
WOOCOMMERCE_VERSION ?= 11.2.1
WOOCOMMERCE_SHA256  ?= 3066f85e46d1c1f6451296da0300b459963b504fd0d2f75869701ee744833aaf
WOOCOMMERCE_DIR     := .cache/plugins/woocommerce
# Written last, after a full unpack, so an interrupted or partial copy is detected and replaced.
WOOCOMMERCE_STAMP   := $(WOOCOMMERCE_DIR)/.download-complete

help: ## List available commands.
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-10s %s\n", $$1, $$2}'

setup: composer up install ## First run: dependencies, containers, WordPress install.

update: up install plugins ## After `git pull`: start containers, re-enable required plugins, apply updates, update plugins. Keeps your settings.

composer: ## Install PHP dev tools (PHPCS, PHPUnit) and the git pre-commit hook.
	$(COMPOSER_CMD) install
	git config core.hooksPath .githooks

woocommerce: ## Download the pinned WooCommerce release into .cache/plugins (once).
	@if grep -qsx "$(WOOCOMMERCE_VERSION)" $(WOOCOMMERCE_STAMP); then \
		echo "WooCommerce $(WOOCOMMERCE_VERSION) is in $(WOOCOMMERCE_DIR)."; \
	else \
		command -v unzip >/dev/null || { echo "unzip is missing. Install it (Ubuntu/WSL: sudo apt install unzip) and run this again."; exit 1; }; \
		mkdir -p .cache/plugins && \
		{ [ ! -e $(WOOCOMMERCE_DIR) ] || echo "$(WOOCOMMERCE_DIR) is incomplete or out of date; replacing it."; } && \
		echo "Downloading WooCommerce $(WOOCOMMERCE_VERSION)..." && \
		curl -fsSL -o .cache/woocommerce.zip https://github.com/woocommerce/woocommerce/releases/download/$(WOOCOMMERCE_VERSION)/woocommerce.zip && \
		echo "$(WOOCOMMERCE_SHA256)  .cache/woocommerce.zip" | sha256sum -c - && \
		echo "Unpacking about 5,500 files. On a Windows drive (/mnt/c) this can take several minutes." && \
		rm -rf .cache/woocommerce-new .cache/woocommerce-old && \
		unzip -q .cache/woocommerce.zip -d .cache/woocommerce-new && \
		{ [ ! -e $(WOOCOMMERCE_DIR) ] || mv $(WOOCOMMERCE_DIR) .cache/woocommerce-old; } && \
		mv .cache/woocommerce-new/woocommerce $(WOOCOMMERCE_DIR) && \
		echo "$(WOOCOMMERCE_VERSION)" > $(WOOCOMMERCE_STAMP) && \
		touch .cache/woocommerce-changed && \
		rm -rf .cache/woocommerce-new .cache/woocommerce-old .cache/woocommerce.zip && \
		echo "WooCommerce $(WOOCOMMERCE_VERSION) is in $(WOOCOMMERCE_DIR)." || \
		{ echo "WooCommerce download failed. If .cache is owned by root, run: sudo chown -R $$(id -u):$$(id -g) .cache"; exit 1; }; \
	fi

up: woocommerce ## Start WordPress and the database.
	@# Created by you, not by Docker (which would make it root-owned), so `make https` can write it.
	@mkdir -p .cache/certs
	$(COMPOSE) up -d --wait db wordpress
	@# A running container keeps showing the old WooCommerce folder after it is replaced, so restart it once.
	@if [ -f .cache/woocommerce-changed ]; then \
		$(COMPOSE) up -d --wait --force-recreate --no-deps wordpress && rm -f .cache/woocommerce-changed; \
	fi

down: ## Stop containers (data is kept).
	$(COMPOSE) down

reset: ## Stop containers and delete the database and WordPress volumes.
	$(COMPOSE) down -v

install: ## Install WordPress and turn on the plugin, theme and WooCommerce. Safe to re-run: keeps your settings.
	@until $(COMPOSE) exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 2; done
	@grep -qsx "$(WOOCOMMERCE_VERSION)" $(WOOCOMMERCE_STAMP) || { echo "WooCommerce in $(WOOCOMMERCE_DIR) is missing or incomplete. Run: make update"; exit 1; }
	@# Settings are chosen once, on a fresh install. Later runs (after every git pull) leave the
	@# permalinks, theme and WooCommerce settings as they are; deliberate fixes ship as one-time
	@# migrations (includes/database/class-migrations.php) that `db upgrade` applies.
	@if $(WP) core is-installed >/dev/null 2>&1; then \
		echo "WordPress is already installed; keeping its settings, theme and plugins."; \
	else \
		$(WP) core install --url=$(SITE_URL) --title="Guam Restaurants (local)" \
			--admin_user=$(WP_ADMIN_USER) --admin_password=$(WP_ADMIN_PASSWORD) --admin_email=$(WP_ADMIN_EMAIL) --skip-email && \
		$(WP) rewrite structure '/%postname%/' --hard && \
		$(WP) theme activate guam-restaurants-theme; \
	fi
	@# Required plugins are activated only when they are off, so their activation routines do not run again.
	@$(WP) plugin is-active guam-restaurants-core || $(WP) plugin activate guam-restaurants-core
	@$(WP) plugin is-active woocommerce || { \
		first=$$($(WP) option get woocommerce_version >/dev/null 2>&1 || echo yes); \
		$(WP) plugin activate woocommerce && \
		if [ -n "$$first" ]; then $(WP) option update woocommerce_coming_soon no; fi; \
	}
	$(WP) guam-restaurants db upgrade
	$(WP) guam-restaurants premium-products
	@echo "Site: $(SITE_URL)  Admin: $(SITE_URL)/wp-admin ($(WP_ADMIN_USER) / $(WP_ADMIN_PASSWORD))"

https: ## Optional: serve https://localhost:$(WP_HTTPS_PORT) with a certificate Firefox trusts (README).
	@mkdir -p .cache/certs
	$(COMPOSE) run --rm --no-deps --user $(shell id -u):$(shell id -g) -v "$(CURDIR)/bin:/scripts:ro" -v "$(CURDIR)/.cache/certs:/certs" \
		--entrypoint sh wordpress /scripts/local-https-cert.sh /certs
	$(COMPOSE) up -d --wait --force-recreate --no-deps wordpress
	@echo "HTTPS: https://localhost:$(WP_HTTPS_PORT)  (http://localhost:$(WP_PORT) keeps working)"
	@echo "Firefox trusts it once you import .cache/certs/local-ca.crt (README, \"Local HTTPS\")."

lint: ## Run PHPCS (WordPress Coding Standards).
	$(PHP_RUN) vendor/bin/phpcs

lint-fix: ## Auto-fix what PHPCS can fix.
	$(PHP_RUN) vendor/bin/phpcbf

test: ## Run the plugin's PHPUnit tests (needs `make up`).
	$(COMPOSE) run --rm -w /project cli vendor/bin/phpunit -c wp-content/plugins/guam-restaurants-core/phpunit.xml.dist

.PHONY: plugins
plugins: ## Show plugin versions and available updates, then install the updates.
	$(COMPOSE) run --rm cli sh /project/bin/update-plugins.sh

# Pinned for the Foursquare extract below.
DUCKDB_VERSION ?= 1.4.1

fsq-extract: ## Download Guam's places from Foursquare Open Source Places into .cache/fsq-guam.jsonl (needs HF_TOKEN in .env).
	@[ -n "$(HF_TOKEN)" ] || { echo "Set HF_TOKEN in .env first (docs/run-fsq-import.md)."; exit 1; }
	@mkdir -p .cache
	@# The token is passed by name only, so it is never printed.
	@HF_TOKEN="$(HF_TOKEN)" FSQ_RELEASE="$(FSQ_RELEASE)" docker run --rm -e HF_TOKEN -e FSQ_RELEASE \
		-v "$(CURDIR)/bin:/scripts:ro" -v "$(CURDIR)/.cache:/out" python:3.12-slim sh -c \
		"pip install --quiet --root-user-action=ignore duckdb==$(DUCKDB_VERSION) && python -I /scripts/fsq-extract-guam.py /out/fsq-guam.jsonl && chown $(shell id -u):$(shell id -g) /out/fsq-guam.jsonl"
	@echo "Next: make wp ARGS=\"guam-restaurants import-fsq --input=/project/.cache/fsq-guam.jsonl --dry-run\""

wp: ## Run WP-CLI, e.g. `make wp ARGS="plugin list"`.
	$(WP) $(ARGS)

logs: ## Follow WordPress and PHP logs.
	$(COMPOSE) logs -f wordpress
