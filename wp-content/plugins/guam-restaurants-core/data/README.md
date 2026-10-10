# Plugin data files

## guam-villages.geojson

Boundaries of Guam's 19 villages, used by `Geo\Village_Locator` to tag
restaurants with their village from their coordinates.

- Source: US Census Bureau, TIGER/Line Shapefiles 2024, County Subdivisions,
  Guam (`tl_2024_66_cousub.zip`),
  <https://www2.census.gov/geo/tiger/TIGER2024/COUSUB/tl_2024_66_cousub.zip>.
  Downloaded 2026-10-10; zip SHA-256
  `f90487aa2f5cce7ad712c0c2ad8cf6478c1e46d31e6103e9b8af9f78cccd8cdd`.
- Licence: public domain (CC0 1.0 per data.gov). Credit "US Census Bureau
  TIGER/Line" on the About our data page.
- Built with `bin/build-village-geojson.py` (needs `pip install pyshp`). The
  open-water "County subdivision not defined" feature is dropped, coordinates
  are rounded to 6 decimals, nothing is simplified.
- Coordinates are NAD83 (EPSG:4269), within about a metre of WGS84 on Guam.
- Feature properties: `slug` (our village slug), `geoid` (Census GEOID),
  `census_name` (the Census name, which is the official Chamorro name for
  several villages).

To update: download the newer edition, run the script, run `make test`
(the locator tests check that all 19 villages are present and that each
Census interior point lands in its own village), and update this file.

## cuisine-rules.json

Our rules for cuisine tags, read by `Tagging\Cuisine_Tagger`. Edit freely;
`wp guam-restaurants retag --dry-run` shows what would change, and `retag`
applies it. Source categories (OSM `cuisine=` and `amenity=`) are mapped in
`Osm\Cuisine_Map`; these rules add tags from the restaurant's name.

- `name_rules`: each rule has a `cuisine` (a slug in the `gr_cuisine`
  taxonomy), `patterns` (words or phrases matched as whole words in the
  normalized name, accents and case ignored), and optional
  `"when": "no_source_cuisine"` so the rule only applies when no source gave
  a cuisine (used for generic words like "coffee").
- Tags added or removed by hand on the edit screen are kept as manual
  overrides; neither ingestion nor `retag` changes them. Each tag's origin
  (source, rule or manual) is shown in the "Tag sources" box.

## awards-template.csv

Header-only input for `wp guam-restaurants import-awards`. How to fill it,
and what may and may not be recorded: `awards-README.md`.
