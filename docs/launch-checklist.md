# Launch checklist

Last updated 2026-10-10.

This is everything left before the directory can go live, gathered from each
finished task in [progress.md](progress.md), [legal-and-policy.md](legal-and-policy.md),
[plugins.md](plugins.md) and [scheduling.md](scheduling.md). Items already done
since those notes were written are left out. Tick each box as it's done, and
add new launch items here rather than only in progress.md.

## Decisions

Each of these changes what gets installed or paid for, so they come first.

- [x] Merge PR #11, the public site (merged 2026-10-10).
- [x] Map tiles: OpenFreeMap chosen 2026-10-10 (free, no uptime guarantee).
      MapTiler Flex ($30/month) is the fallback. Switching later is one setting
      (`GUAM_RESTAURANTS_MAP_STYLE_URL`; [data-sources.md](data-sources.md) §6a).
- [ ] Host: Kinsta single-site, Tokyo data center, about $35/month
      ([hosting.md](hosting.md)). Confirm Tokyo is offered when creating the site.
- [ ] SEO plugin: Yoast SEO (recommended) or the owned Squirrly GEO plugin. Run only one.
- [ ] Newsletter: SendFox (decided in the brief, 2026-10-10). Its API needs a
      paid SendFox plan; on the free plan, copy the weekly HTML from
      Restaurants > Newsletter into a new SendFox campaign instead
      ([seo-and-analytics.md](seo-and-analytics.md), [monetization.md](monetization.md) §8).
- [ ] Google Business Profile for the site: check eligibility first (a
      website-only business may not qualify; [seo-and-analytics.md](seo-and-analytics.md) §3).
- [ ] Sponsored label: "Sponsored" alone or "Sponsored · Ad". Ask counsel
      (question 1 in [legal-and-policy.md](legal-and-policy.md) §8).
- [ ] Ratings sources at launch: Google only, or also Yelp. Yelp needs written
      consent and a paid plan from $229/month.

## Legal and policy

The Google and Yelp terms questions are already cleared. These remain for a
Guam-licensed attorney ([legal-and-policy.md](legal-and-policy.md) §7 to §9).

- [ ] Privacy policy: write it with counsel, include a line about review data,
      and publish it from Settings > Privacy.
- [ ] Terms of use: write with counsel and publish the "Terms of use" draft page.
- [ ] Editorial policy: have counsel review the wording at /editorial-policy/
      ([editorial-policy.md](editorial-policy.md)), add the statement,
      corrections and sponsored-feature sections to the page, and link it from
      the footer and the advertise page.
- [ ] Request rate cards from the Guam outlets in [market-rates.md](market-rates.md)
      and compare them with the launch prices in [monetization.md](monetization.md).
- [ ] Advertiser contract: advertising never buys ratings, reviews, awards or
      rank; payment and cancellation terms.
- [ ] Guam business license and gross receipts tax on advertising revenue.
- [ ] Decide whether to register a DMCA agent before launch.
- [ ] Cookie consent for EU and UK visitors, and which outside privacy laws
      apply (Japan, Korea, US states).
- [ ] Media liability or errors-and-omissions insurance.
- [ ] Pika's Best: wait for PDN's reply before showing award facts, and use no
      logos or badges without permission.

## Plugins

Install on staging first and check each against the current WordPress version
([plugins.md](plugins.md)).

- [ ] Yoast SEO (or Squirrly). Sitemaps then come from it automatically.
- [ ] Ads: none to install. Ads are entered under Ads in wp-admin (see
      monetization.md, "Ad serving"). If the page cache or a security plugin
      blocks REST POSTs, allow `/wp-json/guam-restaurants/v1/ads/events`.
- [ ] Persistent object cache (Redis or Memcached), from the host or W3 Total
      Cache. Use W3 Total Cache's page cache only if the host has none.
- [ ] An SMTP plugin so site email works. Without it, logged-out visitors can't
      submit reviews, job alerts aren't sent, and advertiser leads are saved
      but neither the lead email (to Settings > General > Administration
      Email Address) nor the sender's confirmation goes out.
- [ ] Akismet on a paid plan, checking reviews and form submissions.
- [ ] Happyforms: build the claim and contact forms, add each to its draft
      page, then publish both pages. The correction form is built into the
      plugin and its page is published automatically.
- [ ] Matomo for WordPress: install, tick "Disable cookies", set its own
      tracking code to Disabled, and set the four `GUAM_RESTAURANTS_ANALYTICS*`
      constants ([seo-and-analytics.md](seo-and-analytics.md) §1).
- [ ] Privacy policy: mention cookieless Matomo analytics, the per-listing view
      counts (no visitor data), and what the correction form stores.
- [ ] Search Console: verify the domain, submit the sitemap, add a second
      owner ([seo-and-analytics.md](seo-and-analytics.md) §2).
- [ ] SendFox: set the from name and a from address on the site's domain
      (`GUAM_RESTAURANTS_NEWSLETTER_FROM_*`), confirm the footer shows the
      physical mailing address and unsubscribe link (CAN-SPAM), add the
      SendFox signup form to the site, and set `GUAM_RESTAURANTS_SENDFOX_TOKEN`
      if on a paid plan.
- [ ] Review the first newsletter draft on the Monday after launch; drafts are
      never sent automatically.

## Hosting, keys and settings

Keys go in environment variables or `wp-config.php`, never in the repo. The
full list is in [.env.example](../.env.example).

- [ ] Google API key with a billing account, plus per-day quotas in Google
      Cloud as a backstop.
- [ ] Google Maps Embed key (free), restricted to the site's domain, if Google
      ratings are shown (`GUAM_RESTAURANTS_GOOGLE_MAPS_EMBED_KEY`).
- [ ] Map style URL: leave empty for OpenFreeMap; set it only if switching to MapTiler.
- [ ] Logo and asset URLs for Google Maps, Foursquare and Yelp (if used),
      downloaded from each brand page by hand.
- [ ] Alert email (`GUAM_RESTAURANTS_ALERT_EMAIL`).
- [ ] Server crontab for the scheduled jobs ([scheduling.md](scheduling.md)),
      with `GUAM_RESTAURANTS_CRON_MODE=server`.
- [ ] Confirm the host gzips JSON. The map data is about 450 KB without it and
      40 KB with it.

## Content and data

- [ ] Run `wp guam-restaurants import-osm` on the live site, then `discover`
      once the Google key is set.
- [ ] Review the first `find-duplicates --dry-run` and `retag --dry-run`
      results before applying them.
- [ ] Verify the six malls and their villages in wp-admin. Until then malls
      stay hidden from visitors.
- [ ] Fill the awards CSV by hand from the published results, run
      `import-awards`, and resolve any match items in the review queue.
- [ ] Write intros for villages, malls and cuisines (each term's description in
      wp-admin). Optional; a short generated line shows until then.
- [ ] Delete the local sample restaurants if a copy of the dev database is ever
      used (`bin/generate-sample-restaurants.php delete`).

## Final checks

- [ ] On staging, confirm the map draws its background tiles. This couldn't be
      tested from the build environment.
- [ ] Run Lighthouse on the live home, archive, restaurant and map pages.
- [ ] Check that the footer attributions match the sources actually switched on.
- [ ] Submit a test review, claim, correction and removal request as a
      logged-out visitor and confirm the emails arrive.
- [ ] Run `wp guam-restaurants run-job health-check` and confirm the alert email arrives.
- [ ] Take a backup before opening the site to the public.
