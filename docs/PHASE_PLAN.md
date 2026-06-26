# Lake Kosh Conditions Phase Plan

## Recommendation

Build phases 2 and 3 as a standalone WordPress plugin instead of putting logic in the theme, Elementor, or the Lake Kosh MU plugin.

Reasons:

- Weather fetches and scoring need scheduled jobs, caching, settings, and versioned code.
- Boating and fishing logic will evolve independently from page layout.
- A plugin can expose shortcodes now and REST/block output later.
- Plugin Update Checker lets the live site update through the normal WordPress plugin flow.

## Phase 2: Boating Windows

Goal: identify daylight 3-4 hour pontoon ride windows with comfortable conditions.

Data source:

- Open-Meteo hourly forecast, no API key required.
- Hourly fields: temperature, wind speed, wind gusts, wind direction, rain probability, rain amount, weather code.
- Daily fields: sunrise and sunset.

Core rules:

- Only consider daylight hours.
- Prefer 3-4 hour consecutive windows.
- Prefer wind less than 10 mph.
- Avoid imminent rain, storms, showers, or high rain probability.
- Ideal temperature range is 70-90 F.
- Above ideal range: tell visitors to wear a swimsuit and put up the Bimini.
- Below ideal range: tell visitors to take a jacket.
- Show hour-by-hour wind direction, wind speed, temperature, and rain risk.
- Rate windows as Excellent, Good, Fair, or Poor.

Public output:

- Shortcode: `[lake_kosh_boating_conditions]`.
- Later option: block or REST endpoint for a richer custom page layout.

## Phase 3: Fishing Outlook

Goal: provide fishing recommendations using weather plus fishing-specific signals.

Implemented signals:

- Wind comfort.
- Rain risk.
- Pressure trend over the next 24 hours.
- Pressure-front timing when a meaningful drop is coming later or the next day.
- Moon phase and illumination.
- Solunar major/minor periods from USNO moonrise, moonset, and transit data.

Next signals to add:

- Better front timing logic based on both pressure and wind shift.
- Species-specific guidance if the client wants it later.
- Manual override notes for local conditions.

Data source:

- USNO Astronomical Applications API for moon rise, set, transit, phase, and illumination. No API key is required.

## Admin Settings

Initial settings:

- Latitude and longitude.
- Timezone.
- Forecast days.
- Refresh interval.
- Boating window length.
- Wind, temperature, rain, and front thresholds.
- Maximum windows to show.

## Live-Site Integration

Suggested first deployment:

1. Install plugin on `lakekosh.com`.
2. Add `[lake_kosh_boating_conditions]` to a draft or private page.
3. Verify forecast data, formatting, and scoring.
4. Tune thresholds with real Lake Kosh examples.
5. Add public navigation only after the output looks useful.

Phase 3 is public on `/fishing-conditions/`, with a homepage summary card linking to the detail page.
