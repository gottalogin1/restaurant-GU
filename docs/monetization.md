# Monetization and launch rate card

Last updated 2026-10-10. All prices are USD.

This defines every ad product the site sells, its launch price, and the order
to launch them in. Benchmarks and Guam quotes behind these prices are logged in
[market-rates.md](market-rates.md), which also holds the repricing rule. Every
product here is bound by [editorial-policy.md](editorial-policy.md): nothing a
restaurant buys changes ratings, awards, rankings, reviews, or which
restaurants are listed.

## Rules that apply to every product

- **Label.** Every paid placement carries the label "Sponsored" on the site
  (CLAUDE.md rule 5). Whether to use "Sponsored · Ad" instead is still open
  with counsel ([launch-checklist.md](launch-checklist.md), Decisions).
- **Outside the organic order.** Paid placements sit in their own labeled
  slots, set apart by a border or shading. A restaurant that pays also appears
  in its normal organic position, with no boost.
- **No editorial words.** Visitors never see "Featured," "Top pick," or
  "Recommended" on a paid placement ([legal-and-policy.md](legal-and-policy.md)
  §3.2). "Featured listing" below is the product's name on this rate card and
  in contracts only; on the site it shows as "Sponsored."
- **No awards in ads.** Awards are never part of an ad package, featured
  listing, or sponsor offer, and ad creative the site produces never names an
  awards program ([awards-research.md](awards-research.md); the awards scope
  in [progress.md](progress.md)).
- **No highlights for sale.** Owner quotes, editor's notes, and reviews are
  free and are never part of a package.
- **Links.** Links in paid placements and sponsored features use
  `rel="sponsored"`.
- **Measurement.** Impressions and clicks are counted per ad per day in the
  `gr_ad_daily_stats` table (no personal data). Advertisers get a monthly
  report from those counts.

## Products and launch prices

| # | Product | Launch price | Term |
|---|---|---|---|
| 1 | Claimed basic listing | Free | None |
| 2 | Premium profile | $39/month or $390/year | Monthly or annual |
| 3 | Featured listing | $75/month per page | Monthly |
| 4 | Category sponsor | $150/month (village or mall page), $250/month (top cuisine page) | 6 or 12 months |
| 5 | Banner | $20 per 1,000 impressions, $100 monthly minimum | Monthly |
| 6 | Homepage leaderboard (exclusive) | $400/month | Monthly, after the traffic threshold |
| 7 | Deal or event post | $25 for 7 days, $60 for 30 days | Per post |
| 8 | Newsletter sponsor | See tiers below | Per issue |
| 9 | Sponsored feature | $200 | Per feature |
| 10 | Hotel and tour operator ads | 1.5 × restaurant rates | As the product |
| 11 | Affiliate links | Commission from partner | None |
| 12 | Pick for me sponsor (one ad above each random pick) | $150/month (one village, mall or food type), $250/month (every pick) | Monthly |

### 1. Claimed basic listing (free)

An owner claims their restaurant's existing listing: they confirm with a
code sent to an email at the restaurant's website domain (or, when switched
on, a text to its listed phone), then an administrator approves and the
account is linked (the `gr_claimed_by_user_id` meta). Built 2026-10-10; see
[billing.md](billing.md) §5-6. The owner can:

- edit opening hours, up to 5 menu links, up to 5 specials and up to 8 photos,
  live at once and logged in the change log as the owner's;
- see listing views, website and phone clicks, and their ads' performance on
  the My restaurant dashboard;
- post an owner quote and signature dishes ("From the owner"), pending
  administrator approval like every highlight;
- post one public response to each review;
- report other fact errors (phone, address, closed or moved) through the
  correction form, published once verified.

Claiming is free because it is how owners correct facts, and because claimed
owners are the people the paid products are sold to.

### 2. Premium profile ($39/month or $390/year)

Richer presentation on the restaurant's own page. Built 2026-10-10 and sold
online through WooCommerce with PayPal ([billing.md](billing.md) §4), as
prepaid 1-month or 12-month time with reminders, not automatic renewal.
While it runs, the page gets:

- a call-to-action button (Order online, Reserve a table, See the full menu,
  Ask about catering or Book an event) with the owner's link;
- up to 20 photos supplied by the restaurant instead of 8;
- no other restaurant's paid placements on that restaurant's own page.

Menu links and the views and clicks report are free for every claimed owner
(§1). The "From the owner" section says the restaurant pays for a premium
profile.

A premium profile never changes the restaurant's position in any list,
search, or map, and never changes its ratings, awards, or reviews. The annual
price is ten months' cost for twelve.

### 3. Featured listing ($75/month per page)

A card in the labeled sponsored area of one village, mall, or cuisine page.
Each page has **3 rotating slots**: up to three restaurants share the area, and
the order rotates on each page view so each gets about the same impressions.
The card shows the label "Sponsored" and sits outside the organic list. Mall
pages can be sold only once the mall is verified, because unverified malls are
hidden from visitors.

### 4. Category sponsor ($150 or $250/month, 6- or 12-month term)

One exclusive sponsor per page, shown in a labeled "Sponsored" unit near the
top of the page. Only one category sponsor is sold per page at a time;
featured listing slots on the same page are sold separately.

- Village or mall page: $150/month.
- Top cuisine page: $250/month. Until traffic data exists, "top" means the
  five cuisines with the most listed restaurants. Other cuisine pages sell at
  $150. After the 90-day repricing review, "top" means the five cuisine pages
  with the most page views.
- Terms: 6 or 12 months, paid monthly.

### 5. Banner ($20 CPM, $100 monthly minimum)

A display ad in a sponsored slot, sold by impressions at $20 per 1,000. The
$100 minimum buys 5,000 impressions a month. Banners are served, labeled
"Sponsored" and counted by the plugin's own ad system (see
[Ad serving](#ad-serving) below); Advanced Ads is not used.

### 6. Homepage leaderboard, exclusive ($400/month)

One advertiser owns the top banner on the home page. It goes on sale only after
the site passes **40,000 page views a month**. Measure that as two consecutive
calendar months above 40,000 in analytics, so one spike does not trigger it.

### 7. Deal or event post ($25 for 7 days, $60 for 30 days)

A short offer or event (happy hour, holiday menu, live music) shown on the
restaurant's page and in a labeled "Sponsored" deals list, removed
automatically when the paid period ends. The restaurant supplies the offer and
is responsible for honoring it. Posts are labeled "Provided by the restaurant"
as well as "Sponsored."

### 8. Newsletter sponsor (per issue)

One sponsor block per issue, labeled "Sponsored" and placed apart from the
issue's own content. It follows CAN-SPAM ([legal-and-policy.md](legal-and-policy.md) §3.4).

| Subscribers | Price per issue |
|---|---|
| Under 2,000 | $40 to $75 |
| 2,000 to 5,000 | $75 to $150 |
| 5,000 to 10,000 | $150 to $300 |

Price within a band by the list's size and open rate at the time of sale.

**Platform: SendFox.** The plugin assembles a draft every Monday morning
(Guam time) from the week's openings, closures, new award results and most
viewed listings, with the sponsor block last and labeled "Sponsored"
(Restaurants > Newsletter; set the sponsor there before Monday). A person
reviews and sends every issue; nothing is sent automatically. The SendFox
API, which creates the draft campaign, needs a paid SendFox plan ([API
requests](https://help.sendfox.com/article/309-creating-api-requests),
[endpoints](https://help.sendfox.com/article/278-endpoints), checked
2026-10-10); without it the screen shows the HTML to paste into a new
campaign. The sponsor never affects which restaurants appear in the issue.

### 9. Sponsored feature ($200)

An article about one restaurant or event, written by our staff and paid for by
the advertiser. How it is written and labeled is in
[editorial-policy.md](editorial-policy.md#sponsored-features). In short: it is
labeled "Sponsored" at the top, says who paid for it, carries no ratings,
rankings, or award claims, and is never presented as an editor's note.

### 10. Hotel and tour operator ads (1.5 × restaurant rates)

Hotels, resorts, and tour operators buying any ad product pay 1.5 times the
restaurant price:

| Product | Restaurant rate | Hotel and tour rate |
|---|---|---|
| Featured listing | $75/month | $112.50/month |
| Category sponsor, village or mall | $150/month | $225/month |
| Category sponsor, top cuisine | $250/month | $375/month |
| Banner | $20 CPM, $100 min | $30 CPM, $150 min |
| Homepage leaderboard | $400/month | $600/month |
| Pick for me sponsor, one village, mall or food type | $150/month | $225/month |
| Pick for me sponsor, every pick | $250/month | $375/month |
| Deal or event post | $25 / $60 | $37.50 / $90 |
| Newsletter sponsor | band price | 1.5 × band price |
| Sponsored feature | $200 | $300 |

A restaurant inside a hotel that buys a premium profile for its own listing
pays the restaurant rate.

### 11. Affiliate links

Links to delivery and reservation partners that pay a commission. Each link is
disclosed next to it ("We may earn a commission if you order through this
link") and uses `rel="sponsored"`. Commission never decides which restaurants
are listed or how they are ordered, and an affiliate link is never the only
way to contact a restaurant. Each partner's terms are checked and listed in
[data-sources.md](data-sources.md) before its links go live.

### 12. Pick for me sponsor ($150 or $250/month)

One labeled "Sponsored" box above every result on the random place picker (/what-to-eat/, product `picker_sponsor`). It can run island-wide or only when the visitor chose certain villages, malls or food types; one ad per pick. The random pick below it is never changed by it: every matching restaurant keeps the same chance. While nothing is booked, the box invites businesses to advertise and links to the advertise page.

- **$150/month:** shown when the visitor chose one village, mall or food type (priced like a category sponsor; Sanjay, 2026-10-20).
- **$250/month:** shown above every pick, island-wide.

## Founding-advertiser discount

- **30 percent off** the launch price, locked for six months, for advertisers
  who sign a **six-month term**.
- **Who qualifies:** advertisers who sign before the 90-day repricing review
  in [market-rates.md](market-rates.md#repricing-rule).
- **Which products:** the recurring ones: premium profile, featured listing,
  category sponsor, Pick for me sponsor, and banner. One-off products (deal posts, sponsored
  features, newsletter issues) and the homepage leaderboard are excluded.
- **After six months:** the term renews at the rate card in effect then. The
  advertiser gets 30 days' notice of any change.

| Product | Launch price | Founding price (6-month term) | Term total |
|---|---|---|---|
| Premium profile | $39/month | $27.30/month | $163.80 |
| Featured listing | $75/month | $52.50/month | $315.00 |
| Category sponsor, village or mall | $150/month | $105/month | $630.00 |
| Category sponsor, top cuisine | $250/month | $175/month | $1,050.00 |
| Pick for me sponsor, one village, mall or food type | $150/month | $105/month | $630.00 |
| Pick for me sponsor, every pick | $250/month | $175/month | $1,050.00 |
| Banner | $20 CPM, $100 min | $14 CPM, $70 min | at least $420.00 |

The founding discount stacks with the hotel and tour multiplier (for example,
a hotel featured listing is $112.50 × 0.7 = $78.75/month).

## Launch order

Start with **two paid products, featured listings and premium profiles**, with
free claimed listings as the way in.

1. **At launch: claimed basic listing (free), featured listing, premium
   profile.**
   - Neither paid product needs traffic proof. A $75 or $39 monthly decision
     is small enough for a restaurant owner to make on the first call, before
     the site has analytics to show.
   - Featured listings use the labeled `sponsored-slot` block, which is on
     the village, mall, and cuisine template with three rotating slots (see
     [Ad serving](#ad-serving)).
   - Both are sold to the same people: owners who have claimed their listing.
     Free claims build that list.
   - Two products keep the first sales conversations simple and keep build
     work small: the slot on term pages and the premium profile fields.
2. **After the 90-day review:** category sponsors, banners, and deal or event
   posts. By then the site has real impressions and click-through rates to
   price banners on and to show category sponsors what an exclusive page is
   worth.
3. **When each threshold is met:** newsletter sponsors once the list exists;
   the homepage leaderboard at 40,000 page views a month; sponsored features
   and hotel and tour sales once there is traffic to show visitors' interest;
   affiliate links once a partner program is approved.

## Open items

- The advertiser contract (insertion order) must state that advertising never
  buys ratings, reviews, awards, rankings, or listing decisions
  ([launch-checklist.md](launch-checklist.md)).
- [legal-and-policy.md](legal-and-policy.md) §9 drafted a line noting which
  restaurants advertise on award and ranking pages. It is not in the new
  editorial statement; decide with counsel (question 7 in §8).
- Premium profile contents and deal posts are not built yet. Claimed owners
  now get menu links and photos for free (task 10d), so premium profiles need
  other contents; see [billing.md](billing.md) §7.
- Self-serve checkout is waiting for a decision; Stripe does not accept
  Guam-based businesses ([billing.md](billing.md) §4).

## Ad serving

Built in the plugin (`includes/ads/`, 2026-10-10) instead of Advanced Ads, so
counting works behind a page cache, labels and links follow the rules above,
and nothing needs a paid add-on.

- **Ads** are private `gr_ad` posts under **Ads** in wp-admin (administrators
  only). Fields: advertiser, restaurant (optional; used as headline and link
  when those are empty), product, creative (image and link, or text), start
  and end dates (inclusive, Guam time), target page types, villages, malls and
  cuisines, priority (0-10) and status (active or paused).
- **Products in slots:** featured listing (shown as "Sponsored", up to 3 per
  page), category sponsor (one per page), banner, homepage leaderboard (one
  per page). The theme places a category sponsor slot and a 3-ad sponsored
  listing slot on village, mall and cuisine pages, and a banner slot on the
  home page.
- **Rotation rule:** highest priority first; within a priority, the ad with
  the fewest impressions today first; ties go to the lower ad ID. Exclusive
  products show one ad per page, and an ad never shows twice on a page. Two
  exclusive ads that could land on the same page and day are flagged on save.
  With a full-page cache, the choice is refreshed whenever the cached page is
  rebuilt, and counts stay exact because they come from the browser.
- **Targeting:** an empty list matches every page; several values in one list
  match any of them; different lists must all match. Restaurant pages match
  through the restaurant's own villages, malls and cuisines.
- **Labels and links:** every ad carries its own "Sponsored" label, sits in a
  bordered box outside the results, and every link has
  `rel="sponsored noopener"`. Creative that says "Featured", "Top pick",
  "Recommended", "Editor's choice" or mentions awards cannot be activated.
- **Counting:** `assets/ads/beacon.js` sends an impression when half of an ad
  is on screen and a click when its link is followed, to
  `POST /wp-json/guam-restaurants/v1/ads/events`. Bots (by User-Agent and
  `navigator.webdriver`) are not counted; only ads serving today are counted;
  each request counts an ad and event type once. Only daily totals per ad and
  page type are stored in `gr_ad_daily_stats`: no IP, cookie, user or
  User-Agent.
- **Monthly report:** Ads > Reports shows impressions, clicks and
  click-through rate for a month, for one advertiser or all, per ad and page
  type with a total. Download CSV, or print the page to PDF from the browser.
- **No influence:** ads are not public posts, so they are out of searches,
  feeds, REST and sitemaps (and explicitly excluded from core and Yoast
  sitemaps). The ad system never writes restaurant data, ratings, awards or
  the change log, and adds nothing to the directory, recently opened or
  structured data queries; tests check those are identical with ads running.
