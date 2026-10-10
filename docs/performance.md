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

**Cacheable public REST routes.** `GET /wp-json/guam-restaurants/v1/map` (about 0.5 s
uncached with 1,740 restaurants) and the directory/ratings GETs send no cookies and can
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
