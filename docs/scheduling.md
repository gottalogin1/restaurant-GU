# Scheduled jobs

The plugin runs five jobs. Each one is a WP-CLI command, so a real server
cron can run it directly. WP-Cron is the fallback.

| Job | Command | Window | Suggested crontab (UTC) |
|---|---|---|---|
| OpenStreetMap import, duplicate check, closure checks | `wp guam-restaurants run-job osm` | weekly, plus 1 day grace | `15 3 * * 1` |
| Google status check (needs the Google key) | `wp guam-restaurants run-job google-status` | monthly, plus 3 days grace | `30 4 2 * *` |
| Yelp status check (off unless enabled, see below) | `wp guam-restaurants run-job yelp-status` | monthly, plus 3 days grace | `45 4 2 * *` |
| Renewal reminder emails (docs/billing.md §2) | `wp guam-restaurants run-job renewal-reminders` | daily, plus 1 day grace | `30 22 * * *` (08:30 Guam) |
| Health check | `wp guam-restaurants run-job health-check` | daily | `0 7 * * *` |

`wp guam-restaurants jobs` prints the same table with the last success and
the next deadline. Restaurants > Import runs shows it in the admin, with every
run's counts, notes and errors.

## Server cron (preferred)

1. In `wp-config.php` (or the host environment):

   ```php
   define( 'GUAM_RESTAURANTS_CRON_MODE', 'server' );
   define( 'GUAM_RESTAURANTS_ALERT_EMAIL', 'you@example.com' );
   ```

   With `server`, WP-Cron schedules only a backup copy of the health check,
   so a crontab that stops running is still noticed (the copy skips itself
   when the health check already succeeded in the last 20 hours).

2. Add the crontab lines, as the user that owns the WordPress files:

   ```cron
   15 3 * * 1  cd /path/to/wordpress && wp guam-restaurants run-job osm >> /var/log/guam-jobs.log 2>&1
   30 4 2 * *  cd /path/to/wordpress && wp guam-restaurants run-job google-status >> /var/log/guam-jobs.log 2>&1
   30 22 * * * cd /path/to/wordpress && wp guam-restaurants run-job renewal-reminders >> /var/log/guam-jobs.log 2>&1
   0 7 * * *   cd /path/to/wordpress && wp guam-restaurants run-job health-check >> /var/log/guam-jobs.log 2>&1
   ```

3. Optional: `define( 'DISABLE_WP_CRON', true );` and run WP-Cron itself from
   the crontab (`*/15 * * * * cd /path/to/wordpress && wp cron event run --due-now`),
   which keeps the health check backup and the plugin's other events running
   without site visits.

## WP-Cron fallback

With no setting (`GUAM_RESTAURANTS_CRON_MODE` unset or `wp-cron`) every
enabled job is scheduled on WP-Cron on the hook `guam_restaurants_run_job`.
WP-Cron only runs when someone visits the site, so a quiet site can miss a
window; the health check reports that.

Switching modes reschedules on the next page load. Deactivating the plugin
removes every scheduled event.

## Health check and alerts

Every day the health check compares each enabled job's last successful run
with its window plus grace. A job that is overdue (or has never run since it
was enabled) triggers one email to `GUAM_RESTAURANTS_ALERT_EMAIL` (or the
site admin email) per missed deadline, with the command to run by hand.

A run that queues more than `GUAM_RESTAURANTS_CLOSURE_ALERT_THRESHOLD`
(default 5) restaurants as possibly closed sends an email too. Jobs never
mark a restaurant closed: two checks in a row from the same source put it in
Restaurants > Review queue, and an administrator approves it.

## Cost

The Google status check asks only for `businessStatus` (Place Details Pro).
It stops at the free allowance (4,500 a month, 90% of Google's 5,000) under
the existing $0 caps, so it costs nothing; `wp guam-restaurants check-status
--estimate` shows the count first. Yelp has no free tier, so its check stays
off unless `GUAM_RESTAURANTS_YELP_STATUS_CHECKS=1` and a Yelp key are set and
the ratings caps allow spending.
