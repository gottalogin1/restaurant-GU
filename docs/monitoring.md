# Monitoring

What watches the live site, 2026-10-10. Alerts go to `GUAM_RESTAURANTS_ALERT_EMAIL`
(through WordPress mail, so SMTP must work: docs/plugins.md).

| What | How | Alerts |
|---|---|---|
| Site up and database reachable | External uptime monitor on the health endpoint (§1) | Monitor's email/SMS |
| Scheduled jobs ran | Daily `health-check` job (docs/scheduling.md) | Alert email listing missed jobs |
| Backups ran | Dead-man's switch ping from `bin/backup.sh` (docs/backups.md) | Healthchecks.io email |
| PHP errors | Error log file (§2) | Host log viewer; optional log alert |
| Paid API spend | Monthly spend report (§3) and the hard caps | Email on the 2nd of each month |
| Malware, plugin vulnerabilities | MalCare free (docs/security.md §4) | MalCare email |

## 1. Uptime

`GET /wp-json/guam-restaurants/v1/health` answers without caching (`Cache-Control:
no-store`) and does no outbound calls:

```json
{"status":"ok","checks":{"database":true,"schema":true,"jobs":true}}
```

- `ok` (200): database answers, custom tables are current, no job is overdue.
- `degraded` (200): only scheduled jobs are overdue. The site works; the daily health
  check email says which job.
- `error` (503): the database query failed or the custom tables are out of date.

**Set up (free):** UptimeRobot (50 monitors at 5-minute intervals on the free plan) or
Better Stack. Two monitors:

1. **Keyword monitor** on `https://<site>/wp-json/guam-restaurants/v1/health`,
   alert when the keyword `"status":"error"` **exists** or the request fails. (Alerting on
   "ok missing" would page for `degraded`, which the job email already covers.)
2. **HTTP monitor** on the home page, so a page cache or CDN fault is also seen.

Alert contacts: the owner's email and phone. Interval 5 minutes; alert after 2 failures.
Add the site's status to the CDN's health checks too if the plan has them.

`wp guam-restaurants health` prints the same JSON from the server shell.

## 2. Error logging

- PHP errors go to `WP_DEBUG_LOG` (a path outside the web root, docs/deployment.md §2),
  never to the page. Kinsta and most hosts also show PHP and web server logs in their
  dashboard.
- The plugin writes one line per failed job:
  `[guam-restaurants] job <name> failed: <message>`. Import runs keep their counts and
  errors in Restaurants > Import runs (rule 9).
- Rotate the log: hosts usually do; otherwise logrotate weekly, 8 weeks kept.
- Weekly glance (or a host log alert, if offered) for `PHP Fatal error` and
  `[guam-restaurants]`:
  `grep -E 'PHP Fatal|\[guam-restaurants\]' /<home>/logs/wp-errors.log | tail -50`.
- An external error tracker (Sentry and similar) is not set up; worth adding if fatal
  errors show up more than rarely.
- The CSP runs in report-only mode with no report endpoint; violations show in the
  browser console (docs/security.md §3).

## 3. Monthly API spend report

The `spend-report` job runs on the 1st of each month at 22:00 UTC (08:00 on the 2nd,
Guam time) and emails last month's report once:

```
API usage and estimated spend for 2026-10 (UTC month).

- Google Places: 1,204 calls (1,204 free, 0 billable), estimated $0.00
- Yelp: 0 calls (0 free, 0 billable), estimated $0.00
- Foursquare: 312 calls (312 free, 0 billable), estimated $0.00

Total estimated: $0.00. Caps: $0.00 per month for page views, $0.00 per run.
Estimates price calls past 90% of each free allowance at list price, so they err high.
...
Not metered here:
- OpenStreetMap Overpass: free, not metered (weekly import).
- OpenFreeMap map tiles: free, not metered.
- Google Maps Embed API: free with no usage limit, not metered.
- SendFox: flat monthly plan, not per call.
```

(Numbers above are illustrative.)

- Counts come from `Ratings\Budget`, which records every paid call (discovery,
  matching, status checks, page-view refreshes) per SKU per month. At the start of each
  month it archives last month's counts (24 months kept), so the report works for any
  past month.
- The estimate uses the same rules as the spending caps: calls past 90% of each free
  allowance at list price. The real bill is on each provider's billing page (Google
  Cloud console, Yelp and Foursquare developer dashboards); compare once a quarter.
- Any month: `wp guam-restaurants spend-report --month=2026-10` (add `--email` to send
  it, `--format=json` for scripts).
- The hard limits are `GUAM_RESTAURANTS_RATINGS_RUN_CAP_USD` and
  `GUAM_RESTAURANTS_RATINGS_MONTHLY_CAP_USD` (both 0 by default: free tier only;
  docs/data-sources.md). The report does not change them.
- Also set a budget alert in Google Cloud Billing ($1 is enough while on the free tier),
  since it is the only source billed to a card.
