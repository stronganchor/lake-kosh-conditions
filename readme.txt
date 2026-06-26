=== Lake Kosh Conditions ===
Contributors: stronganchor
Tags: weather, boating, fishing, lake
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later

Weather-backed boating and fishing condition recommendations for Lake Koshkonong.

== Description ==

Lake Kosh Conditions fetches hourly forecast data, caches it in WordPress, and renders boating and fishing recommendations with shortcodes.

Shortcodes:

* `[lake_kosh_boating_conditions]`
* `[lake_kosh_fishing_conditions]`
* `[lake_kosh_boating_conditions view="summary" detail_url="/boating-conditions/"]`
* `[lake_kosh_fishing_conditions view="summary" detail_url="/fishing-conditions/"]`

== Changelog ==

= 0.1.1 =
* Simplified boating output with non-overlapping windows, one hourly detail table, and homepage summary-card shortcode modes.

= 0.1.0 =
* Initial plugin scaffold with Open-Meteo fetch/cache, boating-window scoring, fishing-outlook scaffold, admin settings, and Plugin Update Checker.
