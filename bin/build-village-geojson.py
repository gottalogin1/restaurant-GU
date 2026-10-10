#!/usr/bin/env python3
"""Convert the Census TIGER/Line Guam county subdivision shapefile to the
plugin's village GeoJSON.

Usage:
    pip install pyshp
    python3 bin/build-village-geojson.py path/to/tl_2024_66_cousub.shp \
        wp-content/plugins/guam-restaurants-core/data/guam-villages.geojson

Download: https://www2.census.gov/geo/tiger/TIGER2024/COUSUB/tl_2024_66_cousub.zip
Licence: public domain (CC0 per data.gov). See the plugin's data/README.md.

The "County subdivision not defined" feature (GEOID 6601000000, open water)
is dropped. Coordinates are NAD83 (EPSG:4269), which differs from WGS84 by
about a metre on Guam; they are kept as-is and rounded to 6 decimals.
"""

import json
import sys

import shapefile

# Census GEOID -> our village slug. The Census uses the official Chamorro
# names for several villages; our slugs follow the common English names.
SLUGS = {
    "6601004650": "agana-heights",
    "6601034653": "agat",
    "6601013100": "asan-maina",
    "6601017650": "barrigada",
    "6601026100": "chalan-pago-ordot",
    "6601028050": "dededo",
    "6601034800": "hagatna",
    "6601035503": "inarajan",
    "6601046250": "mangilao",
    "6601044403": "merizo",
    "6601051450": "mongmong-toto-maite",
    "6601059250": "piti",
    "6601062828": "santa-rita",
    "6601065750": "sinajana",
    "6601069650": "talofofo",
    "6601071600": "tamuning",
    "6601035528": "umatac",
    "6601083300": "yigo",
    "6601084600": "yona",
}

UNDEFINED_GEOID = "6601000000"


def round_coords(value):
    if isinstance(value, (list, tuple)):
        if value and isinstance(value[0], (int, float)):
            return [round(value[0], 6), round(value[1], 6)]
        return [round_coords(v) for v in value]
    return value


def main(src, dest):
    reader = shapefile.Reader(src)
    features = []
    for shape_record in reader.iterShapeRecords():
        record = shape_record.record.as_dict()
        geoid = record["GEOID"]
        if geoid == UNDEFINED_GEOID:
            continue
        if geoid not in SLUGS:
            sys.exit(f"Unknown GEOID {geoid} ({record['NAME']}); update SLUGS.")
        geometry = shape_record.shape.__geo_interface__
        features.append(
            {
                "type": "Feature",
                "properties": {
                    "slug": SLUGS[geoid],
                    "geoid": geoid,
                    "census_name": record["NAME"],
                },
                "geometry": {
                    "type": geometry["type"],
                    "coordinates": round_coords(geometry["coordinates"]),
                },
            }
        )

    if len(features) != len(SLUGS):
        sys.exit(f"Expected {len(SLUGS)} villages, found {len(features)}.")

    features.sort(key=lambda f: f["properties"]["geoid"])
    collection = {
        "type": "FeatureCollection",
        "name": "guam-villages",
        "source": "US Census Bureau TIGER/Line 2024, County Subdivisions, Guam (tl_2024_66_cousub)",
        "features": features,
    }
    with open(dest, "w", encoding="utf-8") as handle:
        json.dump(collection, handle, ensure_ascii=False, separators=(",", ":"))
        handle.write("\n")
    print(f"Wrote {len(features)} villages to {dest}")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
