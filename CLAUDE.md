# CLAUDE.md

## Project

A WordPress-based Guam restaurant directory. Restaurants are tagged by
village, malls, and cuisine. The site shows awards history, third-party
ratings, and first-party highlights, and earns revenue from restaurant
advertising.

## Standing rules

1. **Data sourcing.** Use APIs, OpenStreetMap, public datasets, owner
   submissions, and manually researched facts with source links.
2. **Source register.** Every external source must be listed in
   `docs/data-sources.md`.
3. **Facts and copy.** Record facts (for example, a winner and year) with a
   source URL to the website or document that gives the award. Write all
   descriptive copy in your own words, paraphrased.
4. **Third-party reviews.** Do not filter or alter third-party review content
   as returned by the API. Show it as the API returns it, with attribution.
5. **Sponsored content.** Sponsored content must always be labeled
   "Sponsored" and must never change ratings, awards, or organic ranking.
6. **Architecture.** Build as a plugin named `guam-restaurants-core` plus a
   block theme. Do not put business logic in the theme. Prefer well-maintained
   plugins for common needs (forms, SEO, caching, email) and write custom code
   only for what they cannot do. The plugins below are available; for Pro
   versions, the owner will install the license key when appropriate.

   | Plugin | Pro |
   |---|---|
   | Advanced Database Cleaner | |
   | Akismet Anti-spam: Spam Protection | |
   | Amelia | |
   | Classic Editor | |
   | CSS Hero | |
   | Display PHP Version | |
   | DiviFlash | |
   | Featured Video Plus | |
   | GEO Plugin by Squirrly SEO | |
   | GEO Plugin by Squirrly SEO - Advanced Pack | Pro |
   | Gravitec.net - Web Push Notifications | |
   | Happyforms | |
   | Health Check & Troubleshooting | |
   | LoginPress | |
   | LoginPress Pro | Pro |
   | MalCare WordPress Security Plugin | |
   | Master-Detail Tables for wpDataTables | |
   | Post Type Switcher | |
   | Powerful Filters for wpDataTables | |
   | ShortPixel Image Optimizer | |
   | W3 Total Cache | |
   | WishList Member X | |
   | WP .htaccess Editor | |
   | WP Reset PRO | Pro |
   | wpDataTables | |
   | Yoast Duplicate Post | |
   | Your Gallery | |
   | Your Gallery - PRO | Pro |
   | WPXPO suite: PostX, WowStore, WowRevenue, WowInvoice, WowAddons, WowOptin, WholesaleX, WowShipping, WowRecommend (fit notes in `docs/billing.md` §1) | Pro |

7. **Coding standards.** Follow WordPress coding standards. Sanitize input,
   escape output, use nonces and capability checks, and use
   `$wpdb->prepare` for all custom queries.
8. **Secrets.** Store API keys in `wp-config.php` constants or environment
   variables. Never commit secrets. Keep `.env.example` current.
9. **Imports.** Every import job must be idempotent, run through WP-CLI, log
   counts (created, updated, unchanged, failed), and respect rate limits.
10. **Tests.** Write PHPUnit tests for parsing, matching, tagging, and
    ad-serving logic.
11. **Before finishing a task**, run PHPCS and the tests and report results.
12. **Progress log.** Keep a running inventory in `docs/progress.md` of what
    is done, which sources have been checked, and what remains, so work can
    resume without repeating steps.
