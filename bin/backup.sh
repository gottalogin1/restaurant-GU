#!/usr/bin/env bash
# Daily off-site backup of the database and uploads (docs/backups.md).
#
# Runs on the web server from cron, as the user that owns the WordPress files:
#
#   15 17 * * * /path/to/bin/backup.sh >> /path/to/logs/backup.log 2>&1   # 03:15 Guam time (UTC+10)
#
# What it does, every run:
#   1. Exports the database with `wp db export` (one transaction, no table locks), gzips it,
#      and writes a manifest: time, site URL, schema version, restaurant count, SHA-256.
#   2. Copies both to  $BACKUP_REMOTE/db/<UTC timestamp>/  with rclone.
#   3. Syncs wp-content/uploads to  $BACKUP_REMOTE/uploads/current/. Files that were changed or
#      deleted since the last run are moved to  $BACKUP_REMOTE/uploads/history/<timestamp>/,
#      so nothing is lost to a bad sync.
#   4. Deletes database dumps and upload history older than $BACKUP_KEEP_DAYS.
#   5. Pings $BACKUP_PING_URL (for example a Healthchecks.io check) on start, success and
#      failure, so a backup that stops running raises an alert.
#
# Settings come from the environment or from the file named by $BACKUP_ENV_FILE
# (default: ~/.guam-backup.env, never committed):
#
#   BACKUP_REMOTE     rclone destination, e.g. "b2-crypt:guam-restaurants" (required).
#                     Use an rclone "crypt" remote so the provider cannot read the dumps.
#   WP_PATH           WordPress root (required).
#   WP                WP-CLI command (default: wp).
#   UPLOADS_DIR       Uploads folder (default: asked from WordPress).
#   BACKUP_KEEP_DAYS  Days to keep (default: 30).
#   BACKUP_TMP        Scratch folder outside the web root (default: a new mktemp folder).
#   BACKUP_PING_URL   Optional dead-man's-switch URL; /start and /fail are appended.
#
# Exit status is non-zero on any failure.

set -euo pipefail

ENV_FILE="${BACKUP_ENV_FILE:-$HOME/.guam-backup.env}"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && . "$ENV_FILE"

: "${BACKUP_REMOTE:?Set BACKUP_REMOTE (an rclone remote:path)}"
: "${WP_PATH:?Set WP_PATH (the WordPress root)}"
WP="${WP:-wp}"
BACKUP_KEEP_DAYS="${BACKUP_KEEP_DAYS:-30}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TMP="${BACKUP_TMP:-$(mktemp -d)}"
CLEAN_TMP=$([ -z "${BACKUP_TMP:-}" ] && echo 1 || echo 0)

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
ping_url() {
	[ -n "${BACKUP_PING_URL:-}" ] || return 0
	curl -fsS -m 10 --retry 3 -o /dev/null "${BACKUP_PING_URL}$1" || log "warning: ping $1 failed"
}
wp_cli() { $WP --path="$WP_PATH" --skip-themes "$@"; }

cleanup() {
	status=$?
	[ "$CLEAN_TMP" = 1 ] && rm -rf "$TMP"
	if [ "$status" -ne 0 ]; then
		log "backup FAILED (exit $status)"
		ping_url /fail
	fi
}
trap cleanup EXIT

command -v rclone >/dev/null || { log "rclone is not installed"; exit 1; }
mkdir -p "$TMP"
ping_url /start
log "backup $STAMP starting"

# 1. Database.
DUMP="$TMP/db-$STAMP.sql.gz"
wp_cli db export - --single-transaction --quick | gzip -6 > "$DUMP"
gzip -t "$DUMP"
SITE_URL="$(wp_cli option get home)"
SCHEMA="$(wp_cli option get guam_restaurants_core_db_version 2>/dev/null || echo unknown)"
RESTAURANTS="$(wp_cli post list --post_type=gr_restaurant --post_status=any --format=count)"
SHA="$(sha256sum "$DUMP" | cut -d' ' -f1)"
cat > "$TMP/manifest.txt" <<EOF
created_utc=$STAMP
site_url=$SITE_URL
schema_version=$SCHEMA
restaurants=$RESTAURANTS
db_file=$(basename "$DUMP")
db_sha256=$SHA
db_bytes=$(stat -c %s "$DUMP")
EOF
log "database dump $(stat -c %s "$DUMP") bytes, $RESTAURANTS restaurants"

# 2. Off-site copy of the dump.
rclone copy "$TMP" "$BACKUP_REMOTE/db/$STAMP" --include "db-$STAMP.sql.gz" --include manifest.txt
rclone check "$TMP" "$BACKUP_REMOTE/db/$STAMP" --include "db-$STAMP.sql.gz" --one-way
log "database copied to $BACKUP_REMOTE/db/$STAMP"

# 3. Uploads, incremental, keeping replaced and deleted files.
UPLOADS_DIR="${UPLOADS_DIR:-$(wp_cli eval 'echo wp_upload_dir()["basedir"];')}"
[ -d "$UPLOADS_DIR" ] || { log "uploads folder $UPLOADS_DIR not found"; exit 1; }
rclone sync "$UPLOADS_DIR" "$BACKUP_REMOTE/uploads/current" \
	--backup-dir "$BACKUP_REMOTE/uploads/history/$STAMP" \
	--exclude "cache/**" --exclude "*.log"
log "uploads synced from $UPLOADS_DIR"

# 4. Retention.
for dir in db uploads/history; do
	# uploads/history only exists once a file has been replaced or deleted.
	if rclone lsf "$BACKUP_REMOTE/$dir" --max-depth 1 >/dev/null 2>&1; then
		rclone delete "$BACKUP_REMOTE/$dir" --min-age "${BACKUP_KEEP_DAYS}d"
		rclone rmdirs "$BACKUP_REMOTE/$dir" --leave-root
	fi
done

log "backup $STAMP done"
ping_url ""
