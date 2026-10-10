# Progress log

## Awards research (docs/awards-research.md), 2026-10-09
- Done: Pika's Best of Guam (PDN) start year 2014, annual cycle, full 2023 categories/winners/finalists source located.
- Checked: guampdn.biz pikasbestofguam index + 2023 PDF, guampdn.biz advertising page and WP API, Wikipedia PDN.
- Remaining: 2014-2022 and 2024-2025 results; guampdn.com and Internet Archive were blocked from the cloud environment, so check by hand (URLs in awards-research.md section 7). Send partnership email (section 8).

## Legal and policy (docs/legal-and-policy.md), 2026-10-09
- Done: non-lawyer summary of FTC endorsement and review rules (16 CFR 255, 465), ad disclosure, Guam 5 GCA Ch. 32 and 9 GCA Ch. 48, visitor-tracking privacy, owner removal handling; draft editorial independence policy; 10 questions for a Guam attorney.
- Checked: eCFR Parts 255 and 465, FTC review-rule Q&A, platform review guide, native ad guide, 2013 search-ad letter, CAN-SPAM guide, Justia Guam code chapters.
- Remaining: attorney review; confirm current inflation-adjusted penalty, Japan/Korea privacy reach, any pending Guam privacy bill. Note: doc recommends the badge "Sponsored · Ad" since FTC calls "Sponsored by" ambiguous (CLAUDE.md rule 5 says "Sponsored").

## Data sources (docs/data-sources.md), 2026-10-09
- Done: terms, pricing, caching and display rules for OSM/Overpass, Google Places (New), Yelp Places, Foursquare API + OS Places, DPHSS inspection PDFs, TIGER/Line Guam; answers to the five caching/display questions; data policy table; Pika's Best added to the register.
- Key findings: Google ToS 3.2.3(d)(iii) bars Core Services "in a listings or directory service" (recommend skip); Yelp needs written consent for commercial use, 24h cache cap; no source allows storing third-party rating history.
- Remaining: Guam coverage not measured for any source (all data APIs blocked from the cloud environment, no API keys). Run the Overpass queries in data-sources.md section 1 by hand; download TIGER file and confirm 19 villages; verify Yelp display requirements by hand if Yelp is reconsidered.

## Repo setup, 2026-10-09
- Done: CLAUDE.md with the project description and 12 standing rules; researched docs added to the repo.
- Remaining: two more award sources found but not yet checked: Stars and Stripes Guam "Best of Pacific" dining winners (https://guam.stripes.com/best-of-pacific, 2025-26 and 2026-27 lists) and The Guam Guide reader poll archive (https://archives.theguamguide.com/best-guam-reader-poll-food-drink/, about 2013-2014). Check terms and add to the register in data-sources.md if used.
- Remaining: add API keys to .env.example once the first API is chosen.

## WordPress project setup, 2026-10-09
- Done: Docker Compose stack (WordPress 7.1.3 on PHP 8.3 Apache, MariaDB 11.4, WP-CLI 2.12, Composer), Makefile, .env.example.
- Done: plugin skeleton `wp-content/plugins/guam-restaurants-core` (WordPress-style autoloader, activation/deactivation hooks, uninstall.php, `gr_restaurant` post type with `gr_village`, `gr_mall`, `gr_cuisine` taxonomies) and PHPUnit 9.6 with the WordPress test library (wp-phpunit) against a separate `wordpress_test` database.
- Done: block theme skeleton `wp-content/themes/guam-restaurants-theme` (theme.json, index/single/404 templates, header/footer parts), presentation only.
- Done: PHPCS with WordPress Coding Standards 3 and PHPCompatibilityWP (PHP 8.1+, WP 6.6+); `.githooks/pre-commit` runs PHPCS on staged PHP files; GitHub Actions CI runs PHPCS, installs the site, checks plugin activation, and runs PHPUnit.
- Done: README setup steps; docs/hosting.md (recommend Kinsta single site in Tokyo, about $30-35/month); docs/plugins.md (Yoast SEO, Happyforms, W3 Total Cache only where the host has no page cache, MailPoet, Advanced Ads, Akismet).
- Verified in the cloud environment: fresh `make setup` finished in 17 s with images cached; home page and /restaurants/ return 200 with the theme stylesheet; `wp plugin is-active guam-restaurants-core` succeeds; PHPCS clean (12 files); PHPUnit 7 tests, 20 assertions OK; pre-commit hook rejects a non-compliant file.
- Not verified here: wp-env (wordpress.org is blocked from the cloud environment, so Docker Compose with official images was used instead); `make composer` through the Docker composer image (GitHub downloads blocked here; host Composer used); Docker Desktop on macOS/Windows.
- Remaining: choose host and buy plan; install the plugins in docs/plugins.md on staging; decide Yoast SEO vs owned Squirrly GEO; decide MailPoet vs hosted email service; confirm Advanced Ads on WP 7.1; build Happyforms owner-submission handling and sponsored-listing logic in the plugin.

## Content model (docs/content-model.md), 2026-10-10
- Done: design approved by Sanjay with every recommended option (no rating snapshot table, daily ad counters, Tumon as a search alias, unverified malls hidden, $ to $$$$ price bands, extra village aliases).
- Done: `gr_restaurant` meta (15 keys with REST schemas; private keys only in the `edit` context), `gr_price_level` taxonomy, term meta, six custom tables via dbDelta, seeds (19 villages, 6 unverified malls, 17 cuisines, 4 price levels) on activation and via `wp guam seed`.
- Done: Census TIGER 2024 village boundaries in `data/guam-villages.geojson` (19 villages confirmed), PHP point-in-polygon `Geo\Village_Locator`, `wp guam villages assign` with ingestion-run logging and review-queue fallback, Tumon/alias search (front end and REST) and `/village/tumon/` redirect, mall verification checkbox.
- Checked: PHPCS clean; PHPUnit 58 tests pass; on the local site `/village/tumon/` returns 301 to `/village/tamuning/`, an unverified mall archive returns 404, and a restaurant at 13.5180, 144.8390 is tagged Dededo.
- Remaining: verify the six malls (and their villages) in wp-admin; ad beacon and click redirect, review-queue retention cron, and admin screens for awards, highlights and the review queue are not built yet.
