<?php
/**
 * Plugin Name: WP Puller
 * Plugin URI: https://github.com/codician-team/wp-puller
 * Description: Automatically update your WordPress theme or plugin from GitHub. Supports public and private repositories with webhook-based real-time updates.
 * Version: 1.0.8
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Developer
 * Author URI: https://github.com/developer
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-puller
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_PULLER_VERSION', '1.0.8' );
define( 'WP_PULLER_PLUGIN_FILE', __FILE__ );
define( 'WP_PULLER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_PULLER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_PULLER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WP_PULLER_PLUGIN_DIR . 'includes/class-wp-puller.php';

/**
 * Returns the main instance of WP_Puller.
 *
 * @since 1.0.0
 * @return WP_Puller
 */
function wp_puller() {
    return WP_Puller::instance();
}

/**
 * Activation hook.
 *
 * @since 1.0.0
 */
function wp_puller_activate() {
    if ( ! get_option( 'wp_puller_webhook_secret' ) ) {
        // Stored encrypted at rest via WordPress salt-derived encryption.
        // This is the global webhook secret shared by all packages.
        update_option( 'wp_puller_webhook_secret', WP_Puller::encrypt( wp_generate_password( 32, false ) ) );
    }

    if ( false === get_option( 'wp_puller_backup_count' ) ) {
        update_option( 'wp_puller_backup_count', 3 );
    }

    if ( false === get_option( 'wp_puller_packages' ) ) {
        // Migrate a legacy single-package configuration into the new
        // multi-package model, if one exists.
        $packages = array();
        $old_repo = get_option( 'wp_puller_repo_url', '' );

        if ( ! empty( $old_repo ) ) {
            $packages[] = WP_Puller::normalize_package( array(
                'label'         => __( 'Migrated package', 'wp-puller' ),
                'repo_url'      => $old_repo,
                'branch'        => get_option( 'wp_puller_branch', 'main' ),
                'package_type'  => get_option( 'wp_puller_package_type', 'plugin' ),
                'source_path'   => get_option( 'wp_puller_theme_path', '' ),
                'plugin_slug'   => get_option( 'wp_puller_plugin_slug', '' ),
                'package_kind'  => get_option( 'wp_puller_package_kind', 'dir' ),
                'auto_update'   => get_option( 'wp_puller_auto_update', true ),
                'latest_commit' => get_option( 'wp_puller_latest_commit', '' ),
                'last_check'    => get_option( 'wp_puller_last_check', 0 ),
            ) );

            // Promote the legacy token to the global token.
            $old_pat = get_option( 'wp_puller_pat', '' );
            if ( ! empty( $old_pat ) ) {
                update_option( 'wp_puller_global_pat', $old_pat );
            }
        }

        update_option( 'wp_puller_packages', $packages );
    }

    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wp_puller_activate' );

/**
 * Deactivation hook.
 *
 * @since 1.0.0
 */
function wp_puller_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wp_puller_deactivate' );

wp_puller();
