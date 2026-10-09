# Common tasks. Run `make help` for the list.
# Composer and PHP run on your machine if installed, otherwise inside Docker.

# Docker Compose reads .env itself; make reads it only for the admin login below.
-include .env

WP_PORT           ?= 8080
WP_ADMIN_USER     ?= admin
WP_ADMIN_PASSWORD ?= password
WP_ADMIN_EMAIL    ?= admin@example.com
SITE_URL          := http://localhost:$(WP_PORT)

COMPOSE  := docker compose
WP       := $(COMPOSE) run --rm cli wp
COMPOSER_CMD := $(if $(shell command -v composer 2>/dev/null),composer,$(COMPOSE) run --rm --user $(shell id -u):$(shell id -g) composer)
PHP_RUN  := $(if $(shell command -v php 2>/dev/null),,$(COMPOSE) run --rm --user $(shell id -u):$(shell id -g) -w /project cli)

.PHONY: help setup up down reset install composer lint lint-fix test wp logs

help: ## List available commands.
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-10s %s\n", $$1, $$2}'

setup: composer up install ## First run: dependencies, containers, WordPress install.

composer: ## Install PHP dev tools (PHPCS, PHPUnit) and the git pre-commit hook.
	$(COMPOSER_CMD) install
	git config core.hooksPath .githooks

up: ## Start WordPress and the database.
	$(COMPOSE) up -d --wait db wordpress

down: ## Stop containers (data is kept).
	$(COMPOSE) down

reset: ## Stop containers and delete the database and WordPress volumes.
	$(COMPOSE) down -v

install: ## Install WordPress, activate the plugin and theme.
	@until $(COMPOSE) exec -T wordpress test -f /var/www/html/wp-config.php; do sleep 2; done
	$(WP) core is-installed || $(WP) core install --url=$(SITE_URL) --title="Guam Restaurants (local)" \
		--admin_user=$(WP_ADMIN_USER) --admin_password=$(WP_ADMIN_PASSWORD) --admin_email=$(WP_ADMIN_EMAIL) --skip-email
	$(WP) rewrite structure '/%postname%/' --hard
	$(WP) plugin activate guam-restaurants-core
	$(WP) theme activate guam-restaurants-theme
	@echo "Site: $(SITE_URL)  Admin: $(SITE_URL)/wp-admin ($(WP_ADMIN_USER) / $(WP_ADMIN_PASSWORD))"

lint: ## Run PHPCS (WordPress Coding Standards).
	$(PHP_RUN) vendor/bin/phpcs

lint-fix: ## Auto-fix what PHPCS can fix.
	$(PHP_RUN) vendor/bin/phpcbf

test: ## Run the plugin's PHPUnit tests (needs `make up`).
	$(COMPOSE) run --rm -w /project cli vendor/bin/phpunit -c wp-content/plugins/guam-restaurants-core/phpunit.xml.dist

wp: ## Run WP-CLI, e.g. `make wp ARGS="plugin list"`.
	$(WP) $(ARGS)

logs: ## Follow WordPress and PHP logs.
	$(COMPOSE) logs -f wordpress
