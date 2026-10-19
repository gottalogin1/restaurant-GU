# Adding restaurants from Foursquare's business listings

Guam's yellow pages (the Guam Phone Book, guamphonebook.com) block automated
access in their robots.txt, so the directory uses **Foursquare Open Source
Places** instead: a free business-listings dataset (Apache 2.0) with the same
phone-book details: name, address, phone, website, email, Facebook and
Instagram, category, and whether the place has closed. See
`docs/data-sources.md` §4b and §4c.

The import matches every place to the listings you already have before it
creates anything, so it never makes duplicates and never overwrites what
OpenStreetMap, an owner or an editor entered. New places arrive as **drafts**
for you to check and publish.

## One-time setup

1. Sign in (or sign up) at <https://huggingface.co>, open
   <https://huggingface.co/datasets/foursquare/fsq-os-places> and accept the
   dataset's conditions.
2. Create a **read** access token at <https://huggingface.co/settings/tokens>.
3. Put it in your `.env` (never commit it):

   ```
   HF_TOKEN=hf_...
   ```

## Each time (monthly, or when Foursquare publishes a release)

From the repository folder in WSL Ubuntu:

```
git pull
make update
make fsq-extract
```

`make fsq-extract` downloads only Guam's rows into `.cache/fsq-guam.jsonl`
(it reads the dataset over the network, so it can take several minutes). To
use a specific release, add `FSQ_RELEASE=2026-09-15`.

Preview, then import:

```
make wp ARGS="guam-restaurants import-fsq --input=/project/.cache/fsq-guam.jsonl --dry-run"
make wp ARGS="guam-restaurants import-fsq --input=/project/.cache/fsq-guam.jsonl"
```

The output lists places skipped (closed, flagged by Foursquare, not a place
to eat or drink, or not refreshed for three years; change that with
`--max-age-days=`), how many matched existing listings, categories with no
cuisine yet, and the counts (created, updated, unchanged, failed). The run is
also on **Restaurants → Import runs**.

## Find each new place on Google

Then link the new drafts to Google Maps ("our search"), so their pages show
Google's live rating, hours, phone and website:

```
make wp ARGS="guam-restaurants match-ratings --source=google"
```

This uses the Google key in `.env` and stays inside the free monthly
allowance by default. Google's details are shown live on the page and never
stored (Google's terms).

## Check and publish

- **Restaurants → Review queue → Possible duplicate**: a new draft that looks
  like a listing you already have. Merge them, or mark them as different.
- **Restaurants → All restaurants → Drafts**: check each new place, then
  publish it.
- **Restaurants → Website follow-up**: every restaurant with no website of its
  own (none on file, or only a Facebook or Instagram page), with its phone,
  email, social pages and address. Check the Google Maps link first in case
  it has a site we have not recorded, then set the follow-up status
  (To contact, Contacted, Proposal sent, Signed up, Not interested) and keep
  a note.
