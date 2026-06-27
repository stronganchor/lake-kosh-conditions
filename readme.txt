=== Lake Kosh Conditions ===
Contributors: stronganchor
Tags: weather, boating, fishing, lake
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.2.2
License: GPLv2 or later

Weather-backed boating and fishing condition recommendations for Lake Koshkonong.

== Description ==

Lake Kosh Conditions fetches hourly forecast data, caches it in WordPress, and renders boating and fishing recommendations with shortcodes. Fishing recommendations use weather, pressure trend, moon phase, and USNO sun/moon timing for solunar windows.

Shortcodes:

* `[lake_kosh_boating_conditions]`
* `[lake_kosh_fishing_conditions]`
* `[lake_kosh_boating_conditions view="summary" detail_url="/boating-conditions/"]`
* `[lake_kosh_fishing_conditions view="summary" detail_url="/fishing-conditions/"]`

== Changelog ==

= 0.2.2 =
* Rounded displayed temperatures and wind speeds to whole numbers.
* Added wind direction to displayed wind speeds.
* Moved boating and fishing hourly detail into each forecast card.

= 0.2.1 =
* Added a boating forecast safety note and rounded fishing solunar windows to the nearest half hour.

= 0.2.0 =
* Added cached USNO astronomy data and solunar fishing-window scoring with front-timing notes.

= 0.1.2 =
* Limited boating recommendations to one best window per day so adjacent windows do not read as overlapping.

= 0.1.1 =
* Simplified boating output with non-overlapping windows, one hourly detail table, and homepage summary-card shortcode modes.

= 0.1.0 =
* Initial plugin scaffold with Open-Meteo fetch/cache, boating-window scoring, fishing-outlook scaffold, admin settings, and Plugin Update Checker.
