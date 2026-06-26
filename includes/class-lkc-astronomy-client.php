<?php
/**
 * Astronomy fetch and cache layer for solunar fishing windows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LKC_Astronomy_Client {
	const TRANSIENT_KEY = 'lkc_usno_astronomy';

	public function get_astronomy( bool $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$settings = LKC_Settings::get();
		$days     = array();
		$errors   = array();

		for ( $offset = 0; $offset < (int) $settings['forecast_days']; $offset++ ) {
			$date = $this->local_date( (int) $offset, (string) $settings['timezone'] );
			$day  = $this->fetch_day( $date, $settings );

			if ( is_wp_error( $day ) ) {
				$errors[] = $day->get_error_message();
				continue;
			}

			if ( is_array( $day ) ) {
				$days[ $date ] = $day;
			}
		}

		if ( empty( $days ) && ! empty( $errors ) ) {
			return new WP_Error( 'lkc_astronomy_unavailable', 'Astronomy provider returned no usable solunar data.' );
		}

		$data = array(
			'_fetched_at' => current_time( 'mysql' ),
			'_source'     => 'USNO Astronomical Applications API',
			'_errors'     => $errors,
			'days'        => $days,
		);

		$ttl = max( 1, (int) $settings['refresh_hours'] ) * HOUR_IN_SECONDS;
		set_transient( self::TRANSIENT_KEY, $data, $ttl );

		return $data;
	}

	public function refresh(): void {
		$this->get_astronomy( true );
	}

	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	private function fetch_day( string $date, array $settings ) {
		$tz_offset = $this->timezone_offset_hours( $date, (string) $settings['timezone'] );
		$url       = add_query_arg(
			array(
				'date'   => $date,
				'coords' => $settings['latitude'] . ',' . $settings['longitude'],
				'tz'     => (string) $tz_offset,
			),
			'https://aa.usno.navy.mil/api/rstt/oneday'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'LakeKoshConditions/' . LKC_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'lkc_astronomy_http_error', 'Astronomy provider returned an unexpected response.', array( 'status' => $status ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! empty( $data['error'] ) ) {
			return new WP_Error( 'lkc_astronomy_invalid_response', 'Astronomy provider response did not include usable data.' );
		}

		$properties = $data['properties']['data'] ?? array();
		if ( ! is_array( $properties ) ) {
			return new WP_Error( 'lkc_astronomy_missing_data', 'Astronomy provider response did not include day data.' );
		}

		return array(
			'date'              => $date,
			'moon_phase'        => (string) ( $properties['curphase'] ?? '' ),
			'moon_illumination' => (string) ( $properties['fracillum'] ?? '' ),
			'moondata'          => $this->normalize_events( (array) ( $properties['moondata'] ?? array() ), $date ),
			'sundata'           => $this->normalize_events( (array) ( $properties['sundata'] ?? array() ), $date ),
		);
	}

	private function normalize_events( array $events, string $date ): array {
		$normalized = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || empty( $event['phen'] ) || empty( $event['time'] ) ) {
				continue;
			}

			$time = trim( (string) $event['time'] );
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				continue;
			}

			$normalized[] = array(
				'phen'     => (string) $event['phen'],
				'time'     => $time,
				'datetime' => $date . 'T' . $time . ':00',
			);
		}

		return $normalized;
	}

	private function local_date( int $offset, string $timezone ): string {
		$zone = new DateTimeZone( $timezone ?: 'America/Chicago' );
		$date = new DateTimeImmutable( 'now', $zone );

		if ( $offset > 0 ) {
			$date = $date->modify( '+' . $offset . ' days' );
		}

		return $date->format( 'Y-m-d' );
	}

	private function timezone_offset_hours( string $date, string $timezone ): float {
		$zone = new DateTimeZone( $timezone ?: 'America/Chicago' );
		$time = new DateTimeImmutable( $date . ' 12:00:00', $zone );

		return $time->getOffset() / HOUR_IN_SECONDS;
	}
}
