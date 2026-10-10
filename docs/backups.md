# Backups

Daily off-site backups of the database and uploads, and a monthly restore test,
2026-10-10. This is on top of the host's own backups (Kinsta: daily, 14 days), which
live with the same company as the site; these copies do not.

## 1. What is backed up

| What | How often | Kept | Where |
|---|---|---|---|
| Database (all tables, including the plugin's) | daily, 03:15 Guam time | 30 days | `<remote>/db/<UTC timestamp>/db-*.sql.gz` + `manifest.txt` |
| Uploads (`wp-content/uploads`) | daily, same run | current copy, plus replaced or deleted files for 30 days | `<remote>/uploads/current/`, `<remote>/uploads/history/<timestamp>/` |
| Plugin and theme code | every commit | forever | GitHub (this repository) |
| Pre-deploy database and code | every deploy | last 10 releases | server, `DEPLOY_BACKUP_DIR` (docs/deployment.md) |
| `wp-config.php` | by hand, after changes | | password manager (it holds secrets) |

WordPress core and owned plugins are reinstalled from their sources, not backed up.

## 2. Setup

`bin/backup.sh` runs on the web server with WP-CLI and [rclone](https://rclone.org/).

1. **Storage.** Recommended: Backblaze B2 (pay as you go, about $6 per TB a month;
   this site needs a few GB) in a bucket used only for this, with an application key
   limited to that bucket. Turn on the bucket's lifecycle rule "keep only the last
   version" so deleted files really go. Any rclone backend works (S3, Wasabi, Google
   Drive).
2. **Install rclone** on the server (or ask the host; on Kinsta it can be installed in
   the home folder from the static binary).
3. **Configure two remotes** with `rclone config`: `b2` (the bucket) and `b2-crypt`, a
   **crypt** remote on top of it, so the storage company sees only encrypted files.
   Store the crypt password and salt in the password manager: without them the backups
   cannot be read.
4. **Settings** in `~/.guam-backup.env` (mode 600, outside the web root):

   ```sh
   BACKUP_REMOTE=b2-crypt:guam-restaurants
   WP_PATH=/www/<site>/public
   BACKUP_KEEP_DAYS=30
   BACKUP_PING_URL=https://hc-ping.com/<uuid>   # optional, recommended
   ```

5. **Dead-man's switch.** Create a free Healthchecks.io check, period 1 day, grace
   2 hours, and put its URL in `BACKUP_PING_URL`. The script pings `/start`, success
   and `/fail`, so a failed or missing backup sends an email.
6. **Run it once by hand** and look at the remote: `rclone ls b2-crypt:guam-restaurants/db`.
7. **Crontab** (as the site's user):

   ```cron
   15 17 * * * /path/to/bin/backup.sh >> /path/to/logs/guam-backup.log 2>&1
   ```

   `bin/backup.sh` is not part of the plugin; copy the `bin/` folder to the server's
   home folder, or run it from a checkout there.

The database export uses `--single-transaction`, so the site stays up and nothing is
locked. Each dump has a manifest with the time, site URL, schema version, restaurant
count and SHA-256, which the restore test checks against.

## 3. Restoring

**Production after data loss.** Pick the dump (`rclone lsf b2-crypt:guam-restaurants/db`),
then on the server:

```sh
rclone copy b2-crypt:guam-restaurants/db/<stamp> /tmp/restore
sha256sum /tmp/restore/db-*.sql.gz        # compare with manifest.txt
wp db export /tmp/before-restore.sql      # keep the current state
gunzip -c /tmp/restore/db-*.sql.gz | wp db import -
wp cache flush && wp rewrite flush
rclone copy b2-crypt:guam-restaurants/uploads/current wp-content/uploads   # if uploads were lost
```

A single lost or overwritten upload is in `uploads/history/<stamp>/` under the same path.

**Whole site lost:** new WordPress on the host, `wp-config.php` from the password
manager, owned plugins reinstalled, `bin/deploy.sh production` for our code, then the
steps above.

## 4. Restore test (monthly)

`bin/restore-test.sh` restores the newest backup into staging (or any WordPress that is
not production) and checks it:

```sh
TARGET_PATH=/www/<staging>/public TARGET_URL=https://staging.<site> bin/restore-test.sh
```

It refuses to run when `TARGET_URL` is the site URL recorded in the backup, and asks
for confirmation before replacing the target database (`CONFIRM=yes` skips the
question). `SKIP_UPLOADS=1` checks the uploads copy without downloading all of it.

Checks, each PASS or FAIL: dump checksum; import and URL replacement; restaurant count
equals the manifest; schema version; the health endpoint answers; uploads file count
and a random sample of files present.

Afterwards staging holds production data: re-run `wp option update blog_public 0`
(the script keeps the target's own URL), and do not send newsletters or reminders from
it (staging should have `GUAM_RESTAURANTS_CRON_MODE=server` with no crontab).

### Restore test log

Add a row after every test.

| Date | Backup | Target | Result | By |
|---|---|---|---|---|
| 2026-10-10 | 20261010T220632Z (local drill: 1,740 sample restaurants, 409 KB dump, 15 upload files) | local Docker copy | All PASS: checksum, import, 1,740 restaurants, schema 7, health, uploads; refused the production URL as intended | Claude (dry run with a local rclone remote, before the off-site bucket exists) |
| | first real backup | staging | | |
