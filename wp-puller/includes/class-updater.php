<?php
/**
 * Package Updater class for WP Puller.
 *
 * Deploys themes and plugins from one or more GitHub repositories. Each
 * package is configured independently (repository, branch, type, optional
 * custom token/webhook) while sharing a global token and webhook secret by
 * default.
 *
 * @package WP_Puller
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP_Puller_Updater Class.
 */
class WP_Puller_Updater {

    /**
     * GitHub API instance.
     *
     * @var WP_Puller_GitHub_API
     */
    private $github_api;

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
     * @param WP_Puller_GitHub_API $github_api GitHub API instance.
     * @param WP_Puller_Backup     $backup     Backup instance.
     * @param WP_Puller_Logger     $logger     Logger instance.
     */
    public function __construct( $github_api, $backup, $logger ) {
        $this->github_api = $github_api;
        $this->backup     = $backup;
        $this->logger     = $logger;
    }

    /**
     * Update a single configured package.
     *
     * @param array  $pkg    Package configuration.
     * @param string $source Update source (webhook, manual).
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function update_package( $pkg, $source = 'manual' ) {
        if ( ! $this->acquire_update_lock() ) {
            $error = new WP_Error(
                'update_locked',
                __( 'An update is already in progress. Please try again shortly.', 'wp-puller' )
            );
            $this->logger->log_update_error( $error->get_error_message(), $source );
            return $error;
        }

        $this->github_api->set_package( $pkg );

        $result = $this->do_update( $pkg, $source );

        $this->release_update_lock();

        if ( ! is_wp_error( $result ) ) {
            // Persist derived package data (detected slug/kind, last commit).
            $packages = WP_Puller::get_packages();
            foreach ( $packages as &$p ) {
                if ( isset( $p['id'] ) && isset( $pkg['id'] ) && $p['id'] === $pkg['id'] ) {
                    $p = $pkg;
                }
            }
            WP_Puller::save_packages( $packages );
        }

        return $result;
    }

    /**
     * Update every package that has auto-update enabled.
     *
     * @param string $source Update source.
     * @return array Map of package id => result.
     */
    public function update_all( $source = 'manual' ) {
        $results = array();

        foreach ( WP_Puller::get_packages() as $pkg ) {
            if ( empty( $pkg['auto_update'] ) ) {
                continue;
            }
            $results[ $pkg['id'] ] = $this->update_package( $pkg, $source );
        }

        return $results;
    }

    /**
     * Acquire the update lock.
     *
     * @return bool True if the lock was acquired, false if already locked.
     */
    private function acquire_update_lock() {
        if ( get_transient( 'wp_puller_update_lock' ) ) {
            return false;
        }
        // 5-minute TTL as a safety net in case the process dies unexpectedly.
        set_transient( 'wp_puller_update_lock', 1, 5 * MINUTE_IN_SECONDS );
        return true;
    }

    /**
     * Release the update lock.
     */
    private function release_update_lock() {
        delete_transient( 'wp_puller_update_lock' );
    }

    /**
     * Internal update implementation for one package.
     *
     * @param array  $pkg    Package configuration (modified in place on success).
     * @param string $source Update source.
     * @return bool|WP_Error
     */
    private function do_update( &$pkg, $source ) {
        $repo_url     = $pkg['repo_url'];
        $branch       = $pkg['branch'];
        $package_type = $pkg['package_type'];

        if ( empty( $repo_url ) ) {
            $error = new WP_Error(
                'no_repo',
                __( 'No GitHub repository configured.', 'wp-puller' )
            );
            $this->logger->log_update_error( $error->get_error_message(), $source );
            return $error;
        }

        $parsed = $this->github_api->parse_repo_url( $repo_url );

        if ( ! $parsed ) {
            $error = new WP_Error(
                'invalid_repo',
                __( 'Invalid GitHub repository URL.', 'wp-puller' )
            );
            $this->logger->log_update_error( $error->get_error_message(), $source );
            return $error;
        }

        // Deploy the pinned commit if one is set, otherwise the branch head.
        $ref = ! empty( $pkg['commit'] ) ? $pkg['commit'] : $branch;

        $latest_commit = $this->github_api->get_latest_commit( $parsed['owner'], $parsed['repo'], $ref );

        if ( is_wp_error( $latest_commit ) ) {
            $this->logger->log_update_error( $latest_commit->get_error_message(), $source );
            return $latest_commit;
        }

        $backup_path = $this->backup->create_backup( $pkg );

        if ( is_wp_error( $backup_path ) ) {
            $this->logger->log_update_error( $backup_path->get_error_message(), $source );
            return $backup_path;
        }

        if ( ! empty( $backup_path ) ) {
            $this->logger->log_backup_created( $backup_path );
        }

        $zip_file = $this->github_api->download_archive( $parsed['owner'], $parsed['repo'], $ref );

        if ( is_wp_error( $zip_file ) ) {
            $this->logger->log_update_error( $zip_file->get_error_message(), $source );
            return $zip_file;
        }

        $detected = null;

        if ( 'plugin' === $package_type ) {
            $result = $this->install_plugin( $zip_file, $parsed['repo'], $branch, $pkg );
        } else {
            $result = $this->install_theme( $zip_file, $parsed['repo'], $branch, $pkg );
        }

        unlink( $zip_file );

        if ( is_wp_error( $result ) ) {
            $this->logger->log_update_error( $result->get_error_message(), $source );

            // Attempt to auto-restore the backup created before this update.
            if ( ! empty( $backup_path ) ) {
                $restore = $this->backup->restore_backup( basename( $backup_path ), $pkg );

                if ( is_wp_error( $restore ) ) {
                    $this->logger->log(
                        sprintf(
                            /* translators: %s: error message */
                            __( 'Auto-restore failed after update error: %s', 'wp-puller' ),
                            $restore->get_error_message()
                        ),
                        WP_Puller_Logger::STATUS_ERROR,
                        WP_Puller_Logger::SOURCE_SYSTEM
                    );
                } else {
                    $this->logger->log(
                        __( 'Package auto-restored from backup after failed update.', 'wp-puller' ),
                        WP_Puller_Logger::STATUS_INFO,
                        WP_Puller_Logger::SOURCE_SYSTEM
                    );
                }
            }

            return $result;
        }

        if ( 'plugin' === $package_type && is_array( $result ) ) {
            $detected = $result;
        }

        $pkg['latest_commit'] = $latest_commit['sha'];
        $pkg['last_commit_message'] = isset( $latest_commit['message'] ) ? $latest_commit['message'] : '';
        $pkg['last_check']    = time();

        if ( $detected ) {
            $pkg['plugin_slug'] = $detected['slug'];
            $pkg['package_kind'] = $detected['kind'];
        }

        $this->logger->log_update_success( $latest_commit['short_sha'], $source, array(
            'package_id'    => isset( $pkg['id'] ) ? $pkg['id'] : '',
            'package_type'  => $package_type,
            'commit_sha'    => $latest_commit['sha'],
            'commit_message' => substr( $latest_commit['message'], 0, 100 ),
        ) );

        // Kept for backward compatibility with the theme-only releases.
        do_action( 'wp_puller_theme_updated', $latest_commit, $source );
        do_action( 'wp_puller_updated', $latest_commit, $source, $package_type, $pkg );

        return true;
    }

    /**
     * Check if an update is available for a package.
     *
     * @param array $pkg Package configuration.
     * @return array|WP_Error Array with update info, or WP_Error on failure.
     */
    public function check_for_updates( $pkg ) {
        $repo_url = $pkg['repo_url'];
        $branch   = $pkg['branch'];

        if ( empty( $repo_url ) ) {
            return new WP_Error(
                'no_repo',
                __( 'No GitHub repository configured.', 'wp-puller' )
            );
        }

        $parsed = $this->github_api->parse_repo_url( $repo_url );

        if ( ! $parsed ) {
            return new WP_Error(
                'invalid_repo',
                __( 'Invalid GitHub repository URL.', 'wp-puller' )
            );
        }

        $this->github_api->clear_cache();

        $ref = ! empty( $pkg['commit'] ) ? $pkg['commit'] : $branch;

        $latest_commit = $this->github_api->get_latest_commit( $parsed['owner'], $parsed['repo'], $ref );

        if ( is_wp_error( $latest_commit ) ) {
            return $latest_commit;
        }

        $current_commit = $pkg['latest_commit'];

        $pkg['last_check'] = time();

        return array(
            'update_available' => ! empty( $current_commit ) && $current_commit !== $latest_commit['sha'],
            'current_commit'   => $current_commit,
            'latest_commit'    => $latest_commit,
            'is_new_setup'     => empty( $current_commit ),
        );
    }

    /**
     * Resolve the live location of a package.
     *
     * @param array $pkg Package configuration.
     * @return array {
     *     @type string $kind  'dir' or 'file'.
     *     @type string $path  Absolute path to the package.
     *     @type string $slug  Package slug (directory or file basename).
     * }
     */
    public static function get_package_location( $pkg ) {
        if ( 'plugin' === $pkg['package_type'] ) {
            $slug = $pkg['plugin_slug'];

            if ( ! empty( $slug ) ) {
                $path = WP_PLUGIN_DIR . '/' . ltrim( $slug, '/' );
                $kind = ! empty( $pkg['package_kind'] ) ? $pkg['package_kind'] : 'dir';

                return array(
                    'kind' => $kind,
                    'path' => $path,
                    'slug' => basename( $slug ),
                );
            }

            return array(
                'kind' => 'dir',
                'path' => '',
                'slug' => '',
            );
        }

        $theme = wp_get_theme();
        $dir   = $theme->get_stylesheet_directory();

        return array(
            'kind' => 'dir',
            'path' => $dir,
            'slug' => $theme->get_stylesheet(),
        );
    }

    /**
     * Install theme from ZIP file.
     *
     * @param string $zip_file ZIP file path.
     * @param string $repo     Repository name.
     * @param string $branch   Branch name.
     * @param array  $pkg      Package configuration.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    private function install_theme( $zip_file, $repo, $branch, $pkg ) {
        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $theme     = wp_get_theme();
        $theme_dir = $theme->get_stylesheet_directory();

        $temp_dir = get_temp_dir() . 'wp-puller-' . uniqid();

        $result = unzip_file( $zip_file, $temp_dir );

        if ( is_wp_error( $result ) ) {
            $wp_filesystem->delete( $temp_dir, true );
            return new WP_Error(
                'unzip_failed',
                __( 'Failed to extract theme archive.', 'wp-puller' )
            );
        }

        $extracted_dir = $temp_dir . '/' . $repo . '-' . $branch;

        if ( ! is_dir( $extracted_dir ) ) {
            $dirs = glob( $temp_dir . '/*', GLOB_ONLYDIR );

            if ( ! empty( $dirs ) ) {
                $extracted_dir = $dirs[0];
            } else {
                $wp_filesystem->delete( $temp_dir, true );
                return new WP_Error(
                    'invalid_archive',
                    __( 'Invalid theme archive structure.', 'wp-puller' )
                );
            }
        }

        // Handle theme in subdirectory (repo-relative path).
        $theme_path = $pkg['source_path'];
        if ( ! empty( $theme_path ) ) {
            if ( false !== strpos( $theme_path, '..' ) ) {
                $wp_filesystem->delete( $temp_dir, true );
                return new WP_Error(
                    'invalid_path',
                    __( 'Invalid theme path.', 'wp-puller' )
                );
            }

            $extracted_dir = $extracted_dir . '/' . $theme_path;

            if ( ! is_dir( $extracted_dir ) ) {
                $wp_filesystem->delete( $temp_dir, true );
                return new WP_Error(
                    'path_not_found',
                    sprintf(
                        /* translators: %s: theme path */
                        __( 'Theme path "%s" not found in repository.', 'wp-puller' ),
                        $theme_path
                    )
                );
            }
        }

        $style_css = $extracted_dir . '/style.css';

        if ( ! file_exists( $style_css ) ) {
            $wp_filesystem->delete( $temp_dir, true );

            $hint = '';
            if ( empty( $theme_path ) ) {
                $subdirs = glob( $extracted_dir . '/*', GLOB_ONLYDIR );
                foreach ( $subdirs as $subdir ) {
                    if ( file_exists( $subdir . '/style.css' ) ) {
                        $hint = sprintf(
                            /* translators: %s: directory name */
                            __( ' Found theme in "%s" - set this as Theme Path in settings.', 'wp-puller' ),
                            basename( $subdir )
                        );
                        break;
                    }
                }
            }

            return new WP_Error(
                'not_a_theme',
                __( 'The repository does not contain a valid WordPress theme (missing style.css).', 'wp-puller' ) . $hint
            );
        }

        $theme_data = get_file_data( $style_css, array( 'Name' => 'Theme Name' ) );

        if ( empty( $theme_data['Name'] ) ) {
            $wp_filesystem->delete( $temp_dir, true );
            return new WP_Error(
                'invalid_theme',
                __( 'The style.css file does not contain a valid Theme Name header.', 'wp-puller' )
            );
        }

        $this->clear_theme_directory( $theme_dir );

        $copy_result = copy_dir( $extracted_dir, $theme_dir );

        $wp_filesystem->delete( $temp_dir, true );

        if ( is_wp_error( $copy_result ) ) {
            return new WP_Error(
                'copy_failed',
                __( 'Failed to copy theme files.', 'wp-puller' )
            );
        }

        $this->clear_theme_cache();

        return true;
    }

    /**
     * Install plugin from ZIP file.
     *
     * Detects the plugin inside the archive, optionally deactivates it while
     * files are replaced, then re-activates it if it was active. Returns the
     * detected slug/kind so the package can be persisted.
     *
     * @param string $zip_file ZIP file path.
     * @param string $repo     Repository name.
     * @param string $branch   Branch name.
     * @param array  $pkg      Package configuration.
     * @return array|WP_Error Detected slug/kind on success, WP_Error on failure.
     */
    private function install_plugin( $zip_file, $repo, $branch, $pkg ) {
        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $temp_dir = get_temp_dir() . 'wp-puller-' . uniqid();

        $result = unzip_file( $zip_file, $temp_dir );

        if ( is_wp_error( $result ) ) {
            $wp_filesystem->delete( $temp_dir, true );
            return new WP_Error(
                'unzip_failed',
                __( 'Failed to extract plugin archive.', 'wp-puller' )
            );
        }

        $extracted_dir = $temp_dir . '/' . $repo . '-' . $branch;

        if ( ! is_dir( $extracted_dir ) ) {
            $dirs = glob( $temp_dir . '/*', GLOB_ONLYDIR );

            if ( ! empty( $dirs ) ) {
                $extracted_dir = $dirs[0];
            } else {
                $wp_filesystem->delete( $temp_dir, true );
                return new WP_Error(
                    'invalid_archive',
                    __( 'Invalid plugin archive structure.', 'wp-puller' )
                );
            }
        }

        // Handle plugin in subdirectory (repo-relative path).
        $plugin_path = $pkg['source_path'];
        if ( ! empty( $plugin_path ) ) {
            if ( false !== strpos( $plugin_path, '..' ) ) {
                $wp_filesystem->delete( $temp_dir, true );
                return new WP_Error(
                    'invalid_path',
                    __( 'Invalid plugin path.', 'wp-puller' )
                );
            }

            $extracted_dir = $extracted_dir . '/' . $plugin_path;

            if ( ! is_dir( $extracted_dir ) ) {
                $wp_filesystem->delete( $temp_dir, true );
                return new WP_Error(
                    'path_not_found',
                    sprintf(
                        /* translators: %s: plugin path */
                        __( 'Plugin path "%s" not found in repository.', 'wp-puller' ),
                        $plugin_path
                    )
                );
            }
        }

        $plugin = $this->find_plugin( $extracted_dir, $branch );

        if ( ! $plugin ) {
            $wp_filesystem->delete( $temp_dir, true );
            return new WP_Error(
                'not_a_plugin',
                __( 'The repository does not contain a valid WordPress plugin (missing Plugin Name header).', 'wp-puller' )
            );
        }

        // Always target the package's stored slug so updates replace the same
        // directory instead of creating a duplicate on every update.
        $target_slug = ! empty( $pkg['plugin_slug'] )
            ? ltrim( $pkg['plugin_slug'], '/' )
            : $plugin['slug'];

        $relative = ( 'file' === $plugin['kind'] )
            ? $target_slug
            : $target_slug . '/' . basename( $plugin['file'] );

        $self_plugin = plugin_basename( WP_PULLER_PLUGIN_FILE );

        // Deactivate the plugin while swapping files so WordPress does not try
        // to load a half-written plugin. Never deactivate WP Puller itself.
        $was_active = is_plugin_active( $relative ) && $relative !== $self_plugin;

        if ( $was_active ) {
            deactivate_plugins( $relative, true );
        }

        $target = WP_PLUGIN_DIR . '/' . $target_slug;

        $this->clear_plugin_target( $target, $plugin['kind'] );

        if ( 'file' === $plugin['kind'] ) {
            if ( ! $wp_filesystem->copy( $plugin['file'], $target ) ) {
                if ( $was_active ) {
                    activate_plugin( $plugin['relative'], '', false, true );
                }
                $wp_filesystem->delete( $temp_dir, true );
                return new WP_Error(
                    'copy_failed',
                    __( 'Failed to copy plugin file.', 'wp-puller' )
                );
            }
        } else {
            $copy_result = copy_dir( $plugin['dir'], $target );

            if ( is_wp_error( $copy_result ) ) {
                if ( $was_active ) {
                    activate_plugin( $plugin['relative'], '', false, true );
                }
                $wp_filesystem->delete( $temp_dir, true );
                return new WP_Error(
                    'copy_failed',
                    __( 'Failed to copy plugin files.', 'wp-puller' )
                );
            }
        }

        // Verify the main plugin file was actually installed at the target.
        $main_file = ( 'file' === $plugin['kind'] )
            ? $target
            : $target . '/' . basename( $plugin['file'] );

        if ( ! is_file( $main_file ) ) {
            if ( $was_active ) {
                activate_plugin( $plugin['relative'], '', false, true );
            }
            $wp_filesystem->delete( $temp_dir, true );
            return new WP_Error(
                'install_incomplete',
                __( 'The plugin files were not installed correctly.', 'wp-puller' )
            );
        }

        // Re-activate the plugin if it was active before the update.
        if ( $was_active ) {
            $activated = activate_plugin( $relative, '', false, true );

            if ( is_wp_error( $activated ) ) {
                $this->logger->log(
                    sprintf(
                        /* translators: %s: error message */
                        __( 'Plugin re-activation failed after update: %s', 'wp-puller' ),
                        $activated->get_error_message()
                    ),
                    WP_Puller_Logger::STATUS_ERROR,
                    WP_Puller_Logger::SOURCE_SYSTEM
                );
            }
        }

        $wp_filesystem->delete( $temp_dir, true );

        $this->clear_plugin_cache();

        return array(
            'slug' => $target_slug,
            'kind' => $plugin['kind'],
        );
    }

    /**
     * Find a plugin inside an extracted directory.
     *
     * Detection order (most robust first):
     *   1. Directory plugin at the root of $dir (the repo root IS the plugin).
     *   2. Directory plugin inside a sub-directory.
     *   3. Single-file plugin at the root (last resort).
     *
     * Preferring directory-style detection avoids copying only the main file
     * and dropping the rest of the plugin (which breaks activation).
     *
     * @param string $dir    Extracted directory.
     * @param string $branch Branch name (used to tidy the derived slug).
     * @return array|false Plugin descriptor or false if not found.
     */
    private function find_plugin( $dir, $branch = '' ) {
        // 1) Directory plugin at the root of $dir.
        $root_main = $this->plugin_main_file_in_dir( $dir );

        if ( $root_main ) {
            $slug = basename( $dir );

            if ( $branch && preg_match( '/-' . preg_quote( $branch, '/' ) . '$/', $slug ) ) {
                $slug = substr( $slug, 0, -strlen( '-' . $branch ) );
            }

            if ( '' === $slug ) {
                $slug = 'plugin';
            }

            return array(
                'kind'     => 'dir',
                'file'     => $root_main,
                'dir'      => $dir,
                'slug'     => $slug,
                'relative' => $slug . '/' . basename( $root_main ),
                'name'     => $this->plugin_name( $root_main ),
                'version'  => $this->plugin_version( $root_main ),
            );
        }

        // 2) Directory plugin inside a sub-directory.
        foreach ( glob( $dir . '/*', GLOB_ONLYDIR ) as $sub ) {
            $sub_main = $this->plugin_main_file_in_dir( $sub );

            if ( $sub_main ) {
                return array(
                    'kind'     => 'dir',
                    'file'     => $sub_main,
                    'dir'      => $sub,
                    'slug'     => basename( $sub ),
                    'relative' => basename( $sub ) . '/' . basename( $sub_main ),
                    'name'     => $this->plugin_name( $sub_main ),
                    'version'  => $this->plugin_version( $sub_main ),
                );
            }
        }

        // 3) Single-file plugin at the root (last resort).
        foreach ( glob( $dir . '/*.php' ) as $file ) {
            $name = $this->plugin_name( $file );

            if ( ! empty( $name ) ) {
                return array(
                    'kind'     => 'file',
                    'file'     => $file,
                    'slug'     => basename( $file ),
                    'relative' => basename( $file ),
                    'name'     => $name,
                    'version'  => $this->plugin_version( $file ),
                );
            }
        }

        return false;
    }

    /**
     * Find the main plugin file (with a Plugin Name header) inside a directory.
     *
     * @param string $dir Directory to scan.
     * @return string|false Path to the main file, or false.
     */
    private function plugin_main_file_in_dir( $dir ) {
        foreach ( glob( $dir . '/*.php' ) as $file ) {
            if ( ! empty( $this->plugin_name( $file ) ) ) {
                return $file;
            }
        }

        return false;
    }

    /**
     * Get a plugin's name from its header.
     *
     * @param string $file Plugin file path.
     * @return string
     */
    private function plugin_name( $file ) {
        $data = get_file_data( $file, array( 'Name' => 'Plugin Name' ) );
        return $data['Name'];
    }

    /**
     * Get a plugin's version from its header.
     *
     * @param string $file Plugin file path.
     * @return string
     */
    private function plugin_version( $file ) {
        $data = get_file_data( $file, array( 'Version' => 'Version' ) );
        return $data['Version'];
    }

    /**
     * Clear the target of a plugin before copying new files.
     *
     * @param string $target Absolute path to the plugin dir or file.
     * @param string $kind   'dir' or 'file'.
     */
    private function clear_plugin_target( $target, $kind ) {
        global $wp_filesystem;

        if ( 'file' === $kind ) {
            if ( file_exists( $target ) ) {
                $wp_filesystem->delete( $target );
            }
            return;
        }

        // For a directory plugin, remove the entire target so a previous
        // (possibly nested/duplicate) copy cannot linger and cause fatal
        // "Cannot redeclare class" errors on activation.
        if ( is_dir( $target ) ) {
            $wp_filesystem->delete( $target, true );
        }
    }

    /**
     * Clear theme directory contents.
     *
     * @param string $dir Directory path.
     */
    private function clear_theme_directory( $dir ) {
        global $wp_filesystem;

        if ( ! is_dir( $dir ) ) {
            return;
        }

        $files = array_diff( scandir( $dir ), array( '.', '..' ) );

        foreach ( $files as $file ) {
            $path = $dir . '/' . $file;

            if ( is_dir( $path ) ) {
                $wp_filesystem->delete( $path, true );
            } else {
                $wp_filesystem->delete( $path );
            }
        }
    }

    /**
     * Clear theme-related caches.
     */
    private function clear_theme_cache() {
        wp_clean_themes_cache();

        delete_transient( 'dirsize_cache' );

        if ( function_exists( 'opcache_reset' ) ) {
            @opcache_reset();
        }

        do_action( 'wp_puller_cache_cleared' );
    }

    /**
     * Clear plugin-related caches.
     */
    private function clear_plugin_cache() {
        if ( function_exists( 'wp_clean_plugins_cache' ) ) {
            wp_clean_plugins_cache();
        }

        delete_transient( 'dirsize_cache' );

        if ( function_exists( 'opcache_reset' ) ) {
            @opcache_reset();
        }

        do_action( 'wp_puller_cache_cleared' );
    }

    /**
     * Get info about a package's currently installed files.
     *
     * @param array $pkg Package configuration.
     * @return array
     */
    public function get_current_info( $pkg ) {
        if ( 'plugin' === $pkg['package_type'] ) {
            $slug = $pkg['plugin_slug'];

            if ( empty( $slug ) ) {
                return array(
                    'name'      => __( 'No plugin selected', 'wp-puller' ),
                    'version'   => '',
                    'author'    => '',
                    'slug'      => '',
                    'directory' => '',
                    'active'    => false,
                );
            }

            $base = WP_PLUGIN_DIR . '/' . ltrim( $slug, '/' );

            if ( is_dir( $base ) ) {
                $main = $base . '/' . basename( $slug ) . '.php';
                if ( ! file_exists( $main ) ) {
                    foreach ( glob( $base . '/*.php' ) as $candidate ) {
                        if ( ! empty( get_file_data( $candidate, array( 'Name' => 'Plugin Name' ) )['Name'] ) ) {
                            $main = $candidate;
                            break;
                        }
                    }
                }
                $file = $main;
            } else {
                $file = $base;
            }

            $data = get_plugin_data( $file, false, false );

            return array(
                'name'      => $data['Name'],
                'version'   => $data['Version'],
                'author'    => $data['Author'],
                'slug'      => $slug,
                'directory' => dirname( $file ),
                'active'    => is_plugin_active( plugin_basename( $file ) ),
            );
        }

        $theme = wp_get_theme();

        return array(
            'name'      => $theme->get( 'Name' ),
            'version'   => $theme->get( 'Version' ),
            'author'    => $theme->get( 'Author' ),
            'slug'      => $theme->get_stylesheet(),
            'directory' => $theme->get_stylesheet_directory(),
            'active'    => true,
        );
    }

    /**
     * Get update status for a package.
     *
     * @param array $pkg Package configuration.
     * @return array
     */
    public function get_package_status( $pkg ) {
        $repo_url = $pkg['repo_url'];
        $branch   = $pkg['branch'];
        $parsed   = $this->github_api->parse_repo_url( $repo_url );

        return array(
            'id'            => $pkg['id'],
            'is_configured' => ! empty( $repo_url ) && false !== $parsed,
            'repo_url'      => $repo_url,
            'branch'        => $branch,
            'package_type'  => $pkg['package_type'],
            'source_path'   => $pkg['source_path'],
            'plugin_slug'   => $pkg['plugin_slug'],
            'current_commit' => $pkg['latest_commit'],
            'short_commit'  => ! empty( $pkg['latest_commit'] ) ? substr( $pkg['latest_commit'], 0, 7 ) : '',
            'commit_message' => isset( $pkg['last_commit_message'] ) ? $pkg['last_commit_message'] : '',
            'pinned_commit' => isset( $pkg['commit'] ) ? $pkg['commit'] : '',
            'last_check'    => $pkg['last_check'],
            'auto_update'   => $pkg['auto_update'],
            'repo_owner'    => $parsed ? $parsed['owner'] : '',
            'repo_name'     => $parsed ? $parsed['repo'] : '',
        );
    }
}
