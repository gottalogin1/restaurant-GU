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

