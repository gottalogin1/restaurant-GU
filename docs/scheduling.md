# Scheduled jobs

The plugin runs eight jobs. Each one is a WP-CLI command, so a real server
cron can run it directly. WP-Cron is the fallback.

| Job | Command | Window | Suggested crontab (UTC) |
|---|---|---|---|
| OpenStreetMap import, duplicate check, closure checks | `wp guam-restaurants run-job osm` | weekly, plus 1 day grace | `15 3 * * 1` |
| Google discovery from the search log (needs the Google key) | `wp guam-restaurants run-job discover` | daily, plus 1 day grace | `0 4 * * *` |
| Google status check (needs the Google key) | `wp guam-restaurants run-job google-status` | monthly, plus 3 days grace | `30 4 2 * *` |
| Yelp status check (off unless enabled, see below) | `wp guam-restaurants run-job yelp-status` | monthly, plus 3 days grace | `45 4 2 * *` |
| Renewal reminder emails (docs/billing.md §2) | `wp guam-restaurants run-job renewal-reminders` | daily, plus 1 day grace | `30 22 * * *` (08:30 Guam) |
| Health check | `wp guam-restaurants run-job health-check` | daily | `0 7 * * *` |
| Newsletter draft (Monday 06:00 Guam time; never sends) | `wp guam-restaurants run-job newsletter` | weekly, plus 1 day grace | `0 20 * * 0` |
| API spend report for last month (docs/monitoring.md §3) | `wp guam-restaurants run-job spend-report` | monthly, plus 3 days grace | `0 22 1 * *` (08:00 Guam on the 2nd) |
| Uploaded photo cleanup: delete originals after 30 days and unconfirmed uploads after 24 hours | `wp guam-restaurants run-job photo-cleanup` | daily, plus 1 day grace | `20 5 * * *` (15:20 Guam) |

`wp guam-restaurants jobs` prints the same table with the last success and
the next deadline. Restaurants > Scheduled jobs shows it in the admin, and
Restaurants > Import runs shows every run's counts, notes and errors.

## Turning jobs off and running them now

Restaurants > Scheduled jobs (administrators) lists the eight jobs and the
twice-daily ratings refresh with how often each runs, its last success, its
next run and its status. Each row has two buttons:

- **Turn off / Turn on.** A job that is off has no WP-Cron event, `run-job`
  from the server crontab skips it (exit 0; `--force` runs it anyway), and
  the health check does not report it as missed. Turning it back on restarts
  its window, so a long pause does not trigger a missed-window email.
  Turning off the health check stops all missed-job emails; the screen warns
  while it is off. Jobs that need an API key show as off until the key is set.
- **Run now.** Queues one extra run as a single WP-Cron event
  (`guam_restaurants_run_job_now`) and starts WP-Cron in the background, so
  a long import does not hold up the page. It works for a job that is off
  and leaves the regular schedule as it is. A job that is already queued or
  running cannot be queued again. With `DISABLE_WP_CRON`, the extra run waits
  for the `wp cron event run --due-now` crontab line (step 3 below).

The on/off choice is stored in the database (`guam_restaurants_core_jobs_paused`),
so `git pull` and `make update` keep it.

WP-Cron starts by calling the site's own address. In the local Docker stack
that is `http://localhost:8080`, which nothing answers inside the container, so
WP-Cron never ran there before. `docker-compose.yml` sets
`GUAM_RESTAURANTS_CRON_LOOPBACK_URL=http://localhost` so it does. Leave it
unset on a real host.

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
   0 4 * * *   cd /path/to/wordpress && wp guam-restaurants run-job discover >> /var/log/guam-jobs.log 2>&1
   30 4 2 * *  cd /path/to/wordpress && wp guam-restaurants run-job google-status >> /var/log/guam-jobs.log 2>&1
   30 22 * * * cd /path/to/wordpress && wp guam-restaurants run-job renewal-reminders >> /var/log/guam-jobs.log 2>&1
   0 7 * * *   cd /path/to/wordpress && wp guam-restaurants run-job health-check >> /var/log/guam-jobs.log 2>&1
   0 22 1 * *  cd /path/to/wordpress && wp guam-restaurants run-job spend-report >> /var/log/guam-jobs.log 2>&1
   20 5 * * *  cd /path/to/wordpress && wp guam-restaurants run-job photo-cleanup >> /var/log/guam-jobs.log 2>&1
   15 17 * * * /path/to/bin/backup.sh >> /var/log/guam-backup.log 2>&1
   ```

   The last line is the daily off-site backup (docs/backups.md); it is a shell
   script, not a plugin job.

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

The health check also emails when an import source is failing more than
usual (`Monitoring\Failure_Rates`), looking at that source's last 4 runs:

- more than 5% of the records failed;
- 2 or more runs failed outright (for example Overpass unreachable);
- the last 2 runs each needed 3 or more retried requests. The API still
  answered, but it is struggling.

Each run records its retried requests (the "Retried requests" column on
Restaurants > Import runs, with the reasons in the notes). A source is
reported once per new run that still shows the problem. Rating refreshes
are logged as `<source>_refresh` runs (for example `yelp_refresh`): the
twice-daily refresh logs one run per source, and a single page-view refresh
logs a run only when it fails.

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

## Discovery and the search log

`discover` finds restaurants OpenStreetMap is missing. It keeps a search log
(`wp guam-restaurants search-log status|list|add|disable|enable`) of every
Google Places Text Search query it runs: the area searched, when it last ran,
how many results came back and how many were new. Each run does the searches
that have never run first, then the ones whose weekly re-check is due, and
keeps going until none are left or the next request would leave the free
monthly allowance (4,500 Text Search calls, under the default $0 cap). So a
search is never repeated within a week, and every search is re-checked at
least once a week for new places.

The log starts with "restaurants in <village or mall>", cafes, bakeries, bars,
fast food and food trucks in each village, and "<cuisine> restaurants in
Guam". Text Search returns at most 60 results per query, so when a village's
search fills all 60 the log adds "<cuisine> restaurants in <village>" searches
for it. Add your own with `wp guam-restaurants search-log add "<query>"
--village=<slug>`.

New places go to Restaurants > Review queue (New place). "Add as restaurant"
there creates a draft with the place ID and village; fill in the details and
publish it.
