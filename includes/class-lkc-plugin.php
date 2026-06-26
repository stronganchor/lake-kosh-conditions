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

	private $astronomy_client;

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
		$this->weather_client   = new LKC_Weather_Client();
		$this->astronomy_client = new LKC_Astronomy_Client();

		add_action( 'admin_init', array( 'LKC_Settings', 'register' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( self::CRON_HOOK, array( $this->weather_client, 'refresh' ) );
		add_action( self::CRON_HOOK, array( $this->astronomy_client, 'refresh' ) );
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

	public function boating_shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'view'       => 'full',
				'detail_url' => '',
			),
			$atts,
			'lake_kosh_boating_conditions'
		);

		$forecast = $this->weather_client->get_forecast();
		if ( is_wp_error( $forecast ) ) {
			return $this->error_message( $forecast );
		}

		$engine  = new LKC_Recommendations( LKC_Settings::get() );
		$windows = $engine->boating_windows( $forecast );

		if ( 'summary' === strtolower( (string) $atts['view'] ) ) {
			return $this->render_boating_summary( $windows, (string) $atts['detail_url'] );
		}

		return $this->render_boating_detail( $windows );
	}

	public function fishing_shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'view'       => 'full',
				'detail_url' => '',
			),
			$atts,
			'lake_kosh_fishing_conditions'
		);

		$forecast = $this->weather_client->get_forecast();
		if ( is_wp_error( $forecast ) ) {
			return $this->error_message( $forecast );
		}

		$astronomy = $this->astronomy_client->get_astronomy();
		$engine    = new LKC_Recommendations( LKC_Settings::get() );
		$outlook   = $engine->fishing_outlook( $forecast, is_wp_error( $astronomy ) ? array() : $astronomy );

		if ( 'summary' === strtolower( (string) $atts['view'] ) ) {
			return $this->render_fishing_summary( $outlook, (string) $atts['detail_url'] );
		}

		return $this->render_fishing_detail( $outlook );
	}

	private function render_boating_summary( array $windows, string $detail_url ): string {
		ob_start();
		?>
		<section class="lkc-panel lkc-panel-summary lkc-boating-summary">
			<header class="lkc-panel-header">
				<p class="lkc-eyebrow">Pontoon Rides</p>
				<h3><?php echo empty( $windows ) ? esc_html__( 'No strong window yet', 'lake-kosh-conditions' ) : esc_html( $windows[0]['rating'] . ' boating window' ); ?></h3>
			</header>
			<?php if ( empty( $windows ) ) : ?>
				<p>Wind, rain, or temperature are not lining up for a strong pontoon window in the current forecast.</p>
			<?php else : ?>
				<p class="lkc-window-time"><?php echo esc_html( $this->format_window_range( $windows[0] ) ); ?></p>
				<p><?php echo esc_html( $this->window_summary_sentence( $windows[0] ) ); ?></p>
			<?php endif; ?>
			<?php echo $this->detail_link( $detail_url, 'View boating forecast' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function render_boating_detail( array $windows ): string {
		ob_start();
		?>
		<section class="lkc-panel lkc-boating">
			<header class="lkc-panel-header">
				<p class="lkc-eyebrow">Pontoon Ride Windows</p>
				<h2>Best Boating Conditions</h2>
				<p>Daylight windows with lighter wind, comfortable temperatures, and lower rain risk. Each suggested window is shown once so the forecast is easier to scan.</p>
			</header>
			<?php if ( empty( $windows ) ) : ?>
				<p>No strong boating windows are showing in the current forecast. Check again after the next forecast refresh.</p>
			<?php else : ?>
				<div class="lkc-featured-window">
					<div>
						<p class="lkc-eyebrow">Best next window</p>
						<h3><?php echo esc_html( $this->format_window_range( $windows[0] ) ); ?></h3>
						<p><?php echo esc_html( $this->window_summary_sentence( $windows[0] ) ); ?></p>
					</div>
					<span class="<?php echo esc_attr( $this->rating_class( $windows[0]['rating'] ) ); ?>"><?php echo esc_html( $windows[0]['rating'] ); ?></span>
				</div>

				<div class="lkc-window-list">
					<?php foreach ( $windows as $window ) : ?>
						<article class="lkc-window-card">
							<div class="lkc-card-title-row">
								<h3><?php echo esc_html( $this->format_day( $window['start'] ) ); ?></h3>
								<span class="<?php echo esc_attr( $this->rating_class( $window['rating'] ) ); ?>"><?php echo esc_html( $window['rating'] ); ?></span>
							</div>
							<p class="lkc-window-time"><?php echo esc_html( $this->format_compact_window_range( $window ) ); ?></p>
							<dl class="lkc-metric-grid">
								<div><dt>Temp</dt><dd><?php echo esc_html( (string) $window['temp_avg'] ); ?>&deg;F</dd></div>
								<div><dt>Wind</dt><dd><?php echo esc_html( (string) $window['wind_max'] ); ?> mph</dd></div>
								<div><dt>Rain</dt><dd><?php echo esc_html( (string) $window['rain_max'] ); ?>%</dd></div>
							</dl>
							<?php if ( ! empty( $window['notes'] ) ) : ?>
								<p class="lkc-window-note"><?php echo esc_html( implode( ' ', $window['notes'] ) ); ?></p>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>

				<div class="lkc-hour-detail">
					<h3>Hourly Detail</h3>
					<div class="lkc-table-scroll">
						<table class="lkc-hour-table">
							<thead><tr><th>Window</th><th>Hour</th><th>Temp</th><th>Wind</th><th>Rain</th></tr></thead>
							<tbody>
								<?php foreach ( $windows as $window ) : ?>
									<?php foreach ( $window['hours'] as $hour ) : ?>
										<tr>
											<td><?php echo esc_html( $this->format_day( $window['start'] ) . ' ' . $this->format_compact_window_range( $window ) ); ?></td>
											<td><?php echo esc_html( $this->format_hour( $hour['time'] ) ); ?></td>
											<td><?php echo esc_html( (string) round( $hour['temperature'] ) ); ?>&deg;</td>
											<td><?php echo esc_html( $this->wind_label( $hour ) ); ?></td>
											<td><?php echo esc_html( (string) $hour['precip_probability'] ); ?>%</td>
										</tr>
									<?php endforeach; ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function render_fishing_summary( array $outlook, string $detail_url ): string {
		$windows = is_array( $outlook['windows'] ?? null ) ? $outlook['windows'] : array();

		ob_start();
		?>
		<section class="lkc-panel lkc-panel-summary lkc-fishing-summary">
			<header class="lkc-panel-header">
				<p class="lkc-eyebrow">Fishing</p>
				<h3><?php echo empty( $windows ) ? esc_html( $outlook['rating'] . ' fishing conditions' ) : esc_html( $windows[0]['rating'] . ' fishing window' ); ?></h3>
			</header>
			<?php if ( empty( $windows ) ) : ?>
				<p>Wind averages <?php echo esc_html( (string) ( $outlook['average_wind'] ?? 0 ) ); ?> mph, rain risk reaches <?php echo esc_html( (string) ( $outlook['rain_max'] ?? 0 ) ); ?>%, and the moon is <?php echo esc_html( strtolower( (string) ( $outlook['moon_phase'] ?? '' ) ) ); ?>.</p>
			<?php else : ?>
				<p class="lkc-window-time"><?php echo esc_html( $this->format_window_range( $windows[0] ) ); ?></p>
				<p><?php echo esc_html( $this->fishing_summary_sentence( $windows[0] ) ); ?></p>
			<?php endif; ?>
			<?php echo $this->detail_link( $detail_url, 'View fishing forecast' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function render_fishing_detail( array $outlook ): string {
		$windows = is_array( $outlook['windows'] ?? null ) ? $outlook['windows'] : array();

		ob_start();
		?>
		<section class="lkc-panel lkc-fishing">
			<header class="lkc-panel-header">
				<p class="lkc-eyebrow">Fishing Outlook</p>
				<h2><?php echo esc_html( $outlook['rating'] ); ?> Fishing Conditions</h2>
				<p><?php echo esc_html( $outlook['summary'] ); ?></p>
			</header>
			<?php if ( ! empty( $windows ) ) : ?>
				<div class="lkc-featured-window">
					<div>
						<p class="lkc-eyebrow">Best next fishing window</p>
						<h3><?php echo esc_html( $this->format_window_range( $windows[0] ) ); ?></h3>
						<p><?php echo esc_html( $this->fishing_summary_sentence( $windows[0] ) ); ?></p>
					</div>
					<span class="<?php echo esc_attr( $this->rating_class( $windows[0]['rating'] ) ); ?>"><?php echo esc_html( $windows[0]['rating'] ); ?></span>
				</div>
				<div class="lkc-window-list">
					<?php foreach ( $windows as $window ) : ?>
						<article class="lkc-window-card">
							<div class="lkc-card-title-row">
								<h3><?php echo esc_html( $this->format_day( $window['start'] ) ); ?></h3>
								<span class="<?php echo esc_attr( $this->rating_class( $window['rating'] ) ); ?>"><?php echo esc_html( $window['rating'] ); ?></span>
							</div>
							<p class="lkc-window-time"><?php echo esc_html( $this->format_compact_window_range( $window ) ); ?></p>
							<p class="lkc-window-note"><?php echo esc_html( $window['period_type'] . ' solunar period: ' . $window['event_label'] ); ?></p>
							<dl class="lkc-metric-grid">
								<div><dt>Wind</dt><dd><?php echo esc_html( (string) $window['wind_avg'] ); ?> mph</dd></div>
								<div><dt>Rain</dt><dd><?php echo esc_html( (string) $window['rain_max'] ); ?>%</dd></div>
								<div><dt>Moon</dt><dd><?php echo esc_html( (string) ( $window['moon_illumination'] ?: $window['moon_phase'] ) ); ?></dd></div>
							</dl>
							<?php if ( ! empty( $window['notes'] ) ) : ?>
								<p class="lkc-window-note"><?php echo esc_html( implode( ' ', $window['notes'] ) ); ?></p>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
				<div class="lkc-hour-detail">
					<h3>Solunar Table</h3>
					<div class="lkc-table-scroll">
						<table class="lkc-hour-table">
							<thead><tr><th>Window</th><th>Solunar</th><th>Rating</th><th>Wind</th><th>Rain</th><th>Moon</th></tr></thead>
							<tbody>
								<?php foreach ( $windows as $window ) : ?>
									<tr>
										<td><?php echo esc_html( $this->format_day( $window['start'] ) . ' ' . $this->format_compact_window_range( $window ) ); ?></td>
										<td><?php echo esc_html( $window['period_type'] . ': ' . $window['event_label'] ); ?></td>
										<td><?php echo esc_html( $window['rating'] ); ?></td>
										<td><?php echo esc_html( (string) $window['wind_avg'] ); ?> mph</td>
										<td><?php echo esc_html( (string) $window['rain_max'] ); ?>%</td>
										<td><?php echo esc_html( trim( (string) $window['moon_phase'] . ' ' . (string) $window['moon_illumination'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endif; ?>
			<ul class="lkc-stat-list">
				<li><strong>Average wind:</strong> <?php echo esc_html( (string) ( $outlook['average_wind'] ?? 0 ) ); ?> mph</li>
				<li><strong>Rain risk:</strong> up to <?php echo esc_html( (string) ( $outlook['rain_max'] ?? 0 ) ); ?>%</li>
				<li><strong>Pressure drop:</strong> <?php echo esc_html( (string) ( $outlook['pressure_drop'] ?? 0 ) ); ?> hPa</li>
				<li><strong>Moon:</strong> <?php echo esc_html( trim( (string) ( $outlook['moon_phase'] ?? '' ) . ' ' . (string) ( $outlook['moon_illumination'] ?? '' ) ) ); ?></li>
			</ul>
			<?php if ( ! empty( $outlook['notes'] ) ) : ?>
				<ul class="lkc-note-list">
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
			.lake-home-conditions-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
				gap: 1rem;
				margin-top: 1.25rem;
			}
			.lake-home-conditions-grid .lkc-panel {
				margin: 0;
			}
			.lkc-panel {
				border: 1px solid #d8e1dc;
				background: #f7faf8;
				color: #173f42;
				padding: clamp(1.25rem, 4vw, 2rem);
				margin: 1.5rem 0;
				box-sizing: border-box;
			}
			.lkc-panel h2,
			.lkc-panel h3,
			.lkc-panel p,
			.lkc-panel li,
			.lkc-panel dt,
			.lkc-panel dd {
				color: inherit;
			}
			.lkc-panel h2 {
				margin: 0 0 .45rem;
				font-size: clamp(1.55rem, 3vw, 2.25rem);
				line-height: 1.15;
			}
			.lkc-panel h3 {
				margin: 0;
				font-size: clamp(1.08rem, 2vw, 1.35rem);
				line-height: 1.2;
			}
			.lkc-panel-header {
				margin-bottom: 1rem;
			}
			.lkc-panel-summary {
				min-height: 100%;
				display: flex;
				flex-direction: column;
			}
			.lkc-eyebrow {
				margin: 0 0 .35rem;
				font-size: .8rem;
				font-weight: 800;
				letter-spacing: .08em;
				text-transform: uppercase;
			}
			.lkc-featured-window {
				display: flex;
				justify-content: space-between;
				align-items: flex-start;
				gap: 1rem;
				padding: 1rem;
				margin: 1rem 0;
				border: 1px solid #d8e1dc;
				background: #ffffff;
			}
			.lkc-featured-window p:last-child {
				margin-bottom: 0;
			}
			.lkc-window-list {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
				gap: 1rem;
				margin-top: 1rem;
			}
			.lkc-window-card {
				background: #fff;
				border: 1px solid #d8e1dc;
				padding: 1rem;
			}
			.lkc-card-title-row {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: .75rem;
			}
			.lkc-window-time {
				font-weight: 800;
				margin: .55rem 0;
			}
			.lkc-window-note {
				margin: .75rem 0 0;
				font-size: .95rem;
			}
			.lkc-metric-grid {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: .55rem;
				margin: .75rem 0 0;
			}
			.lkc-metric-grid div {
				padding: .65rem;
				background: #f7faf8;
				border: 1px solid #e2ebe6;
			}
			.lkc-metric-grid dt {
				margin: 0 0 .2rem;
				font-size: .75rem;
				font-weight: 800;
				text-transform: uppercase;
			}
			.lkc-metric-grid dd {
				margin: 0;
				font-weight: 800;
			}
			.lkc-rating-pill {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-height: 30px;
				padding: .25rem .6rem;
				border-radius: 999px;
				background: #e7f2ee;
				color: #164c50;
				font-size: .82rem;
				font-weight: 800;
				white-space: nowrap;
			}
			.lkc-rating-excellent {
				background: #dff1e6;
				color: #1c6535;
			}
			.lkc-rating-good {
				background: #e5f3f3;
				color: #155e63;
			}
			.lkc-rating-fair {
				background: #fff1d6;
				color: #7a4a00;
			}
			.lkc-rating-poor,
			.lkc-rating-unavailable {
				background: #f7e3df;
				color: #8a2d1b;
			}
			.lkc-hour-detail {
				margin-top: 1.5rem;
			}
			.lkc-table-scroll {
				overflow-x: auto;
				border: 1px solid #d8e1dc;
				background: #ffffff;
			}
			.lkc-hour-table {
				width: 100%;
				min-width: 680px;
				border-collapse: collapse;
				font-size: .9rem;
			}
			.lkc-hour-table th,
			.lkc-hour-table td {
				border-top: 1px solid #d8e1dc;
				padding: .45rem;
				text-align: left;
			}
			.lkc-hour-table th {
				background: #edf4f1;
				font-weight: 800;
			}
			.lkc-stat-list {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
				gap: .5rem 1rem;
				padding-left: 1rem;
			}
			.lkc-note-list {
				margin-top: 1rem;
			}
			.lkc-actions {
				margin: auto 0 0;
				padding-top: 1rem;
			}
			.lkc-button {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-height: 42px;
				padding: .7rem 1rem;
				background: #155e63;
				color: #ffffff !important;
				font-weight: 800;
				text-decoration: none;
			}
			.lkc-button:hover {
				background: #0e4b4f;
				color: #ffffff !important;
			}
			@media (max-width: 640px) {
				.lkc-featured-window,
				.lkc-card-title-row {
					display: grid;
				}
				.lkc-metric-grid {
					grid-template-columns: 1fr;
				}
				.lkc-button {
					width: 100%;
				}
			}
		</style>
		<?php
	}

	private function detail_link( string $url, string $label ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		return '<p class="lkc-actions"><a class="lkc-button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
	}

	private function format_window_range( array $window ): string {
		$start = strtotime( (string) ( $window['start'] ?? '' ) );
		$end   = $this->window_end_timestamp( $window );

		if ( ! $start || ! $end ) {
			return (string) ( $window['start'] ?? '' ) . ' - ' . (string) ( $window['end'] ?? '' );
		}

		$end_format = date_i18n( 'Y-m-d', $start ) === date_i18n( 'Y-m-d', $end ) ? 'g:i a' : 'D, M j g:i a';

		return date_i18n( 'D, M j g:i a', $start ) . ' - ' . date_i18n( $end_format, $end );
	}

	private function format_compact_window_range( array $window ): string {
		$start = strtotime( (string) ( $window['start'] ?? '' ) );
		$end   = $this->window_end_timestamp( $window );

		if ( ! $start || ! $end ) {
			return (string) ( $window['start'] ?? '' ) . ' - ' . (string) ( $window['end'] ?? '' );
		}

		return date_i18n( 'g:i a', $start ) . ' - ' . date_i18n( 'g:i a', $end );
	}

	private function format_day( string $time ): string {
		$timestamp = strtotime( $time );
		return $timestamp ? date_i18n( 'D, M j', $timestamp ) : $time;
	}

	private function window_summary_sentence( array $window ): string {
		return sprintf(
			'Average temp %s F, max wind %s mph, rain chance up to %s%%.',
			(string) ( $window['temp_avg'] ?? 0 ),
			(string) ( $window['wind_max'] ?? 0 ),
			(string) ( $window['rain_max'] ?? 0 )
		);
	}

	private function fishing_summary_sentence( array $window ): string {
		return sprintf(
			'%s solunar period, %s wind, rain chance up to %s%%.',
			(string) ( $window['period_type'] ?? 'Solunar' ),
			(string) ( $window['wind_avg'] ?? 0 ) . ' mph',
			(string) ( $window['rain_max'] ?? 0 )
		);
	}

	private function rating_class( string $rating ): string {
		return 'lkc-rating-pill ' . sanitize_html_class( 'lkc-rating-' . strtolower( $rating ) );
	}

	private function window_end_timestamp( array $window ): int {
		$end = strtotime( (string) ( $window['end'] ?? '' ) );
		if ( ! $end ) {
			return 0;
		}

		return ! empty( $window['end_is_exact'] ) ? $end : $end + HOUR_IN_SECONDS;
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
