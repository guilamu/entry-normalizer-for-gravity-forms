<?php
/**
 * Plugin Name: Entry Normalizer for Gravity Forms
 * Plugin URI: https://github.com/guilamu/entry-normalizer-for-gravity-forms
 * Description: Normalize Gravity Forms fields (existing entries and future submissions) from BEFORE/AFTER examples: the plugin automatically detects the matching transformation (uppercase, lowercase, capitalization, French phone numbers to +33, etc.).
 * Version: 1.0.0
 * Author: Guilamu
 * Author URI: https://github.com/guilamu
 * Text Domain: entry-normalizer-for-gravity-forms
 * Domain Path: /languages
 * Update URI: https://github.com/guilamu/entry-normalizer-for-gravity-forms/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: AGPL-3.0
 */

defined( 'ABSPATH' ) || exit;

define( 'GFEN_VERSION', '1.0.0' );
define( 'GFEN_PLUGIN_FILE', __FILE__ );
define( 'GFEN_BASENAME', plugin_basename( __FILE__ ) );
define( 'GFEN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFEN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// GitHub auto-updates from releases (see includes/class-github-updater.php).
require_once GFEN_PLUGIN_DIR . 'includes/class-github-updater.php';

add_action( 'init', function () {
	load_plugin_textdomain( 'entry-normalizer-for-gravity-forms', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

add_action( 'gform_loaded', array( 'GFEN_Bootstrap', 'load' ), 5 );

class GFEN_Bootstrap {
	public static function load() {
		if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
			return;
		}
		require_once GFEN_PLUGIN_DIR . 'includes/class-gfen-transforms.php';
		require_once GFEN_PLUGIN_DIR . 'includes/class-gfen-addon.php';
		GFAddOn::register( 'GFEN_AddOn' );
	}
}

// Warn when Gravity Forms is not active.
add_action( 'admin_notices', function () {
	if ( ! class_exists( 'GFForms' ) && current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Entry Normalizer for Gravity Forms requires the Gravity Forms plugin to be active.', 'entry-normalizer-for-gravity-forms' );
		echo '</p></div>';
	}
} );

// Register with Guilamu Bug Reporter.
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		Guilamu_Bug_Reporter::register( array(
			'slug'        => 'entry-normalizer-for-gravity-forms',
			'name'        => 'Entry Normalizer for Gravity Forms',
			'version'     => GFEN_VERSION,
			'github_repo' => 'guilamu/entry-normalizer-for-gravity-forms',
		) );
	}
}, 20 );

/**
 * "View details" and "Report a Bug" links in the plugin's row of the plugins list.
 *
 * @param string[] $links Existing row meta links.
 * @param string   $file  Plugin file currently being rendered.
 * @return string[]
 */
function gfen_plugin_row_meta( $links, $file ) {
	if ( GFEN_BASENAME !== $file ) {
		return $links;
	}

	// "View details" thickbox link — same pattern as WordPress.org-hosted plugins.
	$links[] = sprintf(
		'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
		esc_url( self_admin_url(
			'plugin-install.php?tab=plugin-information&plugin=entry-normalizer-for-gravity-forms'
			. '&TB_iframe=true&width=772&height=926'
		) ),
		esc_attr__( 'More information about Entry Normalizer for Gravity Forms', 'entry-normalizer-for-gravity-forms' ),
		esc_attr__( 'Entry Normalizer for Gravity Forms', 'entry-normalizer-for-gravity-forms' ),
		esc_html__( 'View details', 'entry-normalizer-for-gravity-forms' )
	);

	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		$links[] = sprintf(
			'<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="entry-normalizer-for-gravity-forms" data-plugin-name="%s">%s</a>',
			esc_attr__( 'Entry Normalizer for Gravity Forms', 'entry-normalizer-for-gravity-forms' ),
			esc_html__( '🐛 Report a Bug', 'entry-normalizer-for-gravity-forms' )
		);
	} else {
		// Fallback: prompt user to install Bug Reporter.
		$links[] = '<a href="https://github.com/guilamu/guilamu-bug-reporter/releases" target="_blank">'
			. esc_html__( '🐛 Report a Bug (install Bug Reporter)', 'entry-normalizer-for-gravity-forms' )
			. '</a>';
	}

	return $links;
}
add_filter( 'plugin_row_meta', 'gfen_plugin_row_meta', 10, 2 );

/**
 * Access the add-on instance.
 *
 * @return GFEN_AddOn|null
 */
function gfen_addon() {
	return class_exists( 'GFEN_AddOn' ) ? GFEN_AddOn::get_instance() : null;
}
