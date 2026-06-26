# Lake Kosh Conditions

WordPress plugin for Lake Kosh boating and fishing recommendations.

## What It Does

- Fetches hourly forecast data for Lake Koshkonong from Open-Meteo.
- Caches forecast data with WP transients and refreshes it with WP-Cron.
- Scores daylight 3-4 hour boating windows based on wind, rain, temperature, and storm codes.
- Provides an early fishing outlook based on wind, rain risk, pressure trend, and moon phase.
- Adds WordPress settings under `Settings > Lake Kosh Conditions`.
- Provides shortcodes:
  - `[lake_kosh_boating_conditions]`
  - `[lake_kosh_fishing_conditions]`
  - `[lake_kosh_boating_conditions view="summary" detail_url="/boating-conditions/"]`
  - `[lake_kosh_fishing_conditions view="summary" detail_url="/fishing-conditions/"]`

## Display Notes

- Full boating output shows one featured window, a non-overlapping window list, and one hourly detail table.
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
