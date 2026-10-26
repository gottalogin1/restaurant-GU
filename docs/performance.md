# Performance

Production performance setup, 2026-10-10. Measured on the local Docker stack with
1,740 sample restaurants (about four times the real directory), no object cache and
no page cache, so live numbers should be better.

## 1. Object cache

The directory and map run many small option, term and meta lookups; the plugin also
keeps results in transients (map points, related links, ratings summaries). With a
persistent object cache those stay in memory instead of the `wp_options` table.

- **Host Redis first.** Kinsta, WP Engine, Cloudways and most managed hosts offer
  Redis as an add-on with their own drop-in. Use it when available.
- **Otherwise W3 Total Cache (owned, 2.10.7):** Performance > General Settings >
  Object Cache: on, method **Redis** (or Memcached, whichever the server has). Database
  Cache: **off** (it duplicates the object cache and can serve stale admin data).
- Check: `wp cache type` should print `Redis`/`Memcached`, and `wp guam-restaurants
  preflight` shows "Object cache: ok".
- Cache flushes happen on deploy (`bin/deploy.sh`) and after imports; the plugin
  clears its own transients when a restaurant, award or tag changes.

## 2. Page cache rules

Use the host's page cache if it has one (Kinsta, WP Engine), otherwise W3TC
Page Cache "Disk: Enhanced". Either way, these must **never** be cached:

| Exclude | Why |
|---|---|
| Logged-in users (`wordpress_logged_in_*` cookie) and `wp-admin/`, `wp-login.php` | Owners and staff see personal screens and the admin bar. |
| WooCommerce cookies `woocommerce_items_in_cart`, `wp_woocommerce_session_*` and `/cart/`, `/checkout/`, `/my-account/` | Premium profile checkout. |
| `/owner-dashboard/`, `/claim/` (claim and owner pages) | Per-owner content. |
| `/wp-json/` POST requests | Forms, beacons, owner actions. Caching is for GET only. |
| Any URL with `open=now` | "Open now" changes by the minute. |
| `/feed/` for 15 minutes at most | New restaurants and awards. |
| `?s=` search | Unbounded variations. |

W3TC: Page Cache > Advanced > "Never cache the following pages" (one per line):

```
wp-admin
wp-login.php
cart
checkout
my-account
owner-dashboard
claim
open=now
```

and "Rejected cookies": `wordpress_logged_in`, `woocommerce_items_in_cart`,
`wp_woocommerce_session`. Leave "Don't cache pages for logged in users" ticked.

**Cacheable public REST routes.** `GET /wp-json/guam-restaurants/v1/map` (about 0.3 s
uncached with 1,740 restaurants, §6) and the directory/ratings GETs send no cookies and can
be cached for 10 minutes at the CDN (§5). The health endpoint sends `Cache-Control:
no-store` and must never be cached.

Public forms load their nonce over REST, so cached pages keep working.

## 3. Images: ShortPixel (owned)

- Free plan: 100 credits a month, and **each thumbnail size counts**. Settings >
  ShortPixel: compression **Lossy**, "Also include thumbnails" on, but under Advanced
  exclude sizes the theme does not use, create **WebP** (served with the
  `<picture>` tag), resize large originals to 2048 px, keep EXIF off.
- Owner photo uploads are capped at 5 MB and site uploads at 10 MB and 40 megapixels
  (docs/security.md §2).
- The theme already uses `loading="lazy"` and `srcset` through core image blocks.
  Restaurant cards use the `medium_large` size.
- If the monthly credits run out, ShortPixel stops quietly; buy a one-time credit pack
  rather than a subscription until volume justifies it.

## 4. Query review (EXPLAIN on the slowest five)

Method: query log on the local stack, every public template and REST route visited,
the five slowest distinct queries run again under `EXPLAIN` (MariaDB, 1,740
restaurants, about 63,000 postmeta rows).

| # | Query (where) | Time | EXPLAIN | Note |
|---|---|---|---|---|
| 1 | Map: IDs of all published restaurants with coordinates (`Site\Maps`) | 12–18 ms | `ALL` on posts + filesort | Runs once per cache period; result cached in a transient. |
| 2 | Change log "first seen" lookup (`gr_change_log`, field = `first_seen`) | 13–17 ms | Full scan; the `field_detected` index is skipped because 25% of rows match; filesort | Admin and the "New restaurants" block only. |
| 3 | "Open now" archive (`gr_open_hours` join) | 13 ms | `type_status_date` range on posts, PK lookup with FirstMatch on open hours | Uses indexes as intended. |
| 4 | Default directory archive, page 5, sorted by title | 7–9 ms | filesort on title | Normal for WordPress title sorting. |
| 5 | Taxonomy archive (cuisine/village term join) | 6–9 ms | temporary + filesort | Core `WP_Query` term query. |

Also seen: the related-links cuisine count (5–8 ms) and the "not closed" `NOT EXISTS`
subquery on `gr_status`, which reads about 1,740 meta rows on each directory query.

Page timings without caches: map REST route about 0.5 s, other pages 0.07–0.2 s.

**Decision:** no new indexes now. Every query is under 20 ms at four times the real
size, and the object cache removes most of them from repeat views. Revisit when the
directory passes 10,000 restaurants or any query passes 50 ms; the first candidates
are a `(field, detected_at)` composite index on `gr_change_log` and storing "closed"
as a taxonomy term instead of meta.

To repeat: turn on `SAVEQUERIES`, visit the pages, sort `$wpdb->queries` by time, and
`EXPLAIN` the top five with `wp db query`.

## 5. CDN

Recommended: **Cloudflare free plan** in front of the host (it has edge locations in
Guam and nearby; most visitors are on island). Kinsta includes Cloudflare already.

1. Add the site to Cloudflare, change the domain's name servers, SSL mode **Full
   (strict)**.
2. Caching > Cache Rules:
   - Static files (`/wp-content/`, `/wp-includes/`): edge TTL 1 month, browser TTL 1 year
     (file names carry `?ver=`).
   - `GET /wp-json/guam-restaurants/v1/map*` and `/v1/restaurants*`: eligible for cache,
     edge TTL 10 minutes.
   - HTML: cache only when no `wordpress_logged_in`, `wp_woocommerce_session` or
     `woocommerce_items_in_cart` cookie, and bypass the paths in §2.
3. Rate limiting and real client IPs: docs/security.md §5
   (`GUAM_RESTAURANTS_CLIENT_IP_HEADER=HTTP_CF_CONNECTING_IP`).
4. Purge after deploys: set `DEPLOY_PURGE_CMD` in `deploy/production.env` (a
   Cloudflare API call or the host's purge command).
5. Make sure `.mjs` files go out as `text/javascript` (the map library; check with
   `curl -I .../maplibre-gl.mjs`).

## 6. Speed pass, 2026-10-26

Requested by Sanjay: make the plugin run faster and cut load times. Same setup as
above: local Docker stack, 1,740 sample restaurants (`bin/generate-sample-restaurants.php`)
with menu dishes on 400 of them, no object cache, no page cache. Server times are the
median of 7 requests after one warm-up, measured from WordPress start to shutdown.

### What was slow

1. **The New badge.** Every card and restaurant page asks whether the restaurant is
   new. The answer came from `Queries::recent_openings()` with no limit, which loaded
   every restaurant in the change log (post, all meta, village and mall terms, permalink)
   to keep about 80 of them. That was about 380 queries and 300 ms on every page with a
   card, and it grew with the whole directory, not with the new places.
2. **Cards loaded their facts one restaurant at a time.** Rows on the home page asked
   for IDs only, so each card then fetched its post, meta and terms on its own, and
   every card ran its own query for an approved visitor photo.
3. **Dish pages** read the title of every restaurant in the dish index (400 single-post
   queries) and ran the photo query for each of the 200 cards.
4. **WooCommerce assets on every page.** WooCommerce (only for premium profile
   checkout) added 5 stylesheets and 7 scripts to every public page, and with them
   jQuery and jQuery Migrate in the page head, which block the first paint. The order
   attribution scripts also set `sbjs_*` cookies on every visit.

### What changed

- `Queries::recent_opening_ids()` returns the IDs only: one change-log query plus one
  query that drops trashed and permanently closed places. `recent_openings()` now drops
  the baseline rows (a source's first run) before it loads anything, so the home page
  row and the newsletter load only real openings. Results are the same as before.
- `Restaurant_View::prime()` loads posts, meta, terms, term meta, featured images and
  the newest approved upload (`Photo_Submissions::newest_approved()`, one query) for a
  whole list. Restaurant rows, directory results (page and REST), saved lists and dish
  pages call it before they print cards.
- `Term_Slugs::for_post()` reads the term cache when a post's terms are already loaded.
- `Woo_Checkout::drop_shop_assets()` takes WooCommerce's scripts and styles off every
  page except shop pages (cart, checkout, My Account, products). Public pages no longer
  load jQuery. Order attribution now only sees visits to shop pages.

### Server time and queries, before and after

| Page | Before | After |
|---|---|---|
| Home | 636 ms, 539 queries | 181 ms, 121 queries |
| Directory | 576 ms, 501 | 155 ms, 83 |
| Directory, page 5 | 556 ms, 503 | 154 ms, 83 |
| Directory, Thai + $ | 508 ms, 511 | 150 ms, 91 |
| Restaurant page | 568 ms, 496 | 141 ms, 100 |
| Village page (Dededo) | 591 ms, 496 | 144 ms, 95 |
| Cuisine page (Thai) | 594 ms, 495 | 148 ms, 94 |
| Map page | 125 ms, 79 | 115 ms, 79 |
| Map JSON (REST, 1,740 points) | 358 ms, 71 | 322 ms, 71 |
| Directory REST (filter change) | 532 ms, 469 | 113 ms, 62 |
| Dishes index | 105 ms, 72 | 105 ms, 72 |
| Dish page (kelaguen, 200 places) | 883 ms, 1,087 | 193 ms, 82 |
| What should I eat? | 134 ms, 80 | 122 ms, 80 |
| Recently opened and closed | 398 ms, 333 | 153 ms, 85 |
| /ja/ home | 634 ms, 522 | 179–211 ms, 121 |
| /ja/ restaurant page | 539 ms, 479 | 174 ms, 100 |
| /ko/ village page | 561 ms, 496 | 172 ms, 95 |

### In the browser, before and after

Chromium with phone emulation, CPU slowed 4 times, 1.6 Mbit/s and 150 ms latency
(close to Lighthouse's mobile setting), empty cache, median of 3 loads. FCP is first
contentful paint; on these pages it is also the largest contentful paint.

| Page | FCP | Transferred | Requests |
|---|---|---|---|
| Home | 1,664 → 748 ms | 135 → 62 KB | 25 → 12 |
| Directory | 1,468 → 532 ms | 143 → 69 KB | 27 → 14 |
| Restaurant page | 1,548 → 656 ms | 153 → 79 KB | 34 → 21 |
| Dish page | 1,736 → 604 ms | 152 → 79 KB | 27 → 14 |
| /ja/ home | 1,752 → 692 ms | 137 → 64 KB | 25 → 12 |

Full-page screenshots of the home, directory (390 px), restaurant (1280 and 390 px),
dish and policy pages are pixel-identical before and after.

### Left as they are

- **Map JSON** (about 0.3 s uncached): most of the time is loading every restaurant's
  meta and terms. It is sent with `Cache-Control: public, max-age=300` and kept in the
  object cache, so with Cloudflare (§5) or Redis (§1) visitors rarely wait for it.
- **Dish pages list every place on one page** (360 KB of HTML at 200 places). Paging
  them would change how the page works; revisit when a dish passes about 100 places.
- **The rest of a page's time** (about 110 ms) is WordPress, the block theme and
  WooCommerce starting up. A page cache (§2) and an object cache (§1) remove most of
  it on repeat views; both are still open items in docs/launch-checklist.md.

To repeat: the profiling helpers are not in the repository. Log `timer_stop()`,
`$wpdb->num_queries` and, with `SAVEQUERIES`, each query's caller from a `shutdown`
action in a must-use plugin on the local stack, then group the queries by caller.
