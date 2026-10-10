#!/usr/bin/env bash
# Puts back the plugin and theme (and optionally the database) saved by bin/deploy.sh
# before a release (docs/deployment.md, "Rollback").
#
#   bin/rollback.sh production                         # code saved before the last release
#   bin/rollback.sh production 20261010T090000Z-abc1234
#   bin/rollback.sh production 20261010T090000Z-abc1234 --database
#
# --database also imports the database dump taken just before that release. Anything
# written to the site since then (reviews, leads, orders, imports) is lost, so prefer a
# code-only rollback: schema changes are additive, so older code runs on a newer database.
# Uses the same deploy/<env>.env settings as bin/deploy.sh.

set -euo pipefail

ENVIRONMENT="${1:-}"
case "$ENVIRONMENT" in
	staging | production) ;;
	*) echo "Usage: bin/rollback.sh <staging|production> [release] [--database]"; exit 2 ;;
esac
RELEASE="${2:-}"
WITH_DB=0
[ "${3:-}" = "--database" ] || [ "${2:-}" = "--database" ] && WITH_DB=1
[ "$RELEASE" = "--database" ] && RELEASE=""

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

remote() {
	if [ "$DEPLOY_SSH" = "local" ]; then
		bash -c "$1"
	else
		ssh -p "$DEPLOY_SSH_PORT" -o BatchMode=yes "$DEPLOY_SSH" "$1"
	fi
}

[ -n "$RELEASE" ] || RELEASE="$(remote "cat '$DEPLOY_BACKUP_DIR/last-release'")"
SAVED="$DEPLOY_BACKUP_DIR/$RELEASE"
remote "test -d '$SAVED/$PLUGIN'" || { echo "No saved code at $SAVED"; exit 1; }
echo "Rolling $ENVIRONMENT back to the code saved before release $RELEASE."
if [ "$WITH_DB" = 1 ]; then
	echo "The database will be replaced by $SAVED/db.sql.gz. Changes made since then are lost."
fi
if [ "${CONFIRM:-}" != "yes" ]; then
	read -r -p "Type yes to continue: " answer
	[ "$answer" = "yes" ] || { echo "Stopped."; exit 1; }
fi

remote "set -e
	for dir in '$PLUGIN' '$THEME'; do
		if [ -d '$SAVED'/\$dir ]; then
			rsync -a --delete '$SAVED'/\$dir/ '$DEPLOY_PATH'/\$dir/
		fi
	done"
echo "Code restored."

if [ "$WITH_DB" = 1 ]; then
	remote "cd '$DEPLOY_PATH' && gunzip -c '$SAVED/db.sql.gz' | $DEPLOY_WP --path='$DEPLOY_PATH' db import -"
	echo "Database restored."
fi

remote "cd '$DEPLOY_PATH' && $DEPLOY_WP --path='$DEPLOY_PATH' cache flush && $DEPLOY_WP --path='$DEPLOY_PATH' rewrite flush"
if [ -n "${DEPLOY_PURGE_CMD:-}" ]; then
	remote "cd '$DEPLOY_PATH' && $DEPLOY_PURGE_CMD"
fi
curl -sS -m 20 "${DEPLOY_URL%/}/wp-json/guam-restaurants/v1/health" && echo
echo "Rollback done. Check the site, then fix forward with a new release."
