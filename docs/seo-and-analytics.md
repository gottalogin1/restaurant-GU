# SEO, analytics, Search Console and Google Business Profile

What the plugin does for search and analytics, and the setup steps only the
owner can do. Launch items are also in [launch-checklist.md](launch-checklist.md).
Checked 2026-10-10.

## 1. Analytics: Matomo for WordPress (recommended)

**Recommendation: [Matomo for WordPress](https://wordpress.org/plugins/matomo/),
cookieless, on the same host.** It is free and open source (GPL), stores data
in the site's own database, and has a "Disable cookies" setting, so no cookie
banner is needed for analytics. It runs inside WordPress, so there is no
separate server or monthly fee (zero-spend rule).

Options considered:

| Tool | Cost | Why not chosen |
|---|---|---|
| Matomo for WordPress | Free | Chosen. Adds database rows and some load on the WordPress host; prune old raw data in its settings if the database grows. |
| Umami Cloud (Hobby) | Free up to 100K events/month, one site ([pricing](https://umami.is/pricing)) | Its [terms](https://umami.is/terms) restrict free use for a "revenue-generating endeavor or commercial enterprise". This site sells ads, so the free plan is not a safe fit. The paid plan or a self-hosted Umami would be. |
| Plausible | No free cloud plan ([pricing](https://plausible.io/pricing)) | Community Edition is free but needs its own server ([self-hosting](https://plausible.io/self-hosted-web-analytics)). |

### Setup (Matomo)

1. Install and activate **Matomo Analytics – Ethical Stats** from Plugins > Add New.
2. Matomo > Settings > Privacy & GDPR: tick **Disable cookies** (also enforced
   by our tracker, below). Matomo > Settings > Tracking: set **Add tracking
   code** to **Disabled**, because the plugin prints its own cookieless code
   (otherwise pages would be counted twice).
3. Add to `wp-config.php` (or the environment):

   ```php
   define( 'GUAM_RESTAURANTS_ANALYTICS', 'matomo' );
   define( 'GUAM_RESTAURANTS_ANALYTICS_SCRIPT_URL', 'https://YOUR-DOMAIN/wp-content/uploads/matomo/matomo.js' );
   define( 'GUAM_RESTAURANTS_ANALYTICS_ENDPOINT', 'https://YOUR-DOMAIN/wp-json/matomo/v1/hit/' );
   define( 'GUAM_RESTAURANTS_ANALYTICS_SITE_ID', '1' );
   ```

   Matomo explains where to find the tracker URL
   ([FAQ](https://matomo.org/faq/wordpress/how-do-i-find-the-javascript-tracker-url-that-is-used-in-matomo-for-wordpress)).
   The site ID is shown under Matomo > Settings or the Matomo dashboard URL.
4. Open the site logged out and check Matomo > Summary shows the visit, and
   the browser's storage shows no `_pk_` cookies.
5. Add one line to the privacy policy: page views are counted with Matomo,
   on our own server, without cookies; Do Not Track is respected.

### What the tracker does (`Analytics\Tracker`)

- Prints nothing unless `GUAM_RESTAURANTS_ANALYTICS` is set, and never in
  wp-admin, in previews, or for logged-in editors.
- Matomo: `disableCookies`, `setDoNotTrack`, then the page view
  ([cookieless tracking](https://matomo.org/faq/how-to/how-do-i-enforce-tracking-without-cookies/)).
- Umami (if chosen later on a paid or self-hosted plan): set
  `GUAM_RESTAURANTS_ANALYTICS=umami`, the script URL and the website ID. It is
  cookieless already; `data-do-not-track` and `data-domains` are set
  ([tracker configuration](https://docs.umami.is/docs/tracker-configuration)).

### Listing view counts (`Analytics\Listing_Views`)

Separate from Matomo, the plugin counts views of each restaurant page per day
(table `gr_listing_views`: restaurant, date, count). Nothing about the
visitor is stored. Bots are skipped and one visitor can add at most 60 views
an hour. Rows are deleted after 400 days. The newsletter's "Most viewed this
week" uses these counts. They also give advertisers per-listing figures later.

## 2. Google Search Console

1. Go to [Search Console](https://search.google.com/search-console) and add a
   **Domain** property for the site's domain.
2. Verify with the DNS TXT record Google shows, added at the domain registrar
   or DNS host ([verification help](https://support.google.com/webmasters/answer/9008080)).
   Domain verification covers http, https and www at once.
3. Sitemaps > add `sitemap_index.xml` if Yoast SEO is installed, or
   `wp-sitemap.xml` without it. Unverified malls and ads are already left out.
4. Settings > Users and permissions: add a second owner so access isn't tied
   to one account.
5. After a week, check Pages (indexing) for errors, and Enhancements for
   Restaurant and breadcrumb structured data warnings.
6. Use URL inspection on one restaurant page, one village page and the home
   page, and request indexing at launch.
7. Do the same in [Bing Webmaster Tools](https://www.bing.com/webmasters)
   (it can import the Search Console property).
8. **Naver Search Advisor** (Korean searches): Naver does not crawl a new site
   on its own. Register the domain at
   [searchadvisor.naver.com](https://searchadvisor.naver.com), verify it with
   the meta tag or DNS record it shows, submit the sitemap index, and request
   collection of `/ko/`. The Korean pages are in their own sitemap
   (`guam-ko-sitemap.xml` with Yoast, `wp-sitemap-guam-ko-1.xml` without).
9. **Squirrly GEO beside Yoast**: install it only after Yoast, and turn its
   titles, meta, canonical, Open Graph, schema and sitemaps off before the
   site goes live (steps in `docs/plugins.md`, SEO section). Yoast stays the
   one plugin that prints SEO tags.
10. **IndexNow**: install Microsoft's free IndexNow plugin so new and changed
   pages are sent to Bing and Naver right away
   ([Naver supports IndexNow](https://searchengineland.com/naver-korean-search-engine-now-supports-indexnow-429880)).

## 3. Google Business Profile for the site's own brand

**Eligibility first.** Google's [guidelines](https://support.google.com/business/answer/3038177)
say a business qualifies when it "has a physical location that customers can
visit, or travels to customers where they are", and a rented mailing address
the business doesn't operate from is not eligible. A website-only directory
does not clearly meet this. Create a profile only if the business meets
customers in person (for example, a staffed office, or as a service-area
business that visits restaurants to sell advertising). Otherwise skip it and
rely on the Organization structured data and social profiles below; a
suspended profile is harder to fix than none.

If eligible:

1. Sign in at [business.google.com](https://business.google.com) with a
   shared business Google account (not a personal one).
2. Business name: exactly the site's brand name, with no keywords added.
3. Category: the closest real category for what customers meet you for
   (for example "Advertising agency" if that is the in-person activity).
   Do not pick "Restaurant".
4. Location: a storefront address only if customers visit it during stated
   hours. Otherwise choose service-area business, hide the address and list
   the Guam villages served.
5. Website: the home page. Phone: a number answered during stated hours.
6. Verify by the method Google offers (often a video recording).
7. Add the logo, cover photo and a short description in your own words.
8. Copy the profile's share link into `GUAM_RESTAURANTS_BRAND_PROFILES`
   (below).

### Brand profiles in structured data

`GUAM_RESTAURANTS_BRAND_PROFILES` is a comma-separated list of the site's own
profile URLs (Google Business Profile, Facebook, Instagram...). They go in
the home page's Organization `sameAs`, which helps Google link the brand to
those profiles.

## 4. What the plugin does for SEO

- **Titles and descriptions.** Village, mall and cuisine pages get distinct
  titles: "Restaurants in Dededo, Guam", "Restaurants at Micronesia Mall,
  Dededo, Guam", "Korean Restaurants on Guam", and a price page title. The
  meta description is the term's own description (written in wp-admin) or a
  generated one with the count. With Yoast installed, our title is used
  unless a title was set for that term in Yoast; the description likewise.
  Filtered and sorted listings stay noindex,follow.
- **Internal links** (`guam-restaurants/related-links` block, in the
  taxonomy and restaurant templates):
  - village page: malls in the village (verified only) and its common cuisines;
  - mall page: its village and the cuisines found there;
  - cuisine page: villages that have it and related cuisines;
  - restaurant page: up to 5 more restaurants of the same cuisine in the same
    village, then its village, mall and cuisine pages.
- **Structured data.** Restaurant JSON-LD on each restaurant page (already
  built). New: `ItemList` on village, mall, cuisine and price pages (the
  restaurants on that page in order), and `Organization` plus `WebSite` with a
  site search action on the home page. The Organization block is skipped when
  an SEO plugin is active, since Yoast prints its own (set the brand profiles
  in Yoast > Settings > Site representation then).
- **Corrections page** is linked from every restaurant page and the newsletter.
- **Sitemaps for pages that are not posts** (`Site\Sitemaps`, added
  2026-10-26). Three extra sitemaps, in Yoast's index or WordPress's:
  `pages` (the dish pages and the awards pages, which are served by rewrite
  rules, so neither lists them), and `ja` and `ko` (every public page in
  that language, without the pages kept in English). With Yoast they are
  `guam-pages-sitemap.xml`, `guam-ja-sitemap.xml` and `guam-ko-sitemap.xml`;
  without it, `wp-sitemap-guam-pages-1.xml` and so on. An empty list is left
  out of the index.
- **Dish and awards pages** get a canonical link (WordPress prints one only
  on posts and pages), and Yoast's title, description, canonical and share
  link. An awards page narrowed by year, program or category in the selector
  form is noindex,follow with no canonical, like filtered lists.
- **Dish pages** get an `ItemList` of their restaurants in page order.
- **Restaurant data** adds `postalCode` when the address has a 969xx ZIP
  code, and `hasMenu` from the owner's first menu link while the listing is
  claimed (the same rule as the Menu button).
- **Cuisine-in-village pages** (`Site\Cuisine_Villages`, added 2026-10-26,
  gap G1). A village page with one cuisine chosen and nothing else
  (`/villages/tumon/?cuisine=korean`) is a page of its own when 5 or more
  open restaurants match (`Cuisine_Villages::MIN_PLACES`, Sanjay's
  threshold; closed places don't count). It gets the heading and title
  "Korean Restaurants in Tumon, Guam", a description with the count, a
  canonical link, an `ItemList`, an entry in the `pages` sitemap, and links
  from the village page ("Popular cuisines in ...") and the cuisine page
  ("Where to find ... food"). Below 5 places, or with any other filter or
  sort, the URL stays noindex,follow. The list of pairs is cached for an
  hour and refreshed when restaurants or terms change.
- **Page intros** (`data/intros.php`, added 2026-10-26, plan phase 2).
  Every seeded village, mall and cuisine page and every Guam favorite dish
  page has a short intro in our own words, approved by Sanjay, in English,
  Japanese and Korean. They replace the generated count line at the top of
  the page and become the meta description. A one-time migration copies
  them into term Descriptions that are still empty, so they stay editable
  in wp-admin (Restaurants > Villages, Malls, Cuisines) and a Description
  someone wrote is kept; translated pages show the dictionary version of
  the shipped text, and an edited Description shows in English until
  `wp guam-restaurants translations missing` lists it for translation. Dish
  intros show under the dish page heading. The same migration renames the
  cuisine to CHamoru and The Plaza to Dusit Place ("The Plaza" stays an
  alias); both keep their web addresses. Sources: `docs/data-sources.md` §8c.
- **Fresh pages** (added 2026-10-27, plan phase 3, comparison additions
  15, 22, 23 and 26). `/new/` lists every month with new places and
  `/new/YYYY-MM/` each month, with a map and an `ItemList`; a place counts
  in the month it opened (owner or editor date) or else the month the
  directory first found it, skipping the starting inventory. A month page is
  indexed at 5 or more places, like cuisine-in-village pages, and is a 404
  when it has none. `/this-week/` shows the next seven days of village
  fiestas, weekly markets, owner truck stops and holiday hours. Both are in
  the `pages` sitemap, the header has a "This week" link, and the newsletter
  links both. Village pages show the next village fiesta with its source.
  Holiday hours change Open now, Open late and Open at on those dates.
  Sources: `docs/data-sources.md` §8d.
- The full plan, with what comes next, is the SEO plan doc
  (https://claude.ai/artifact/JcXSSwwXHeqLCb91Ye8ZYC).

## 5. Sources

Checked 2026-10-10: wordpress.org/plugins/matomo; matomo.org FAQs linked
above; umami.is/pricing; umami.is/terms; docs.umami.is tracker configuration;
plausible.io/pricing; plausible.io/self-hosted-web-analytics; Google Business
Profile guidelines (support.google.com/business/answer/3038177); Search
Console verification help (support.google.com/webmasters/answer/9008080).
SendFox sources are in [monetization.md](monetization.md) §8.
