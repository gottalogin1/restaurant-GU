"""Writes Guam's places from Foursquare Open Source Places as JSON Lines.

Usage: python -I fsq-extract-guam.py OUTPUT_FILE

Reads the dataset straight from Hugging Face with DuckDB (only the columns
and row groups it needs). Needs HF_TOKEN: a Hugging Face access token for an
account that has accepted the dataset's conditions at
https://huggingface.co/datasets/foursquare/fsq-os-places. FSQ_RELEASE picks a
release (for example 2026-09-15); the latest is used when it is unset.

`make fsq-extract` runs this in a throwaway Python container. Then import
the file with `wp guam-restaurants import-fsq` (docs/run-fsq-import.md).
"""

import json
import os
import re
import sys
import urllib.request

import duckdb

DATASET = "foursquare/fsq-os-places"

# Guam's bounding box; keep in step with Place_Mapper::BOUNDS.
SOUTH, NORTH, WEST, EAST = 13.2, 13.7, 144.6, 145.0

COLUMNS = [
    "fsq_place_id", "name", "latitude", "longitude", "address", "locality",
    "region", "postcode", "country", "tel", "website", "email",
    "facebook_id", "instagram", "fsq_category_labels", "date_created",
    "date_refreshed", "date_closed", "unresolved_flags",
]


def latest_release(token):
    request = urllib.request.Request(
        f"https://huggingface.co/api/datasets/{DATASET}/tree/main/release",
        headers={"Authorization": f"Bearer {token}"},
    )
    with urllib.request.urlopen(request, timeout=60) as response:
        entries = json.load(response)
    dates = sorted(
        match.group(1)
        for entry in entries
        if (match := re.search(r"dt=(\d{4}-\d{2}-\d{2})$", entry.get("path", "")))
    )
    if not dates:
        sys.exit("No releases found. Has this Hugging Face account accepted the dataset's conditions?")
    return dates[-1]


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    output = sys.argv[1]
    token = os.environ.get("HF_TOKEN", "").strip()
    if not token:
        sys.exit("Set HF_TOKEN (a Hugging Face access token).")
    if not re.fullmatch(r"hf_[A-Za-z0-9]+", token):
        sys.exit("HF_TOKEN does not look like a Hugging Face token (hf_...).")

    release = os.environ.get("FSQ_RELEASE", "").strip() or latest_release(token)
    if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", release):
        sys.exit("FSQ_RELEASE must look like 2026-09-15.")
    print(f"Reading Foursquare Open Source Places release {release} (this can take several minutes)...")

    con = duckdb.connect()
    con.execute("INSTALL httpfs; LOAD httpfs;")
    con.execute(f"CREATE SECRET hf_token (TYPE huggingface, TOKEN '{token}')")
    source = f"hf://datasets/{DATASET}/release/dt={release}/places/parquet/*.parquet"
    con.execute(
        f"""
        COPY (
            SELECT {", ".join(COLUMNS)}
            FROM read_parquet('{source}')
            WHERE latitude BETWEEN {SOUTH} AND {NORTH}
              AND longitude BETWEEN {WEST} AND {EAST}
            ORDER BY fsq_place_id
        ) TO '{output.replace("'", "''")}' (FORMAT json)
        """
    )
    count = con.execute(f"SELECT count(*) FROM read_json_auto('{output.replace(chr(39), chr(39) * 2)}')").fetchone()[0]
    print(f"Wrote {count} places to {output}.")


if __name__ == "__main__":
    main()
