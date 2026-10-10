# Filling in `awards-template.csv`

`awards-template.csv` is the input for
`wp guam-restaurants import-awards <file.csv>`. Copy it, add one row per
result, and import the copy. The template itself has only the header.

## What we record, and nothing else

We record **results only**. Each row is a set of facts plus the address of
the page where the result was published:

| Column | Required | What to put |
|---|---|---|
| `program` | yes | The program's name, spelled the same way every time, e.g. `Pika's Best of Guam`. |
| `year` | yes | The contest year as the program names it (four digits). |
| `category` | yes | The category name exactly as the page prints it, e.g. `Best Burger`. |
| `placement` | yes | `winner`, `finalist` or `other`. `2nd`, `3rd` and `runner-up` are read as `finalist`; `nominee`, `top 5` and `honorable mention` as `other`. |
| `restaurant_name` | yes | The restaurant's name as the page gives it. |
| `source_url` | yes | The full `http(s)://` address of the page that gives this result. **Rows without one are rejected.** |
| `source_note` | no | Our own plain description of where the result was found, e.g. `PDN 2023 winners PDF, dining section`. Do not paste text from the page. |

Do **not** copy anything else from the program: no descriptions, write-ups,
quotes, vote counts, photos, logos, badges or graphics. The site shows each
result as a citation in our wording, such as
"Pika's Best of Guam 2019, Best Burger: Winner", with a link to `source_url`.

Results are stored as mentions: `source_url` is the page that mentioned the
result, and the result is not checked with the publisher (`verified = 0`).
Enter a result only when you have a page that states it; never infer a
year from a streak or a later article without that year's own source.

## Where results are published

See `docs/awards-research.md` for what was found and what is still missing.

- Pika's Best of Guam (Pacific Daily News): the yearly winners pages and
  PDFs on guampdn.biz and guampdn.com. Only 2023 was reachable in the
  research (`https://guampdn.biz/pikasbestofguam/2023-Winners.pdf`).
- Earlier years: Internet Archive snapshots of those pages
  (`https://web.archive.org/web/*/guampdn.biz/pikasbestofguam/*`). Use the
  snapshot's own `web.archive.org/web/<timestamp>/...` address as
  `source_url` so the link shows the page you read.
- Other pages that mention a result (a news story, the restaurant's own
  announcement) are fine as sources for that one result; link that page.

Read pages in a normal browser and type the facts in. Do not scrape.

## Example

These rows show the format only; the restaurants and addresses are not real.

```csv
program,year,category,placement,restaurant_name,source_url,source_note
Example Awards,2023,Best Burger,winner,Example Burger House,https://example.com/2023-winners,Example 2023 winners page
Example Awards,2023,Best Burger,2nd,Example Grill,https://example.com/2023-winners,Example 2023 winners page
```

## Importing

```sh
make wp ARGS="guam-restaurants import-awards /path/to/awards.csv --dry-run"
make wp ARGS="guam-restaurants import-awards /path/to/awards.csv"
make wp ARGS="guam-restaurants awards-report"
```

(With Docker Compose, put the file somewhere the `cli` container can read,
for example in `wp-content/uploads/`, and pass that path.)

- Names are matched to existing restaurants by their current name and by
  the **Former names** field (`gr_former_names`, one per line). Add an old
  name there when a restaurant was renamed.
- A clear match (score 0.9 or more, well ahead of any other restaurant)
  is stored. A possible match (0.6 to 0.9, or two similar restaurants) is
  not stored; it goes to the review queue as `match_conflict` with kind
  `award_match`. Fix it by adding the name to the right restaurant's former
  names, or by correcting `restaurant_name`, then import again; the queue
  item is then marked merged.
- A name with no match becomes a **draft** restaurant with business status
  `unknown` (or `permanently_closed` with `--unmatched-status=permanently_closed`),
  so past winners stay in the archive. Complete or publish it later.
- Importing the same file again changes nothing: each result is stored once
  per restaurant, program, year and category. A changed placement, URL or
  note updates that one row.
- Each run is logged in `gr_ingestion_runs` (source `awards_csv`) and ends
  with the years and categories in the file and a gaps report for the
  whole table.
