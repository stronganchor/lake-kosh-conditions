<?php
/**
 * Weather forecast fetch and cache layer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LKC_Weather_Client {
	const TRANSIENT_KEY = 'lkc_open_meteo_forecast';

	public function get_forecast( bool $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$settings = LKC_Settings::get();
		$url      = add_query_arg(
			array(
				'latitude'           => $settings['latitude'],
				'longitude'          => $settings['longitude'],
				'hourly'             => 'temperature_2m,precipitation_probability,precipitation,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,pressure_msl,cloud_cover',
				'daily'              => 'sunrise,sunset',
				'temperature_unit'   => 'fahrenheit',
				'wind_speed_unit'    => 'mph',
				'precipitation_unit' => 'inch',
				'timezone'           => $settings['timezone'],
				'forecast_days'      => $settings['forecast_days'],
			),
			'https://api.open-meteo.com/v1/forecast'
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
			return new WP_Error( 'lkc_weather_http_error', 'Weather provider returned an unexpected response.', array( 'status' => $status ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['hourly']['time'] ) ) {
			return new WP_Error( 'lkc_weather_invalid_response', 'Weather provider response did not include hourly forecast data.' );
		}

		$data['_fetched_at'] = current_time( 'mysql' );
		$ttl                = max( 1, (int) $settings['refresh_hours'] ) * HOUR_IN_SECONDS;
		set_transient( self::TRANSIENT_KEY, $data, $ttl );

		return $data;
	}

	public function refresh(): void {
		$this->get_forecast( true );
	}

	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
