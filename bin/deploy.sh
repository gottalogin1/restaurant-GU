#!/usr/bin/env bash
# Deploys the plugin and theme to staging or production over SSH (docs/deployment.md).
#
#   bin/deploy.sh staging
#   bin/deploy.sh production
#   bin/deploy.sh production --dry-run     # shows what would change, changes nothing
#
# Settings come from deploy/<env>.env (git-ignored; copy deploy/example.env):
#   DEPLOY_SSH          user@host for ssh and rsync ("local" runs on this machine).
#   DEPLOY_SSH_PORT     SSH port (default 22).
#   DEPLOY_PATH         WordPress root on the server.
#   DEPLOY_URL          Public site URL, for the smoke test.
#   DEPLOY_WP           WP-CLI command on the server (default: wp).
#   DEPLOY_BACKUP_DIR   Folder outside the web root for pre-deploy backups
#                       (default: $DEPLOY_PATH/../deploy-backups).
#   DEPLOY_PURGE_CMD    Command that purges the page cache/CDN, run on the server, e.g.
#                       "wp kinsta cache purge --all" or "wp w3-total-cache flush all" (optional).
#
# Steps: refuse uncommitted changes (and, for production, anything not on origin/main);
# build from `git archive` (tests left out); on the server: database dump and a copy of
# the current plugin and theme into DEPLOY_BACKUP_DIR/<release>; rsync the new code;
# `wp guam-restaurants db upgrade`; flush caches and rewrite rules; purge the page cache;
# `wp guam-restaurants preflight` (--strict on production); smoke test the health endpoint
# and home page. If anything after the rsync fails, it prints the rollback command.

set -euo pipefail

ENVIRONMENT="${1:-}"
DRY_RUN=0
[ "${2:-}" = "--dry-run" ] && DRY_RUN=1
case "$ENVIRONMENT" in
	staging | production) ;;
	*) echo "Usage: bin/deploy.sh <staging|production> [--dry-run]"; exit 2 ;;
esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${DEPLOY_ENV_FILE:-$ROOT/deploy/$ENVIRONMENT.env}"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && . "$ENV_FILE"
: "${DEPLOY_SSH:?Set DEPLOY_SSH in $ENV_FILE}"
: "${DEPLOY_PATH:?Set DEPLOY_PATH in $ENV_FILE}"
: "${DEPLOY_URL:?Set DEPLOY_URL in $ENV_FILE}"
DEPLOY_WP="${DEPLOY_WP:-wp}"
DEPLOY_SSH_PORT="${DEPLOY_SSH_PORT:-22}"
DEPLOY_BACKUP_DIR="${DEPLOY_BACKUP_DIR:-$DEPLOY_PATH/../deploy-backups}"
PLUGIN=wp-content/plugins/guam-restaurants-core
THEME=wp-content/themes/guam-restaurants-theme

log() { printf '\n==> %s\n' "$*"; }
remote() {
	if [ "$DEPLOY_SSH" = "local" ]; then
		bash -c "$1"
	else
		ssh -p "$DEPLOY_SSH_PORT" -o BatchMode=yes "$DEPLOY_SSH" "$1"
	fi
}
rsync_to() {
	local target="$2"
	[ "$DEPLOY_SSH" = "local" ] || target="$DEPLOY_SSH:$2"
	local extra=()
	[ "$DRY_RUN" = 1 ] && extra=(--dry-run --itemize-changes)
	rsync -az --delete --delay-updates "${extra[@]}" \
		-e "ssh -p $DEPLOY_SSH_PORT -o BatchMode=yes" "$1/" "$target/"
}
wp_remote() { remote "cd '$DEPLOY_PATH' && $DEPLOY_WP --path='$DEPLOY_PATH' $*"; }

cd "$ROOT"
log "Checking the working tree"
if [ -n "$(git status --porcelain -- "$PLUGIN" "$THEME")" ]; then
	echo "Uncommitted changes in the plugin or theme. Commit or stash them first."
	exit 1
fi
if [ "$ENVIRONMENT" = "production" ]; then
	git fetch -q origin main
	if ! git merge-base --is-ancestor HEAD origin/main; then
		echo "Production deploys only commits that are on origin/main (merged and CI green)."
		exit 1
	fi
fi
RELEASE="$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD)"
echo "Release $RELEASE to $ENVIRONMENT ($DEPLOY_URL)"

log "Building from git"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT
git archive HEAD "$PLUGIN" "$THEME" | tar -x -C "$BUILD"
rm -rf "$BUILD/$PLUGIN/tests" "$BUILD/$PLUGIN/phpunit.xml.dist"

if [ "$DRY_RUN" = 1 ]; then
	log "Dry run: files that would change"
	rsync_to "$BUILD/$PLUGIN" "$DEPLOY_PATH/$PLUGIN"
	rsync_to "$BUILD/$THEME" "$DEPLOY_PATH/$THEME"
	exit 0
fi

log "Backing up the database and current code on the server"
remote "set -e; mkdir -p '$DEPLOY_BACKUP_DIR/$RELEASE'; chmod 700 '$DEPLOY_BACKUP_DIR'
	cd '$DEPLOY_PATH' && $DEPLOY_WP --path='$DEPLOY_PATH' db export - --single-transaction --quick | gzip > '$DEPLOY_BACKUP_DIR/$RELEASE/db.sql.gz'
	gzip -t '$DEPLOY_BACKUP_DIR/$RELEASE/db.sql.gz'
	for dir in '$PLUGIN' '$THEME'; do
		if [ -d \"$DEPLOY_PATH/\$dir\" ]; then mkdir -p \"$DEPLOY_BACKUP_DIR/$RELEASE/\$(dirname \$dir)\"; cp -a \"$DEPLOY_PATH/\$dir\" \"$DEPLOY_BACKUP_DIR/$RELEASE/\$dir\"; fi
	done
	echo '$RELEASE' > '$DEPLOY_BACKUP_DIR/last-release'
	ls -1d '$DEPLOY_BACKUP_DIR'/*/ | sort | head -n -10 | xargs -r rm -rf"

rollback_hint() {
	echo
	echo "Deploy stopped after the code was copied. To go back:"
	echo "  bin/rollback.sh $ENVIRONMENT $RELEASE            # code only"
	echo "  bin/rollback.sh $ENVIRONMENT $RELEASE --database # code and database"
}
trap 'rm -rf "$BUILD"; rollback_hint' ERR

log "Copying the plugin and theme"
remote "mkdir -p '$DEPLOY_PATH/$PLUGIN' '$DEPLOY_PATH/$THEME'"
rsync_to "$BUILD/$PLUGIN" "$DEPLOY_PATH/$PLUGIN"
rsync_to "$BUILD/$THEME" "$DEPLOY_PATH/$THEME"

log "Upgrading custom tables"
wp_remote "plugin is-active guam-restaurants-core || $DEPLOY_WP --path='$DEPLOY_PATH' plugin activate guam-restaurants-core"
wp_remote "guam-restaurants db upgrade"

log "Flushing caches"
wp_remote "cache flush"
wp_remote "rewrite flush"
if [ -n "${DEPLOY_PURGE_CMD:-}" ]; then
	remote "cd '$DEPLOY_PATH' && $DEPLOY_PURGE_CMD"
fi

log "Preflight"
if [ "$ENVIRONMENT" = "production" ]; then
	wp_remote "guam-restaurants preflight --strict"
else
	wp_remote "guam-restaurants preflight"
fi

log "Smoke test"
health="$(curl -sS -m 20 "${DEPLOY_URL%/}/wp-json/guam-restaurants/v1/health")"
echo "$health"
case "$health" in
	*'"status":"ok"'* | *'"status":"degraded"'*) ;;
	*) echo "Health check failed."; false ;;
esac
curl -fsS -m 20 -o /dev/null "${DEPLOY_URL%/}/"
curl -fsS -m 20 -o /dev/null "${DEPLOY_URL%/}/restaurants/"

trap - ERR
log "Deployed $RELEASE to $ENVIRONMENT"
