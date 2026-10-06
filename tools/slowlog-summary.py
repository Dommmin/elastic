#!/usr/bin/env python3
"""
Czytelne podsumowanie search slowloga ES (stdin: linie JSON z `docker compose
logs`, przefiltrowane po `index_search_slowlog`). Używane przez `make es-slowlog`.

Po co: DoD ETAPU 7 wymaga DOWODU, że kliknięcie facetu nie odpala zbędnych
agregacji. Surowy slowlog to ściana escapowanego JSON-a — ten skrypt mówi
wprost, CO dane zapytanie liczyło (wyniki, facety, histogram, kolejna strona).

Klasyfikacja po treści zapytania (`elasticsearch.slowlog.source`), nie po
heurystykach czasowych — patrz ProductSearchService:
  - "global" w aggs           -> facety (buildFacetAggs)
  - "price_histogram"         -> histogram cen (priceHistogram)
  - "search_after"            -> kolejna strona infinite scrolla
  - "match_bool_prefix"       -> autocomplete (suggest)
"""
import json
import sys


def classify(source: str) -> str:
    parts = []

    if "match_bool_prefix" in source:
        return "autocomplete"

    if '\\"global\\"' in source or '"global"' in source:
        parts.append("wyniki + FACETY")
    elif "price_histogram" in source:
        parts.append("HISTOGRAM cen")
    elif "search_after" in source:
        parts.append("kolejna strona (bez agregacji)")
    else:
        parts.append("wyniki (bez agregacji)")

    return ", ".join(parts)


def main() -> int:
    rows = []
    for line in sys.stdin:
        line = line.strip()
        if not line.startswith("{"):
            continue
        try:
            record = json.loads(line)
        except json.JSONDecodeError:
            continue

        rows.append((
            record.get("@timestamp", "?")[11:23],
            record.get("user.name", "?"),
            str(record.get("elasticsearch.slowlog.took_millis", "?")) + " ms",
            record.get("elasticsearch.slowlog.total_hits", "?"),
            classify(record.get("elasticsearch.slowlog.source", "")),
        ))

    if not rows:
        print("Brak zapytań w slowlogu. Czy włączyłeś `make es-slowlog-on`?")
        return 0

    rows.sort()
    header = ("czas (UTC)", "użytkownik", "took", "trafienia", "co liczyło")
    widths = [max(len(str(r[i])) for r in rows + [header]) for i in range(len(header))]

    def fmt(row):
        return "  ".join(str(value).ljust(widths[i]) for i, value in enumerate(row))

    print(fmt(header))
    print("  ".join("-" * w for w in widths))
    for row in rows:
        print(fmt(row))

    print(f"\nRazem zapytań do ES: {len(rows)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
