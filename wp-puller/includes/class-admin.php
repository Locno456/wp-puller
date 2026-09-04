<?php
/**
 * Admin class for WP Puller.
 *
 * Manages the multi-package UI: global settings (shared token, webhook
 * secret, backup count) and per-package configuration (repository, branch,
 * type, optional custom token/webhook), plus all AJAX actions.
 *
 * @package WP_Puller
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP_Puller_Admin Class.
 */
class WP_Puller_Admin {

    /**
     * GitHub API instance.
     *
     * @var WP_Puller_GitHub_API
     */
    private $github_api;

    /**
     * Package updater instance.
     *
     * @var WP_Puller_Updater
     */
    private $updater;

    /**
     * Backup instance.
     *
     * @var WP_Puller_Backup
     */
    private $backup;

    /**
     * Logger instance.
     *
     * @var WP_Puller_Logger
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param WP_Puller_GitHub_API    $github_api GitHub API instance.
     * @param WP_Puller_Updater       $updater    Package updater instance.
     * @param WP_Puller_Backup        $backup     Backup instance.
     * @param WP_Puller_Logger        $logger     Logger instance.
     */
    public function __construct( $github_api, $updater, $backup, $logger ) {
        $this->github_api = $github_api;
        $this->updater    = $updater;
        $this->backup     = $backup;
        $this->logger     = $logger;

        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        add_action( 'wp_ajax_wp_puller_save_global', array( $this, 'ajax_save_global' ) );
        add_action( 'wp_ajax_wp_puller_save_package', array( $this, 'ajax_save_package' ) );
        add_action( 'wp_ajax_wp_puller_delete_package', array( $this, 'ajax_delete_package' ) );
        add_action( 'wp_ajax_wp_puller_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_wp_puller_check_updates', array( $this, 'ajax_check_updates' ) );
        add_action( 'wp_ajax_wp_puller_update_package', array( $this, 'ajax_update_package' ) );
        add_action( 'wp_ajax_wp_puller_restore_backup', array( $this, 'ajax_restore_backup' ) );
        add_action( 'wp_ajax_wp_puller_delete_backup', array( $this, 'ajax_delete_backup' ) );
        add_action( 'wp_ajax_wp_puller_regenerate_secret', array( $this, 'ajax_regenerate_secret' ) );
        add_action( 'wp_ajax_wp_puller_clear_logs', array( $this, 'ajax_clear_logs' ) );

        // Server-side (no-JS) fallbacks so the UI works even if JavaScript fails.
        add_action( 'admin_post_wp_puller_save_global', array( $this, 'handle_global_post' ) );
        add_action( 'admin_post_wp_puller_save_package', array( $this, 'handle_package_post' ) );
        add_action( 'admin_post_wp_puller_update_package', array( $this, 'handle_update_post' ) );
        add_action( 'admin_post_wp_puller_delete_package', array( $this, 'handle_delete_post' ) );
        add_action( 'admin_post_wp_puller_restore_backup', array( $this, 'handle_restore_post' ) );
        add_action( 'admin_post_wp_puller_check_updates', array( $this, 'handle_check_post' ) );
        add_action( 'admin_post_wp_puller_delete_backup', array( $this, 'handle_delete_backup' ) );
        add_action( 'admin_post_wp_puller_clear_logs', array( $this, 'handle_clear_logs' ) );
        add_action( 'admin_post_wp_puller_regenerate_secret', array( $this, 'handle_regenerate_secret' ) );
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'WP Puller', 'wp-puller' ),
            __( 'WP Puller', 'wp-puller' ),
            'manage_options',
            'wp-puller',
            array( $this, 'render_admin_page' ),
            'dashicons-update',
            80
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'wp-puller' ) ) {
            return;
        }

        wp_enqueue_style(
            'wp-puller-admin',
            WP_PULLER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WP_PULLER_VERSION
        );

        wp_enqueue_script(
            'wp-puller-admin',
            WP_PULLER_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WP_PULLER_VERSION,
            true
        );

        wp_localize_script( 'wp-puller-admin', 'wpPuller', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wp_puller_nonce' ),
            'strings'  => array(
                'saving'           => __( 'Saving...', 'wp-puller' ),
                'saved'            => __( 'Settings saved!', 'wp-puller' ),
                'testing'          => __( 'Testing connection...', 'wp-puller' ),
                'connected'        => __( 'Connected successfully!', 'wp-puller' ),
                'checking'         => __( 'Checking for updates...', 'wp-puller' ),
                'updating'         => __( 'Updating package...', 'wp-puller' ),
                'updated'          => __( 'Package updated successfully!', 'wp-puller' ),
                'restoring'        => __( 'Restoring backup...', 'wp-puller' ),
                'restored'         => __( 'Backup restored successfully!', 'wp-puller' ),
                'deleting'         => __( 'Deleting backup...', 'wp-puller' ),
                'deleted'          => __( 'Backup deleted!', 'wp-puller' ),
                'regenerating'     => __( 'Regenerating secret...', 'wp-puller' ),
                'regenerated'      => __( 'Secret regenerated!', 'wp-puller' ),
                'confirmRestore'   => __( 'Are you sure you want to restore this backup? Your current package will be replaced.', 'wp-puller' ),
                'confirmDelete'    => __( 'Are you sure you want to delete this backup?', 'wp-puller' ),
                'confirmRegenerate'=> __( 'Are you sure? You will need to update the secret in GitHub.', 'wp-puller' ),
                'confirmDeletePkg' => __( 'Are you sure you want to delete this package?', 'wp-puller' ),
            ),
        ) );
    }

    /**
     * Render admin page.
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-puller' ) );
        }

        $packages      = WP_Puller::get_packages();
        $webhook_info  = WP_Puller_Webhook_Handler::get_setup_instructions();
        $logs          = $this->logger->get_recent_logs( 10 );
        $backup_count  = get_option( 'wp_puller_backup_count', 3 );
        $global_pat    = self::get_masked_pat();
        $global_status = self::get_pat_status();

        $flash = '';
        $flash_key = 'wp_puller_flash_' . get_current_user_id();
        $flash_data = get_transient( $flash_key );
        if ( $flash_data ) {
            delete_transient( $flash_key );
            $flash = $flash_data;
        }

        $package_views = array();
        foreach ( $packages as $pkg ) {
            $location = WP_Puller_Updater::get_package_location( $pkg );
            $package_views[] = array(
                'pkg'       => $pkg,
                'status'    => $this->updater->get_package_status( $pkg ),
                'info'      => $this->updater->get_current_info( $pkg ),
                'backups'   => $this->backup->get_backups( $location['slug'] ),
            );
        }

        include WP_PULLER_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * AJAX: Save global settings.
     */
    public function ajax_save_global() {
        $this->verify_ajax_request();

        $result = $this->save_global_from_input( $_POST );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Global settings saved successfully.', 'wp-puller' ) ) );
    }

    /**
     * Persist global settings from raw input (used by AJAX and no-JS fallback).
     *
     * @param array $input Raw input (typically $_POST).
     * @return true|WP_Error
     */
    private function save_global_from_input( $input ) {
        $backup_count = isset( $input['backup_count'] ) ? absint( $input['backup_count'] ) : 3;
        $pat          = isset( $input['global_pat'] ) ? sanitize_text_field( wp_unslash( $input['global_pat'] ) ) : '';

        update_option( 'wp_puller_backup_count', max( 1, min( 10, $backup_count ) ) );

        if ( ! empty( $pat ) && '*****' !== substr( $pat, 0, 5 ) ) {
            update_option( 'wp_puller_global_pat', WP_Puller::encrypt( $pat ) );
        }

        $this->logger->log(
            __( 'Global settings updated', 'wp-puller' ),
            WP_Puller_Logger::STATUS_INFO,
            WP_Puller_Logger::SOURCE_MANUAL
        );

        return true;
    }

    /**
     * No-JS fallback: handle global settings POST via admin-post.php.
     */
    public function handle_global_post() {
        if ( ! current_user_can( 'manage_options' )
            || empty( $_POST['wp_puller_global_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_puller_global_nonce'] ) ), 'wp_puller_save_global' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-puller' ) );
        }

        $result = $this->save_global_from_input( $_POST );

        if ( is_wp_error( $result ) ) {
            wp_redirect( admin_url( 'admin.php?page=wp-puller&wp_puller_err=' . urlencode( $result->get_error_message() ) ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=wp-puller&wp_puller_msg=' . urlencode( __( 'Global settings saved successfully.', 'wp-puller' ) ) ) );
        }
        exit;
    }

    /**
     * AJAX: Save (add or update) a package.
     */
    public function ajax_save_package() {
        $this->verify_ajax_request();

        $result = $this->persist_package( $_POST );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Package saved successfully.', 'wp-puller' ) ) );
    }

    /**
     * Persist a package from raw input (used by AJAX and no-JS fallback).
     *
     * @param array $input Raw input (typically $_POST).
     * @return true|WP_Error
     */
    private function persist_package( $input ) {
        $id = isset( $input['pkg_id'] ) ? sanitize_key( wp_unslash( $input['pkg_id'] ) ) : '';

        $existing = $id ? WP_Puller::get_package( $id ) : null;

        $pkg = array();

        $pkg['id']           = $id;
        $pkg['label']        = isset( $input['label'] ) ? sanitize_text_field( wp_unslash( $input['label'] ) ) : '';
        $pkg['repo_url']     = isset( $input['repo_url'] ) ? esc_url_raw( wp_unslash( $input['repo_url'] ) ) : '';
        $pkg['branch']       = isset( $input['branch'] ) ? sanitize_text_field( wp_unslash( $input['branch'] ) ) : 'main';
        $pkg['source_path']  = isset( $input['source_path'] ) ? sanitize_text_field( wp_unslash( $input['source_path'] ) ) : '';
        $pkg['plugin_slug']  = isset( $input['plugin_slug'] ) ? sanitize_text_field( wp_unslash( $input['plugin_slug'] ) ) : '';
        $pkg['auto_update']  = isset( $input['auto_update'] ) && 'true' === $input['auto_update'];

        $package_type = isset( $input['package_type'] ) ? sanitize_key( wp_unslash( $input['package_type'] ) ) : 'plugin';
        if ( ! in_array( $package_type, array( 'theme', 'plugin' ), true ) ) {
            $package_type = 'plugin';
        }
        $pkg['package_type'] = $package_type;

        $pkg['commit'] = isset( $input['commit'] ) ? sanitize_text_field( wp_unslash( $input['commit'] ) ) : '';

        if ( false !== strpos( $pkg['source_path'], '..' ) || false !== strpos( $pkg['plugin_slug'], '..' ) ) {
            return new WP_Error( 'invalid_path', __( 'Invalid path.', 'wp-puller' ) );
        }

        $pkg['latest_commit'] = $existing ? $existing['latest_commit'] : '';
        $pkg['last_check']    = $existing ? $existing['last_check'] : 0;
        $pkg['package_kind']  = $existing ? $existing['package_kind'] : 'dir';
        $pkg['last_commit_message'] = $existing ? $existing['last_commit_message'] : '';

        $token_mode = isset( $input['token_mode'] ) ? sanitize_key( wp_unslash( $input['token_mode'] ) ) : 'global';
        if ( ! in_array( $token_mode, array( 'global', 'custom' ), true ) ) {
            $token_mode = 'global';
        }
        $pkg['token_mode'] = $token_mode;

        $pat = isset( $input['pat'] ) ? sanitize_text_field( wp_unslash( $input['pat'] ) ) : '';
        if ( 'custom' === $token_mode ) {
            if ( ! empty( $pat ) && '*****' !== substr( $pat, 0, 5 ) ) {
                $pkg['pat'] = WP_Puller::encrypt( $pat );
            } elseif ( $existing && ! empty( $existing['pat'] ) ) {
                $pkg['pat'] = $existing['pat'];
            } else {
                $pkg['pat'] = '';
            }
        } else {
            $pkg['pat'] = '';
        }

        $webhook_mode = isset( $input['webhook_mode'] ) ? sanitize_key( wp_unslash( $input['webhook_mode'] ) ) : 'global';
        if ( ! in_array( $webhook_mode, array( 'global', 'custom' ), true ) ) {
            $webhook_mode = 'global';
        }
        $pkg['webhook_mode'] = $webhook_mode;

        $webhook_secret = isset( $input['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $input['webhook_secret'] ) ) : '';
        if ( 'custom' === $webhook_mode ) {
            if ( ! empty( $webhook_secret ) && '*****' !== substr( $webhook_secret, 0, 5 ) ) {
                $pkg['webhook_secret'] = WP_Puller::encrypt( $webhook_secret );
            } elseif ( $existing && ! empty( $existing['webhook_secret'] ) ) {
                $pkg['webhook_secret'] = $existing['webhook_secret'];
            } else {
                $pkg['webhook_secret'] = '';
            }
        } else {
            $pkg['webhook_secret'] = '';
        }

        $packages = WP_Puller::get_packages();
        $found    = false;
        foreach ( $packages as &$p ) {
            if ( ! empty( $id ) && isset( $p['id'] ) && $p['id'] === $id ) {
                $p = $pkg;
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            $packages[] = $pkg;
        }

        WP_Puller::save_packages( $packages );

        $this->logger->log(
            sprintf(
                __( 'Package saved: %s', 'wp-puller' ),
                $pkg['label'] ?: $pkg['repo_url']
            ),
            WP_Puller_Logger::STATUS_INFO,
            WP_Puller_Logger::SOURCE_MANUAL
        );

        return true;
    }

    /**
     * No-JS fallback: handle package save POST via admin-post.php.
     */
    public function handle_package_post() {
        if ( ! current_user_can( 'manage_options' )
            || empty( $_POST['wp_puller_pkg_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_puller_pkg_nonce'] ) ), 'wp_puller_save_package' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-puller' ) );
        }

        $result = $this->persist_package( $_POST );

        if ( is_wp_error( $result ) ) {
            wp_redirect( admin_url( 'admin.php?page=wp-puller&wp_puller_err=' . urlencode( $result->get_error_message() ) ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=wp-puller&wp_puller_msg=' . urlencode( __( 'Package saved successfully.', 'wp-puller' ) ) ) );
        }
        exit;
    }

    /**
     * No-JS fallback: handle package update POST via admin-post.php.
     */
    public function handle_update_post() {
        if ( ! $this->verify_post_nonce( 'wp_puller_update_package', 'wp_puller_update_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-puller' ) );
        }

        $id  = isset( $_POST['pkg_id'] ) ? sanitize_key( wp_unslash( $_POST['pkg_id'] ) ) : '';
        $pkg = WP_Puller::get_package( $id );

        if ( ! $pkg ) {
            $this->flash_redirect( 'error', __( 'Package not found.', 'wp-puller' ) );
        }

        $result = $this->updater->update_package( $pkg, WP_Puller_Logger::SOURCE_MANUAL );

        if ( is_wp_error( $result ) ) {
            $this->flash_redirect( 'error', $result->get_error_message() );
        }

        $this->flash_redirect( 'success', __( 'Package updated successfully.', 'wp-puller' ) );
    }

    /**
     * No-JS fallback: handle package delete POST via admin-post.php.
     */
    public function handle_delete_post() {
        if ( ! $this->verify_post_nonce( 'wp_puller_delete_package', 'wp_puller_delete_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-puller' ) );
        }

        $id = isset( $_POST['pkg_id'] ) ? sanitize_key( wp_unslash( $_POST['pkg_id'] ) ) : '';

        if ( empty( $id ) ) {
            $this->flash_redirect( 'error', __( 'Invalid package.', 'wp-puller' ) );
        }

        $packages = WP_Puller::get_packages();
        $packages = array_filter(
            $packages,
            function( $p ) use ( $id ) {
                return ! ( isset( $p['id'] ) && $p['id'] === $id );
            }
        );

        WP_Puller::save_packages( array_values( $packages ) );

        $this->flash_redirect( 'success', __( 'Package deleted.', 'wp-puller' ) );
    }

    /**
     * No-JS fallback: handle backup restore POST via admin-post.php.
     */
    public function handle_restore_post() {
        if ( ! $this->verify_post_nonce( 'wp_puller_restore_backup', 'wp_puller_restore_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-puller' ) );
        }

        $backup_name = isset( $_POST['backup_name'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_name'] ) ) : '';
        $id          = isset( $_POST['pkg_id'] ) ? sanitize_key( wp_unslash( $_POST['pkg_id'] ) ) : '';
        $pkg         = WP_Puller::get_package( $id );

        if ( empty( $backup_name ) ) {
            $this->flash_redirect( 'error', __( 'Invalid backup name.', 'wp-puller' ) );
        }

        $result = $this->backup->restore_backup( $backup_name, $pkg );

        if ( is_wp_error( $result ) ) {
            $this->flash_redirect( 'error', $result->get_error_message() );
        }

        $this->flash_redirect( 'success', __( 'Backup restored successfully.', 'wp-puller' ) );
    }

    /**
     * No-JS fallback: handle check-for-updates POST via admin-post.php.
     */
    public function handle_check_post() {
        if ( ! $this->verify_post_nonce( 'wp_puller_check_updates', 'wp_puller_check_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-puller' ) );
        }

        $id  = isset( $_POST['pkg_id'] ) ? sanitize_key( wp_unslash( $_POST['pkg_id'] ) ) : '';
        $pkg = WP_Puller::get_package( $id );

        if ( ! $pkg ) {
            $this->flash_redirect( 'error', __( 'Package not found.', 'wp-puller' ) );
        }

        $result = $this->updater->check_for_updates( $pkg );

        if ( is_wp_error( $result ) ) {
            $this->flash_redirect( 'error', $result->get_error_message() );
        }

        $packages = WP_Puller::get_packages();
        foreach ( $packages as &$p ) {
            if ( $p['id'] === $id ) {
                $p['last_check'] = $pkg['last_check'];
            }
        }
        WP_Puller::save_packages( $packages );

        $title = isset( $result['latest_commit']['message'] ) ? wp_trim_words( $result['latest_commit']['message'], 12, '…' ) : '';

        if ( $result['is_new_setup'] ) {
            $msg = __( 'Ready to install. Click "Update Now" to pull from GitHub.', 'wp-puller' );
        } elseif ( $result['update_available'] ) {
            $msg = sprintf( __( 'Update available! Latest: %s — %s', 'wp-puller' ), $result['latest_commit']['short_sha'], $title );
        } else {
            $msg = sprintf( __( 'Package is up to date (%s — %s).', 'wp-puller' ), $result['latest_commit']['short_sha'], $title );
        }

        $this->flash_redirect( 'success', $msg );
    }

    /**
     * No-JS fallback: handle backup delete POST via admin-post.php.
     */
    public function handle_delete_backup() {
        if ( ! $this->verify_post_nonce( 'wp_puller_delete_backup', 'wp_puller_delete_backup_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-puller' ) );
        }

        $backup_name = isset( $_POST['backup_name'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_name'] ) ) : '';

        if ( empty( $backup_name ) ) {
            $this->flash_redirect( 'error', __( 'Invalid backup name.', 'wp-puller' ) );
        }

        $result = $this->backup->delete_backup( $backup_name );

        if ( is_wp_error( $result ) ) {
            $this->flash_redirect( 'error', $result->get_error_message() );
        }

        $this->flash_redirect( 'success', __( 'Backup deleted successfully.', 'wp-puller' ) );
    }

    /**
     * No-JS fallback: handle clear logs POST via admin-post.php.
     */
    public function handle_clear_logs() {
        if ( ! $this->verify_post_nonce( 'wp_puller_clear_logs', 'wp_puller_clear_logs_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-puller' ) );
        }

        $this->logger->clear_logs();

        $this->flash_redirect( 'success', __( 'Logs cleared.', 'wp-puller' ) );
    }

    /**
     * No-JS fallback: handle regenerate webhook secret POST via admin-post.php.
     */
    public function handle_regenerate_secret() {
        if ( ! $this->verify_post_nonce( 'wp_puller_regenerate_secret', 'wp_puller_regen_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-puller' ) );
        }

        $new_secret = WP_Puller_Webhook_Handler::generate_secret();
        WP_Puller_Webhook_Handler::store_secret( $new_secret );

        $this->logger->log(
            __( 'Webhook secret regenerated', 'wp-puller' ),
            WP_Puller_Logger::STATUS_INFO,
            WP_Puller_Logger::SOURCE_MANUAL
        );

        $this->flash_redirect( 'success', __( 'Secret regenerated. Update it in GitHub.', 'wp-puller' ) );
    }

    /**
     * Verify a server-side POST nonce.
     *
     * @param string $action Nonce action.
     * @param string $name   Field name.
     * @return bool
     */
    private function verify_post_nonce( $action, $name ) {
        return current_user_can( 'manage_options' )
            && ! empty( $_POST[ $name ] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ), $action );
    }

    /**
     * Store a flash message and redirect back to the admin page.
     *
     * @param string $type success|error.
     * @param string $msg  Message.
     */
    private function flash_redirect( $type, $msg ) {
        $key = 'wp_puller_flash_' . get_current_user_id();
        set_transient( $key, array( 'type' => $type, 'message' => $msg ), 30 );
        wp_redirect( admin_url( 'admin.php?page=wp-puller' ) );
        exit;
    }

    /**
     * AJAX: Delete a package.
     */
    public function ajax_delete_package() {
        $this->verify_ajax_request();

        $id = isset( $_POST['pkg_id'] ) ? sanitize_key( wp_unslash( $_POST['pkg_id'] ) ) : '';

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid package.', 'wp-puller' ) ) );
        }

        $packages = WP_Puller::get_packages();
        $packages = array_filter( $packages, function( $p ) use ( $id ) {
            return ! ( isset( $p['id'] ) && $p['id'] === $id );
        } );

        WP_Puller::save_packages( array_values( $packages ) );

        wp_send_json_success( array( 'message' => __( 'Package deleted.', 'wp-puller' ) ) );
    }

    /**
     * AJAX: Test connection for a repository URL.
     */
    public function ajax_test_connection() {
        $this->verify_ajax_request();

        $repo_url = isset( $_POST['repo_url'] ) ? esc_url_raw( wp_unslash( $_POST['repo_url'] ) ) : '';

        if ( empty( $repo_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a repository URL.', 'wp-puller' ) ) );
        }

        $result = $this->github_api->test_connection( $repo_url );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $repo_info = array(
            'name'         => isset( $result['name'] ) ? $result['name'] : '',
            'full_name'    => isset( $result['full_name'] ) ? $result['full_name'] : '',
            'description'  => isset( $result['description'] ) ? $result['description'] : '',
            'private'      => isset( $result['private'] ) ? $result['private'] : false,
            'default_branch' => isset( $result['default_branch'] ) ? $result['default_branch'] : 'main',
        );

        wp_send_json_success( array(
            'message' => __( 'Connection successful!', 'wp-puller' ),
            'repo'    => $repo_info,
        ) );
    }

    /**
     * AJAX: Check for updates for a package.
     */
    public function ajax_check_updates() {
        $this->verify_ajax_request();

        $id   = isset( $_POST['pkg_id'] ) ? sanitize_key( wp_unslash( $_POST['pkg_id'] ) ) : '';
        $pkg  = WP_Puller::get_package( $id );

        if ( ! $pkg ) {
            wp_send_json_error( array( 'message' => __( 'Package not found.', 'wp-puller' ) ) );
        }

        $result = $this->updater->check_for_updates( $pkg );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Persist last_check.
        $packages = WP_Puller::get_packages();
        foreach ( $packages as &$p ) {
            if ( $p['id'] === $id ) {
                $p['last_check'] = $pkg['last_check'];
            }
        }
        WP_Puller::save_packages( $packages );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Update a package now.
     */
    public function ajax_update_package() {
        $this->verify_ajax_request();

        $id  = isset( $_POST['pkg_id'] ) ? sanitize_key( wp_unslash( $_POST['pkg_id'] ) ) : '';
        $pkg = WP_Puller::get_package( $id );

        if ( ! $pkg ) {
            wp_send_json_error( array( 'message' => __( 'Package not found.', 'wp-puller' ) ) );
        }

        $result = $this->updater->update_package( $pkg, WP_Puller_Logger::SOURCE_MANUAL );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Package updated successfully!', 'wp-puller' ),
            'status'  => $this->updater->get_package_status( $pkg ),
        ) );
    }

    /**
     * AJAX: Restore a backup for a package.
     */
    public function ajax_restore_backup() {
        $this->verify_ajax_request();

        $backup_name = isset( $_POST['backup_name'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_name'] ) ) : '';
        $id          = isset( $_POST['pkg_id'] ) ? sanitize_key( wp_unslash( $_POST['pkg_id'] ) ) : '';
        $pkg         = WP_Puller::get_package( $id );

        if ( empty( $backup_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid backup name.', 'wp-puller' ) ) );
        }

        $result = $this->backup->restore_backup( $backup_name, $pkg );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $this->logger->log_restore_success( $backup_name );

        wp_send_json_success( array(
            'message' => __( 'Backup restored successfully!', 'wp-puller' ),
        ) );
    }

    /**
     * AJAX: Delete a backup.
     */
    public function ajax_delete_backup() {
        $this->verify_ajax_request();

        $backup_name = isset( $_POST['backup_name'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_name'] ) ) : '';

        if ( empty( $backup_name ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid backup name.', 'wp-puller' ) ) );
        }

        $result = $this->backup->delete_backup( $backup_name );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Backup deleted successfully!', 'wp-puller' ),
        ) );
    }

    /**
     * AJAX: Regenerate the global webhook secret.
     */
    public function ajax_regenerate_secret() {
        $this->verify_ajax_request();

        $new_secret = WP_Puller_Webhook_Handler::generate_secret();
        WP_Puller_Webhook_Handler::store_secret( $new_secret );

        $this->logger->log(
            __( 'Webhook secret regenerated', 'wp-puller' ),
            WP_Puller_Logger::STATUS_INFO,
            WP_Puller_Logger::SOURCE_MANUAL
        );

        wp_send_json_success( array(
            'message' => __( 'Secret regenerated. Update it in GitHub.', 'wp-puller' ),
            'secret'  => $new_secret,
        ) );
    }

    /**
     * AJAX: Clear logs.
     */
    public function ajax_clear_logs() {
        $this->verify_ajax_request();

        $this->logger->clear_logs();

        wp_send_json_success( array(
            'message' => __( 'Logs cleared.', 'wp-puller' ),
        ) );
    }

    /**
     * Verify AJAX request.
     */
    private function verify_ajax_request() {
        if ( ! check_ajax_referer( 'wp_puller_nonce', 'nonce', false ) ) {
            wp_send_json_error( array(
                'message' => __( 'Security check failed.', 'wp-puller' ),
            ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(
                'message' => __( 'You do not have permission to perform this action.', 'wp-puller' ),
            ) );
        }
    }

    /**
     * Get the masked global PAT for display.
     *
     * @return string
     */
    public static function get_masked_pat() {
        $encrypted = get_option( 'wp_puller_global_pat', '' );

        if ( empty( $encrypted ) ) {
            return '';
        }

        $decrypted = WP_Puller::decrypt( $encrypted );

        if ( empty( $decrypted ) ) {
            return '';
        }

        return str_repeat( '*', min( strlen( $decrypted ), 24 ) );
    }

    /**
     * Get global PAT status for debugging.
     *
     * @return array
     */
    public static function get_pat_status() {
        $encrypted = get_option( 'wp_puller_global_pat', '' );

        if ( empty( $encrypted ) ) {
            return array(
                'stored'   => false,
                'decrypts' => false,
                'type'     => 'none',
                'message'  => 'No token saved',
            );
        }

        $decrypted = WP_Puller::decrypt( $encrypted );

        if ( empty( $decrypted ) ) {
            return array(
                'stored'   => true,
                'decrypts' => false,
                'type'     => 'unknown',
                'message'  => 'Token stored but decryption failed',
            );
        }

        $type = 'classic';
        if ( strpos( $decrypted, 'github_pat_' ) === 0 ) {
            $type = 'fine-grained';
        } elseif ( strpos( $decrypted, 'ghp_' ) === 0 ) {
            $type = 'classic';
        }

        return array(
            'stored'   => true,
            'decrypts' => true,
            'type'     => $type,
            'length'   => strlen( $decrypted ),
            'message'  => sprintf( 'Token OK (%s, %d chars)', $type, strlen( $decrypted ) ),
        );
    }
}
