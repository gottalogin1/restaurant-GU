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
- [x] Newsletter: SendFox on a paid plan (Sanjay, 2026-10-10), so the API
      creates each weekly draft. Set `GUAM_RESTAURANTS_SENDFOX_TOKEN` on the
      host; without it, copy the HTML from Restaurants > Newsletter
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

- [ ] Privacy policy: the draft is filled in on activation
      ([legal-pages.md](legal-pages.md)); counsel fills the bracketed parts
      (owner, contact, host, email service, retention), then publish it from
      Settings > Privacy.
- [ ] Privacy policy, visitor accounts: the draft now covers saved lists,
      email accounts and Google/Facebook sign-in. If the privacy page was
      already edited or published, copy those sections from
      `data/legal/privacy-policy.md` by hand (the plugin never overwrites an
      edited page). Google and Meta both need the published URL.
- [ ] Terms of use: counsel fills the bracketed parts (refunds, DMCA agent,
      liability, disputes), then publish the "Terms of use" draft page.
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
- [ ] **To do (revise): photo upload agreement.** Set the company's formal
      name in `GUAM_RESTAURANTS_LEGAL_NAME` (wp-config.php) once it is decided;
      until then the agreement and the upload form use the site name. Have
      counsel review /photo-upload-agreement/ (copyright transfer by a tick
      box, the fallback license, minors) and bump `Photos\Agreement::VERSION`
      after any change. Text: `data/legal/photo-upload-agreement.md`.
- [ ] Register a DMCA agent: visitors can now upload photos.
- [ ] Photos: raise PHP `upload_max_filesize` to 20M and `post_max_size` to
      24M, and set `GUAM_RESTAURANTS_MAX_UPLOAD_MB` to 20 in wp-config.php
      (it was 10). Run `wp guam-restaurants cuisine-photos --dry-run`, then
      without `--dry-run`, and look at each cuisine photo.

## Plugins

Install on staging first and check each against the current WordPress version
([plugins.md](plugins.md)).

- [ ] Yoast SEO (or Squirrly). Sitemaps then come from it automatically.
- [ ] LoginPress (free) + LoginPress Pro: license in LoginPress > License,
      Social Login add-on on, Google and Facebook apps set up, Turnstile on
      the registration form ([accounts-and-saved-lists.md](accounts-and-saved-lists.md) §2).
- [ ] Settings > General: "Anyone can register" is on, new user role
      Subscriber (a one-time migration sets this; check nobody turned it off).
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
- [ ] Happyforms: build the contact form, add it to its draft page, then
      publish it. The claim and correction forms are built into the plugin
      and their pages are published automatically (docs/billing.md §5).
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
- [ ] Settings > General: turn on "Anyone can register" (default role
      Subscriber) so owners can create an account to claim a listing.
- [ ] Before the first invoice: answer the DRT and accountant questions in
      docs/billing.md §3.
- [ ] Premium profile checkout: WooCommerce with Site visibility set to Live,
      WooCommerce PayPal Payments connected, taxes off until §3 is answered,
      WowInvoice prefix `GRW-`, `wp guam-restaurants premium-products`, then
      one test purchase and refund (docs/billing.md §4).

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
- [ ] Japanese and Korean pages ([translation.md](translation.md) §6): install the
      WordPress core language packs for 日本語 and 한국어, purge the page cache, reset the
      header if it was edited, and run `wp guam-restaurants translations missing --lang=ja`
      (and `ko`) after the live content is in, so a translation pass can fill the gaps.
- [ ] Server crontab for the scheduled jobs ([scheduling.md](scheduling.md)),
      with `GUAM_RESTAURANTS_CRON_MODE=server`.
- [ ] Confirm the host gzips JSON. The map data is about 450 KB without it and
      40 KB with it.

## Production readiness ([deployment.md](deployment.md))

- [ ] `wp-config.php` constants from [deployment.md](deployment.md) §2 on staging
      and production; `deploy/staging.env` and `deploy/production.env` on the
      machine that deploys.
- [ ] Two Factor plugin installed, every administrator enrolled with backup codes,
      then `GUAM_RESTAURANTS_REQUIRE_ADMIN_2FA` on ([security.md](security.md) §4).
- [ ] LoginPress Pro license and its Limit Login Attempts add-on, tested on
      staging with WordPress 7.1; or MalCare's login CAPTCHA.
- [ ] MalCare free connected (firewall, malware scan alerts).
- [ ] Rename or remove any user called `admin`.
- [ ] Server headers and the `xmlrpc.php` block at the host or CDN
      ([security.md](security.md) §3); `expose_php = Off`.
- [ ] Cloudflare (or the host CDN): cache rules, POST rate limit on
      `/wp-json/guam-restaurants/v1/`, then
      `GUAM_RESTAURANTS_CLIENT_IP_HEADER` ([performance.md](performance.md) §5,
      [security.md](security.md) §5).
- [ ] Page cache exclusions ([performance.md](performance.md) §2) and ShortPixel
      API key and settings (§3).
- [ ] Off-site backups: B2 bucket, rclone crypt remote, `~/.guam-backup.env`,
      Healthchecks.io ping, crontab; first restore test into staging, logged in
      [backups.md](backups.md) §4.
- [ ] Uptime monitor on the health endpoint and the home page
      ([monitoring.md](monitoring.md) §1); Google Cloud budget alert.
- [ ] `wp guam-restaurants preflight --strict` passes on production.
- [ ] After a clean week: `GUAM_RESTAURANTS_HSTS` on, then
      `GUAM_RESTAURANTS_CSP=enforce`.

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
