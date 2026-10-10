# Guam Restaurants Core

All business logic for the directory lives here (CLAUDE.md rule 6); the theme only presents it.

- `guam-restaurants-core.php`: plugin header, constants, autoloader, activation hooks.
- `includes/`: classes in the `GuamRestaurants\Core` namespace. The autoloader maps
  `GuamRestaurants\Core\Imports\Osm_Importer` to `includes/imports/class-osm-importer.php`.
- `tests/integration/`: PHPUnit tests (file names end in `-test.php`). Run with `make test` from the repo root.
- `uninstall.php`: removes plugin options when the plugin is deleted. Restaurant content is kept.
- `data/`: bundled data files (village boundaries); see `data/README.md`.

## Content model

Design and reasoning: `docs/content-model.md`.

- Post type `gr_restaurant` with meta `gr_*` (`Restaurant_Meta`). Private fields
  (claimed-by user, Google and Yelp ids, coordinate source) appear only in the
  REST `edit` context.
- Taxonomies `gr_village` (19 villages; aliases such as Tumon in term meta),
  `gr_mall` (hidden from visitors until verified), `gr_cuisine` (hierarchical),
  `gr_price_level`. Seeded on activation and by `wp guam seed`.
- Tables `{prefix}gr_awards`, `gr_change_log`, `gr_ingestion_runs`,
  `gr_review_queue`, `gr_highlights`, `gr_ad_daily_stats` (`Database\Schema`).
  There is no rating snapshot table: no source permits storing rating history.
- `wp guam villages assign [--dry-run] [--only-missing]` tags restaurants
  with their village from OSM, Foursquare OS, owner or manual coordinates.


## Imports

Run `wp guam seed` first. Both commands log created, updated, unchanged and
failed counts and record each run in `gr_ingestion_runs` (CLAUDE.md rule 9).

- `wp guam-restaurants import-osm [--dry-run] [--input=<file>] [--save-response=<file>] [--post-status=<status>] [--mall-radius=<metres>]`
  imports eating and drinking places from OpenStreetMap (`Osm\Importer`).
  Upserts by `gr_osm_id`, maps cuisines with `data/osm-cuisine-map.json`
  and lists unmapped values, sets the village from the Census boundaries
  and the mall from OSM mall outlines (or a radius around a mall point),
  and writes changes to `gr_change_log`. One Overpass request per run with
  an identifying user agent and up to 5 retries. `--input` imports a saved
  Overpass response (for example one downloaded from overpass-turbo.eu
  with the query from `Osm\Overpass_Client::query()`).
- `wp guam-restaurants discover [--dry-run] [--scope=<all|villages|malls>] [--max-pages=<n>] [--delay=<seconds>]`
  runs Google Places Text Search per village and per mall and adds places
  we do not list to `gr_review_queue` as `new_place` candidates, storing
  only the place ID. Never creates posts. Needs
  `GUAM_RESTAURANTS_GOOGLE_PLACES_KEY` (wp-config.php constant or
  environment variable).

## Ratings

Rules come from `docs/data-sources.md` §7 and §9. Only each source's ID is
stored permanently; no rating history is kept.

- `wp guam-restaurants match-ratings --source=<google|yelp|foursquare> [--limit=<n>] [--rematch] [--dry-run] [--estimate-only] [--delay=<seconds>]`
  matches restaurants by name and distance (150 m). Clear matches store the
  ID and `gr_<source>_match_confidence`; others go to the review queue as
  `match_conflict`.
- `wp guam-restaurants refresh-ratings [--estimate-only]` refreshes cached
  Yelp ratings now; WP-Cron runs it twice a day.
- Every API call is counted per billing unit for the month (`Ratings\Budget`)
  and is free until 90% of the source's free monthly allowance is used
  (`Ratings\Pricing`). Both caps (`GUAM_RESTAURANTS_RATINGS_RUN_CAP_USD`,
  `GUAM_RESTAURANTS_RATINGS_MONTHLY_CAP_USD`) default to $0, so by default
  no paid call is made: matching and `discover` stop, and live ratings hide
  until next month. Commands print the estimate (free and billable requests)
  first. Free in practice: Google matching (4,500 searches a month) and about
  1,800 Google rating views a month (900 with reviews, then 900 without).
  Foursquare ratings and Yelp have no free tier, so they never show at $0.
- `GuamRestaurants\Core\get_ratings( $restaurant_id, $args )` returns one
  entry per source (never a combined score). Google and Foursquare are
  fetched live; Yelp comes from a cache of at most 24 hours.
- Block `guam-restaurants/ratings` and shortcode
  `[guam_restaurant_ratings id="" sources="google,foursquare" reviews="yes"]`
  print a placeholder filled in the browser from
  `GET /wp-json/guam-restaurants/v1/restaurants/{id}/ratings`, so page caches
  never store ratings. Yelp is shown only on its own (`sources="yelp"`), in a
  page section with no other ratings or visitor content. Pages that show
  Google ratings must use a Google map (Maps Embed), not MapTiler.

## Visitor reviews

- Block `guam-restaurants/visitor-reviews` and shortcode
  `[guam_visitor_reviews id="" form="yes" trend="yes"]` show approved visitor
  reviews (newest first, positive and negative alike), a monthly trend chart
  of their average rating, and a submission form. Content is loaded from
  `/wp-json/guam-restaurants/v1/restaurants/{id}/visitor-reviews`.
- New reviews wait in Restaurants > Visitor reviews. Rejecting one requires a
  published rule. Do not place this block in the same page section as Yelp.
- Behind a proxy or CDN, return the visitor's real IP from the
  `guam_restaurants_review_client_ip` filter so rate limits work.
