# Data sources and source register

Research date: 2026-10-09. Prepared for the Guam restaurant directory
(`guam-restaurants-core`). This is the project's source register (CLAUDE.md
rule 2): every external source the site uses must have a row in §9.

This is a reading of published terms by a non-lawyer. Terms change; each
claim below links the page it came from and the "last updated" date seen on
that page. Re-check before building an importer.

**How to read the evidence marks**

- ✅ quoted or summarized from the linked official page on 2026-10-09
- ⚠️ from prior knowledge, because the official page could not be read
  from the research environment (blocked by robots.txt or the network);
  verify by hand before relying on it

## 0. Summary

| Source | Recommendation | One-line reason |
|---|---|---|
| OpenStreetMap (Overpass / extracts) | **Use** | Open licence, storable, good base for names, locations, cuisine tags. Attribution and ODbL share-alike apply. |
| US Census TIGER/Line (Guam) | **Use** | Public domain (CC0) village boundaries for village tagging. |
| Guam DPHSS inspection results | **Use with limits** | Public facts (grade, demerits, date) in periodic PDFs; no API, no stated terms. Show as facts with date and source link. |
| Foursquare Open Source Places | **Use with limits** | Apache 2.0 bulk POI data (name, address, phone, website, category, closed date). Good for gap-filling; quality varies. |
| Foursquare Places API | **Use with limits** (or skip) | Ratings, hours, tips are Premium-only and **cannot be cached** on a server on pay-as-you-go. Little gain over the OS dataset for a directory. |
| Google Places API (New) | **Skip for now** | Google's terms bar use of Maps Core Services "in a listings or directory service", forbid caching ratings, and forbid showing Places content on a non-Google map. Revisit only with written confirmation from Google. |
| Yelp Places API | **Skip** | Ad-supported site is "commercial use", which needs Yelp's written consent; 24-hour cache cap; Yelp ratings may not be shown alongside other user-generated content. Paid plans start at $229/month. |
| Pika's Best of Guam (PDN) | **Use with limits** | Facts only (year, category, placing) with source URL, pending PDN's reply. See §8 and `docs/awards-research.md`. |

## 1. OpenStreetMap via Overpass API

**Fields offered.** Free-form tags. Relevant ones for restaurants:
`amenity` (restaurant, fast_food, cafe, bar, pub, food_court, ice_cream),
`name`, `name:ja`, `name:ko`, `cuisine`, `addr:*`, `opening_hours`,
`phone`, `website`, `diet:*`, `takeaway`, `delivery`, `outdoor_seating`,
`wheelchair`, `brand`, `brand:wikidata`, plus coordinates and, for areas,
geometry (useful for malls: `shop=mall` polygons, so restaurants can be
assigned to a mall by containment). No ratings or reviews.

**Guam coverage.** **Not tested.** The research environment's network
blocks overpass-api.de and every public mirror (HTTP 403 at the proxy), and
overpass-api.de disallows fetch tools in robots.txt. Run these by hand at
<https://overpass-turbo.eu/> before the first import:

```
// Count of eating/drinking places on Guam, by type
[out:json][timeout:90];
area["ISO3166-1"="GU"][admin_level=2]->.gu;
nwr["amenity"~"^(restaurant|fast_food|cafe|bar|pub|food_court|ice_cream)$"](area.gu);
out count;
```

```
// Field completeness sample: export as CSV
[out:csv(::type,::id,amenity,name,cuisine,"addr:street","addr:city",opening_hours,phone,website;true;",")][timeout:90];
area["ISO3166-1"="GU"][admin_level=2]->.gu;
nwr["amenity"~"^(restaurant|fast_food|cafe)$"](area.gu);
out tags;
```

```
// Malls (for mall tagging by polygon containment)
[out:json][timeout:60];
area["ISO3166-1"="GU"][admin_level=2]->.gu;
nwr["shop"="mall"](area.gu);
out tags center;
```

Record the counts and the share of rows with `cuisine`, `opening_hours`,
`phone`, `website` in `docs/progress.md`. Expect Tumon, Tamuning and Hagåtña
to be well mapped and southern villages to be thinner (inferred, not
measured).

**Pricing and limits.** Free. Public instance guidance: under 10,000
queries and 1 GB per day is fine, but ongoing projects should divide by 100
(under 100 queries and 10 MB per day), send an identifying `User-Agent`,
not run queries in parallel, wait 30 s on HTTP 429/406, and use
self-hosted or paid servers for commercial use. ✅
<https://wiki.openstreetmap.org/wiki/Overpass_API> (the page also says the
main instance is currently overloaded). For a weekly Guam import, one query
per run is enough; a Geofabrik extract is an alternative (⚠️ check which
Geofabrik region contains Guam).

**Storing, caching, displaying.** Data is under the Open Database Licence
(ODbL 1.0). It may be stored permanently and displayed. Two obligations:

- **Attribution** ✅ <https://osmfoundation.org/wiki/Licence/Attribution_Guidelines>:
  credit "OpenStreetMap" and make clear the data is under the ODbL, for
  example "© OpenStreetMap contributors" linked to
  <https://www.openstreetmap.org/copyright>. On a map, in a corner or
  adjacent to it; for non-map uses, place it where users would look (site
  footer and an "About our data" page).
- **Share-alike.** If we publicly use a *derivative database* (OSM data
  modified or merged with our own facts), that database must be offered
  under the ODbL. A *produced work* (the web pages) only needs attribution.
  Design consequence: keep OSM-derived fields (geometry, OSM tags) in their
  own table keyed by OSM id, separate from first-party content (editorial
  copy, awards, ad data), so only the OSM-derived layer is subject to
  share-alike. ⚠️ Confirm with the OSMF "Collective Database" and
  "Horizontal Layers" community guidelines:
  <https://osmfoundation.org/wiki/Licence/Community_Guidelines>.
- Note Yelp's terms (§3) forbid feeding Yelp geocodes into OSM; never mix
  sources in the OSM layer.

**Recommendation: Use.** Primary seed for restaurant names, locations,
cuisine, mall polygons. Improve gaps by editing OSM directly (good
citizenship, and fixes stay open).

## 2. Google Places API (New)

**Fields offered and SKU tier** ✅
<https://developers.google.com/maps/documentation/places/web-service/place-details>

| Tier | Relevant fields |
|---|---|
| Essentials (IDs only) | `id` (place ID), `photos` (references), `attributions` |
| Essentials | `formattedAddress`, `addressComponents`, `location`, `types`, `plusCode` |
| Pro | `displayName`, `businessStatus`, `googleMapsUri`, `primaryType`, `openingDate` |
| Enterprise | `rating`, `userRatingCount`, `priceLevel`, `priceRange`, `regularOpeningHours`, `nationalPhoneNumber`, `websiteUri` |
| Enterprise + Atmosphere | `reviews` (up to 5 ⚠️), `reviewSummary`, `generativeSummary`, `editorialSummary`, `dineIn`, `takeout`, `delivery`, `servesBreakfast` … `servesVegetarianFood`, `outdoorSeating`, `reservable`, `paymentOptions`, `parkingOptions` |

A request is billed at the highest tier of any field requested.

**Guam coverage.** **Not tested**: no Google API key is available in this
environment. Inferred: Google Maps is the most complete commercial dataset
for Guam, but that cannot be measured without a key.

**Pricing** ✅ <https://developers.google.com/maps/billing-and-pricing/pricing>,
<https://mapsplatform.google.com/pricing/> (free caps are per SKU per month;
the old $200 credit ended 2025-02-28)

| SKU | Free per month | Price per 1,000 after that (to 100k) |
|---|---|---|
| Place Details Essentials | 10,000 | $5 |
| Place Details Pro | 5,000 | $17 |
| Place Details Enterprise (needed for rating, count) | 1,000 | $20 |
| Place Details Enterprise + Atmosphere (needed for reviews) | 1,000 | $25 |
| Text / Nearby Search Pro | 5,000 | $32 |
| Text / Nearby Search Enterprise | 1,000 | $35 |
| Text Search Enterprise + Atmosphere | 1,000 | $40 |

Because ratings cannot be cached (below), every page view that shows a
Google rating is a billable Enterprise call: 1,000 free views a month, then
$20 per 1,000.

**Storing and caching** ✅ Maps Service Specific Terms §A.3 and §B.14
(last modified 2026-06-10)
<https://cloud.google.com/maps-platform/terms/maps-service-terms>; Maps
Platform ToS §3.2.3 (last modified 2026-08-26)
<https://cloud.google.com/maps-platform/terms>

- Place ID: may be stored indefinitely.
- Latitude/longitude: may be cached up to 30 consecutive days, then deleted.
- Everything else (names, addresses, ratings, counts, hours, reviews): no
  caching. §3.2.3(a) bars pre-fetching, storing or rehosting, and
  specifically "copy and save business names, addresses, or user reviews".
- §3.2.3(c)(iv) bars using Places lat/lng for point-in-polygon analysis, so
  Google coordinates **cannot** be used to assign restaurants to villages
  or malls.

**Display rules** ✅
<https://developers.google.com/maps/documentation/places/web-service/policies>
(updated 2026-10-07)

- Without a Google map: show the Google Maps logo (16–19 dp tall; outlined
  on busy backgrounds), or the text "Google Maps" when space is tight;
  never hide or alter it.
- Show any third-party attributions returned, as "Google Maps" plus the
  provider name.
- Reviews and photos: credit the author (name, avatar, profile link when
  space allows; avatar at minimum); give each a link to the source on
  Google Maps (`googleMapsUri`) and a report link (`flagContentUri`); show a
  notice describing how reviews are ordered and filtered (default:
  relevance).
- Review summaries: heading "Review summary", the API's `disclosureText`
  unmodified directly below, and the provided links.
- Places content must not appear on or near a non-Google map (ToS
  §3.2.3(e), Service Terms §14.2).

**Blocking issue.** ToS §3.2.3(d)(iii): the customer will not "use the
Google Maps Core Services in a listings or directory service or to create
or augment an advertising product." Places API (New) is listed as a Core
Service ✅ <https://cloud.google.com/maps-platform/terms/maps-services>.
An ad-supported restaurant directory fits that description on a plain
reading.

**Recommendation: Skip for now.** Do not use Google Places for listing
data or ratings unless Google (Maps Platform sales or legal) confirms in
writing that this use is allowed, and put that question to the Guam
attorney alongside `docs/legal-and-policy.md`. If it is ever allowed, the
only storable field is the place ID; ratings and counts must be fetched
live per view, and the listing page must not also show our OSM map.

## 3. Yelp Places API (formerly Fusion)

**Fields offered.** Business search and details: name, categories, rating
(stars), review count, price, phone, address, coordinates, hours,
photos, transactions, attributes. Review excerpts: up to 3 per business on
Enhanced, up to 7 on Premium, first 160 characters each; Base has no photos
or excerpts. ✅ <https://business.yelp.com/data/products/places-api/>
(the FAQ there uses older plan names; tier contents may have moved).

**Guam coverage.** **Not tested**: no Yelp key, and api.yelp.com is blocked
from this environment. Inferred: Yelp covers Guam, but sparsely compared
with the US mainland.

**Pricing** ✅ <https://business.yelp.com/data/products/places-api/>

| Plan | Monthly | Overage per 1,000 | Includes |
|---|---|---|---|
| Base | $229 | $5.91 | Base attributes, no photos, no excerpts |
| Enhanced | $299 | $6.57 | Up to 3 photos and 3 excerpts |
| Premium | $643 | $14.13 | Up to 12 photos, 7 excerpts, Review Highlights |

Free: 30-day trial with 5,000 calls (business email required). Minimum
1,000 calls billed per day. Over 150,000 calls a month needs a sales
contract. No ongoing free tier.

**Storing and caching** ✅ Yelp API Terms (last updated 2026-09-22)
<https://terms.yelp.com/developers/api_terms/>

- §5(a): no storing any Yelp Content longer than 24 hours, except Yelp
  business IDs, which may be kept "solely for back-end matching purposes".
- §5(b): may not modify Yelp Content "or use it to update or create your
  own database of business listing information".
- §4 and §7.5: on termination, delete everything and certify it on request.

**Other restrictions that matter here** ✅ same page

- §5(c): may not display Yelp ratings "alongside or in conjunction with
  other user-generated content", or use them to benefit a Yelp competitor.
  This conflicts with showing Google ratings or our own visitor highlights
  on the same page.
- §5(d): no use "for any commercial purpose without the express written
  consent of Yelp". §1/§5 define non-commercial as use that generates no
  promotional or monetary value. A site selling restaurant ads is
  commercial. Whether a paid plan counts as that consent is not stated;
  get it in writing.
- §5(e), (l), (q): keep Yelp's hyperlinks and tracking parameters intact;
  no URL shorteners.
- §5(g): Yelp geocodes must not be used in OpenStreetMap or contributed to
  open-source maps.
- §5(j): may not let users rate, vote or comment on Yelp Content.

**Display rules (stars and logos).** ⚠️ The Display Requirements page
(<https://docs.developer.yelp.com/docs/display-requirements>) and brand
page (<https://www.yelp.com/brand>) disallow automated fetching, so they
were not read. From prior knowledge, verify by hand: use Yelp's official
star-rating image assets (never redraw stars or show a bare number); show
the Yelp logo or burst next to Yelp content; show the review count with
the stars; link the business name, stars, and review count back to the
Yelp business page using the URL the API returns; attribute every excerpt
to Yelp and its author; follow Yelp's logo size, clear-space, and colour
rules.

**Recommendation: Skip.** Cost, the commercial-use consent requirement,
the 24-hour cap and the ban on showing Yelp ratings next to other UGC make
it a poor fit. Revisit only with a written commercial licence from Yelp.

## 4. Foursquare

### 4a. Foursquare Places API

**Fields offered** ✅
<https://docs.foursquare.com/fsq-developers-places/reference/response-fields>

- Pro: name, location, categories, `tel`, `website`, `social_media`,
  `date_closed`.
- Premium: `rating` (0–10), `stats` (counts of ratings, tips, photos),
  `popularity`, `price` (1–4), `hours`, `menu` (URL), `tips` (text),
  `photos`, `tastes`, `attributes`.

**Guam coverage.** **Not tested**: no key, and the API host is blocked from
this environment.

**Pricing** ✅ <https://foursquare.com/pricing/>: Pro endpoints free for
the first 500 calls a month (the page also advertises 10,000 free Pro calls
in the sandbox; the two figures are not reconciled), then $15 per 1,000.
Premium (any Premium field, tips, photos) from $18.75 per 1,000 with no
free tier.

**Storing and caching** ✅ Usage Guidelines
<https://developer.foursquare.com/docs/usage-guidelines>: Foursquare place
IDs, photo IDs and address IDs may be cached without time limit. Other
attributes: pay-as-you-go and sandbox customers may not cache at all;
enterprise customers may cache for 24 hours on the end user's device only,
"no server-based caching is permitted".

**Attribution and other terms** ✅ API License Agreement (last updated
2024-02-29) <https://foursquare.com/legal/terms/apilicenseagreement/>:
"Powered by Foursquare" branded attribution on every page or screen that
shows the data; no derivative works; no use for other purposes without
written consent; calculated scores and reliability metrics are internal
use only (§2.3); no using the data to market to the listed businesses
(§2.5.4), which matters for ad sales.

**Recommendation: Use with limits.** Only if live per-view Foursquare
ratings or tips are wanted, with "Powered by Foursquare". Otherwise use the
open dataset below.

### 4b. Foursquare Open Source Places (FSQ OS Places)

✅ <https://docs.foursquare.com/data-products/docs/fsq-places-open-source>,
schema <https://docs.foursquare.com/data-products/docs/places-os-data-schema>

- Licence: Apache 2.0. Storable and modifiable; keep the licence and
  copyright notice (put it on the "About our data" page).
- Fields: `fsq_place_id`, name, latitude/longitude, address, locality,
  region, postcode, country, `tel`, `website`, `email`, Facebook /
  Instagram / X ids, category ids and labels, `date_created`,
  `date_refreshed`, `date_closed`, `unresolved_flags`. Delta files give
  add/update/remove/merge per release. No ratings, hours, or tips.
- Guam coverage: **not tested** (download hosts blocked here). Filter on
  `country = 'GU'` after download.
- Use: cross-check OSM, find restaurants OSM lacks, flag closures via
  `date_closed`. Treat `date_refreshed` as a staleness signal and verify
  before publishing.

## 5. Guam DPHSS food-establishment data

**What exists.** The Division of Environmental Health publishes
"DEH Sanitation Inspection Results" as PDFs covering a few weeks each,
linked from <https://www.dphss.guam.gov/reports-resources>. Found by
search on 2026-10-09:

- 07.14.25–09.07.25, 09.08.25–09.28.25, 09.29.25–10.30.25,
  11.07.25–11.25.25, 12.01.25–12.28.25, 03.30.26–04.26.26 (Revised), e.g.
  <https://dphss.guam.gov/sites/default/files/DEH%20Sanitation%20Inspection%20Results%2003.30.26%20-%2004.26.26%20(Revised).pdf>

Gaps: nothing was found for January–March 2026 or after April 2026, and the
listing page shows only three files, so older files fall off it. Keep our
own copy of each PDF's URL and download date.

**Fields** ✅ (read from two PDFs): No., establishment name and village
(one combined column), type of establishment, date of inspection, type of
inspection (regular, follow-up, complaint, assessment), demerits, grade
(A/B/N/A), violated item numbers, comments (including "cease operation"
directives). The March–April 2026 file has 186 rows, 79 of them
restaurants, across 14 village labels. Labels such as "Tumon" and "Upper
Tumon" are not official villages, so map them to Tamuning.

Grade scale (per a Marianas Business Journal summary, not DPHSS itself):
A up to 10 demerits, B 11–20, C 20–40, D (closure) over 41
<https://www.mbjguam.com/keeping-guam-safe-public-health-inspection-updates>.

**No list of permitted establishments** is published on
<https://dphss.guam.gov/sanitary-permits> or the DEH page
<https://dphss.guam.gov/dphss-divisions/division-environmental-health-deh>.
A public-records request to DEH for the sanitary permit roster (name,
address, permit type, status) would be the best restaurant-existence
check on Guam.

**Pricing:** free. **Terms:** none stated on the pages. The rows are
government facts; show them as facts (grade, demerits, date, inspection
type) with the PDF link, and do not reproduce the free-text comments
wholesale. ⚠️ Government of Guam works are not automatically public domain
the way federal works are; confirm with the attorney.

**Display caution.** Show the inspection date beside the grade, say it is
"as published by DPHSS", never let ads affect it (CLAUDE.md rule 5), and
handle matching errors carefully: a wrong match could defame a restaurant.
Require a human check before a B-or-worse grade or a closure appears.

**Recommendation: Use with limits.** Manual or semi-automated PDF import
(parse tables, human-confirm matches), refreshed when a new PDF appears.

## 6. US Census TIGER/Line for Guam villages

✅ <https://catalog.data.gov/dataset/tiger-line-shapefile-2024-state-guam-gu-county-subdivision>

- Dataset: TIGER/Line 2024 County Subdivisions, Guam (state FIPS 66).
  Download: <https://www2.census.gov/geo/tiger/TIGER2024/COUSUB/tl_2024_66_cousub.zip>
  (check for a 2025 edition under `TIGER2025/` first).
- Fields ✅ (checked in the file, 2026-10-10): `STATEFP`, `COUNTYFP`,
  `COUSUBFP`, `COUSUBNS`, `GEOID`, `GEOIDFQ`, `NAME`, `NAMELSAD`, `LSAD`,
  `CLASSFP`, `MTFCC`, `FUNCSTAT`, `ALAND`, `AWATER`, `INTPTLAT`, `INTPTLON`,
  polygon geometry. Datum NAD83 (EPSG:4269), within about a metre of WGS84
  on Guam.
- Coverage ✅: 20 features, the 19 villages plus one "County subdivision not
  defined" feature (GEOID 6601000000, open water, dropped). The Census uses
  official Chamorro names for several villages (Hågat, Inalåhan, Malesso',
  Humåtak, Talo'fo'fo, Sånta Rita-Sumai, Asan); our slugs use the common
  English names and map by GEOID.
- Stored as `wp-content/plugins/guam-restaurants-core/data/guam-villages.geojson`,
  built by `bin/build-village-geojson.py` from the 2024 edition (zip SHA-256
  `f90487aa2f5cce7ad712c0c2ad8cf6478c1e46d31e6103e9b8af9f78cccd8cdd`).
  census.gov is blocked from the cloud environment, so the zip was
  downloaded by hand.
- Licence: CC0 1.0 per data.gov. Store permanently. Attribution not
  required; credit "US Census Bureau TIGER/Line" anyway. Free, no limits.
- Use: point-in-polygon on **OSM** (not Google) coordinates to tag each
  restaurant's village. Informal areas (Tumon, Upper Tumon, Harmon) are
  search aliases of Tamuning, not separate terms (decision 2026-10-10).

**Recommendation: Use.**

## 7. Answers to the five questions

**(1) How long may star ratings and review counts be cached?**

| Source | Ratings and counts |
|---|---|
| Google | Not at all. Only the place ID may be stored (indefinitely) and lat/lng for 30 days. Fetch rating and `userRatingCount` live. |
| Yelp | Up to 24 hours, then delete. Only business IDs may be kept longer. |
| Foursquare API | Pay-as-you-go: not at all. Enterprise: 24 hours on the user's device only, never on our server. |
| OSM, TIGER, FSQ OS | No ratings offered. |

**(2) May review text be displayed, how much, and with what attribution?**

- Google: yes, as returned (up to 5 reviews ⚠️), unmodified and unfiltered
  (also CLAUDE.md rule 4). Credit the author (avatar at minimum; name and
  profile link when space allows), show the Google Maps logo or text,
  link each review to Google Maps and provide the report link, and show a
  notice explaining ordering. Summaries need the "Review summary" heading
  and Google's disclosure text. Subject to the directory-use blocker in §2.
- Yelp: only the excerpts the API returns (up to 3 or 7 per business,
  160 characters each, depending on plan), unmodified, with Yelp's
  hyperlink intact, Yelp branding, and author attribution; not next to
  other UGC; commercial use needs written consent.
- Foursquare: tips are a Premium field; show as returned with "Powered by
  Foursquare"; no server caching.

**(3) May rating history be stored?**
No for every commercial source: Google forbids caching ratings, Yelp caps
storage at 24 hours, Foursquare forbids server caching. A "rating over
time" chart can only use first-party data (our own visitor highlights or
editorial scores). Change tracking on facts (open/closed, address, hours
from OSM and owner submissions, DPHSS grades, awards) is fine.

**(4) What display rules apply to Yelp stars and logos?**
⚠️ Not verified (Yelp's display-requirements and brand pages block
automated reading). From prior knowledge: official Yelp star images only,
Yelp logo or burst beside the content, review count shown with the stars,
link back to the Yelp business page with the API-supplied URL, follow
Yelp's size and clear-space rules. Verified from the API Terms: no implied
endorsement by Yelp (§5(h)), no suggestion Yelp is the author of our
content (§5(o)), keep Yelp links and tracking parameters (§5(e), (l)), and
no Yelp ratings alongside other user-generated content (§5(c)).

**(5) What do Google's terms require when ratings are shown outside a
Google map?**
Showing Places content with no map is allowed (Service Terms §14.1), but
it must never appear with or near a non-Google map (§14.2, ToS §3.2.3(e)),
so a page with our OSM map cannot also show Google ratings. Show the
Google Maps logo (or "Google Maps" text if space is tight) unaltered, any
third-party attributions, author credit for reviews, links to Google Maps
and to report content, and an ordering notice. Ratings are fetched live,
not stored. Separately, ToS §3.2.3(d)(iii) bars using Core Services in a
listings or directory service, which is why §2 recommends skipping Google.

## 8. Pika's Best of Guam (Pacific Daily News)

Sources and research in `docs/awards-research.md` (§5, §7).

| Source | URL | Use |
|---|---|---|
| 2023 winners PDF | <https://guampdn.biz/pikasbestofguam/2023-Winners.pdf> | Facts: year, category, placing |
| 2023 voting page | <https://www.guampdn.biz/advertising/pikabestofguam/> | Schedule facts |
| PDN main site, Internet Archive | guampdn.com, web.archive.org (blocked here; check by hand) | Earlier years |

**Recommendation: Use with limits.** Record facts only (award name, year,
category, placing) with a source URL per fact; no copied text, images or
logo. Hold off importing full winner lists until PDN answers the
permission email in `docs/awards-research.md` §8.

## 9. Data policy table

| Data | Source | Policy | Expiry / refresh |
|---|---|---|---|
| Restaurant name, coordinates, cuisine, address, phone, website, hours | OSM (ODbL) | **Stored permanently** in a separate OSM layer, with attribution and share-alike | Re-import weekly |
| Mall polygons | OSM | **Stored permanently** | Re-import monthly |
| POI cross-check fields, closed date | FSQ OS Places (Apache 2.0) | **Stored permanently** | Each release (use delta files) |
| Village boundaries | Census TIGER/Line (CC0) | **Stored permanently** | Yearly |
| Inspection grade, demerits, date, type, PDF URL | DPHSS PDFs | **Stored permanently** as dated facts, history kept | When a new PDF appears |
| Award year, category, placing, source URL | Pika's Best (PDN) | **Stored permanently** as facts | Yearly, after winners announced |
| Editorial copy, highlights, owner submissions, ad and sponsor data | First-party | **Stored permanently** | n/a |
| Google place ID | Google | **Stored permanently** (only if Google is ever approved) | n/a |
| Google lat/lng | Google | **Cached with expiry** (30 days), never used for village/mall tagging | 30 days |
| Yelp business ID | Yelp | **Stored** for back-end matching only (only if Yelp is ever licensed) | Delete on termination |
| Yelp rating, count, excerpts, details | Yelp | **Cached with expiry**, 24 hours max (only if licensed) | 24 hours |
| Foursquare place ID | Foursquare API | **Stored permanently** (performance only) | n/a |
| Google rating, count, reviews, hours, summaries | Google | **Fetched live** per view, never stored (only if approved) | n/a |
| Foursquare rating, hours, tips, photos | Foursquare API | **Fetched live** per view (pay-as-you-go) | n/a |
| Map tiles | OSM-based tile provider (to be chosen; osm.org tiles are not for production) | **Fetched live** | n/a |

## 10. Research-only references (not data sources)

Competitor pages reviewed for `docs/competitive-analysis.md`
(guamfoody.com, visitguam.com, tableagent.com, wanderlog.com,
honolulumagazine.com, theguamguide.com) are research references, not
data sources. No listing data from them may be imported (CLAUDE.md rules
1 and 3). Legal sources are listed in `docs/legal-and-policy.md`.

## 11. Every URL checked for this document

| URL | Result |
|---|---|
| https://overpass-api.de/api/interpreter | Blocked by network proxy; robots.txt disallows fetch tools |
| https://overpass.kumi.systems, https://overpass.private.coffee, https://maps.mail.ru/osm/tools/overpass | Blocked by network proxy |
| https://nominatim.openstreetmap.org, https://taginfo.openstreetmap.org, https://api.openstreetmap.org | Blocked by network proxy |
| https://wiki.openstreetmap.org/wiki/Overpass_API | Read |
| https://osmfoundation.org/wiki/Licence/Attribution_Guidelines | Read |
| https://developers.google.com/maps/documentation/places/web-service/policies | Read (updated 2026-10-07) |
| https://developers.google.com/maps/documentation/places/web-service/place-details | Read |
| https://developers.google.com/maps/documentation/places/web-service/usage-and-billing | Read |
| https://developers.google.com/maps/billing-and-pricing/pricing | Read |
| https://mapsplatform.google.com/pricing/ | Read |
| https://cloud.google.com/maps-platform/terms | Read (modified 2026-08-26) |
| https://cloud.google.com/maps-platform/terms/maps-service-terms | Read (modified 2026-06-10) |
| https://cloud.google.com/maps-platform/terms/maps-services | Read |
| https://terms.yelp.com/developers/api_terms/ | Read (updated 2026-09-22) |
| https://business.yelp.com/data/products/places-api/ | Read |
| https://docs.developer.yelp.com/docs/display-requirements | Robots disallowed; not read |
| https://docs.developer.yelp.com/docs/fusion-display-requirements | Error |
| https://www.yelp.com/developers/display_requirements, https://www.yelp.com/brand | Robots disallowed; not read |
| https://api.yelp.com | Blocked by network proxy |
| https://foursquare.com/pricing/ | Read |
| https://foursquare.com/legal/terms/apilicenseagreement/ | Read (updated 2024-02-29) |
| https://developer.foursquare.com/docs/usage-guidelines | Read |
| https://docs.foursquare.com/fsq-developers-places/reference/response-fields | Read |
| https://docs.foursquare.com/data-products/docs/fsq-places-open-source | Read |
| https://docs.foursquare.com/data-products/docs/places-os-data-schema | Read |
| https://docs.foursquare.com/developer/docs/pricing-and-billing, https://docs.foursquare.com/developer/docs/attribution | Error |
| https://places-api.foursquare.com | Blocked by network proxy |
| https://dphss.guam.gov/sanitary-permits | Read; no establishment list |
| https://dphss.guam.gov/dphss-divisions/division-environmental-health-deh | Read; no results listed |
| https://www.dphss.guam.gov/reports-resources | Read; three inspection PDFs linked |
| DEH Sanitation Inspection Results 09.08.25–09.28.25 and 03.30.26–04.26.26 PDFs | Read (structure and counts) |
| DEH Sanitation Inspection Results 09.29.25–10.30.25 PDF | Fetch error |
| https://www.mbjguam.com/keeping-guam-safe-public-health-inspection-updates | Read |
| https://catalog.data.gov/dataset/tiger-line-shapefile-2024-state-guam-gu-county-subdivision | Read |
| https://www2.census.gov/geo/tiger/, https://tigerweb.geo.census.gov | Blocked by network proxy |

## 12. To do before the first import

1. Run the three Overpass queries in §1 by hand and log counts.
2. ~~Download the TIGER Guam county-subdivision file; confirm 19 villages.~~
   Done 2026-10-10 (see §6).
3. Read Yelp's display requirements by hand only if Yelp is reconsidered.
4. Ask the Guam attorney about Google ToS §3.2.3(d)(iii), Yelp §5(d), and
   reuse of Government of Guam inspection PDFs.
5. Send DEH a records request for the sanitary permit roster.
6. Pick a production map tile provider and record its terms here.
