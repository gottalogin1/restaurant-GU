#!/usr/bin/env bash
# Restore drill: restores the newest off-site backup into a NON-production WordPress and
# checks it (docs/backups.md, "Restore test"). Run it monthly, on staging or a local copy.
#
#   bin/restore-test.sh                 # newest backup
#   bin/restore-test.sh 20261010T171500Z
#
# Settings (environment or $BACKUP_ENV_FILE, default ~/.guam-backup.env):
#   BACKUP_REMOTE   rclone remote:path the backups were written to (required).
#   TARGET_PATH     WordPress root to restore INTO (required). Its database is replaced.
#   TARGET_URL      That site's own URL, e.g. https://staging.example.com (required).
#   WP              WP-CLI command (default: wp).
#   TARGET_UPLOADS  Uploads folder of the target (default: asked from WordPress).
#   SKIP_UPLOADS=1  Check the uploads copy without downloading it all.
#
# Safety: refuses to run when TARGET_URL is the URL recorded in the backup (production),
# and asks for confirmation before replacing the target database.
#
# Checks, each printed as PASS or FAIL:
#   - the dump's SHA-256 matches the manifest;
#   - the import succeeds and the restaurant count matches the manifest;
#   - the custom tables' schema version matches;
#   - the site's health endpoint answers "ok" or "degraded";
#   - uploads: file count of the off-site copy, and 20 random files present after restore.
# Write the result in the log table in docs/backups.md.

set -euo pipefail

ENV_FILE="${BACKUP_ENV_FILE:-$HOME/.guam-backup.env}"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && . "$ENV_FILE"

: "${BACKUP_REMOTE:?Set BACKUP_REMOTE}"
: "${TARGET_PATH:?Set TARGET_PATH (the WordPress root to restore into)}"
: "${TARGET_URL:?Set TARGET_URL (the target site URL)}"
WP="${WP:-wp}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
FAILED=0

wp_cli() { $WP --path="$TARGET_PATH" --skip-themes "$@"; }
pass() { printf 'PASS  %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*"; FAILED=1; }
started=$(date +%s)

STAMP="${1:-$(rclone lsf "$BACKUP_REMOTE/db" --dirs-only | sed 's#/$##' | sort | tail -1)}"
[ -n "$STAMP" ] || { echo "No backups found in $BACKUP_REMOTE/db"; exit 1; }
echo "Restoring backup $STAMP into $TARGET_URL"

rclone copy "$BACKUP_REMOTE/db/$STAMP" "$TMP"
# Read the manifest as data (key=value lines), never as shell code.
manifest() { sed -n "s/^$1=//p" "$TMP/manifest.txt" | head -1; }
site_url="$(manifest site_url)"
db_file="$(basename "$(manifest db_file)")"
db_sha256="$(manifest db_sha256)"
restaurants="$(manifest restaurants)"
schema_version="$(manifest schema_version)"
created_utc="$(manifest created_utc)"
if [ "${TARGET_URL%/}" = "${site_url%/}" ]; then
	echo "Refusing: $TARGET_URL is the site this backup came from. Restore tests never touch production."
	exit 1
fi

if [ "$(sha256sum "$TMP/$db_file" | cut -d' ' -f1)" = "$db_sha256" ]; then pass "checksum of $db_file"; else fail "checksum of $db_file"; fi

if [ "${CONFIRM:-}" != "yes" ]; then
	read -r -p "This replaces the database of $TARGET_URL. Type yes to continue: " answer
	[ "$answer" = "yes" ] || { echo "Stopped."; exit 1; }
fi

gunzip -c "$TMP/$db_file" | wp_cli db import -
wp_cli search-replace "$site_url" "${TARGET_URL%/}" --all-tables --skip-columns=guid --quiet
pass "database imported and URLs changed to $TARGET_URL"

count="$(wp_cli post list --post_type=gr_restaurant --post_status=any --format=count)"
if [ "$count" = "$restaurants" ]; then pass "restaurants: $count"; else fail "restaurants: $count, manifest says $restaurants"; fi

schema="$(wp_cli option get guam_restaurants_core_db_version 2>/dev/null || echo unknown)"
if [ "$schema" = "$schema_version" ]; then pass "schema version $schema"; else fail "schema version $schema, manifest says $schema_version"; fi

wp_cli cache flush >/dev/null 2>&1 || true
health="$(curl -sS -m 20 "${TARGET_URL%/}/wp-json/guam-restaurants/v1/health" || true)"
case "$health" in
	*'"status":"ok"'* | *'"status":"degraded"'*) pass "health endpoint: $health" ;;
	*) fail "health endpoint: ${health:-no answer}" ;;
esac

remote_files="$(rclone lsf -R --files-only "$BACKUP_REMOTE/uploads/current" | wc -l)"
pass "off-site uploads copy holds $remote_files files"
TARGET_UPLOADS="${TARGET_UPLOADS:-$($WP --path="$TARGET_PATH" eval 'echo wp_upload_dir()["basedir"];')}"
if [ "${SKIP_UPLOADS:-0}" != "1" ]; then
	rclone copy "$BACKUP_REMOTE/uploads/current" "$TARGET_UPLOADS"
	pass "uploads restored to $TARGET_UPLOADS"
fi
missing=0
sampled=0
while IFS= read -r file; do
	[ -n "$file" ] || continue
	sampled=$((sampled + 1))
	[ -f "$TARGET_UPLOADS/$file" ] || missing=$((missing + 1))
done < <(rclone lsf -R --files-only "$BACKUP_REMOTE/uploads/current" | shuf -n 20 2>/dev/null || true)
if [ "$missing" -eq 0 ]; then pass "$sampled random upload files present"; else fail "$missing of $sampled random upload files missing"; fi

echo "Took $(( $(date +%s) - started )) seconds. Backup created $created_utc."
exit "$FAILED"
