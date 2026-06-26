<?php
/**
 * Plugin bootstrap, shortcodes, cron, and rendering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LKC_Plugin {
	const CRON_HOOK = 'lkc_refresh_weather_forecast';

	private static $instance = null;

	private $weather_client;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	public static function activate(): void {
		if ( false === get_option( LKC_Settings::OPTION_NAME, false ) ) {
			add_option( LKC_Settings::OPTION_NAME, LKC_Settings::defaults(), '', false );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function init(): void {
		$this->weather_client = new LKC_Weather_Client();

		add_action( 'admin_init', array( 'LKC_Settings', 'register' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( self::CRON_HOOK, array( $this->weather_client, 'refresh' ) );
		add_shortcode( 'lake_kosh_boating_conditions', array( $this, 'boating_shortcode' ) );
		add_shortcode( 'lake_kosh_fishing_conditions', array( $this, 'fishing_shortcode' ) );
		add_action( 'wp_head', array( $this, 'styles' ) );
	}

	public function admin_menu(): void {
		add_options_page(
			'Lake Kosh Conditions',
			'Lake Kosh Conditions',
			'manage_options',
			'lake-kosh-conditions',
			array( 'LKC_Settings', 'render_page' )
		);
	}

	public function boating_shortcode(): string {
		$forecast = $this->weather_client->get_forecast();
		if ( is_wp_error( $forecast ) ) {
			return $this->error_message( $forecast );
		}

		$engine  = new LKC_Recommendations( LKC_Settings::get() );
		$windows = $engine->boating_windows( $forecast );

		ob_start();
		?>
		<section class="lkc-panel lkc-boating">
			<header class="lkc-panel-header">
				<p class="lkc-eyebrow">Pontoon Ride Windows</p>
				<h2>Best Boating Conditions</h2>
				<p>Daylight windows with lighter wind, comfortable temperatures, and lower rain risk.</p>
			</header>
			<?php if ( empty( $windows ) ) : ?>
				<p>No strong boating windows are showing in the current forecast. Check again after the next forecast refresh.</p>
			<?php else : ?>
				<div class="lkc-window-list">
					<?php foreach ( $windows as $window ) : ?>
						<article class="lkc-window-card">
							<h3><?php echo esc_html( $window['rating'] ); ?></h3>
							<p class="lkc-window-time"><?php echo esc_html( $this->format_time( $window['start'] ) . ' - ' . $this->format_time( $window['end'] ) ); ?></p>
							<p>Average temp <?php echo esc_html( (string) $window['temp_avg'] ); ?>&deg;F, max wind <?php echo esc_html( (string) $window['wind_max'] ); ?> mph, rain chance up to <?php echo esc_html( (string) $window['rain_max'] ); ?>%.</p>
							<?php if ( ! empty( $window['notes'] ) ) : ?>
								<ul>
									<?php foreach ( $window['notes'] as $note ) : ?>
										<li><?php echo esc_html( $note ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<table class="lkc-hour-table">
								<thead><tr><th>Hour</th><th>Temp</th><th>Wind</th><th>Rain</th></tr></thead>
								<tbody>
									<?php foreach ( $window['hours'] as $hour ) : ?>
										<tr>
											<td><?php echo esc_html( $this->format_hour( $hour['time'] ) ); ?></td>
											<td><?php echo esc_html( (string) round( $hour['temperature'] ) ); ?>&deg;</td>
											<td><?php echo esc_html( $this->wind_label( $hour ) ); ?></td>
											<td><?php echo esc_html( (string) $hour['precip_probability'] ); ?>%</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	public function fishing_shortcode(): string {
		$forecast = $this->weather_client->get_forecast();
		if ( is_wp_error( $forecast ) ) {
			return $this->error_message( $forecast );
		}

		$engine  = new LKC_Recommendations( LKC_Settings::get() );
		$outlook = $engine->fishing_outlook( $forecast );

		ob_start();
		?>
		<section class="lkc-panel lkc-fishing">
			<header class="lkc-panel-header">
				<p class="lkc-eyebrow">Fishing Outlook</p>
				<h2><?php echo esc_html( $outlook['rating'] ); ?> Fishing Conditions</h2>
				<p><?php echo esc_html( $outlook['summary'] ); ?></p>
			</header>
			<ul class="lkc-stat-list">
				<li><strong>Average wind:</strong> <?php echo esc_html( (string) ( $outlook['average_wind'] ?? 0 ) ); ?> mph</li>
				<li><strong>Rain risk:</strong> up to <?php echo esc_html( (string) ( $outlook['rain_max'] ?? 0 ) ); ?>%</li>
				<li><strong>Pressure drop:</strong> <?php echo esc_html( (string) ( $outlook['pressure_drop'] ?? 0 ) ); ?> hPa</li>
				<li><strong>Moon:</strong> <?php echo esc_html( (string) ( $outlook['moon_phase'] ?? '' ) ); ?></li>
			</ul>
			<?php if ( ! empty( $outlook['notes'] ) ) : ?>
				<ul>
					<?php foreach ( $outlook['notes'] as $note ) : ?>
						<li><?php echo esc_html( $note ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	public function styles(): void {
		?>
		<style>
			.lkc-panel {
				border: 1px solid #d8e1dc;
				background: #f7faf8;
				color: #173f42;
				padding: clamp(1.25rem, 4vw, 2rem);
				margin: 2rem 0;
			}
			.lkc-panel h2,
			.lkc-panel h3,
			.lkc-panel p {
				color: inherit;
			}
			.lkc-panel-header {
				margin-bottom: 1rem;
			}
			.lkc-eyebrow {
				margin: 0 0 .35rem;
				font-size: .8rem;
				font-weight: 800;
				letter-spacing: .08em;
				text-transform: uppercase;
			}
			.lkc-window-list {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
				gap: 1rem;
			}
			.lkc-window-card {
				background: #fff;
				border: 1px solid #d8e1dc;
				padding: 1rem;
			}
			.lkc-window-card h3 {
				margin-top: 0;
			}
			.lkc-window-time {
				font-weight: 800;
			}
			.lkc-hour-table {
				width: 100%;
				border-collapse: collapse;
				font-size: .9rem;
			}
			.lkc-hour-table th,
			.lkc-hour-table td {
				border-top: 1px solid #d8e1dc;
				padding: .45rem;
				text-align: left;
			}
			.lkc-stat-list {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
				gap: .5rem 1rem;
				padding-left: 1rem;
			}
		</style>
		<?php
	}

	private function error_message( WP_Error $error ): string {
		if ( current_user_can( 'manage_options' ) ) {
			return '<p class="lkc-error">Lake Kosh Conditions error: ' . esc_html( $error->get_error_message() ) . '</p>';
		}

		return '<p class="lkc-error">Condition recommendations are temporarily unavailable.</p>';
	}

	private function format_time( string $time ): string {
		$timestamp = strtotime( $time );
		return $timestamp ? date_i18n( 'D, M j g:i a', $timestamp ) : $time;
	}

	private function format_hour( string $time ): string {
		$timestamp = strtotime( $time );
		return $timestamp ? date_i18n( 'g a', $timestamp ) : $time;
	}

	private function wind_label( array $hour ): string {
		return round( (float) $hour['wind_speed'] ) . ' mph ' . $this->compass_direction( (int) $hour['wind_direction'] );
	}

	private function compass_direction( int $degrees ): string {
		$directions = array( 'N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW' );
		$index      = (int) round( $degrees / 45 ) % 8;
		return $directions[ $index ];
	}
}
