# Billing, taxes and claimed profiles

**Status:** 2026-10-10. Built: manual invoicing on each ad, the billing report,
renewal reminder emails, the listing claim flow and the owner dashboard.
**Not built, waiting for Sanjay's decision:** self-serve checkout (section 4).
Section 3 lists questions to confirm, not tax or legal advice.

## 1. WPXPO plugins: what fits

Sanjay owns the WPXPO suite (Pro, unlimited sites). Checked on 2026-10-10
against wpxpo.com and each plugin's `readme.txt` on plugins.svn.wordpress.org.
Every WPXPO plugin except PostX extends WooCommerce, which the site does not
run yet, so most of them only become useful if WooCommerce checkout is
approved (section 4).

| Plugin | What it does (from its docs) | Use here |
|---|---|---|
| WowInvoice (`wow-pdf-invoices-packing-slips` 1.1.6) | PDF invoices and packing slips for WooCommerce orders; number format with prefix, suffix and minimum length; invoices attached to order emails and downloadable from My Account. | **Only with WooCommerce.** It invoices WooCommerce orders. Its docs mention no invoices without an order and no paid/unpaid tracking, due dates or payment reminders. If WooCommerce is approved, use it for premium profile invoices with prefix `GR-` so numbers look like the manual ones. Until then it cannot do the manual invoicing asked for. |
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

## 4. Self-serve checkout for premium profiles: decision needed

Premium profiles are $39/month or $390/year
([monetization.md](monetization.md) §2). Two ways to let owners pay online:

**Important finding (checked 2026-10-10).** Stripe's own support article says a
US business "must be located in the 50 states, Washington, D.C., or Puerto
Rico", and other US territories are not supported
([Stripe support](https://support.stripe.com/questions/stripe-availability-for-outlying-territories-of-supported-countries)).
A Guam-based business therefore cannot open a Stripe account on its own. The
article does not cover a business registered in a US state that operates in
Guam; ask the accountant (section 3, question 1) and Stripe. This blocks
option B, and blocks option A with the Stripe or WooPayments gateway (WooPayments
runs on Stripe).

| | A. WooCommerce + subscription extension | B. Stripe Payment Links |
|---|---|---|
| What it is | WooCommerce on this site; a "Premium profile" subscription product; the WooCommerce Subscriptions extension renews it automatically. | Links to a Stripe-hosted checkout page for a monthly or yearly price; Stripe Billing renews it. |
| Works for a Guam-based business | Only with a gateway that accepts Guam-based merchants and supports saved cards for renewals. PayPal is the likely candidate but its Guam merchant support was **not verified**. Stripe/WooPayments: no (above). | **No**, unless the business is located in a US state or Puerto Rico. |
| Cost | WooCommerce free; WooCommerce Subscriptions **$279/year** ([woocommerce.com](https://woocommerce.com/products/woocommerce-subscriptions/), checked 2026-10-10); gateway card fees. Not zero-spend. | No monthly fee; card fees 2.9% + 30¢ per US card; Stripe Billing 0.7% of subscription volume ([stripe.com/pricing](https://stripe.com/pricing), checked 2026-10-10). |
| WPXPO plugins it uses | WowStore (checkout and My Account pages), WowInvoice (PDF invoices with a `GR-` prefix), WowRevenue (founding discount, annual plan). | None. |
| Link to the owner's account | Built in: the order belongs to the logged-in owner, so a paid or lapsed subscription can switch the premium profile on or off automatically (small custom code). | Needs a webhook endpoint and signing secret, or an admin flipping it by hand from Stripe's emails. |
| Invoices and receipts | WooCommerce orders + WowInvoice PDFs; renewal reminders from Subscriptions. | Stripe receipts and invoices (post-payment invoices 0.4%, capped at $2). Numbering is Stripe's, separate from `GR-`. |
| Site weight and upkeep | Adds WooCommerce (large plugin, its own tables, updates, security patches) to a site that sells one product. | Nothing on the site; a link per plan. |
| Card data | Hosted or tokenized by the gateway either way; the site never stores card numbers ([legal-and-policy.md](legal-and-policy.md) data-breach row). | Same. |

**Recommendation:** keep manual invoicing (built) until there are paying
premium profiles to justify the $279/year, and confirm a gateway first. When a
gateway is confirmed, choose A (WooCommerce + Subscriptions with that gateway),
because it is the only option that works from Guam today if PayPal or another
gateway confirms support, and it puts WowStore, WowInvoice and WowRevenue to
use. Choose B only if the business ends up located in a US state.
**Nothing in section 4 is built until Sanjay approves an option.**

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

- Sanjay: choose a checkout option (section 4), or keep manual invoicing.
- Sanjay with DRT and an accountant: section 3, before the first invoice.
- [monetization.md](monetization.md) lists extra photos and menu links as
  premium profile features; this build gives them to every claimed owner, as
  asked in task 10d. Decide what premium profiles add instead (for example
  more photos, a call-to-action button, no rival ads on the page) when they are
  built.
- Turn on "Anyone can register" and working site email (SMTP) before
  announcing claims.
- Host rate limit for `/listing/events` and `/ads/events`.
