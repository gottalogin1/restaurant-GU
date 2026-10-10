# Competitive Analysis: Guam and Regional Food Directories

_Prepared 2026-10-09. Public pages only. No text or images were copied; every description below is a paraphrase of what was observed. Each site's robots.txt was checked before its pages were opened (see the URL log at the end)._

## How this was done, and its limits

- Pages were read once, on 2026-10-09, with a text-only fetch tool. Content that loads through JavaScript after the page renders (for example map widgets, the GuamFoody truck list, and Visit Guam's restaurant cards) was often not captured. Where a feature could not be seen, it is marked **(not verified)** rather than reported as missing.
- Field-level observations come from one or two sample listings per site, not a full audit. Counts are what the page itself reported.
- **robots.txt note on guamfoody.com:** its robots.txt blocks a list of named AI crawlers (including ClaudeBot, Claude-Web, and anthropic-ai) from the whole site. The user-initiated fetch agent used here is not on that list and falls under the general rules, which allow `/places/`. Out of respect for the evident intent, review was limited to six pages viewed one at a time, the way a person would browse, with no crawling and no bulk collection. **Recommendation:** no future automated work (imports, monitoring, link-checking) should touch guamfoody.com.
- TripAdvisor was considered and **not reviewed**: its robots.txt blocks ClaudeBot sitewide and its terms bar automated access. Yelp and Google Maps were excluded per project rule 1; they are covered as data sources in `docs/data-sources.md`, not as competitors.

## Sites reviewed

| # | Site | Type | Scope |
|---|---|---|---|
| 1 | guamfoody.com | Independent Guam food directory (WordPress) | Guam, 700+ listings claimed |
| 2 | visitguam.com, Restaurants | Guam Visitors Bureau tourism directory | Guam, 87 restaurants |
| 3 | tableagent.com (Guam and Oahu) | Multi-market directory with a reservation product | Guam has 7 listings; Oahu is larger |
| 4 | wanderlog.com Guam lists | Travel-planner app with auto-compiled "best of" lists | Per-village lists (Dededo: 36) |
| 5 | honolulumagazine.com, Food & Drink | Regional city magazine with an awards program (Hale ʻAina) | Oʻahu and Hawaiʻi |
| 6 | theguamguide.com, Restaurant Guide | Guam lifestyle and visitor blog | Guam, article-based |

---

## 1. GuamFoody (guamfoody.com)

The incumbent and the most direct competitor.

- **Content types:** restaurant, food truck, café, and bar listings; hosted digital menus (the site claims about 330 restaurants and roughly 20,000 menu items); recipes; a closed-restaurants memorial page; a food truck tracker; job board; tours; an online store and digital products; press mentions.
- **Navigation and filters:** keyword search with a "Near" field; a village filter (about 35 areas, with counts); about 60 cuisine and category tags with A–Z, popular, count, new, and favorites sorts; curated sets for Pika's Best and Mom & Pop. Several grid and list layouts. **No map view was seen** (not verified for JS-loaded content). No price, open-now, or mall filters were seen.
- **How restaurants are organized:** by village and by category. Malls appear only incidentally (in comment titles and on the closed list), not as a browse dimension. "Pika's Best" is a filter flag, not a per-year award history.
- **Listing fields (sample: a Thai restaurant in Tamuning):** name, partial street address (no number), village, phone, weekly hours table, short description, posted date. Missing on that sample: website, social links, price range, visible cuisine tag, photos, map or coordinates, menu, awards, closure flag, delivery links, amenities. Listing cards on the index pages often lack photos and hours.
- **Ratings and reviews:** first-party only. Logged-in users leave comments with stars; the sample showed one review. No third-party ratings, no verification signal, no review policy seen.
- **Monetization:** an "Ads" section on a separate subdomain (terms not visible), a $5/month consumer "Plus" tier for cross-restaurant price comparison, free owner menu hosting (likely a lead-in to paid products), job postings, tours, store and digital products. **No "Sponsored" or "Featured" labels were seen on listings** (not verified whether paid placement exists).
- **Visible gaps:**
  - Thin listings: missing address numbers, websites, photos, price, and coordinates on samples.
  - No map browsing seen.
  - No mall or shopping-center browsing.
  - Awards shown only as a single flag, with no year or category.
  - No change history: only a posted date is visible; the modified date exists only in page metadata.
  - Closed restaurants live on a separate narrative page (about 45 entries) with mixed date precision (some exact, some year-only or approximate), uneven sourcing, and at least one internal inconsistency (an entry filed under one mall with another center's address). There's no closure flag on the listing itself.
  - Truck tracker showed no active trucks at capture time (may load dynamically; not verified).
  - Search result pages are blocked in robots.txt, so search engines can't index faceted results.

## 2. Visit Guam: Restaurants (Guam Visitors Bureau)

- **Content types:** restaurants as one category of a tourism listing system, alongside attractions, beaches, and shopping. A separate CHamoru food culture section.
- **Navigation and filters:** grid, list, and **map views**; about 24 cuisine checkboxes; a village filter (about 10 shown, more behind "View More"); sort by recommended or distance. No price, mall/hotel, open-now, or rating filters.
- **Organization:** cuisine plus village. Tumon is treated as a village. 87 restaurants total, which is far below the actual count on island.
- **Listing fields (sample: a Tumon beach bar):** name, location without a street number, phone, email, website (through a tracking redirect), social links, a photo gallery, and embedded videos. Missing: hours, price, cuisine as a structured field, map pin on the detail page, menu, ratings.
- **Ratings and reviews:** none. A TripAdvisor partner logo appears sitewide only.
- **Monetization:** none visible per listing. A "Membership Application" link suggests listings are tied to bureau membership (not verified). Cross-promotions such as airline discounts and a trip builder.
- **Visible gaps:** small, visitor-focused subset of restaurants; no hours; no ratings; no update dates; no awards.

## 3. TableAgent (tableagent.com: Guam and Oʻahu)

- **Content types:** restaurant listings bundled with a reservation and online-payment product sold to restaurants. Gift cards.
- **Navigation and filters:** browse by location, cuisine, price, star rating, feature (for example ocean view, big-screen TV, takeout), meal type, occasion, and a "Best Restaurants" set; keyword search; 10-language selector. The facet design is the strongest here, but the data behind it is sparse.
- **Organization:** market, then facets. Guam has only **7 listings**, including one duplicate restaurant at two URLs with conflicting prices.
- **Listing fields (samples in Guam and Ewa Beach):** address, phone, hours, website (sometimes blank), cuisine, price level in $ signs plus a word label, feature checklist, reservation form. Missing: map, photos (empty section), menu (label only), last-updated date.
- **Ratings and reviews:** an internal 5-star score built from site votes (both samples rested on **one vote**); no review text.
- **Monetization:** SaaS sold to restaurants (reservations, payments, pricing page) and "submit or update your restaurant." No display ads seen.
- **Visible gaps:** very low Guam coverage; template leftovers (stray values, conflicting hours, hours all marked closed next to bookable slots); no freshness signals; ratings with no statistical weight.

## 4. Wanderlog (wanderlog.com Guam village lists)

- **Content types:** auto-generated "best restaurants in [village]" lists inside a trip-planning app. Lists exist per village and category.
- **Navigation and filters:** list pages per place and category; one map for the whole list; links to itineraries and nearby lists. No in-list filters.
- **Organization:** **by village**, which is notable, ranked by how often places are reviewed or mentioned elsewhere (the page gives two slightly different explanations of its method).
- **Listing fields:** name, Google and TripAdvisor ratings with review counts, category, occasional price, address, website, phone, a machine-written summary, and Google review snippets with reviewer names. **"Permanently closed" flags** appear on some entries. Hours not shown.
- **Ratings and reviews:** entirely third-party (Google, TripAdvisor), shown with source attribution.
- **Monetization:** app installs, hotel-booking widget, trip-planner upsell.
- **Visible gaps:** no local knowledge; closed places still occupy ranked slots; Dededo list last updated Oct 17, 2025; no malls, awards, or owner-supplied data.

## 5. Honolulu Magazine: Food & Drink (regional benchmark)

Chosen as the closest model for an awards-led regional food brand (comparable to how Pika's Best works on Guam).

- **Content types:** news on openings and closings, curated best-of lists, cheap-eats and happy-hour roundups, "we tried it" pieces, the annual **Hale ʻAina Awards**, and a "Restaurants We Love" feature.
- **Navigation and filters:** sections and tags; **19 neighborhood category pages**; site search. No restaurant database or structured filters.
- **Organization:** editorial articles, not listings. Awards are arranged by year (newest first), with one article per winner labeled by category and older years in a category archive.
- **Listing fields:** none structured. Winners link to articles, not profiles; no address or map on the awards hub.
- **Ratings and reviews:** no stars; recognition is through awards and editorial picks.
- **Monetization:** print and digital subscriptions, newsletters, display advertising with a media kit, ticketed events (the awards gala), a branded-content team, and a presenting sponsor for the awards. **Branded content was identified by byline only, with no explicit "Sponsored" label seen.**
- **Visible gaps (for a directory use case):** no searchable directory; awards don't connect to restaurant data; no change tracking.

## 6. The Guam Guide (theguamguide.com)

- **Content types:** lifestyle and visitor articles; the "Restaurant Guide" is a set of themed roundups (new cafés by month, spicy dishes, buffets, late-night eats) and single-restaurant features.
- **Navigation and filters:** none for food; no search facets, categories, or map.
- **Organization:** articles in reverse chronological order. Only 8 articles in the restaurant guide.
- **Listing fields:** none structured; cards show a headline and a summary.
- **Ratings and reviews:** none.
- **Monetization:** none visible; the publisher is not named.
- **Visible gaps:** last dated roundup is from 2024; no directory at all.

---

## Cross-site comparison

| Capability | GuamFoody | Visit Guam | TableAgent | Wanderlog | Honolulu Mag | Guam Guide |
|---|---|---|---|---|---|---|
| Guam coverage | High (700+) | Low (87) | Very low (7) | Medium (per village) | n/a | Very low |
| Village browsing | Yes | Yes | Location (unpopulated) | Yes | Neighborhoods | No |
| Mall / shopping-center browsing | No | No | No | No | No | No |
| Cuisine filter | Yes | Yes | Yes | No | No | No |
| Price filter | No | No | Yes | No | No | No |
| Feature / amenity filter | Partial (24-hour, breakfast) | No | Yes | No | No | No |
| Open-now filter | No | No | No | No | No | No |
| Map view | Not seen | Yes | No | List-level | No | No |
| Hours on listing | Yes | No | Yes (unreliable) | No | No | No |
| Third-party ratings | No | No | No | Google + TripAdvisor | No | No |
| First-party reviews | Yes (thin) | No | Votes only | No | No | No |
| Awards with year and category | Flag only | No | No | No | Yes | No |
| Closed-restaurant handling | Separate page | Not seen | Not seen | Inline flag | Articles | No |
| Visible "last updated" / change log | No | No | No | List date only | Article dates | Article dates |
| Owner claim / update | Add listing, vendor dashboard | Membership (not verified) | Submit / update | No | No | No |
| Sponsored labels seen | No | No | No | No | Byline only | No |
| Hosted menus | Yes (strong) | No | No | No | No | No |

## Monetization patterns observed

1. **Restaurant-side SaaS or tools** (TableAgent reservations; GuamFoody menu hosting and vendor dashboard).
2. **Display and native advertising** (Honolulu Magazine media kit; GuamFoody Ads subdomain).
3. **Consumer subscriptions** (GuamFoody Plus at $5/month; magazine subscriptions).
4. **Events and sponsorship** (Hale ʻAina gala with a presenting sponsor).
5. **Travel commerce** (Wanderlog hotel booking; Visit Guam airline and trip promotions).
6. **Jobs, tours, merchandise** (GuamFoody).

None of the six visibly labels paid placement as "Sponsored" in listing results. This is an opening for a site that does it clearly (project rule 5).

---

## Ranked features that would make a new site more thorough

Ranked by how much each closes a gap that no competitor fills, weighted by how much a Guam diner would notice it.

1. **Complete, structured listings with a visible completeness standard.** Full street address, village, coordinates, phone, website, socials, hours, price level, cuisines, and closure status on every listing, with a "fields verified" indicator. Every competitor has thin or inconsistent records.
2. **Change tracking and freshness on every listing.** A visible "last verified" date and a public change log (opened, moved, renamed, hours changed, closed), including inline closure flags instead of a separate memorial page. No competitor shows this.
3. **Mall and shopping-center browsing.** Browse by Micronesia Mall, GPO, Agana Shopping Center, Tumon Sands Plaza, and hotels, alongside villages. Nobody offers it, and it matches how people on Guam actually look for food.
4. **Map-first browsing with filters.** A map plus list combining village, mall, cuisine, price, open now, and features. Only Visit Guam has a map, and it covers just 87 places.
5. **Awards history by year and category.** Pika's Best (and others) shown on each restaurant as year and category, with a source link and an awards archive page. GuamFoody has only a flag; Honolulu Magazine shows the model but doesn't tie it to listings.
6. **Attributed third-party ratings alongside first-party highlights.** Ratings from licensed APIs with clear source attribution and compliant caching (see `docs/data-sources.md`), kept separate from editorial picks. Wanderlog shows the demand; GuamFoody's single-review stars show the cold-start problem.
7. **"Open now" and accurate hours,** including holiday and temporary-closure notes, with owner self-service updates.
8. **Owner claim and update workflow with verification,** so restaurants keep their own data current; this also supports ad sales.
9. **Clear "Sponsored" labeling and a published editorial policy** stating that ads never affect ratings, awards, or ranking. No competitor shows labels, so this is a trust differentiator.
10. **Food truck and pop-up schedules** that owners update. GuamFoody's tracker showed no active trucks at capture time.
11. **Indexable facet pages** (for example "Korean in Tamuning" or "Food at Micronesia Mall") with unique, original descriptions for SEO. GuamFoody blocks its search pages in robots.txt.
12. **Dietary and feature tags** (halal, vegetarian, kid-friendly, late night, parking, delivery apps), following TableAgent's facet model but with real data behind it.
13. **Menu links or owner-supplied menus.** GuamFoody's hosted-menu depth is hard to match early; start with links and owner uploads.
14. **Multilingual UI (English, Japanese, Korean)** for Guam's main visitor markets. Only TableAgent offers languages, and it doesn't localize Guam data.

---

## URL log (every URL checked, 2026-10-09)

| URL | Result |
|---|---|
| https://guamfoody.com/robots.txt | Read. Blocks named AI crawlers (ClaudeBot, Claude-Web, anthropic-ai, GPTBot, and others) sitewide; `*` allows `/places/` |
| https://guamfoody.com/ | Read |
| https://guamfoody.com/places/tas-thai-restaurant/ | Read (sample listing) |
| https://guamfoody.com/places/?pikas=best | Read |
| https://guamfoody.com/rip-foody/ | Read |
| https://guamfoody.com/truck-tracker/ | Read; truck data not captured (likely JS) |
| https://ad.guamfoody.com/robots.txt | Fetch error; Ads subdomain therefore **not opened** |
| https://www.visitguam.com/robots.txt | Read. No Claude agents named; `*` allowed; crawl-delay 2 |
| https://www.visitguam.com/ | Read |
| https://www.visitguam.com/things-to-do/restaurants/ | Read; cards not captured (likely JS), count from URL parameters |
| https://www.visitguam.com/listing/the-beach-restaurant-%26-bar/343/ | Read (sample listing) |
| https://tableagent.com/robots.txt | Read. `*` Allow all |
| https://tableagent.com/guam/ | Read |
| https://tableagent.com/guam/pikas-cafe/ | Read (sample listing) |
| https://tableagent.com/oahu/meal-type/dinner/ | Read |
| https://tableagent.com/oahu/restaurant-tombo/ | Read (sample listing) |
| https://wanderlog.com/robots.txt | Read. `/list/` allowed |
| https://wanderlog.com/list/geoCategory/206366 | Read (Dededo list) |
| https://www.honolulumagazine.com/robots.txt | Read. No Claude agents named; `*` allowed |
| https://www.honolulumagazine.com/dining/ | Read |
| https://www.honolulumagazine.com/the-hale-aina-awards/ | Read |
| https://theguamguide.com/robots.txt | Read. `/food/` allowed |
| https://theguamguide.com/ | Read |
| https://theguamguide.com/food/restaurant-guide | Read |
| https://www.tripadvisor.com/robots.txt | Read. ClaudeBot blocked sitewide; **TripAdvisor pages not opened** |

Search queries used to find comparables (results pages only, not opened unless listed above): "Guam restaurant directory dining guide"; "Hawaii local restaurant directory guide Honolulu dining search by neighborhood"; "Saipan restaurants directory Marianas dining guide"; "Frolic Hawaii restaurant guide Honolulu Star-Advertiser"; "Guam restaurants list by village Tumon Hagatna Dededo directory map"; "site:visitguam.com/listing restaurant". No Saipan/CNMI directory with structured listings turned up.

## Claims not verified

- Whether GuamFoody, Visit Guam, or TableAgent render a map or extra listing data through JavaScript.
- GuamFoody's advertising products and prices (Ads subdomain not opened) and whether any paid placement exists.
- Whether Visit Guam restaurant listings require bureau membership.
- How Hale ʻAina voting works.
- GuamFoody's 700+ listing and menu counts (self-reported on its homepage).
- The publisher of theguamguide.com.
