# Billing, taxes and claimed profiles

**Status:** 2026-10-10. Built: manual invoicing on each ad, the billing report,
renewal reminder emails, the listing claim flow and the owner dashboard.
Self-serve checkout for premium profiles (WooCommerce + PayPal, approved by
Sanjay on 2026-10-10) is built; section 4 lists the setup steps in wp-admin.
Section 3 lists questions to confirm, not tax or legal advice.

## 1. WPXPO plugins: what fits

Sanjay owns the WPXPO suite (Pro, unlimited sites). Checked on 2026-10-10
against wpxpo.com and each plugin's `readme.txt` on plugins.svn.wordpress.org.
Every WPXPO plugin except PostX extends WooCommerce. WooCommerce checkout was
approved on 2026-10-10 (section 4), so WowInvoice, WowStore and WowRevenue are
now in use for premium profiles.

| Plugin | What it does (from its docs) | Use here |
|---|---|---|
| WowInvoice (`wow-pdf-invoices-packing-slips` 1.1.6) | PDF invoices and packing slips for WooCommerce orders; number format with prefix, suffix and minimum length; invoices attached to order emails and downloadable from My Account. | **Only with WooCommerce.** It invoices WooCommerce orders. Its docs mention no invoices without an order and no paid/unpaid tracking, due dates or payment reminders. Used for premium profile invoices with prefix `GRW-` (section 4), so online numbers never collide with the manual `GR-YYYY-NNNN` series. Until then it cannot do the manual invoicing asked for. |
| WowStore (`product-blocks` 4.5.5) | WooCommerce store builder: product blocks, checkout and My Account page templates. No subscriptions or recurring payments in its docs. | **Only with WooCommerce:** build the checkout and My Account pages for premium profiles. |
| WowRevenue (`revenue` 2.3.2) | Product bundles and discount campaigns for WooCommerce. No subscriptions. | **Only with WooCommerce:** the 30% founding-advertiser discount and the annual premium profile price could be campaigns. |
| WowAddons | Extra product options for WooCommerce. | Only with WooCommerce, and only if premium profiles get paid add-ons. Not needed now. |
| WholesaleX (`wholesalex` 3.1.0) | B2B pricing for WooCommerce, with a registration form builder, custom roles, manual approval and email confirmation. Will not activate without WooCommerce. | **No.** Its approval and email confirmation are for wholesale customer accounts. It cannot check that an email is at the restaurant's own domain or tie an account to one listing. |
| PostX (`ultimate-post` 5.0.41) | Gutenberg post grids and lists, query builder with custom post types and taxonomies, dynamic content from post meta, front-end post submission. No user dashboard. | **Not used now.** The directory pages already have their own blocks, and front-end post submission cannot limit owners to their own listing's hours, menu, photos and specials. Possible later for editorial/blog grids. If used, keep it updated: its 5.0.40 changelog fixed an unauthenticated custom-field disclosure in the Dynamic Content add-on, and listings hold private meta. |
| WowOptin (`optin` 1.4.50) | Popups and opt-in forms that send to email marketing and CRM tools. Its docs mention no stored submissions, email verification or developer hooks. | **Not for billing or claims.** Possible later for a newsletter or "Claim your listing" popup. |
| WowShipping, WowRecommend | Table-rate shipping; AI product recommendations, both for WooCommerce. | No. |

So the invoice number, paid status, unpaid/expiring report and reminders
are a small custom part of the plugin (section 2): no WPXPO plugin tracks
whether an ad is paid or sends renewal reminders, and all their invoicing
needs WooCommerce orders.

**License keys.** WPXPO Pro licenses are activated in each plugin's settings in
wp-admin on the host. Nothing in this repository needs a key; never commit
one (CLAUDE.md rule 8).

## 2. Manual invoicing (built)

Invoices are written and sent outside WordPress for now. Each ad (Ads > edit)
has a **Billing** box:

- **Invoice number.** Tick "Issue the next invoice number" and save. Format
  `GR-YYYY-NNNN`: the Guam year of issue and a sequence that restarts every
  year (`GR-2026-0001`). A number is never reused or changed; a cancelled
  invoice is marked **Void** and keeps its number, so the sequence has no gaps.
  The counter is the `guam_restaurants_invoice_sequence` option, and it is kept
  if the plugin is deleted.
- Amount (USD), invoice date (default: the day the number is issued), due date
  (default: invoice date + 14 days), payment status (Unpaid, Paid, Void), paid
  date (default: the day it is marked paid), billing email, and a note such as a
  check or transfer reference.

**Ads > Billing** lists:

1. unpaid invoices, oldest due date first, with days overdue;
2. active ads ending in the next 30 days, with their billing email, which
   reminders were sent and their invoice status;
3. ads serving today with no invoice number.

The ads list has an **Invoice** column.

**Renewal reminders.** The daily `renewal-reminders` job (docs/scheduling.md)
emails the ad's billing email 14 days and 3 days before its end date (Guam
time). Each is sent once per end date; extending the end date starts over. A
missed day is caught up, and when both are due only the 3-day one goes. Ads
without a billing email are skipped and counted. The email names the ad and
end date and asks the advertiser to reply to renew. It quotes no price and
promises no placement. Reply-To is the site admin email. It needs working site
email (SMTP), like the other alerts.

## 3. Before the first invoice: confirm with DRT and an accountant

Questions to take to the Guam Department of Revenue and Taxation
([guamtax.com](https://www.guamtax.com/); business registration starts at
[Register Now](https://www.guamtax.com/app/register.do)) and to a local
accountant. Nothing here is a conclusion; get the answers in writing where
possible and record them here with the date and who answered.

**Business registration and structure**

1. Should the directory run as a sole proprietorship, or should Sanjay form an
   LLC or corporation first? What does each mean for liability (advertiser
   contracts, user content) and for tax filing?
2. Is a federal EIN needed, and what has to be registered with DRT before the
   first invoice?
3. Does the business need a trade name registration for the site's name?

**Business license**

4. Which Guam business license classification covers an online directory that
   sells advertising (and possibly paid premium listings)? Is one license
   enough, or are separate ones needed for advertising, web publishing and any
   newsletter?
5. Does a business with no storefront, run from home or online, need any other
   permit (home occupation, zoning)?
6. Fees, renewal date, and whether the license number must appear on invoices.

**Gross receipts (business privilege) tax on advertising income**

7. Is income from selling advertising on the site subject to the business
   privilege (gross receipts) tax? At what rate today? Rates have changed
   recently, so ask for the current rate and the notice that set it rather
   than relying on any figure found online.
8. Does the answer change for: premium profile subscriptions; ads bought by
   advertisers outside Guam (for example a Japanese tour operator or a
   US-mainland chain); affiliate commissions; newsletter sponsorships?
9. May the tax be shown as a separate line on invoices, or must it be built
   into the price? If shown, how should it be worded?
10. Filing: how often (monthly?), the GRT/BPT return form, e-filing, and the
    deadline. Is there a small-business threshold or exemption?
11. Are amounts taxed when invoiced or when paid? How are void invoices,
    refunds and bad debts handled?
12. How should the 30% founding-advertiser discount appear on invoices, and is
    the tax worked out on the discounted price?

**Income tax and records**

13. How is net income from the site taxed under the Guam Territorial Income
    Tax, and are estimated payments needed?
14. If payments come through a card processor, what reporting (Form 1099-K)
    to expect, and how processor fees are recorded.
15. What an invoice must show (business name and address, license number,
    invoice number and date, tax line), and how long invoices and payment
    records must be kept. Does the `GR-YYYY-NNNN` numbering with yearly restart
    meet DRT's expectations?
16. Bookkeeping: which records the accountant wants each month (Ads > Billing
    gives unpaid and expiring lists; Ads > Reports gives impressions and clicks
    per advertiser).

Also see [legal-and-policy.md](legal-and-policy.md) §7 (business structure and
licensing) and the advertiser contract item in
[monetization.md](monetization.md) "Open items".

## 4. Self-serve checkout for premium profiles (built: WooCommerce + PayPal)

**Decision (Sanjay, 2026-10-10):** build checkout on WooCommerce so the WPXPO
plugins can be used, and take payments through PayPal on Sanjay's PayPal
account. Stripe stays out: Stripe says Guam-based businesses cannot open an
account ([Stripe support](https://support.stripe.com/questions/stripe-availability-for-outlying-territories-of-supported-countries),
checked 2026-10-10), and WooPayments runs on Stripe.

### What was built

Premium profiles are **prepaid time** on one listing: 1 month ($39, 30 days) or
12 months ($390, 365 days), from [monetization.md](monetization.md) §2. There
is **no automatic renewal**, because that needs the WooCommerce Subscriptions
extension ($279/year, not zero-spend). Instead the owner gets reminder emails
14 and 3 days before the end (the same daily `renewal-reminders` job as ads)
with a link to buy more time; new time starts the day after the current end
date, so nothing paid for is lost. Auto-renewal can be added later with
Subscriptions; PayPal Payments' readme says it works with it.

1. **Products.** `wp guam-restaurants premium-products` creates the two
   products (virtual, one per order, hidden from the shop and search). It is
   safe to run again and never overwrites a price changed in wp-admin. Any
   product with **Premium profile days** set (Product data > General) counts.
2. **Buying.** Only from **My restaurant**: the buttons link to the cart with
   the restaurant and a security token. The plugin refuses the product in the
   cart unless the buyer is the restaurant's verified owner, and checks again
   before checkout. The order line shows the restaurant.
3. **Payment.** WooCommerce checkout with PayPal (below). When the order is
   paid (status processing or completed), the days are added to the
   restaurant once, with an order note. Refunded or cancelled orders take the
   days off once.
4. **What premium adds** on the restaurant's own page, while it runs:
   - a call-to-action button with a fixed label (Order online, Reserve a table,
     See the full menu, Ask about catering, Book an event) and the owner's link;
   - up to 20 owner photos instead of 8;
   - no other restaurant's Sponsored placements on that page (ads linked to the
     same restaurant, or to no restaurant, still show).
   The "From the owner" section then says the restaurant pays for a premium
   profile. Premium never changes ratings, awards, reviews or position in any
   list, search or map (CLAUDE.md rule 5); `tests/integration/premium-test.php`
   checks that it only writes its own fields.
5. **Admin.** Each restaurant's edit screen has a **Premium profile** box
   (ad managers): the end date, a field to set it by hand (for a premium
   profile sold on a manual invoice) and the last 10 changes.
6. **Owners keep wp-admin.** WooCommerce normally sends accounts without
   editing rights to My Account; the plugin lets linked owners stay in
   wp-admin so they can reach My restaurant.

### Setup on the live site (Sanjay, in wp-admin)

Nothing here goes in the repository; no keys or account details are committed.

1. Install and activate **WooCommerce** (Plugins > Add New). Finish or skip the
   setup wizard. Set **WooCommerce > Settings > Site visibility** to **Live**;
   a new store starts in "Coming soon" mode, which hides the whole site from
   visitors.
2. Store address: Guam; currency USD. Leave **taxes off** until the accountant
   answers section 3 (Guam gross receipts tax is normally the seller's tax, not
   a sales tax added at checkout; confirm before showing any tax line).
3. Install **WooCommerce PayPal Payments** (free, by WooCommerce; 4.1.3 on
   2026-10-10, GPLv2). Go to WooCommerce > Settings > Payments > PayPal and
   connect Sanjay's PayPal account (log in to PayPal when the plugin asks).
   Its readme says a business or personal account works, with no monthly or
   setup fee; PayPal's per-payment fees apply. Its readme does not list Guam;
   connecting the account is the test. If PayPal refuses, keep manual invoicing
   (section 2) and tell Claude.
   The WPXPO docs checked for section 1 did not show a payment gateway of
   their own; if a WPXPO plugin offers a PayPal setting, the PayPal Payments
   connection is still the one WooCommerce checkout uses.
4. Run `wp guam-restaurants premium-products` (or create the products by hand
   with Premium profile days 30 and 365).
5. Activate the WPXPO plugins that fit (section 1) and add their license keys
   in wp-admin:
   - **WowInvoice:** invoices on order emails and in My Account. Use prefix
     **`GRW-`** so online invoice numbers never collide with the manual
     `GR-YYYY-NNNN` series.
   - **WowStore:** style the cart, checkout and My Account pages.
   - **WowRevenue:** the founding-advertiser discount
     ([monetization.md](monetization.md)) as a campaign on the two products.
6. Caching: cart, checkout and My Account must not be page-cached. W3 Total
   Cache and managed hosts skip them when WooCommerce is detected; check once
   with a logged-out test purchase.
7. Do one real purchase with a small test price, check that the listing shows
   the premium end date and the order note, then refund it and check the days
   come off.

### Local development

`make up` downloads WooCommerce 11.2.1 from its GitHub release (checksum
pinned in the `Makefile`) into `.cache/plugins/`, mounts it, and `make install`
activates it, turns off "coming soon" and creates the products. The PHPUnit
suite loads it, so the checkout tests run in CI. PayPal is not installed
locally.

## 5. Listing claims (built)

Restaurant pages link to **Claim this listing** (`/claim-this-listing/`,
published by the plugin with the claim form block). Steps:

1. **Account.** The owner logs in or registers. Registration needs Settings >
   General > "Anyone can register" on, with "Subscriber" as the default role.
   LoginPress Pro (owned) can style the login and registration screens.
2. **Request.** The owner chooses how to confirm:
   - **Email (always on):** a six-digit code goes to an address at the
     restaurant's own website domain, taken from the listing's website.
     Subdomains and parent domains count (`chef@mail.example.com` for
     `example.com`). Not offered when the listing has no website or the website
     is on a shared host (Facebook, Instagram, Linktree, Google Business sites,
     Wix, Square, Squarespace, Weebly, WordPress.com, Yelp, TripAdvisor,
     delivery apps or free email hosts), because anyone can have a page or
     address there.
   - **Phone (off by default):** a code texted to the phone number on the
     listing, never one the claimant types. Set
     `GUAM_RESTAURANTS_CLAIM_PHONE_CODES=1` to offer it. Texts cost money, so it
     also needs an SMS sender: a small plugin or snippet that hooks
     `guam_restaurants_send_claim_sms` (arguments: sent flag, phone, message,
     restaurant ID) and returns `true` once sent. With the switch on and no
     sender, requests fail with "the code could not be sent".
   Listings that offer neither show "contact us"; an administrator can still
   link an owner from the user's profile after checking by other means.
3. **Verify.** The code works for 30 minutes; five wrong tries end the claim;
   five code requests per account per hour. Codes are stored only as a keyed
   hash.
4. **Approve.** Administrators get an email and see **Restaurants > Claims**
   (capability `gr_approve_claims`, administrators only; roles version 4), with
   the account, how it was confirmed and the address the code went to.
   Approval sets the restaurant's `gr_claimed_by_user_id`, adds the Restaurant
   owner role (other roles are kept) and closes other open claims on that
   listing. An administrator cannot approve their own claim. The claimant gets
   an email either way; a rejection includes the reason typed.

Claims are stored in the `gr_claims` table (schema version 6).

**What a claimed owner can change** (My restaurant screen,
`Owners\Owner_Content`): opening hours (saved with source "owner", so the OSM
import never overwrites them), up to 5 menu links, up to 5 specials with an
optional end date, and up to 8 photos they upload (JPEG, PNG or WebP up to
5 MB, alt text required). Changes go live at once and are written to the
change log with source "owner". Specials and link labels follow the ad
wording rules: no "Featured", "Top pick", "Recommended", "editor's choice" or
award claims. On the restaurant page they appear in a **From the owner**
section. Owners also reply to reviews and write an owner quote (Highlights >
Write, unchanged; replies and quotes are moderated).

**What a claimed owner cannot change:** owners never get `edit_post` on a
restaurant, so the post editor, REST API and meta API are closed to them. They
cannot change ratings, awards, status or opening and closing dates, the change
history, other restaurants, or the owner link, and cannot approve, hide,
edit or delete any review. `tests/integration/claims-test.php` checks each of
these.

## 6. Owner dashboard (built)

**My restaurant** in wp-admin, for linked owners:

- listing views, clicks to the website and taps on the phone number, for the
  last 30 days and the 30 days before (Guam dates);
- each Sponsored placement linked to the restaurant: impressions, clicks and
  click-through rate for the last 30 days;
- the edit forms above and a link to reply to reviews.

Views and clicks are counted like ads: `assets/owners/listing.js` sends a
beacon from the restaurant page to `POST /wp-json/guam-restaurants/v1/listing/events`,
so cached pages count, and bots are dropped. Only daily totals per restaurant
are stored (`gr_listing_daily_stats`): no IP address, cookie, user ID or
User-Agent. Counting starts when this version is installed. Like the ad
events endpoint, it has no rate limit of its own; add the host-level limit
planned for `/ads/events` to `/listing/events` too.

## 7. Open items

- Sanjay: the wp-admin setup in section 4 (WooCommerce, PayPal Payments,
  WowInvoice prefix `GRW-`, Site visibility Live), then one test purchase and
  refund.
- Automatic renewal: needs WooCommerce Subscriptions ($279/year). Not bought.
- Sanjay with DRT and an accountant: section 3, before the first invoice.
- Premium profile contents are now: call-to-action button, 20 photos instead
  of 8, no rival restaurants' ads on the page (section 4). Menu links stay free
  for every claimed owner.
- Turn on "Anyone can register" and working site email (SMTP) before
  announcing claims.
- Host rate limit for `/listing/events` and `/ads/events`.
