<?php
/**
 * Plugin Name: Lake Kosh Conditions
 * Description: Weather-backed boating and fishing condition recommendations for Lake Koshkonong.
 * Version: 0.2.2
 * Update URI: https://github.com/stronganchor/lake-kosh-conditions
 * Author: Strong Anchor Tech
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LKC_VERSION', '0.2.2' );
define( 'LKC_PLUGIN_FILE', __FILE__ );
define( 'LKC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LKC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function lkc_get_update_branch(): string {
	$branch = 'main';

	if ( defined( 'LKC_UPDATE_BRANCH' ) && is_string( LKC_UPDATE_BRANCH ) ) {
		$override = trim( LKC_UPDATE_BRANCH );
		if ( '' !== $override ) {
			$branch = $override;
		}
	}

	return (string) apply_filters( 'lkc_update_branch', $branch );
}

function lkc_bootstrap_update_checker(): void {
	$checker_file = LKC_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
	if ( ! file_exists( $checker_file ) ) {
		return;
	}

	require_once $checker_file;

	if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return;
	}

	$repo_url = (string) apply_filters( 'lkc_update_repository', 'https://github.com/stronganchor/lake-kosh-conditions' );
	$slug     = dirname( plugin_basename( __FILE__ ) );

	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$repo_url,
		__FILE__,
		$slug
	);

	$update_checker->setBranch( lkc_get_update_branch() );

	foreach ( array( 'LKC_GITHUB_TOKEN', 'STRONGANCHOR_GITHUB_TOKEN', 'ANCHOR_GITHUB_TOKEN' ) as $constant_name ) {
		if ( ! defined( $constant_name ) || ! is_string( constant( $constant_name ) ) ) {
			continue;
		}

		$token = trim( (string) constant( $constant_name ) );
		if ( '' !== $token ) {
			$update_checker->setAuthentication( $token );
			break;
		}
	}
}

lkc_bootstrap_update_checker();

require_once LKC_PLUGIN_DIR . 'includes/class-lkc-settings.php';
require_once LKC_PLUGIN_DIR . 'includes/class-lkc-weather-client.php';
require_once LKC_PLUGIN_DIR . 'includes/class-lkc-astronomy-client.php';
require_once LKC_PLUGIN_DIR . 'includes/class-lkc-recommendations.php';
require_once LKC_PLUGIN_DIR . 'includes/class-lkc-plugin.php';

register_activation_hook( __FILE__, array( 'LKC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LKC_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'LKC_Plugin', 'instance' ) );
