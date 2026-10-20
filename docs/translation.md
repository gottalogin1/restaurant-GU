# Japanese and Korean pages (machine translation)

Layout comparison item 9, built 2026-10-20. Sanjay accepted machine translation that is
not 100% accurate, "something similar to Google Translate". Every page gets a Japanese copy
under `/ja/` and a Korean copy under `/ko/`, translated by Google Translate and labeled as a
machine translation. English stays the original.

## 1. Choice: TranslatePress free first, with a Google Cloud Translation key

Sanjay (2026-10-20): a yearly fee is not worth it before launch; find a free or
lifetime-deal option. Plan: start on **TranslatePress free** with one language ($0), and add
the second language later with one TranslatePress Personal purchase. A lapsed TranslatePress
licence does not switch anything off ("When your licence expires the plugin will still
continue to work as configured", [pricing page](https://translatepress.com/pricing/)), so
that purchase never has to be renewed; only updates, support and TranslatePress AI stop.
Japan and Korea are Guam's two key visitor markets
([GVB via Isla Public Media](https://www.islapublic.org/news/2026-09-09/guam-tourism-arrivals-down-37-percent-in-july-as-gvb-targets-key-markets)),
so pick whichever matters more for the first language.

| Option | Cost for English + Japanese + Korean | Indexable `/ja/` `/ko/` URLs | How it fits this site |
|---|---|---|---|
| **TranslatePress free** (start here) | €0 + Google use (§3); **one** extra language | Yes, with hreflang | Translates the finished HTML, so our blocks, REST-filled lists and owner text all work without duplicating posts. Translations are stored once and served from the site, so page caches work. Honors `data-no-translation` and selector filters, which the plugin uses (§4). |
| **TranslatePress Personal**, bought once (second language) | €99 once; the licence can lapse | Yes; SEO Pack adds translated sitemaps and meta | Same plugin, so no code change. After the licence lapses the Pro add-ons keep working but get no updates. |
| Autoglot | Free plugin; $0.0005 to $0.001 per word **per language**, prepaid, no subscription (about $70 to $200 to fill both languages once) | Yes | Works the same way (local tables, `notranslate` class). But its [terms](https://autoglot.com/terms-and-conditions/) are under Russian law with arbitration in St. Petersburg, no company is named, about 100 installs, and the engine is not disclosed. Built and tested on branch `claude/translations-autoglot` in case it is chosen. |
| MultiLipi (AppSumo lifetime deal) | $79 once for 100K words and 4 languages | Yes | Sold out on AppSumo as of August 2026 ([LTD Stash](https://ltdstash.com/reviews/multilipi-lifetime-deal/)); founded 2024. |
| Linguise | Free for one month (600,000 words), then monthly plans ([prices](https://www.linguise.com/prices/)) | Yes | Subscription, hosted translation store. |
| GTranslate free | €0 | **No** (browser widget, nothing indexed) | Also sends every page, including Google review text, to Google from the visitor's browser. |
| GTranslate paid | $9.99 to $39.99/month; URL translation only on Business ($29.99) and up | Yes | Pages are translated and cached on GTranslate's servers, so Google, Yelp and Foursquare content would be stored by a third party unless every block is excluded. |
| Weglot | Word-quota plans; 1,700+ listings in two languages need a high tier | Yes | Hosted translation store, same third-party storage concern as GTranslate. |
| Polylang or WPML | Pro plans plus machine-translation credits | Yes | One post per language: 1,740 restaurants become 5,220 posts, and every import, tag, meta field and block would have to sync across copies. Not a fit for a data-driven directory. |

"Lifetime" TranslatePress copies sold on Gumroad and similar sites are resold GPL files: no
licence key, no updates, unknown changes. Do not use them.

Checked 2026-10-20: [TranslatePress on wordpress.org](https://wordpress.org/plugins/translatepress-multilingual/)
(3.3.8, 400,000+ installs, tested to WordPress 7.1.3; free version is one extra language;
Extra Languages, SEO Pack and DeepL are paid add-ons),
[TranslatePress pricing](https://translatepress.com/pricing/) (yearly only: Personal €99,
Business €199, Developer €349; no lifetime licence),
[automatic translation docs](https://translatepress.com/docs/automatic-translation/)
(Google Translate v2 with your own key works in the free version; each string is translated
once and saved), [Autoglot on wordpress.org](https://wordpress.org/plugins/autoglot/) (3.0.2,
100+ installs), [Autoglot pricing](https://autoglot.com/pricing/) and
[FAQ](https://autoglot.com/frequently-asked-questions/) (words counted per language; balance
shared by all sites on one key), GTranslate plan list via [fitgap](https://us.fitgap.com/products/gtranslate).

DeepL (Business plan) is usually a little better for Japanese and Korean, but it is a yearly
plan and Google's quality is good enough for what was asked. Switching engines later is a
setting.

## 2. What the plugin does (`includes/translation/`)

- **Language switcher block** (`guam-restaurants/language-switcher`) in a thin row above the
  header: English · 日本語 · 한국어, each in its own script, current one underlined. It renders
  nothing until TranslatePress publishes a second language (with the free version it shows
  English and the one translated language). Turn TranslatePress's own floating
  switcher off.
- **Notice on every translated page**, written by hand in Japanese and Korean (not machine
  translated): it is a Google Translate machine translation and may contain mistakes; names,
  addresses, place names and other sites' ratings and reviews are left as written. Links to
  the English original and to the disclaimer, plus "powered by Google Translate".
- **Machine-translation markup:** `<link rel="alternate machine-translated-from" hreflang="en">`
  pointing at the English page, as Google's
  [markup requirements](https://docs.cloud.google.com/translate/markup) ask.
- **Kept as written** (`Languages::keep()`: class `notranslate`, `translate="no"` and
  `data-no-translation`; the class is also added to TranslatePress's
  `trp_no_translate_selectors` and `trp_skip_selectors_from_dynamic_translation` lists):
  restaurant names (cards, page title, map pop-ups, award lists, recent changes, "Listing:"),
  street addresses, village and mall names (cards, page meta, address, chips, filters, picker,
  village and mall page headings), photo authors and licences, names of reviewers, owners and
  staff writers, and every Google, Yelp and Foursquare part of the ratings box (logo/attribution,
  provider names, review authors and text, Google's phone, website and hours). Our own labels
  around them are translated. The marker sits on a span inside each link, so TranslatePress
  still points the link at the page in the visitor's language.
- **REST stays in English:** `rest_url()` for `guam-restaurants/*` routes drops the `/ja/` or
  `/ko/` prefix. TranslatePress translates JSON fetched through a language prefix and would
  then store Google review text in its tables; without the prefix the JSON is never touched.
  Text that scripts insert from it (directory results, the Saved page, the picker, map pop-ups)
  is translated in the browser by TranslatePress's dynamic translation, except kept text.
- **Google in the visitor's language:** on translated pages the ratings box adds `lang=ja` or
  `lang=ko`, and the Google request adds `languageCode`. Google then writes its own hours,
  review dates and (where it chooses) review translations in that language. This is Google's
  own output, shown as returned (CLAUDE.md rule 4), and costs the same SKU. Yelp and
  Foursquare stay as they come.
- **Data sources page** gets a Translations section (anchor `#translations`) with Google's
  required disclaimer, added once (Legal VERSION 4). The privacy policy draft mentions Google
  Cloud Translation (refilled only while the draft is unedited; otherwise add §5's text by hand).

## 3. Cost

- TranslatePress: **€0** with one language on the free version. The second language needs
  Personal, **€99 once** (renewal optional, §1).
- [Google Cloud Translation](https://cloud.google.com/translate/pricing) (Basic, v2):
  **$20 per million characters**, first 500,000 characters a month free (a $10 monthly
  credit). Every character sent counts, spaces included.
- TranslatePress sends each piece of text once per language and keeps the result, so the cost
  is mostly a one-time fill. Shared text (menus, labels, filter names) is translated once for
  the whole site. Per restaurant, the new text is mostly the description, owner text, specials
  and visitor reviews. Rough estimate for 1,740 restaurants: 1 to 3 million characters for both
  languages, **about $10 to $50 once**, then new reviews and edits stay inside the free
  $10 a month. A local test page sent about 2,500 characters, most of it labels shared by all
  pages.
- Guard rails: TranslatePress's daily character limit (set it to 200,000 to spread the first
  fill over a couple of weeks and stay near the free tier), plus a Google Cloud budget alert.

## 4. Data rules check

- **Google Places content** may not be stored (Maps ToS §3.2.3(a)) and must be shown as returned.
  It is fetched by our REST endpoint, which TranslatePress never processes (§2), and the
  ratings box marks every Google part as kept, so it is never sent to Google Translate or saved
  in TranslatePress's tables. Translations of it come only from Google's own `languageCode`.
- **Yelp** may not be modified (§5(b)) and is cached 24 hours at most; **Foursquare** forbids
  server caching. Same treatment as Google: kept, never translated or stored.
- **OSM and FSQ OS data** may be stored and modified, but names and addresses are kept anyway
  so visitors can match signs and maps.
- **Our own text and visitor reviews** are translated. Reviewer names are kept, so no names go
  to Google. Privacy policy wording is in §5.
- **Google's Cloud Translation attribution rules**
  ([attribution page](https://docs.cloud.google.com/translate/attribution)): make clear the
  page is an automatic translation by Google Translate (the notice), show the "powered by
  Google Translate" badge next to the translation, linking to translate.google.com (the
  notice; set `GUAM_RESTAURANTS_GOOGLE_TRANSLATE_BADGE_URL` to Google's badge image, until
  then the words are shown), show Google's disclaimer (Data sources page), and mark the pages
  as machine translated (the `machine-translated-from` link).
- **Open question:** the same attribution page says Google's logo may not appear on the same
  page as logos of competing search services. The badge sits on translated restaurant pages
  that can also show the Foursquare logo (and Yelp's, if Yelp is ever turned on). Until counsel
  says otherwise, leave `GUAM_RESTAURANTS_FOURSQUARE_LOGO_URL` empty so Foursquare shows as the
  text "Powered by Foursquare", which Foursquare allows, and keep Yelp off.
- **Sponsored labels** are translated like other labels (a Japanese reader sees the label in
  Japanese), and nothing about ranking changes.

## 5. Owner setup (Sanjay)

1. **Install TranslatePress free.** In wp-admin, Plugins > Add New: install and activate
   **TranslatePress - Multilingual**. For the second language later, buy Personal once,
   upload the Pro zip from your TranslatePress account and enter the license key under
   Settings > TranslatePress > License (turn auto-renew off in the account if you do not want
   updates). Never put the key in the repository or chat.
2. **Settings > TranslatePress > General:** default language English (United States); add
   the first language (Japanese or Korean; the other one after step 1's purchase); URL slugs
   `ja` and `ko`; "Use a subdirectory for the default
   language" **No**; "Force language in custom links" **Yes**; floating language switcher
   **off** (the header block replaces it). Leave URL slug translation off so village names stay
   in the addresses.
3. **Google Cloud key:** in the Google Cloud project that has billing (the Places one is fine),
   enable **Cloud Translation API**, create an API key, restrict it to Cloud Translation API
   only and to the web server's IP address, and add a budget alert (for example $20 a month).
4. **Settings > TranslatePress > Automatic Translation:** turn it on, engine Google Translate
   v2, paste the key, set the daily character limit (200,000 suggested). TranslatePress keeps
   this key in its own settings, like the LoginPress license, not in `wp-config.php`.
5. **Badge:** download the "powered by Google Translate" badge from
   <https://docs.cloud.google.com/translate/attribution>, upload it to Media, and set
   `GUAM_RESTAURANTS_GOOGLE_TRANSLATE_BADGE_URL` to its URL.
6. **Privacy policy:** if the draft was already edited, add to "Content from other services":
   "Japanese and Korean pages. These pages are machine translated with Google Cloud
   Translation. Our server sends the text shown on our pages to Google, including reviews
   visitors have posted, translates each piece once and keeps the translation. Names shown
   with reviews are not sent. Your browser does not contact Google for this." and add
   "Google Cloud Translation for the Japanese and Korean pages (page text only)" to "Who we
   share it with". If the Data sources page was edited, the Translations section is still
   added before "Report a problem" (or at the end).
7. **Caches:** purge the page cache once after turning translations on. Pages are cached per
   URL, so `/ja/...` and `/ko/...` are cached on their own.
8. **Header:** if the header was ever edited in the Site Editor, reset it there to pick up
   the language links.
9. **Check:** open `/ja/` and `/ko/` and a few restaurant, village and map pages. The first
   view of each page is slower while its new text is translated; after that it is served from
   TranslatePress's tables. To fix a bad translation, use TranslatePress's "Translate Site"
   editor; edits are kept.
10. **Search Console:** the sitemap gains `/ja/` and `/ko/` URLs (SEO Pack). Google removed its
    advice to block auto-translated pages in June 2025 and judges them by usefulness; if
    Search Console reports the translated pages as low quality, they can be set to noindex.

## 6. Limits

- Page titles, meta descriptions and some attributes (for example "Save Sample Kitchen" on the
  heart button's label) are translated as whole strings, so a restaurant name inside them may
  come out transliterated. Fix individual ones in TranslatePress's editor if needed.
- Sentences that contain a village name ("25 restaurants in Yona", "Most are in Tamuning")
  are translated as a whole; Google usually leaves the name alone.
- Text that scripts add to a translated page (results, pop-ups, the Saved list) is translated
  in the browser after it appears, so for a moment it may show in English.
- Testing: TranslatePress cannot be downloaded from the cloud sandbox (wordpress.org is blocked
  there). Local checks used a stand-in that imitates TranslatePress's public functions, URL
  prefix and selector filter (not committed), and the PHPUnit tests use a language filter.
  Try it on staging with the real plugin before turning it on live.
