# Content model: guam-restaurants-core

Status: **approved by Sanjay on 2026-10-10 with every ★ option in §9.**
Implemented in `guam-restaurants-core`; see §10 for where the code differs
from this text.
Date: 2026-10-10. Checked against `CLAUDE.md`, `docs/data-sources.md`,
`docs/legal-and-policy.md`, and the project decisions on awards (mention
only) and inspection grades (skipped).

Legend: `+` marks a field I added to what was requested, `~` marks a
requested item I changed, `✗` marks a requested item I recommend not
building. Every change has its reason in §8.

Conventions (all defaults, easy to change):

- Prefix: post meta keys `grc_*`, tables `{$wpdb->prefix}grc_*`, options
  `grc_*`, REST namespace `grc/v1`.
- All timestamps are `DATETIME` in UTC. Display uses `Pacific/Guam`
  (ChST, UTC+10, no daylight saving).
- Tables are created by `dbDelta()` on activation and on a `grc_db_version`
  option bump. Enum-like columns are `VARCHAR` validated in PHP (dbDelta
  handles `ENUM` changes badly).

---

## 1. Overview diagram

```
                         ┌──────────────────────────────┐
                         │  CPT  restaurant  (wp_posts) │
                         │  title, content, excerpt,    │
                         │  featured image, slug        │
                         │  + post meta grc_*  (§3)     │
                         └──────────────┬───────────────┘
        taxonomies (wp_term_relationships)│        custom tables (restaurant_id → wp_posts.ID)
   ┌───────────────┬──────────────┬───────┴──────┬─────────────────┬───────────────────┐
   │ village (19)  │ mall (6, all │ cuisine      │ price_level     │                   │
   │ flat, 1 per   │ unverified)  │ hierarchical │ flat, 1 per     │                   │
   │ restaurant    │ flat, 0..1   │ 1..n         │ restaurant      │                   │
   │ term meta:    │ term meta:   │ term meta:   │ term meta:      │                   │
   │ geoid,aliases │ verified,    │ osm_keys,    │ rank            │                   │
   │ chamorro_name │ village, osm │ fsq_ids      │                 │                   │
   └───────────────┴──────────────┴──────────────┴─────────────────┘                   │
                                                                                        │
   restaurant 1 ──< grc_awards            (third-party award mentions, facts + URL)    │
   restaurant 1 ──< grc_highlights        (first-party text; owner response → parent)  │
   restaurant 1 ──< grc_change_log >── 0..1 grc_ingestion_runs                         │
   restaurant 0..1 ─< grc_review_queue >── 0..1 grc_ingestion_runs                     │
   ad (future CPT) 1 ──< grc_ad_daily_stats  (aggregated counters, no personal data)   │
                                                                                        │
   ✗ grc_rating_snapshots  → not created; live fetch / transients only (§8.1)          │
                                                                                        │
   data/guam-villages.geojson ── VillageLocator::slug_for(lat, lng) ── village term ───┘
```

---

## 2. Post type `restaurant`

| Setting | Value |
|---|---|
| `public` | true |
| `has_archive` | `restaurants` |
| `rewrite` | `['slug' => 'restaurant', 'with_front' => false]` → `/restaurant/{slug}/` |
| `show_in_rest` | true, `rest_base` `restaurants` |
| `supports` | title, editor, excerpt, thumbnail, custom-fields, revisions |
| `menu_icon` | `dashicons-food` |
| `capability_type` | `post` (custom caps later if owners get to edit their own listing) |
| `taxonomies` | village, mall, cuisine, price_level |

---

## 3. Post meta (`register_post_meta('restaurant', …)`, `single => true`)

REST column: **public** = in `view` context; **edit** = only in `edit`
context with an `auth_callback` requiring `edit_post`.

| Key | Type / schema | Default | REST | Notes |
|---|---|---|---|---|
| `grc_address` | string, max 255 | "" | public | Street line only; village comes from the taxonomy. |
| `grc_latitude` | number, −90..90 | null | public | Sanity check: Guam bbox 13.2..13.7 (warn, not reject). |
| `grc_longitude` | number, −180..180 | null | public | Bbox 144.6..145.0. |
| `+ grc_coord_source` | string enum `osm, fsq_os, owner, manual` | "" | edit | Google coords may not be stored past 30 days or used for point-in-polygon (§8.3). |
| `grc_phone` | string, pattern `^\+1671\d{7}$` | "" | public | Stored E.164, displayed `(671) xxx-xxxx`. |
| `grc_website` | string, format `uri` | "" | public | `esc_url_raw` on save. |
| `grc_hours` | object (schema below) | null | public | Structured weekly hours. |
| `grc_status` | string enum `open, temporarily_closed, permanently_closed, unknown` | `unknown` | public | |
| `grc_opened_on` | string, pattern `^\d{4}(-\d{2}(-\d{2})?)?$` | "" | public | Partial dates allowed (`2019`, `2019-06`), since many closure/opening dates are only known to the year. |
| `grc_closed_on` | same | "" | public | Required when status is `permanently_closed` (warn). |
| `grc_claimed_by_user_id` | integer ≥ 0 | 0 | edit | Never public (privacy). |
| `grc_google_place_id` | string | "" | edit | Storable indefinitely, but Google is "skip for now" (§8.2). |
| `grc_yelp_id` | string | "" | edit | Back-end matching only per Yelp §5(a); Yelp is "skip" (§8.2). |
| `grc_foursquare_id` | string | "" | public | FSQ OS Places id (Apache 2.0). |
| `grc_osm_id` | string, pattern `^(node|way|relation)/\d+$` | "" | public | Type is part of the id; needed for the attribution link. |

`grc_hours` schema:

```
{
  "type": "object",
  "properties": {
    "weekly": {                         // 0..n intervals; a day with none is closed
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "day":   { "enum": ["mo","tu","we","th","fr","sa","su"] },
          "open":  { "type": "string", "pattern": "^([01]\\d|2[0-3]):[0-5]\\d$" },
          "close": { "type": "string", "pattern": "^([01]\\d|2[0-4]):[0-5]\\d$" }  // close < open = past midnight
        },
        "required": ["day","open","close"]
      }
    },
    "always_open": { "type": "boolean" },
    "note":        { "type": "string", "maxLength": 200 },   // e.g. "Holiday hours vary", our own words
    "osm_raw":     { "type": "string" },                     // original opening_hours tag, for re-parsing
    "source":      { "enum": ["osm","owner","manual"] },
    "verified_on": { "type": "string", "format": "date" }
  }
}
```

---

## 4. Taxonomies

| Taxonomy | Hierarchical | REST | Rewrite | Cardinality (enforced in UI/import) |
|---|---|---|---|---|
| `village` | no | yes | `/village/{slug}/` | exactly 1 |
| `mall` | no | yes | `/mall/{slug}/` | 0 or 1 |
| `cuisine` | **yes** | yes | `/cuisine/{parent}/{child}/` | 1..n |
| `price_level` | no | yes | `/price/{slug}/` | 0 or 1 |

### 4.1 `village`: the 19 villages

Term meta: `grc_census_geoid` (string, joins to the GeoJSON), `grc_aliases`
(array of strings, searchable), `grc_chamorro_name` (string, display only).

| # | Name | Slug | Aliases (searchable) |
|---|---|---|---|
| 1 | Agana Heights | `agana-heights` | |
| 2 | Agat | `agat` | Hagåt |
| 3 | Asan-Maina | `asan-maina` | Asan, Maina |
| 4 | Barrigada | `barrigada` | Barrigada Heights |
| 5 | Chalan Pago-Ordot | `chalan-pago-ordot` | Chalan Pago, Ordot |
| 6 | Dededo | `dededo` | Liguan, Finegayan |
| 7 | Hagåtña | `hagatna` | Agana, Hagatna |
| 8 | Inarajan | `inarajan` | Inalåhan |
| 9 | Mangilao | `mangilao` | |
| 10 | Merizo | `merizo` | Malesso' |
| 11 | Mongmong-Toto-Maite | `mongmong-toto-maite` | Mongmong, Toto, Maite |
| 12 | Piti | `piti` | |
| 13 | Santa Rita | `santa-rita` | Sumay |
| 14 | Sinajana | `sinajana` | |
| 15 | Talofofo | `talofofo` | |
| 16 | Tamuning | `tamuning` | **Tumon**, Upper Tumon, Harmon |
| 17 | Umatac | `umatac` | Humåtak |
| 18 | Yigo | `yigo` | |
| 19 | Yona | `yona` | |

Aliases other than Tumon are my suggestions; confirm or trim them.
The `grc_census_geoid` values are filled from the TIGER file at build time
(the file's feature count must be 19, which also closes the open item in
`docs/data-sources.md` §12).

**Tumon alias, how it works.** There is no `tumon` term. A search hook
(`pre_get_posts` for front-end search, `rest_restaurant_query` for the REST
API) checks the query string against every village's `grc_aliases`
(case- and diacritic-insensitive); a match adds a `village` tax query for
that term, and `/village/tumon/` 301-redirects to `/village/tamuning/`.
So "tumon" finds Tamuning restaurants, but a Tumon-only list is not possible
(see question 3).

### 4.2 `mall`: seed requiring your confirmation

Term meta: `grc_verified` (bool, default **false**), `grc_village` (term id),
`grc_osm_id`, `grc_aliases`, `grc_verified_by`, `grc_verified_on`.

| Name | Slug | Village (my guess, unverified) | Aliases | Verified |
|---|---|---|---|---|
| Micronesia Mall | `micronesia-mall` | Dededo | | **no** |
| Guam Premier Outlets | `guam-premier-outlets` | Tamuning | GPO | **no** |
| Agana Shopping Center | `agana-shopping-center` | Hagåtña | ASC | **no** |
| Tumon Sands Plaza | `tumon-sands-plaza` | Tamuning | | **no** |
| The Plaza | `the-plaza` | Tamuning | The Plaza Shopping Center | **no** |
| Pacific Place | `pacific-place` | Tamuning | | **no** |

Unverified malls are seeded as terms but hidden from public archives,
filters, and the REST term list until you mark them verified (an admin
checkbox, logged with user and date). Mall assignment later uses OSM
`shop=mall` polygons by containment (`docs/data-sources.md` §1), falling
back to the review queue.

### 4.3 `cuisine`: seed (hierarchical, all top level for now)

Term meta: `grc_osm_cuisine` (array, OSM `cuisine=` values that map here,
used by the importer), `grc_fsq_category_ids` (array).

| Name | Slug | OSM `cuisine` / `amenity` mapping (importer hint) |
|---|---|---|
| Chamorro | `chamorro` | chamorro |
| Filipino | `filipino` | filipino |
| Japanese | `japanese` | japanese, sushi, ramen |
| Korean | `korean` | korean |
| Chinese | `chinese` | chinese |
| American | `american` | american, burger |
| Mexican | `mexican` | mexican, tex-mex |
| Italian | `italian` | italian |
| BBQ and grill | `bbq-and-grill` | barbecue, grill |
| Seafood | `seafood` | seafood, fish |
| Steakhouse | `steakhouse` | steak_house |
| Pizza | `pizza` | pizza |
| Bakery and dessert | `bakery-and-dessert` | dessert, donut, ice_cream (amenity), shop=bakery |
| Coffee and cafe | `coffee-and-cafe` | coffee_shop, amenity=cafe |
| Fast food | `fast-food` | amenity=fast_food |
| Bar and pub | `bar-and-pub` | amenity=bar, amenity=pub |
| Local plate lunch | `local-plate-lunch` | (none in OSM; manual) |

Children (for example Japanese › Ramen, Sushi) can be added later without a
migration.

### 4.4 `price_level`: seed (my default; not in the request)

Term meta: `grc_rank` (int 1–4, for sorting). Terms: `$` (`p1`, under $15),
`$$` (`p2`, $15–30), `$$$` (`p3`, $30–60), `$$$$` (`p4`, over $60), per person.
Values come from owners or editors only; Google and Yelp price fields may
not be stored.

---

## 5. Custom tables

All tables: `ENGINE=InnoDB`, `$wpdb->get_charset_collate()`, `id BIGINT
UNSIGNED NOT NULL AUTO_INCREMENT` primary key unless stated. No foreign-key
constraints (WordPress convention); integrity is kept in PHP, and deleting a
restaurant post deletes its rows via `before_delete_post`.

### 5.1 `grc_awards`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| restaurant_id | BIGINT UNSIGNED NOT NULL | → wp_posts.ID |
| program | VARCHAR(191) NOT NULL | "Pika's Best of Guam" |
| year | SMALLINT UNSIGNED NOT NULL | |
| category | VARCHAR(191) NOT NULL DEFAULT '' | Fact, as named by the program |
| placement | VARCHAR(20) NOT NULL | `winner, finalist, other` |
| source_url | VARCHAR(2048) NOT NULL | Page that **mentioned** the award |
| source_note | TEXT NULL | Our own words |
| verified | TINYINT(1) NOT NULL DEFAULT 0 | 1 only if checked against the awarding organization |
| + created_at, updated_at | DATETIME | |

Indexes: `UNIQUE uniq_award (restaurant_id, program, year, category)` (makes
imports idempotent), `KEY restaurant_year (restaurant_id, year)`,
`KEY program_year (program, year)`.

Per the 2026-10-09 decision, Pika's awards are recorded only when another
source mentions them, with that page's URL, and stay `verified = 0`.

### 5.2 `grc_rating_snapshots`: ✗ not created

See §8.1. Replacement: `RatingProvider` interface per source with a
`max_cache_seconds()` taken from that source's terms; any allowed cache is a
transient `grc_rating_{source}_{post_id}`; nothing is ever written to a
table.

| Source | Max server cache | Status |
|---|---|---|
| Google Places | 0 (live per view) | Skipped (directory-use bar, ToS §3.2.3(d)(iii)) |
| Yelp | 86,400 s (24 h) | Skipped (needs written commercial consent) |
| Foursquare API (pay-as-you-go) | 0 (live per view) | Optional, not planned |

So at launch no third-party ratings are shown at all, and no provider
class is built until a source is approved.

### 5.3 `grc_change_log`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| restaurant_id | BIGINT UNSIGNED NOT NULL | |
| field | VARCHAR(64) NOT NULL | Meta key, `title`, or `tax:village` etc. |
| old_value | LONGTEXT NULL | JSON-encoded for objects |
| new_value | LONGTEXT NULL | |
| source | VARCHAR(32) NOT NULL | `osm, fsq_os, owner, editor, census` (never `google`/`yelp`) |
| detected_at | DATETIME NOT NULL | |
| + run_id | BIGINT UNSIGNED NULL | → grc_ingestion_runs.id when written by an import |

Indexes: `KEY restaurant_detected (restaurant_id, detected_at)`,
`KEY field_detected (field, detected_at)`, `KEY run_id (run_id)`.

### 5.4 `grc_ingestion_runs`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| source | VARCHAR(32) NOT NULL | |
| started_at | DATETIME NOT NULL | |
| finished_at | DATETIME NULL | NULL = running or crashed |
| created, updated, unchanged, failed | INT UNSIGNED NOT NULL DEFAULT 0 | CLAUDE.md rule 9 counts |
| notes | TEXT NULL | |

Indexes: `KEY source_started (source, started_at)`.

### 5.5 `grc_review_queue`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| type | VARCHAR(32) NOT NULL | `new_place, match_conflict, village_unresolved, mall_unresolved, closure_signal, owner_submission` |
| payload | LONGTEXT NOT NULL | JSON; never Google or Yelp content |
| confidence | DECIMAL(4,3) NULL | 0.000–1.000 |
| status | VARCHAR(20) NOT NULL DEFAULT 'pending' | `pending, approved, rejected, merged` |
| created_at | DATETIME NOT NULL | |
| + restaurant_id | BIGINT UNSIGNED NULL | Null for brand-new places |
| + source | VARCHAR(32) NOT NULL | |
| + run_id | BIGINT UNSIGNED NULL | |
| + resolved_by, resolved_at | BIGINT UNSIGNED NULL, DATETIME NULL | |

Indexes: `KEY status_type (status, type, created_at)`,
`KEY restaurant_id (restaurant_id)`. Retention: owner-submission payloads
(may hold names, emails) are purged 24 months after resolution
(`docs/legal-and-policy.md` §5.2 item 6) by a daily cron.

### 5.6 `grc_highlights`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| restaurant_id | BIGINT UNSIGNED NOT NULL | |
| type | VARCHAR(20) NOT NULL | `owner_quote, editor_note, user_review, + owner_response` |
| body | TEXT NOT NULL | |
| author_name | VARCHAR(191) NOT NULL | Display name only |
| status | VARCHAR(20) NOT NULL DEFAULT 'pending' | `pending, approved, rejected` |
| created_at | DATETIME NOT NULL | |
| + parent_id | BIGINT UNSIGNED NULL | Owner response → the user_review it answers (one per review) |
| + author_user_id | BIGINT UNSIGNED NULL | Never exposed publicly |
| + disclosure | VARCHAR(255) NULL | Material connection or insider relationship, shown next to the text |
| + moderation_reason | VARCHAR(32) NULL | Required when `rejected`; one of the published rule ids (`off_topic, wrong_business, hate_speech, personal_info, undisclosed_connection, fake`) |
| + moderated_by, moderated_at | BIGINT UNSIGNED NULL, DATETIME NULL | Audit trail |
| + updated_at | DATETIME NULL | |

Indexes: `KEY restaurant_status_type (restaurant_id, status, type)`,
`KEY status_created (status, created_at)`, `KEY parent_id (parent_id)`.

### 5.7 `grc_ad_events` → ~ `grc_ad_daily_stats`

| Column | Type | Notes |
|---|---|---|
| ad_id | BIGINT UNSIGNED NOT NULL | → future ad post type (out of scope here) |
| stat_date | DATE NOT NULL | Guam local date |
| page_type | VARCHAR(32) NOT NULL | `home, restaurant, village, mall, cuisine, search, other` |
| impressions | INT UNSIGNED NOT NULL DEFAULT 0 | |
| clicks | INT UNSIGNED NOT NULL DEFAULT 0 | |

Keys: `PRIMARY KEY (ad_id, stat_date, page_type)`, `KEY stat_date (stat_date)`.
Writes are `INSERT … ON DUPLICATE KEY UPDATE impressions = impressions + 1`.
No IP, user agent, cookie, referrer, user id, or per-event timestamp is
stored. Impressions are counted by a small cookie-free beacon to
`POST /grc/v1/ad-stats` (server-side counting at render time would be
wrong once W3 Total Cache serves cached pages); clicks go through
`/go/ad/{id}` which increments and redirects. Known bots are dropped by
user-agent check before counting, and the user agent is not stored.

---

## 6. Village lookup helper (plan only)

**Data file.** `guam-restaurants-core/data/guam-villages.geojson`, built
once at development time from TIGER/Line County Subdivisions for Guam
(state FIPS 66, `tl_2024_66_cousub.zip`, or the 2025 edition if published).
Conversion: shapefile → GeoJSON (EPSG:4326) with `ogr2ogr` or mapshaper,
coordinates rounded to 6 decimals (~0.1 m), no simplification (coastal
villages are thin). Feature properties kept: `GEOID`, `NAME`, plus our
`slug`. A `data/README.md` records the source URL, edition, download date,
and CC0 licence. The build script lives in `bin/`, not in the runtime.
`www2.census.gov` was blocked from this environment during research; if it
still is at build time I'll ask you to download the zip or fetch it through
a folder on your device.

**Class** `GRC\Geo\VillageLocator`:

```
VillageLocator::slug_for( float $lat, float $lng ): ?string
  1. reject non-finite input → null
  2. load GeoJSON once per request (static), cached parsed in a
     non-expiring option keyed by file hash so it isn't re-parsed each request
  3. for each feature: bbox pre-check, then ray-casting (even-odd) over
     every ring of every polygon of a (Multi)Polygon; inside outer ring
     and not inside any hole → match
  4. return the matching feature's slug, else null (offshore / outside Guam)

VillageLocator::assign( int $post_id ): ?string
  - uses grc_latitude/grc_longitude only when grc_coord_source is
    osm, fsq_os, owner or manual
  - match → set village term (logs to grc_change_log if it changed)
  - null  → grc_review_queue item of type village_unresolved
```

Points exactly on a shared boundary resolve to the first match in GEOID
order (deterministic). No nearest-polygon fallback: a beachfront point that
falls in the water goes to the review queue instead of being guessed.

WP-CLI: `wp grc villages seed` (terms + term meta, idempotent) and
`wp grc villages assign [--dry-run] [--only-missing]` (logs an ingestion
run with created/updated/unchanged/failed).

**PHPUnit tests** (`tests/Geo/VillageLocatorTest.php`):

| Test | Input | Expected |
|---|---|---|
| Known Dededo point (village core near the Dededo Mall/municipal area) | 13.5180, 144.8390 | `dededo` |
| Tumon hotel row | 13.5140, 144.8040 | `tamuning` |
| Hagåtña, near the Plaza de España | 13.4757, 144.7497 | `hagatna` |
| Philippine Sea west of Tumon | 13.52, 144.70 | null |
| Far outside Guam | 0, 0 | null |
| Non-finite input | NAN, INF | null |
| Synthetic square with a hole (fixture GeoJSON) | inside hole / inside ring | null / slug |
| Synthetic MultiPolygon | second polygon | slug |
| Seed file sanity | feature count | 19, all slugs match the village terms |
| Alias search | query "tumon" | resolves to term `tamuning` |

The three real coordinates are from my own knowledge of Guam; I'll confirm
each against the actual polygons when the file is in place and adjust the
point (not the expected village) if one sits near a boundary.

---

## 7. Seeding

All seeds run through idempotent WP-CLI commands (`wp grc seed villages`,
`malls`, `cuisines`, `price-levels`), matched by slug, so re-running never
duplicates. Mall seeding writes `grc_verified = 0` and never flips an
existing verified mall back.

---

## 8. Where the request conflicts with the docs or CLAUDE.md

1. **`rating_snapshots` is not created.** `docs/data-sources.md` §7 Q3: no
   source permits storing rating history. Google forbids caching ratings at
   all, Yelp caps storage at 24 hours, Foursquare pay-as-you-go forbids
   server caching. Google and Yelp are both "skip". So: no table; a
   transient keyed by source with that source's maximum TTL, and for
   Google/Foursquare no cache at all. Also, a page showing a live Google
   rating must not be stored in W3 Total Cache's full-page cache, so live
   ratings would have to load client-side. A "rating over time" chart can
   only use first-party data.
2. **`google_place_id` and `yelp_id`.** Storing them is allowed (place ID
   indefinitely, Yelp ID only for back-end matching), but both sources are
   skipped, so the fields stay empty and are not public in REST.
3. **Coordinates.** Google lat/lng can be cached only 30 days and may not be
   used for point-in-polygon (§2, ToS §3.2.3(c)(iv)), hence `+ grc_coord_source`
   and the locator's refusal to use other sources.
4. **OSM share-alike.** `docs/data-sources.md` §1 suggests keeping
   OSM-derived fields in a separate layer keyed by OSM id. The requested
   model puts OSM facts (address, phone, hours, coordinates) in post meta
   next to owner-submitted values, which makes those fact fields a derivative
   database that must be offered under the ODbL if published. Editorial copy,
   awards, highlights, and ad data stay separate either way (question 1).
5. **`user_review` highlights vs. rule 4.** Rule 4 covers third-party API
   reviews, so first-party user reviews are allowed, but
   `docs/legal-and-policy.md` §2.3 and the draft review policy require:
   positive and negative published alike; rejection only under a published
   rule (`+ moderation_reason`); insider reviews disclosed or rejected
   (§465.5, `+ disclosure`); one labeled owner response (`+ parent_id`); never
   removed because an owner or advertiser asks. Editor notes need a
   disclosure when a meal was hosted (§255.5). Sponsored status may never
   change highlight status (rule 5).
6. **`ad_events` as requested is per-event, not aggregated.** One row per
   impression with `occurred_at` is event-level logging. Daily counters
   satisfy "aggregated, no personal data" exactly, are far smaller, and fit
   the privacy baseline (`docs/legal-and-policy.md` §5.2). Hence the swap to
   `grc_ad_daily_stats`.
7. **Awards `verified`.** Per the 2026-10-09 decision, Pika's awards are
   mention-only, unvalidated; `source_url` is the mentioning page and
   `verified` stays 0. Full winner imports stay on hold.
8. **Tumon.** `docs/data-sources.md` §6 suggested Tumon/Harmon as our own
   "area" tags inside Tamuning; the request makes Tumon a search alias.
   I followed the request.
9. **`claimed_by_user_id`** is personal data and is kept out of public REST.

---

## 9. Open questions (recommendation marked ★)

1. OSM facts in post meta: **★ keep in post meta and accept ODbL for those
   fact fields** / add a separate `grc_osm_features` layer table.
2. Ad tracking: **★ daily counters (`grc_ad_daily_stats`)** / per-event
   `grc_ad_events` as requested.
3. Tumon: **★ search alias only (as asked)** / also an "area" sub-tag so
   `/area/tumon/` lists only Tumon.
4. Unverified malls: **★ hidden from the public until verified** / shown
   with a "not yet verified" note.
5. Price level: **★ seed `$`–`$$$$` per person as above** / different bands.
6. Village aliases beyond Tumon: **★ keep the suggested list** / Tumon only.

---

## 10. Implementation notes (2026-10-10)

- **Names.** The plugin skeleton already used the `gr_` prefix, so the code
  uses `gr_restaurant`, `gr_village`, `gr_mall`, `gr_cuisine`,
  `gr_price_level`, meta and term meta `gr_*`, tables `{prefix}gr_*`, and
  options `guam_restaurants_core_*` instead of `restaurant`/`grc_*`. The
  archive stays at `/restaurants/` and single pages at `/restaurants/{slug}/`
  as set up earlier. WP-CLI commands are `wp guam seed` and
  `wp guam villages assign`.
- **Coordinates over REST.** WordPress REST meta cannot be nullable, so
  unset latitude/longitude read as 0 over REST. Guam is nowhere near 0,0.
- **Census names.** The TIGER file uses official Chamorro names for seven
  villages; they are stored in `gr_chamorro_name` and as aliases, and the
  file maps to our slugs by GEOID. Census interior points for all 19
  villages are checked in the tests.
- **Mall village guesses.** Micronesia Mall's seeded village (Dededo) is a
  guess; a rough coordinate for it fell just inside Tamuning in the Census
  boundaries. Confirm when verifying the mall.
- **Parsed boundaries** are cached per request only, not in an option: the
  locator runs in WP-CLI imports, not on page views.
- **Not built yet** (no feature uses them): the ad impression beacon and
  click redirect, the 24-month review-queue purge cron, rating providers,
  and admin screens for awards, highlights and the review queue. The
  tables and allowed values (`Vocabulary`) are in place for them.
- A single unverified mall can still be read by ID over REST; it is hidden
  from term lists, restaurant term lists, and its archive (404).
