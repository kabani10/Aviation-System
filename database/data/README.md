# Reference data source files

`countries.csv` and `airports.csv` are a filtered snapshot of the public-domain
[OurAirports](https://ourairports.com) dataset, loaded by `ReferenceDataSeeder`.

- `countries.csv` — every country, unfiltered (`code`, `name`).
- `airports.csv` — airports of type `large_airport`, `medium_airport`, or
  `small_airport` with a real 4-letter ICAO code. Heliports, seaplane bases,
  balloonports, and closed airports are excluded as out of scope for
  fixed-wing business aviation.

## Refreshing

```bash
curl -o /tmp/airports_raw.csv https://davidmegginson.github.io/ourairports-data/airports.csv
curl -o /tmp/countries_raw.csv https://davidmegginson.github.io/ourairports-data/countries.csv
```

Then re-run the same filter used to produce the current files: countries pass
through as-is (`code`, `name`); airports keep only `type` in
`large_airport`/`medium_airport`/`small_airport` with a 4-character
`icao_code`, using `municipality` for `city` (falling back to the airport
`name` when blank) and `iso_country` for `country_code`.
