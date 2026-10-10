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
7. Optional: do the same in [Bing Webmaster Tools](https://www.bing.com/webmasters)
   (it can import the Search Console property).

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

## 5. Sources

Checked 2026-10-10: wordpress.org/plugins/matomo; matomo.org FAQs linked
above; umami.is/pricing; umami.is/terms; docs.umami.is tracker configuration;
plausible.io/pricing; plausible.io/self-hosted-web-analytics; Google Business
Profile guidelines (support.google.com/business/answer/3038177); Search
Console verification help (support.google.com/webmasters/answer/9008080).
SendFox sources are in [monetization.md](monetization.md) §8.
