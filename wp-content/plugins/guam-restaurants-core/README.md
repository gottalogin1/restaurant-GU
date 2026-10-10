# Guam Restaurants Core

All business logic for the directory lives here (CLAUDE.md rule 6); the theme only presents it.

- `guam-restaurants-core.php`: plugin header, constants, autoloader, activation hooks.
- `includes/`: classes in the `GuamRestaurants\Core` namespace. The autoloader maps
  `GuamRestaurants\Core\Imports\Osm_Importer` to `includes/imports/class-osm-importer.php`.
- `tests/integration/`: PHPUnit tests (file names end in `-test.php`). Run with `make test` from the repo root.
- `uninstall.php`: removes plugin options when the plugin is deleted. Restaurant content is kept.
