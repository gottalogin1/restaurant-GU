# Data sources

Every external source the site uses is listed here (CLAUDE.md rule 2). Add a
row before importing anything from a new source.

**Status** values: `candidate` (listed, not yet evaluated), `checked` (terms
read and access tested, notes filled in), `in use` (an importer or manual
workflow uses it), `rejected` (with the reason).

**Method** values: `API`, `dataset` (bulk download), `manual` (a person looks
up a fact and records it with the source URL), `owner` (submitted by the
restaurant).

Before moving a source to `checked`, read its current terms and record any
limits on storing, caching, or displaying its data. Notes marked
"verify" have not been confirmed against the provider's current terms yet.

## Listings, locations, and ratings

| Source | URL | Method | Provides | Notes | Status |
|---|---|---|---|---|---|
| OpenStreetMap (Overpass API) | https://overpass-api.de/ | API | Restaurant names, coordinates, cuisine tags, opening hours, addresses | ODbL licence: credit "© OpenStreetMap contributors" and link to the licence. Overpass has usage limits; keep queries small and cached. | candidate |
| OpenStreetMap extract (Geofabrik) | https://download.geofabrik.de/australia-oceania.html | dataset | Full OSM data for the region, for bulk imports | Same ODbL terms. Guam may sit in a regional extract rather than its own file; verify. | candidate |
| Google Places API (New) | https://developers.google.com/maps/documentation/places/web-service | API | Place details, rating, rating count, a few reviews, hours, phone, website | Paid per request. Terms limit what can be stored: place IDs can be kept; most other fields must be refreshed rather than cached long-term (verify current rules). Reviews must be shown with Google's attribution. | candidate |
| Yelp Places API (formerly Fusion) | https://docs.developer.yelp.com/ | API | Business details, rating, review count, review excerpts | Now a paid plan. Check Guam coverage and the display and caching rules before use (verify). | candidate |
| Tripadvisor Content API | https://tripadvisor-content-api.readme.io/ | API | Location details, rating, a few reviews | Has display requirements for logos and links: https://docs.terra.tripadvisor.com/docs/display-requirements. Check the free tier and call limits (verify). | candidate |
| Foursquare Places API | https://docs.foursquare.com/developer/reference/place-search | API | Place details, categories, hours | Possible cross-check for matching and categories (verify licence for display). | candidate |
| Wikidata | https://www.wikidata.org/ | API | Stable IDs and facts for notable restaurants, hotels, and malls | CC0, no attribution required, but credit anyway. | candidate |
| Owner submissions | (site form, Happyforms) | owner | Hours, menus, photos the owner holds rights to, descriptions, contact details | Store the submitter, the date, and the owner's confirmation that they own the photos. | candidate |
| Restaurant's own website or social page | (per restaurant) | manual | Hours, menu facts, contact details | Record facts only, with the page URL. Do not copy photos or descriptions. | candidate |

## Villages and geography

| Source | URL | Method | Provides | Notes | Status |
|---|---|---|---|---|---|
| US Census TIGER/Line shapefiles, Guam | https://catalog.data.gov/dataset/tiger-line-shapefile-2024-state-guam-gu-place | dataset | Boundary polygons to place a restaurant in a village from its coordinates | Public domain. Confirm which layer (place, county subdivision, or tract) matches Guam's 19 villages. | candidate |
| US Census cartographic boundary files, Guam | https://catalog.data.gov/dataset/2019-cartographic-boundary-shapefile-current-place-for-guam-1-500000-b01c6 | dataset | Simplified boundaries for maps | Public domain. | candidate |

Guam's 19 villages, for the village taxonomy: Agana Heights, Agat,
Asan-Maina, Barrigada, Chalan Pago-Ordot, Dededo, Hagåtña, Inarajan,
Mangilao, Merizo, Mongmong-Toto-Maite, Piti, Santa Rita, Sinajana, Talofofo,
Tamuning (including Tumon), Umatac, Yigo, Yona.

## Malls

Mall tenancy is recorded by hand from each mall's own store directory, with
the directory URL and the date checked. Starting list (URLs still to be
recorded):

| Mall | Village | Method | Status |
|---|---|---|---|
| Micronesia Mall | Dededo | manual | candidate |
| Guam Premier Outlets (GPO) | Tamuning | manual | candidate |
| Agana Shopping Center | Hagåtña | manual | candidate |
| T Galleria by DFS | Tamuning (Tumon) | manual | candidate |
| The Plaza Shopping Center | Tamuning (Tumon) | manual | candidate |
| Tumon Sands Plaza | Tamuning (Tumon) | manual | candidate |

## Awards (recorded as facts: award, category, year, winner, source URL)

| Source | URL | Method | Notes | Status |
|---|---|---|---|---|
| Stars and Stripes Guam, "Best of Pacific" (Dining) | https://guam.stripes.com/best-of-pacific | manual | Yearly dining winners, for example the 2025-26 and 2026-27 lists. | candidate |
| The Guam Guide reader poll (archive) | https://archives.theguamguide.com/best-guam-reader-poll-food-drink/ | manual | Older reader's and editors' choice by category (about 2013 to 2014). Good for awards history. Confirm the year on each page. | candidate |
| Guam Hotel and Restaurant Association (GHRA) | (official site URL to record) | manual | Industry recognitions. The Hospitality Heroes awards honor people, not restaurants, so only use them where a restaurant is named. | candidate |
| Guam Business Magazine | https://www.guambusinessmagazine.com/ | manual | Check for restaurant or hospitality awards. | candidate |
| Local news (Guam Daily Post, Pacific Daily News, KUAM) | https://www.postguam.com/ | manual | Award announcements, openings and closings. Record facts with the article URL. | candidate |

## Health inspections (optional, facts only)

| Source | URL | Method | Notes | Status |
|---|---|---|---|---|
| Guam DPHSS, Division of Environmental Health, sanitation inspection results | https://dphss.guam.gov/ | manual | Published as PDF reports by date range, for example "DEH Sanitation Inspection Results 09.29.25 - 10.30.25". Decide whether the site shows inspection results before importing; if so, show the grade, the date, and a link to the report. | candidate |
