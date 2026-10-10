# Third-party plugins

Recommended plugins for SEO, forms, caching, email newsletter, analytics and ad management.
Checked on 2026-10-09 against each plugin's wordpress.org page and its `readme.txt`
(`plugins.svn.wordpress.org/<slug>/trunk/readme.txt`). WordPress core at the time: 7.1.3.

CLAUDE.md rule 6 says to prefer plugins for common needs and to use the owner's
existing plugins where they fit. Owned plugins are marked **(owned)**.

"Last update" is the date of the latest release. Where wordpress.org only shows a relative
time ("4 days ago"), the date is computed from 2026-10-09 and marked "about".

## Summary

| Need | Plugin | Owned? | License | Latest version | Last update | Active installs |
|---|---|---|---|---|---|---|
| SEO | [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) (free) | No | GPLv3 | 28.6 | 2026-09-29 | 10+ million |
| Forms | [Happyforms](https://wordpress.org/plugins/happyforms/) | Yes | GPLv2 or later | 1.26.17 | 2026-10-05 | 20,000+ |
| Caching | [W3 Total Cache](https://wordpress.org/plugins/w3-total-cache/) | Yes | GPLv2 or later | 2.10.7 | about 2026-10-02 | 900,000+ |
| Email newsletter | SendFox (hosted service, not a plugin) | No | n/a | n/a | n/a | n/a |
| Analytics | [Matomo for WordPress](https://wordpress.org/plugins/matomo/) | No | GPLv3 or later | not recorded | checked 2026-10-10 (tested up to WP 7.1) | 100,000+ |
| Ad management | Built into `guam-restaurants-core` (Advanced Ads not needed) | | | | | |
| Checkout (premium profiles) | [WooCommerce](https://github.com/woocommerce/woocommerce/releases/tag/11.2.1) + [WooCommerce PayPal Payments](https://wordpress.org/plugins/woocommerce-paypal-payments/) | No (free) | GPLv3 / GPLv2 | 11.2.1 / 4.1.3 | 2026-10-10 checked | |
| Form spam (supports Forms) | [Akismet](https://wordpress.org/plugins/akismet/) | Yes | GPLv2 or later | 5.7.2 | about 2026-08 | 5+ million |

## SEO: Yoast SEO (free)

- **Why:** The directory's SEO value comes from many restaurant, village, mall and cuisine
  pages. Yoast generates XML sitemaps for custom post types and taxonomies, outputs a
  schema graph that our plugin can extend with `Restaurant` data through Yoast's schema
  filters, and provides breadcrumbs. It is the most widely used SEO plugin, so most
  problems already have documented answers. Tested up to 7.1.3.
- **Owned alternative considered:** GEO Plugin by Squirrly SEO (owned, plus the Advanced Pack
  Pro license; slug `squirrly-seo`, GPLv2 or later, version 14.2.7, about 2026-10-05,
  30,000+ installs, tested up to 7.0.7). Not chosen as the primary because its page says most
  processing and data storage run on Squirrly's cloud and the cloud tools need a connected
  Squirrly account, it does not clearly document taxonomy sitemaps or breadcrumbs, and it is
  tested only to 7.0.7. Its "GEO" (AI-search citation) features are a reasonable later
  experiment; it can import Yoast data if the owner switches.
- **Note:** Run only one SEO plugin at a time; two will output duplicate meta tags and sitemaps.

## Forms: Happyforms (owned)

- **Why:** Already owned, actively maintained (changelog entry dated 2026-10-05, tested up to
  7.1.2), and covers what the site needs first: contact form, "suggest a correction", and
  owner submissions for a listing. Owner submissions are a permitted data source under
  CLAUDE.md rule 1.
- **Custom code still needed:** turning an approved owner submission into a restaurant post
  belongs in `guam-restaurants-core` (rule 6), hooked to Happyforms' submission action, with
  a moderation step.
- **Watch:** wordpress.org support shows 0 of 1 issues resolved in the last two months. If
  owner submissions grow (file uploads, multi-step), re-evaluate.
- **Spam:** Pair with **Akismet (owned)**. Akismet keys are free only for personal sites; a
  commercial directory needs a paid Akismet plan. Confirm on staging that Happyforms
  submissions are checked by Akismet; if not, use the form's own CAPTCHA option.

## Caching: W3 Total Cache (owned), only where the host has no page cache

- **Why:** Already owned, maintained (2.10.7, about 2026-10-02, tested up to 7.1.3), and covers
  page, object, database and browser caching plus CDN rewriting.
- **Important:** The managed hosts recommended in `docs/hosting.md` (Kinsta, WP Engine,
  Rocket.net) cache pages at the server and CDN. On those hosts, leave W3 Total Cache
  **off** (or use only browser-cache headers) and check the host's disallowed-plugin list
  before installing; two page caches cause stale pages and hard-to-debug bugs. Use W3 Total
  Cache when the host provides no page cache (for example a Cloudways server without
  Breeze, or a plain VPS).
- **Directory-specific:** Sponsored placements must stay labeled and must not change organic
  ranking (rule 5). If ad rotation happens at render time, exclude those fragments from page
  caching or rotate client-side, so cached pages do not freeze one sponsor in place.
- **Public site (2026-10-10).** The directory, map and recent-changes blocks cache their
  queries with `wp_cache_*` (`Site\Cache`). Without a persistent object cache that only
  lasts one request, so turn on W3 Total Cache's object cache with Redis or Memcached, or
  use the host's object cache. Page cache: listings filtered by "Open now" set
  `DONOTCACHEPAGE`, and the REST endpoints send `Cache-Control: public, max-age=300`
  (60 seconds for open now). Make sure the host gzips `application/json`; the map endpoint
  is about 450 KB raw and 40 KB gzipped at 1,700 restaurants. The local Docker setup does
  this in `docker/apache/compression.conf`.

## Email newsletter: SendFox (2026-10-10)

- **Why:** Chosen in the brief. The plugin builds the weekly issue itself
  (`includes/newsletter/`) and either creates a SendFox draft campaign through the API
  or shows HTML to paste. No newsletter plugin is needed. MailPoet was the earlier
  suggestion and is no longer needed.
- **Cost:** SendFox's API needs a paid plan; the free plan works with copy and paste.
- **Compliance:** Follow the CAN-SPAM notes in `docs/legal-and-policy.md` (physical address,
  unsubscribe link, honest subject lines). SendFox adds the address and unsubscribe link.

## Analytics: Matomo for WordPress (2026-10-10)

- **Why:** Free, open source, data stays on the site's host, and it can run without cookies.
  The plugin prints a cookieless tracker from `GUAM_RESTAURANTS_ANALYTICS*` constants, so
  Matomo's own tracking code is set to Disabled. Setup and alternatives (Umami, Plausible)
  are in `docs/seo-and-analytics.md`.

## Ad management: built into the plugin (2026-10-10)

Advanced Ads was the first choice, but its click and impression tracking is a paid add-on,
its tracking does not count pages served from a page cache without extra setup, and
sponsored listings need directory logic anyway. The plugin now serves ads itself
(`includes/ads/`, described in `docs/monetization.md`, "Ad serving"): an `gr_ad` post type,
the Sponsored slot block, rotation, a beacon that counts behind page caches, and a monthly
CSV report. Do not install Advanced Ads. If it was installed on staging for the old home
placement, its "home" placement is no longer printed by the theme and can be deleted with
the plugin.

## WPXPO suite (owned, Pro, unlimited sites), 2026-10-10

Checked plugin by plugin in [billing.md](billing.md) §1. Every WPXPO plugin
except PostX needs WooCommerce, which the site does not run, so none of them
can do manual invoicing, claims or the owner dashboard. WowStore, WowInvoice
and WowRevenue come into use if WooCommerce checkout for premium profiles is
approved ([billing.md](billing.md) §4). WholesaleX does not fit listing claims.
PostX is not needed for the directory blocks. Pro licenses are activated in
wp-admin on the host and never committed.

## Other owned plugins worth using

Not part of this request, listed so they are not forgotten: ShortPixel Image Optimizer
(image compression), MalCare (security and malware scanning, if the host does not include
it), Yoast Duplicate Post (cloning restaurant drafts), Health Check & Troubleshooting
(debugging conflicts). Classic Editor, CSS Hero and DiviFlash do not fit a block theme and
should stay inactive.

## How to re-check

1. Open `https://wordpress.org/plugins/<slug>/` for version, "Last updated", tested-up-to and
   installs.
2. Open `https://plugins.svn.wordpress.org/<slug>/trunk/readme.txt` for the license and
   changelog dates.
3. Update the table above and the date at the top.

## Checkout: WooCommerce and WooCommerce PayPal Payments (added 2026-10-10)

- **Why:** Sanjay approved WooCommerce checkout for premium profiles so the owned WPXPO
  plugins (WowInvoice, WowStore, WowRevenue) can be used, with PayPal as the gateway because
  Stripe does not accept Guam-based businesses. Details, setup steps and what the plugin adds
  are in `docs/billing.md` §4.
- **Versions checked:** WooCommerce 11.2.1 (GitHub release; requires WordPress 7.0 and PHP
  7.4), pinned for local development in the `Makefile`. PayPal Payments 4.1.3 (readme on
  plugins.svn.wordpress.org; requires WooCommerce 9.6, tested up to WordPress 7.1).
- **Automatic renewal:** WPXPO WowSubscriptions (owned suite) once WPXPO releases it; WooCommerce Subscriptions ($279/year) is not needed.
