# Lake Kosh Conditions

WordPress plugin for Lake Kosh boating and fishing recommendations.

## What It Does

- Fetches hourly forecast data for Lake Koshkonong from Open-Meteo.
- Fetches sun/moon timing from the USNO Astronomical Applications API for solunar fishing windows.
- Caches forecast data with WP transients and refreshes it with WP-Cron.
- Scores daylight 3-4 hour boating windows based on wind, rain, temperature, and storm codes.
- Provides fishing windows based on solunar major/minor periods, wind, rain risk, pressure-front timing, and moon phase.
- Adds WordPress settings under `Settings > Lake Kosh Conditions`.
- Provides shortcodes:
  - `[lake_kosh_boating_conditions]`
  - `[lake_kosh_fishing_conditions]`
  - `[lake_kosh_boating_conditions view="summary" detail_url="/boating-conditions/"]`
  - `[lake_kosh_fishing_conditions view="summary" detail_url="/fishing-conditions/"]`

## Display Notes

- Full boating output shows one featured window, one recommended window per day, and each window card has its own hourly detail table.
- Full boating output includes a safety/planning note reminding visitors to check current weather before going out.
- Full fishing output shows one featured window and ranked solunar cards, with each card carrying its own hourly detail table.
- Displayed temperatures and wind speeds are rounded up to whole numbers.
- Displayed wind speeds include compass direction when forecast direction is available.
- Fishing solunar windows are rounded to the nearest half hour for easier scanning.
- Summary mode is intended for homepage cards that link to fuller boating and fishing pages.

## Update Checker

This plugin includes Plugin Update Checker and points at:

`https://github.com/stronganchor/lake-kosh-conditions`

The default update branch is `main`. Override with:

```php
define( 'LKC_UPDATE_BRANCH', 'dev' );
```

For private repo installs, define one of:

```php
define( 'LKC_GITHUB_TOKEN', '...' );
define( 'STRONGANCHOR_GITHUB_TOKEN', '...' );
define( 'ANCHOR_GITHUB_TOKEN', '...' );
```

Do not commit tokens.

## Phase Plan

See [docs/PHASE_PLAN.md](docs/PHASE_PLAN.md).
