<?php
/**
 * Settings storage and admin form helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LKC_Settings {
	const OPTION_NAME = 'lkc_settings';

	public static function defaults(): array {
		return array(
			'latitude'              => '42.9289',
			'longitude'             => '-89.0332',
			'timezone'              => 'America/Chicago',
			'forecast_days'         => 5,
			'refresh_hours'         => 3,
			'window_min_hours'      => 3,
			'window_max_hours'      => 4,
			'wind_limit_mph'        => 10,
			'ideal_temp_min'        => 70,
			'ideal_temp_max'        => 90,
			'rain_probability_max'  => 25,
			'rain_amount_max'       => 0.02,
			'front_pressure_drop'   => 4.0,
			'max_boating_windows'   => 5,
			'weather_provider'      => 'open-meteo',
		);
	}

	public static function get(): array {
		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge( self::defaults(), $settings );
	}

	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		$output = array();

		$output['latitude']             = self::sanitize_decimal( $input['latitude'] ?? $defaults['latitude'], -90, 90, $defaults['latitude'] );
		$output['longitude']            = self::sanitize_decimal( $input['longitude'] ?? $defaults['longitude'], -180, 180, $defaults['longitude'] );
		$output['timezone']             = sanitize_text_field( $input['timezone'] ?? $defaults['timezone'] );
		$output['forecast_days']        = self::sanitize_int( $input['forecast_days'] ?? $defaults['forecast_days'], 1, 14, $defaults['forecast_days'] );
		$output['refresh_hours']        = self::sanitize_int( $input['refresh_hours'] ?? $defaults['refresh_hours'], 1, 24, $defaults['refresh_hours'] );
		$output['window_min_hours']     = self::sanitize_int( $input['window_min_hours'] ?? $defaults['window_min_hours'], 1, 8, $defaults['window_min_hours'] );
		$output['window_max_hours']     = self::sanitize_int( $input['window_max_hours'] ?? $defaults['window_max_hours'], 1, 8, $defaults['window_max_hours'] );
		$output['wind_limit_mph']       = self::sanitize_int( $input['wind_limit_mph'] ?? $defaults['wind_limit_mph'], 1, 40, $defaults['wind_limit_mph'] );
		$output['ideal_temp_min']       = self::sanitize_int( $input['ideal_temp_min'] ?? $defaults['ideal_temp_min'], 32, 110, $defaults['ideal_temp_min'] );
		$output['ideal_temp_max']       = self::sanitize_int( $input['ideal_temp_max'] ?? $defaults['ideal_temp_max'], 32, 120, $defaults['ideal_temp_max'] );
		$output['rain_probability_max'] = self::sanitize_int( $input['rain_probability_max'] ?? $defaults['rain_probability_max'], 0, 100, $defaults['rain_probability_max'] );
		$output['rain_amount_max']      = self::sanitize_decimal( $input['rain_amount_max'] ?? $defaults['rain_amount_max'], 0, 3, $defaults['rain_amount_max'] );
		$output['front_pressure_drop']  = self::sanitize_decimal( $input['front_pressure_drop'] ?? $defaults['front_pressure_drop'], 0, 20, $defaults['front_pressure_drop'] );
		$output['max_boating_windows']  = self::sanitize_int( $input['max_boating_windows'] ?? $defaults['max_boating_windows'], 1, 12, $defaults['max_boating_windows'] );
		$output['weather_provider']     = 'open-meteo';

		if ( $output['window_max_hours'] < $output['window_min_hours'] ) {
			$output['window_max_hours'] = $output['window_min_hours'];
		}

		if ( $output['ideal_temp_max'] < $output['ideal_temp_min'] ) {
			$output['ideal_temp_max'] = $output['ideal_temp_min'];
		}

		return $output;
	}

	public static function register(): void {
		register_setting(
			'lkc_settings',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		?>
		<div class="wrap">
			<h1>Lake Kosh Conditions</h1>
			<p>Configure the location and thresholds used for boating and fishing recommendations.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'lkc_settings' ); ?>
				<table class="form-table" role="presentation">
					<?php
					self::number_row( 'latitude', 'Latitude', $settings['latitude'], 'step="0.0001"' );
					self::number_row( 'longitude', 'Longitude', $settings['longitude'], 'step="0.0001"' );
					self::text_row( 'timezone', 'Timezone', $settings['timezone'] );
					self::number_row( 'forecast_days', 'Forecast days', $settings['forecast_days'], 'min="1" max="14"' );
					self::number_row( 'refresh_hours', 'Refresh hours', $settings['refresh_hours'], 'min="1" max="24"' );
					self::number_row( 'window_min_hours', 'Minimum boating window hours', $settings['window_min_hours'], 'min="1" max="8"' );
					self::number_row( 'window_max_hours', 'Maximum boating window hours', $settings['window_max_hours'], 'min="1" max="8"' );
					self::number_row( 'wind_limit_mph', 'Ideal wind limit (mph)', $settings['wind_limit_mph'], 'min="1" max="40"' );
					self::number_row( 'ideal_temp_min', 'Ideal minimum temperature', $settings['ideal_temp_min'], 'min="32" max="110"' );
					self::number_row( 'ideal_temp_max', 'Ideal maximum temperature', $settings['ideal_temp_max'], 'min="32" max="120"' );
					self::number_row( 'rain_probability_max', 'Maximum rain probability (%)', $settings['rain_probability_max'], 'min="0" max="100"' );
					self::number_row( 'rain_amount_max', 'Maximum rain amount per hour (in)', $settings['rain_amount_max'], 'step="0.01" min="0" max="3"' );
					self::number_row( 'front_pressure_drop', 'Fishing front pressure-drop trigger (hPa)', $settings['front_pressure_drop'], 'step="0.1" min="0" max="20"' );
					self::number_row( 'max_boating_windows', 'Maximum boating windows to show', $settings['max_boating_windows'], 'min="1" max="12"' );
					?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function text_row( string $key, string $label, $value ): void {
		?>
		<tr>
			<th scope="row"><label for="lkc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input id="lkc-<?php echo esc_attr( $key ); ?>" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $value ); ?>"></td>
		</tr>
		<?php
	}

	private static function number_row( string $key, string $label, $value, string $attrs = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="lkc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input id="lkc-<?php echo esc_attr( $key ); ?>" type="number" <?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> name="<?php echo esc_attr( self::OPTION_NAME . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $value ); ?>"></td>
		</tr>
		<?php
	}

	private static function sanitize_int( $value, int $min, int $max, int $default ): int {
		$value = is_numeric( $value ) ? (int) $value : $default;
		return max( $min, min( $max, $value ) );
	}

	private static function sanitize_decimal( $value, float $min, float $max, $default ): string {
		$value = is_numeric( $value ) ? (float) $value : (float) $default;
		$value = max( $min, min( $max, $value ) );
		return (string) $value;
	}
}
