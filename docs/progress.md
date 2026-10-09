# Progress log

## Awards research (docs/awards-research.md), 2026-10-09
- Done: Pika's Best of Guam (PDN) start year 2014, annual cycle, full 2023 categories/winners/finalists source located.
- Checked: guampdn.biz pikasbestofguam index + 2023 PDF, guampdn.biz advertising page and WP API, Wikipedia PDN.
- Remaining: 2014-2022 and 2024-2025 results; guampdn.com and Internet Archive were blocked from the cloud environment, so check by hand (URLs in awards-research.md section 7). Send partnership email (section 8).

## Legal and policy (docs/legal-and-policy.md), 2026-10-09
- Done: non-lawyer summary of FTC endorsement and review rules (16 CFR 255, 465), ad disclosure, Guam 5 GCA Ch. 32 and 9 GCA Ch. 48, visitor-tracking privacy, owner removal handling; draft editorial independence policy; 10 questions for a Guam attorney.
- Checked: eCFR Parts 255 and 465, FTC review-rule Q&A, platform review guide, native ad guide, 2013 search-ad letter, CAN-SPAM guide, Justia Guam code chapters.
- Remaining: attorney review; confirm current inflation-adjusted penalty, Japan/Korea privacy reach, any pending Guam privacy bill. Note: doc recommends the badge "Sponsored · Ad" since FTC calls "Sponsored by" ambiguous (CLAUDE.md rule 5 says "Sponsored").

## Data sources (docs/data-sources.md), 2026-10-09
- Done: terms, pricing, caching and display rules for OSM/Overpass, Google Places (New), Yelp Places, Foursquare API + OS Places, DPHSS inspection PDFs, TIGER/Line Guam; answers to the five caching/display questions; data policy table; Pika's Best added to the register.
- Key findings: Google ToS 3.2.3(d)(iii) bars Core Services "in a listings or directory service" (recommend skip); Yelp needs written consent for commercial use, 24h cache cap; no source allows storing third-party rating history.
- Remaining: Guam coverage not measured for any source (all data APIs blocked from the cloud environment, no API keys). Run the Overpass queries in data-sources.md section 1 by hand; download TIGER file and confirm 19 villages; verify Yelp display requirements by hand if Yelp is reconsidered.

## Repo setup, 2026-10-09
- Done: CLAUDE.md with the project description and 12 standing rules; researched docs added to the repo.
- Remaining: two more award sources found but not yet checked: Stars and Stripes Guam "Best of Pacific" dining winners (https://guam.stripes.com/best-of-pacific, 2025-26 and 2026-27 lists) and The Guam Guide reader poll archive (https://archives.theguamguide.com/best-guam-reader-poll-food-drink/, about 2013-2014). Check terms and add to the register in data-sources.md if used.
- Remaining: scaffold the guam-restaurants-core plugin and block theme; add .env.example once the first API is chosen; set up PHPCS (WordPress Coding Standards) and PHPUnit.
