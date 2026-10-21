# Japanese and Korean pages

Layout comparison item 9, built 2026-10-20. Sanjay accepted translations that are not 100%
accurate. Every page gets a Japanese copy under `/ja/` and a Korean copy under `/ko/`,
labeled as an AI translation. English stays the original.

## 1. Choice: translations written with Claude that ship with the plugin

Sanjay (2026-10-20): no yearly fee before launch, then "option 2": Claude translates the
site's text once, the translations ship in the plugin as WordPress translation files, and the
plugin serves `/ja/` and `/ko/` itself. **Cost: $0**, no translation plugin, no API key, no
service called while visitors browse.

Options looked at before that (checked 2026-10-20):

| Option | Cost for English + Japanese + Korean | Why not |
|---|---|---|
| TranslatePress free + Google Cloud Translation key | €0 for one language; the second needs Personal (€99, yearly; a lapsed licence keeps working) plus Google per character | A yearly product and a Google key; Google's attribution, badge and logo rules on every translated page. Built first on this PR, replaced. [Pricing](https://translatepress.com/pricing/) |
| Autoglot | Free plugin, $0.0005 to $0.001 per word per language, prepaid | Russian-law [terms](https://autoglot.com/terms-and-conditions/), no named company, about 100 installs, engine not disclosed. Built on branch `claude/translations-autoglot` (no PR), not used. |
| MultiLipi (AppSumo lifetime deal) | $79 once | Sold out as of August 2026 ([LTD Stash](https://ltdstash.com/reviews/multilipi-lifetime-deal/)). |
| Linguise, Weglot, GTranslate paid | Monthly or word-quota plans | Subscriptions; pages translated and stored on their servers, including other sites' content. |
| GTranslate free | €0 | Browser widget: nothing indexed, and every page (Google reviews included) goes to Google from the visitor's browser. |
| Polylang or WPML | Pro plans | One post per language: 1,740 restaurants become 5,220 posts to keep in sync. |

What this choice gives up: text added after a translation pass (new pages, new interface
text) shows in English on `/ja/` and `/ko/` until the next pass (§4). Restaurant descriptions,
owner highlights and visitor reviews are not translated (§5).

## 2. How it works (`includes/translation/`)

- **Addresses** (`Router`): `/ja/...` and `/ko/...` serve the same page as the English
  address. The prefix is taken off the request before WordPress reads it and put back after,
  WordPress runs in the `ja` or `ko_KR` locale (so `<html lang>` follows), and `home_url()`
  adds the prefix, so links, forms and the plugin's REST calls stay in the language. Admin,
  login and files under `wp-content` and `wp-includes` never get a prefix.
- **Interface text** comes from the plugin's translation files in `languages/`:
  `guam-restaurants-core-ja.po`/`.mo`, `-ko_KR.po`/`.mo`, and JSON files for the scripts
  (directory, map, picker, Saved page, ratings and reviews boxes). 541 of 1,462 strings are
  translated: everything visitors see. Owner, admin, claims, checkout and admin email text is
  left in English on purpose.
- **Saved and theme text** (`Dictionary`, `data/translations/ja.php` and `ko.php`): page and
  post titles, text blocks (paragraph, heading, list item, button, tagline), headings and labels
  set on the plugin's blocks, menu links and the search box in the theme's templates, cuisine
  names, price level descriptions and the tagline. Each is looked up as written (spaces
  collapsed), then in the translation files (the pages the plugin creates use its own
  strings). Anything not found stays English. Restaurant titles are never looked up.
- **Kept as written** (`Languages::keep()`: class `notranslate` and `translate="no"`, which
  browser translators honor): restaurant names, street addresses, village and mall names,
  photo authors and licences, names of reviewers, owners and staff writers, and every Google,
  Yelp and Foursquare part of the ratings box.
- **English only:** the legal pages, the privacy policy, the editorial policy, Advertise and
  the media kit send `/ja/` and `/ko/` visitors to the English page (302) and list no other
  languages.
- **Notice** at the top of translated pages, in Japanese or Korean: the page is an AI
  translation of the English original and may contain mistakes; names, addresses, place names,
  other sites' ratings and reviews and visitors' reviews are as written. Links to the English
  page and to the Translations section on the Data sources page (`#translations`).
- **Language switcher block** (`guam-restaurants/language-switcher`) above the header:
  English · 日本語 · 한국어, each in its own script, current one underlined.
- **hreflang:** every page except 404, search and English-only pages lists itself in en, ja,
  ko and x-default (English).
- **Caching:** cached site queries are kept per language; page caches store `/ja/` and `/ko/`
  addresses on their own.
- **Lists** ("Asian, Bakery and dessert and Vietnamese") use 、 in Japanese and a comma in
  Korean, without core language packs.
- **Google in the visitor's language:** on translated pages the ratings box asks Google Place
  Details for `languageCode=ja` or `ko`, so Google writes its own hours, review dates and
  (where it chooses) review translations in that language. Google's own output, shown as
  returned (CLAUDE.md rule 4), same SKU. Yelp and Foursquare stay as they come.

## 3. Cost

$0. No plugin, no subscription, no API key. Translation passes are done with Claude as part
of normal development work.

## 4. Adding or changing text

Interface text (strings in PHP or JS wrapped in `__()` and friends):

1. Run `make translations`. It refreshes `languages/guam-restaurants-core.pot`, merges new
   strings into both `.po` files (as untranslated), rebuilds the `.mo` and JSON files, and
   prints how many saved texts still have no translation.
2. Ask Claude to translate the new empty entries in both `.po` files, then run
   `make translations` again and commit `languages/`.

Saved text (new pages, edited headings, new cuisines, template edits in the Site Editor):

1. `make wp ARGS="guam-restaurants translations missing --lang=ja"` (and `--lang=ko`) prints
   the English text with no translation as JSON (`--format=count` for just the number).
2. Ask Claude to add translations to `data/translations/ja.php` and `ko.php` (keys exactly as
   printed), then commit. On the live site, run the same command after content changes; a
   developer adds the entries and deploys.

Fixing a bad translation: edit the entry in the `.po` file (interface text) or the
dictionary file (saved text) and run `make translations`.

## 5. Limits

- **Not translated:** restaurant descriptions, owner highlights and menus, visitor reviews,
  and other sites' content. The legal pages, editorial policy and advertiser pages are English
  only.
- **Search** reads English words only; the search hint on translated pages says so.
- **WordPress's own text** (for example "Page not found" defaults, comment form, date names)
  needs core language packs on the server (§6).
- **New text** shows in English until the next pass (§4).
- **Admin emails** sent because of a `/ja/` request (a claim, a photo upload) stay English,
  because those strings were left untranslated.

## 6. Owner setup (Sanjay)

1. **Core language packs:** in wp-admin, Settings > General, pick 日本語 in "Site Language",
   save, pick 한국어, save, then set it back to English (United States) and save. WordPress
   downloads both packs on the way. Or run `wp language core install ja ko_KR`.
2. **Caches:** purge the page cache once after deploying.
3. **Header:** if the header was ever edited in the Site Editor, reset it there to pick up
   the language links.
4. **Check:** open `/ja/` and `/ko/` and a few restaurant, village, map and picker pages.
5. **Search Console:** add the `/ja/` and `/ko/` home pages; the hreflang links point Google
   at the rest.

## 7. Data rules check

- **Google Places, Yelp and Foursquare content** is never translated, sent anywhere or stored
  by this feature: it is kept as written and nothing is translated at runtime. Translations of
  Google's text come only from Google's own `languageCode`.
- **Visitors' and owners' text** is not translated, so nothing they wrote is sent anywhere.
- **No translation service** is used, so there are no third-party attribution rules and the
  privacy policy needs no change. The Data sources page's Translations section says the
  translations are made with AI and the English page applies.
- **Sponsored labels** are translated like other labels, and nothing about ranking changes.
