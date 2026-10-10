# Legal pages

The site's legal and policy pages, 2026-10-10. **Not legal advice:** the privacy policy
and terms are drafts for a Guam-licensed attorney (questions in
docs/legal-and-policy.md §8). The disclosure and data credits describe what the site
already does and are published.

## Pages

| Page | URL | Source text | Status after activation |
|---|---|---|---|
| Privacy policy (with the cookie notice) | WordPress's privacy page (Settings > Privacy) | `data/legal/privacy-policy.md` | **Draft**, bracketed placeholders for counsel |
| Terms of use | `/terms/` (from `Site\Pages`) | `data/legal/terms.md` | **Draft**, bracketed placeholders for counsel |
| Editorial policy | `/editorial-policy/` | `data/legal/editorial-policy.md`, inserted above the existing highlights rules (`[guam_editorial_policy]`) | Published (page already existed) |
| Advertising disclosure | `/advertising-disclosure/` | `data/legal/advertising-disclosure.md` | Published |
| Data sources and credits | `/data-sources/` | `data/legal/data-attributions.md` | Published |

All five are linked from the footer policy links (`Site\Blocks::policy_links()`), along
with Advertise with us; each link shows once its page is published. The texts live in the plugin under
`wp-content/plugins/guam-restaurants-core/data/legal/`.

## How they get into the site

`Legal\Pages` runs once per text version (`Legal\Pages::VERSION`, stored in the
`guam_restaurants_legal_pages` option) after the plugin loads:

- Converts the Markdown to core blocks (`Legal\Markdown`: headings, lists, tables,
  links, bold) and fills `{site}`, `{date}` and the page URLs.
- **Never overwrites an edited page.** The privacy page is only filled while it is
  WordPress's untouched draft; the terms only while they hold the placeholder; the
  editorial statement is added once. Disclosure and data pages are only created when
  missing.
- To push a new text version to pages nobody edited, change the file and bump
  `VERSION`. For a page already edited in wp-admin, edit it there.

## Cookie notice: no banner needed today

The privacy policy has a cookie table instead of a banner, because the site sets no
optional cookies:

- Analytics (Matomo, or Plausible/Umami) run **cookieless** with Do Not Track respected
  (docs/seo-and-analytics.md).
- No ad network or tracking pixels; ads are served by the plugin from our own server,
  and the ad beacon stores daily totals only.
- The remaining cookies are strictly necessary: sign-in, WooCommerce cart at checkout,
  PayPal at checkout, and Google's own cookies inside the embedded map on restaurant
  pages.

**Add a consent banner** if any of these change: Matomo cookies turned on, Google
Analytics or a marketing pixel added, Gravitec push used with tracking, or an ad network
added. Ask counsel whether the Google map embed needs click-to-load for EU visitors
(docs/legal-and-policy.md §5).

## Before launch (open items)

1. Counsel fills the placeholders: legal name and address, privacy contact, host and
   email service names, server log retention, the retention periods, refund policy,
   DMCA agent, limitation of liability, dispute process.
2. Register a DMCA designated agent with the U.S. Copyright Office ($6, renew every
   3 years) and put it in the terms.
3. Publish the privacy page and terms. Preflight warns until both are published.
4. Build what the privacy draft promises: a retention purge for leads, corrections and
   claims, and WordPress privacy export/erase hooks for them (Tools > Export/Erase
   Personal Data).
