<?php
/**
 * Recommendation scoring for boating and fishing conditions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LKC_Recommendations {
	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function boating_windows( array $forecast ): array {
		$hours      = $this->build_hour_rows( $forecast );
		$min_hours  = (int) $this->settings['window_min_hours'];
		$max_hours  = (int) $this->settings['window_max_hours'];
		$candidates = array();

		$count = count( $hours );
		for ( $start = 0; $start < $count; $start++ ) {
			for ( $length = $max_hours; $length >= $min_hours; $length-- ) {
				if ( $start + $length > $count ) {
					continue;
				}

				$window = array_slice( $hours, $start, $length );
				if ( ! $this->is_consecutive_daylight_window( $window ) ) {
					continue;
				}

				$scored = $this->score_boating_window( $window );
				if ( $scored['score'] >= 55 ) {
					$candidates[] = $scored;
				}
			}
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				if ( $a['score'] === $b['score'] ) {
					return strcmp( $a['start'], $b['start'] );
				}

				return $b['score'] <=> $a['score'];
			}
		);

		return $this->select_boating_windows( $candidates, (int) $this->settings['max_boating_windows'] );
	}

	public function fishing_outlook( array $forecast, array $astronomy = array() ): array {
		$hours = $this->build_hour_rows( $forecast );
		$next  = array_slice( $hours, 0, 24 );

		if ( empty( $next ) ) {
			return array(
				'rating'  => 'Unavailable',
				'score'   => 0,
				'summary' => 'Fishing outlook is unavailable until forecast data is refreshed.',
				'notes'   => array(),
			);
		}

		$avg_wind      = $this->average( wp_list_pluck( $next, 'wind_speed' ) );
		$rain_max      = max( wp_list_pluck( $next, 'precip_probability' ) );
		$pressure_drop = $this->pressure_drop( $next );
		$moon          = $this->current_moon_phase( $astronomy );
		$illumination  = $this->current_moon_illumination( $astronomy );
		$front         = $this->front_signal( $hours );
		$windows       = $this->fishing_windows( $hours, $astronomy, $front );
		$score         = 60;
		$notes         = array();

		if ( $avg_wind <= 8 ) {
			$score  += 10;
			$notes[] = 'Light wind should make boat control easier.';
		} elseif ( $avg_wind > 15 ) {
			$score  -= 15;
			$notes[] = 'Higher wind may make fishing less comfortable.';
		}

		if ( $rain_max > (int) $this->settings['rain_probability_max'] ) {
			$score  -= 12;
			$notes[] = 'Rain chances are elevated in the next 24 hours.';
		}

		if ( $pressure_drop >= (float) $this->settings['front_pressure_drop'] ) {
			$score  += 8;
			$notes[] = 'Pressure is dropping, which can be useful ahead of a front.';
		}

		if ( ! empty( $front ) ) {
			$score  += 6;
			$notes[] = sprintf(
				'A pressure drop of about %s hPa is showing %s.',
				$front['drop'],
				$front['label']
			);
		}

		if ( ! empty( $windows ) ) {
			$score = max( $score, (int) $windows[0]['score'] - 5 );
		}

		$notes[] = 'Moon phase: ' . $moon . ( $illumination ? ' (' . $illumination . ' illuminated)' : '' ) . '.';
		$score   = max( 0, min( 100, $score ) );

		return array(
			'rating'        => $this->rating_from_score( $score ),
			'score'         => $score,
			'average_wind'  => round( $avg_wind, 1 ),
			'rain_max'      => $rain_max,
			'pressure_drop' => round( $pressure_drop, 1 ),
			'moon_phase'    => $moon,
			'moon_illumination' => $illumination,
			'front'         => $front,
			'windows'       => $windows,
			'summary'       => 'Fishing outlook based on weather, solunar periods, pressure trend, and moon phase.',
			'notes'         => $notes,
		);
	}

	private function fishing_windows( array $hours, array $astronomy, array $front = array() ): array {
		$periods  = $this->solunar_periods( $astronomy );
		$windows  = array();
		$per_day  = array();

		foreach ( $periods as $period ) {
			$window = $this->score_fishing_period( $period, $hours, $front );
			if ( empty( $window['hours'] ) || $window['score'] < 50 ) {
				continue;
			}

			$windows[] = $window;
		}

		usort(
			$windows,
			static function ( array $a, array $b ): int {
				if ( $a['score'] === $b['score'] ) {
					return strcmp( $a['start'], $b['start'] );
				}

				return $b['score'] <=> $a['score'];
			}
		);

		$selected = array();
		foreach ( $windows as $window ) {
			$day = substr( (string) $window['start'], 0, 10 );
			if ( ( $per_day[ $day ] ?? 0 ) >= 2 ) {
				continue;
			}

			if ( $this->overlaps_selected_window( $window, $selected ) ) {
				continue;
			}

			$selected[]      = $window;
			$per_day[ $day ] = ( $per_day[ $day ] ?? 0 ) + 1;

			if ( count( $selected ) >= 6 ) {
				break;
			}
		}

		usort(
			$selected,
			static function ( array $a, array $b ): int {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		return $selected;
	}

	private function solunar_periods( array $astronomy ): array {
		$periods = array();
		$days    = is_array( $astronomy['days'] ?? null ) ? $astronomy['days'] : array();

		foreach ( $days as $day ) {
			if ( ! is_array( $day ) ) {
				continue;
			}

			foreach ( (array) ( $day['moondata'] ?? array() ) as $event ) {
				if ( ! is_array( $event ) || empty( $event['phen'] ) || empty( $event['datetime'] ) ) {
					continue;
				}

				$phen      = (string) $event['phen'];
				$timestamp = strtotime( (string) $event['datetime'] );
				if ( ! $timestamp ) {
					continue;
				}

				if ( false !== stripos( $phen, 'Transit' ) ) {
					$type      = 'Major';
					$label     = false !== stripos( $phen, 'Lower' ) ? 'Moon underfoot' : 'Moon overhead';
					$half_span = HOUR_IN_SECONDS;
				} elseif ( 'Rise' === $phen || 'Set' === $phen ) {
					$type      = 'Minor';
					$label     = 'Rise' === $phen ? 'Moonrise' : 'Moonset';
					$half_span = 45 * MINUTE_IN_SECONDS;
				} else {
					continue;
				}

				$start = $this->round_to_half_hour( $timestamp - $half_span );
				$end   = $this->round_to_half_hour( $timestamp + $half_span );

				$periods[] = array(
					'start'             => date( 'Y-m-d\TH:i:s', $start ),
					'end'               => date( 'Y-m-d\TH:i:s', $end ),
					'event_time'        => (string) $event['datetime'],
					'period_type'       => $type,
					'event_label'       => $label,
					'moon_phase'        => (string) ( $day['moon_phase'] ?? '' ),
					'moon_illumination' => (string) ( $day['moon_illumination'] ?? '' ),
					'end_is_exact'      => true,
				);
			}
		}

		usort(
			$periods,
			static function ( array $a, array $b ): int {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		return $periods;
	}

	private function round_to_half_hour( int $timestamp ): int {
		$interval = 30 * MINUTE_IN_SECONDS;

		return (int) ( round( $timestamp / $interval ) * $interval );
	}

	private function score_fishing_period( array $period, array $hours, array $front ): array {
		$period_hours = $this->hours_between( $hours, (string) $period['start'], (string) $period['end'] );
		$wind_avg     = $this->average( wp_list_pluck( $period_hours, 'wind_speed' ) );
		$rain_max     = empty( $period_hours ) ? 0 : max( wp_list_pluck( $period_hours, 'precip_probability' ) );
		$score        = 'Major' === $period['period_type'] ? 66 : 58;
		$notes        = array();

		$score += $this->moon_activity_bonus( (string) $period['moon_phase'], (string) $period['moon_illumination'] );

		if ( $wind_avg <= 8 ) {
			$score  += 8;
			$notes[] = 'Light wind should help boat control.';
		} elseif ( $wind_avg <= 12 ) {
			$score += 4;
		} elseif ( $wind_avg > 18 ) {
			$score  -= 18;
			$notes[] = 'Wind may make fishing less comfortable.';
		} elseif ( $wind_avg > 14 ) {
			$score  -= 10;
			$notes[] = 'Wind is higher than ideal.';
		}

		if ( $rain_max > (int) $this->settings['rain_probability_max'] ) {
			$score  -= 12;
			$notes[] = 'Rain chances are elevated.';
		}

		if ( ! empty( $period_hours ) && $this->has_storm_or_rain_code( $period_hours ) ) {
			$score  -= 20;
			$notes[] = 'Forecast codes suggest rain or storms nearby.';
		}

		$front_note = $this->front_note_for_window( $period, $front );
		if ( '' !== $front_note ) {
			$score  += 10;
			$notes[] = $front_note;
		}

		$score = (int) max( 0, min( 100, round( $score ) ) );

		return array(
			'start'             => $period['start'],
			'end'               => $period['end'],
			'end_is_exact'      => true,
			'event_time'        => $period['event_time'],
			'period_type'       => $period['period_type'],
			'event_label'       => $period['event_label'],
			'moon_phase'        => $period['moon_phase'],
			'moon_illumination' => $period['moon_illumination'],
			'rating'            => $this->rating_from_score( $score ),
			'score'             => $score,
			'wind_avg'          => round( $wind_avg, 1 ),
			'rain_max'          => $rain_max,
			'hours'             => $period_hours,
			'notes'             => $notes,
		);
	}

	private function build_hour_rows( array $forecast ): array {
		$hourly  = $forecast['hourly'] ?? array();
		$daily   = $forecast['daily'] ?? array();
		$sunrise = $daily['sunrise'] ?? array();
		$sunset  = $daily['sunset'] ?? array();
		$rows    = array();
		$now     = $this->current_forecast_hour();

		foreach ( (array) ( $hourly['time'] ?? array() ) as $index => $time ) {
			if ( (string) $time < $now ) {
				continue;
			}

			$date = substr( (string) $time, 0, 10 );
			$day  = array_search( $date, array_map( array( $this, 'date_part' ), $sunrise ), true );

			$rows[] = array(
				'time'                 => (string) $time,
				'temperature'          => (float) ( $hourly['temperature_2m'][ $index ] ?? 0 ),
				'precip_probability'   => (int) ( $hourly['precipitation_probability'][ $index ] ?? 0 ),
				'precipitation'        => (float) ( $hourly['precipitation'][ $index ] ?? 0 ),
				'weather_code'         => (int) ( $hourly['weather_code'][ $index ] ?? 0 ),
				'wind_speed'           => (float) ( $hourly['wind_speed_10m'][ $index ] ?? 0 ),
				'wind_direction'       => (int) ( $hourly['wind_direction_10m'][ $index ] ?? 0 ),
				'wind_gusts'           => (float) ( $hourly['wind_gusts_10m'][ $index ] ?? 0 ),
				'pressure'             => (float) ( $hourly['pressure_msl'][ $index ] ?? 0 ),
				'cloud_cover'          => (int) ( $hourly['cloud_cover'][ $index ] ?? 0 ),
				'sunrise'              => false !== $day && isset( $sunrise[ $day ] ) ? (string) $sunrise[ $day ] : '',
				'sunset'               => false !== $day && isset( $sunset[ $day ] ) ? (string) $sunset[ $day ] : '',
			);
		}

		return $rows;
	}

	private function current_forecast_hour(): string {
		$timezone = new DateTimeZone( (string) ( $this->settings['timezone'] ?? 'America/Chicago' ) );
		$now      = new DateTimeImmutable( 'now', $timezone );

		return $now->format( 'Y-m-d\TH:00' );
	}

	private function is_consecutive_daylight_window( array $window ): bool {
		$last_timestamp = 0;

		foreach ( $window as $hour ) {
			if ( ! $this->is_daylight( $hour ) ) {
				return false;
			}

			$timestamp = strtotime( $hour['time'] );
			if ( $last_timestamp && HOUR_IN_SECONDS !== $timestamp - $last_timestamp ) {
				return false;
			}

			$last_timestamp = $timestamp;
		}

		return true;
	}

	private function score_boating_window( array $window ): array {
		$wind_max   = max( wp_list_pluck( $window, 'wind_speed' ) );
		$gust_max   = max( wp_list_pluck( $window, 'wind_gusts' ) );
		$temp_avg   = $this->average( wp_list_pluck( $window, 'temperature' ) );
		$rain_max   = max( wp_list_pluck( $window, 'precip_probability' ) );
		$rain_total = array_sum( wp_list_pluck( $window, 'precipitation' ) );
		$score      = 100;
		$notes      = array();

		if ( $wind_max > (int) $this->settings['wind_limit_mph'] ) {
			$score -= min( 35, ( $wind_max - (int) $this->settings['wind_limit_mph'] ) * 4 );
			$notes[] = 'Wind is above the preferred limit.';
		}

		if ( $gust_max > (int) $this->settings['wind_limit_mph'] + 5 ) {
			$score -= 10;
			$notes[] = 'Gusts may be noticeable.';
		}

		if ( $rain_max > (int) $this->settings['rain_probability_max'] || $rain_total > (float) $this->settings['rain_amount_max'] ) {
			$score -= 25;
			$notes[] = 'Rain risk is elevated.';
		}

		if ( $this->has_storm_or_rain_code( $window ) ) {
			$score -= 30;
			$notes[] = 'Forecast codes suggest rain or storms nearby.';
		}

		if ( $temp_avg > (int) $this->settings['ideal_temp_max'] ) {
			$score -= min( 15, ( $temp_avg - (int) $this->settings['ideal_temp_max'] ) * 1.5 );
			$notes[] = 'Wear a swimsuit and put up your Bimini.';
		} elseif ( $temp_avg < (int) $this->settings['ideal_temp_min'] ) {
			$score -= min( 20, ( (int) $this->settings['ideal_temp_min'] - $temp_avg ) * 1.5 );
			$notes[] = 'Take a jacket.';
		}

		$score = (int) max( 0, min( 100, round( $score ) ) );

		$last_hour = $window[ count( $window ) - 1 ];

		return array(
			'start'      => $window[0]['time'],
			'end'        => $last_hour['time'],
			'rating'     => $this->rating_from_score( $score ),
			'score'      => $score,
			'wind_max'   => round( $wind_max, 1 ),
			'gust_max'   => round( $gust_max, 1 ),
			'temp_avg'   => round( $temp_avg, 1 ),
			'rain_max'   => $rain_max,
			'hours'      => $window,
			'notes'      => $notes,
		);
	}

	private function select_boating_windows( array $candidates, int $max_windows ): array {
		$selected = array();
		$per_day  = array();

		foreach ( $candidates as $candidate ) {
			$day = substr( (string) $candidate['start'], 0, 10 );
			if ( ( $per_day[ $day ] ?? 0 ) >= 1 ) {
				continue;
			}

			if ( $this->overlaps_selected_window( $candidate, $selected ) ) {
				continue;
			}

			$selected[]       = $candidate;
			$per_day[ $day ] = ( $per_day[ $day ] ?? 0 ) + 1;

			if ( count( $selected ) >= $max_windows ) {
				break;
			}
		}

		usort(
			$selected,
			static function ( array $a, array $b ): int {
				return strcmp( $a['start'], $b['start'] );
			}
		);

		return $selected;
	}

	private function overlaps_selected_window( array $candidate, array $selected ): bool {
		$candidate_start = strtotime( (string) $candidate['start'] );
		$candidate_end   = $this->period_end_timestamp( $candidate );

		foreach ( $selected as $window ) {
			$window_start = strtotime( (string) $window['start'] );
			$window_end   = $this->period_end_timestamp( $window );

			if ( $candidate_start < $window_end && $window_start < $candidate_end ) {
				return true;
			}
		}

		return false;
	}

	private function period_end_timestamp( array $window ): int {
		$end = strtotime( (string) ( $window['end'] ?? '' ) );
		if ( ! $end ) {
			return 0;
		}

		return ! empty( $window['end_is_exact'] ) ? $end : $end + HOUR_IN_SECONDS;
	}

	private function hours_between( array $hours, string $start, string $end ): array {
		$start_ts = strtotime( $start );
		$end_ts   = strtotime( $end );
		$selected = array();

		if ( ! $start_ts || ! $end_ts ) {
			return $selected;
		}

		foreach ( $hours as $hour ) {
			$hour_ts = strtotime( (string) $hour['time'] );
			if ( $hour_ts >= $start_ts && $hour_ts <= $end_ts ) {
				$selected[] = $hour;
			}
		}

		if ( ! empty( $selected ) ) {
			return $selected;
		}

		$event_midpoint = $start_ts + (int) round( ( $end_ts - $start_ts ) / 2 );
		$closest        = null;
		$closest_delta  = PHP_INT_MAX;

		foreach ( $hours as $hour ) {
			$hour_ts = strtotime( (string) $hour['time'] );
			$delta   = abs( $hour_ts - $event_midpoint );
			if ( $delta < $closest_delta ) {
				$closest       = $hour;
				$closest_delta = $delta;
			}
		}

		return $closest ? array( $closest ) : array();
	}

	private function front_signal( array $hours ): array {
		$threshold = (float) $this->settings['front_pressure_drop'];
		$best      = array();
		$count     = count( $hours );

		for ( $index = 0; $index < $count; $index++ ) {
			if ( empty( $hours[ $index ]['pressure'] ) ) {
				continue;
			}

			for ( $ahead = 6; $ahead <= 30; $ahead += 6 ) {
				if ( ! isset( $hours[ $index + $ahead ] ) || empty( $hours[ $index + $ahead ]['pressure'] ) ) {
					continue;
				}

				$drop = (float) $hours[ $index ]['pressure'] - (float) $hours[ $index + $ahead ]['pressure'];
				if ( $drop < $threshold ) {
					continue;
				}

				if ( empty( $best ) || $drop > (float) $best['drop'] ) {
					$time = (string) $hours[ $index + $ahead ]['time'];
					$best = array(
						'time'        => $time,
						'drop'        => round( $drop, 1 ),
						'hours_ahead' => $ahead,
						'label'       => $this->front_label( $time ),
					);
				}
			}
		}

		return $best;
	}

	private function front_note_for_window( array $window, array $front ): string {
		if ( empty( $front['time'] ) ) {
			return '';
		}

		$window_end = strtotime( (string) $window['end'] );
		$front_time = strtotime( (string) $front['time'] );

		if ( ! $window_end || ! $front_time || $front_time <= $window_end ) {
			return '';
		}

		$hours_until_front = ( $front_time - $window_end ) / HOUR_IN_SECONDS;
		if ( $hours_until_front > 36 ) {
			return '';
		}

		return sprintf( 'A pressure drop is showing %s.', $front['label'] );
	}

	private function front_label( string $time ): string {
		$timestamp = strtotime( $time );
		if ( ! $timestamp ) {
			return 'later in the forecast';
		}

		$today    = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
		$tomorrow = date_i18n( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS );
		$date     = date_i18n( 'Y-m-d', $timestamp );

		if ( $date === $today ) {
			return 'later today';
		}

		if ( $date === $tomorrow ) {
			return 'tomorrow';
		}

		return 'on ' . date_i18n( 'D, M j', $timestamp );
	}

	private function current_moon_phase( array $astronomy ): string {
		$days = is_array( $astronomy['days'] ?? null ) ? $astronomy['days'] : array();
		foreach ( $days as $day ) {
			if ( is_array( $day ) && ! empty( $day['moon_phase'] ) ) {
				return (string) $day['moon_phase'];
			}
		}

		return $this->moon_phase_label( current_time( 'timestamp' ) );
	}

	private function current_moon_illumination( array $astronomy ): string {
		$days = is_array( $astronomy['days'] ?? null ) ? $astronomy['days'] : array();
		foreach ( $days as $day ) {
			if ( is_array( $day ) && ! empty( $day['moon_illumination'] ) ) {
				return (string) $day['moon_illumination'];
			}
		}

		return '';
	}

	private function moon_activity_bonus( string $phase, string $illumination ): int {
		$phase = strtolower( $phase );
		$value = (int) preg_replace( '/[^0-9]/', '', $illumination );

		if ( false !== strpos( $phase, 'full' ) || false !== strpos( $phase, 'new' ) || $value >= 85 || ( $value > 0 && $value <= 15 ) ) {
			return 8;
		}

		if ( false !== strpos( $phase, 'quarter' ) ) {
			return 4;
		}

		return 0;
	}

	private function rating_from_score( int $score ): string {
		if ( $score >= 90 ) {
			return 'Excellent';
		}

		if ( $score >= 75 ) {
			return 'Good';
		}

		if ( $score >= 60 ) {
			return 'Fair';
		}

		return 'Poor';
	}

	private function is_daylight( array $hour ): bool {
		if ( empty( $hour['sunrise'] ) || empty( $hour['sunset'] ) ) {
			return true;
		}

		$time    = strtotime( $hour['time'] );
		$sunrise = strtotime( $hour['sunrise'] );
		$sunset  = strtotime( $hour['sunset'] );

		return $time >= $sunrise && $time <= $sunset;
	}

	private function has_storm_or_rain_code( array $window ): bool {
		foreach ( $window as $hour ) {
			$code = (int) $hour['weather_code'];
			if ( ( $code >= 51 && $code <= 67 ) || ( $code >= 80 && $code <= 82 ) || ( $code >= 95 && $code <= 99 ) ) {
				return true;
			}
		}

		return false;
	}

	private function pressure_drop( array $hours ): float {
		$pressures = array_values(
			array_filter(
				wp_list_pluck( $hours, 'pressure' ),
				static function ( $pressure ): bool {
					return is_numeric( $pressure ) && (float) $pressure > 0;
				}
			)
		);

		if ( count( $pressures ) < 2 ) {
			return 0.0;
		}

		$last_pressure = $pressures[ count( $pressures ) - 1 ];

		return max( 0, (float) $pressures[0] - (float) $last_pressure );
	}

	private function moon_phase_label( int $timestamp ): string {
		$synodic_month = 29.530588853;
		$known_new     = strtotime( '2000-01-06 18:14:00 UTC' );
		$days          = ( $timestamp - $known_new ) / DAY_IN_SECONDS;
		$age           = fmod( $days, $synodic_month );

		if ( $age < 0 ) {
			$age += $synodic_month;
		}

		if ( $age < 1.84566 ) {
			return 'New moon';
		}
		if ( $age < 5.53699 ) {
			return 'Waxing crescent';
		}
		if ( $age < 9.22831 ) {
			return 'First quarter';
		}
		if ( $age < 12.91963 ) {
			return 'Waxing gibbous';
		}
		if ( $age < 16.61096 ) {
			return 'Full moon';
		}
		if ( $age < 20.30228 ) {
			return 'Waning gibbous';
		}
		if ( $age < 23.99361 ) {
			return 'Last quarter';
		}
		if ( $age < 27.68493 ) {
			return 'Waning crescent';
		}

		return 'New moon';
	}

	private function average( array $values ): float {
		$values = array_values(
			array_filter(
				$values,
				static function ( $value ): bool {
					return is_numeric( $value );
				}
			)
		);

		if ( empty( $values ) ) {
			return 0.0;
		}

		return array_sum( $values ) / count( $values );
	}

	private function date_part( string $datetime ): string {
		return substr( $datetime, 0, 10 );
	}
}
