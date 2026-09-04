<?php
/**
 * Backup class for WP Puller.
 *
 * @package WP_Puller
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WP_Puller_Backup Class.
 */
class WP_Puller_Backup {

    /**
     * Backup directory base name.
     *
     * @var string
     */
    const BACKUP_DIR = 'wp-puller-backups';

    /**
     * Get the backup directory path.
     *
     * The directory name includes a random suffix stored in the database so it
     * is not guessable on servers (e.g. Nginx) where .htaccess has no effect.
     *
     * @return string
     */
    public function get_backup_dir() {
        $suffix = get_option( 'wp_puller_backup_dir_suffix', '' );

        if ( empty( $suffix ) ) {
            $suffix = wp_generate_password( 16, false );
            update_option( 'wp_puller_backup_dir_suffix', $suffix, false );
        }

        return WP_CONTENT_DIR . '/' . self::BACKUP_DIR . '-' . $suffix;
    }

    /**
     * Ensure backup directory exists and is protected.
     *
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function ensure_backup_dir() {
        $backup_dir = $this->get_backup_dir();

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( ! $wp_filesystem->is_dir( $backup_dir ) ) {
            if ( ! $wp_filesystem->mkdir( $backup_dir, 0755 ) ) {
                return new WP_Error(
                    'mkdir_failed',
                    __( 'Failed to create backup directory.', 'wp-puller' )
                );
            }
        }

        $htaccess = $backup_dir . '/.htaccess';
        if ( ! $wp_filesystem->exists( $htaccess ) ) {
            $wp_filesystem->put_contents( $htaccess, "Deny from all\n" );
        }

        $index = $backup_dir . '/index.php';
        if ( ! $wp_filesystem->exists( $index ) ) {
            $wp_filesystem->put_contents( $index, "<?php\n// Silence is golden.\n" );
        }

        return true;
    }

    /**
     * Create a backup of a package (theme or plugin).
     *
     * Returns an empty string (not an error) when there is no installed package
     * to back up yet (e.g. the very first deploy into an empty target).
     *
     * @param array $pkg Package configuration.
     * @return string|WP_Error Backup directory path on success, WP_Error on failure.
     */
    public function create_backup( $pkg ) {
        $result = $this->ensure_backup_dir();

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $location = WP_Puller_Updater::get_package_location( $pkg );

        if ( empty( $location['path'] ) || ( ! is_dir( $location['path'] ) && ! is_file( $location['path'] ) ) ) {
            // Nothing installed to back up yet — not an error.
            return '';
        }

        $slug = $location['slug'];

        $timestamp   = gmdate( 'Y-m-d_H-i-s' );
        $backup_name = $slug . '_' . $timestamp;
        $backup_path = $this->get_backup_dir() . '/' . $backup_name;

        global $wp_filesystem;

        if ( 'file' === $location['kind'] ) {
            // Single-file package: store the file inside a backup directory.
            if ( ! $wp_filesystem->is_dir( $backup_path ) ) {
                $wp_filesystem->mkdir( $backup_path, 0755 );
            }

            if ( ! $wp_filesystem->copy( $location['path'], $backup_path . '/' . basename( $location['path'] ) ) ) {
                return new WP_Error(
                    'backup_failed',
                    __( 'Failed to create package backup.', 'wp-puller' )
                );
            }
        } else {
            if ( ! $this->recursive_copy( $location['path'], $backup_path ) ) {
                return new WP_Error(
                    'backup_failed',
                    __( 'Failed to create package backup.', 'wp-puller' )
                );
            }
        }

        $this->cleanup_old_backups( $slug );

        return $backup_path;
    }

    /**
     * Restore a package (theme or plugin) from a backup.
     *
     * @param string $backup_name Backup directory name.
     * @param array  $pkg         Package configuration.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function restore_backup( $backup_name, $pkg = null ) {
        $backup_path = $this->get_backup_dir() . '/' . sanitize_file_name( $backup_name );

        if ( ! is_dir( $backup_path ) ) {
            return new WP_Error(
                'backup_not_found',
                __( 'Backup not found.', 'wp-puller' )
            );
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $location = WP_Puller_Updater::get_package_location( $pkg );

        // Restoring requires knowing which package to target. Themes always
        // resolve to the active theme; plugins rely on the saved slug.
        if ( empty( $location['slug'] ) ) {
            return new WP_Error(
                'backup_target_unknown',
                __( 'Cannot determine which package to restore. Open WP Puller settings, save the package configuration, then try again.', 'wp-puller' )
            );
        }

        // If the live package was uninstalled, rebuild its path from the slug.
        // (The active theme always exists, so this only affects plugins.)
        if ( empty( $location['path'] ) || ( ! is_dir( $location['path'] ) && ! is_file( $location['path'] ) ) ) {
            $location['path'] = WP_PLUGIN_DIR . '/' . ltrim( $location['slug'], '/' );
        }

        $parent = ( 'file' === $location['kind'] ) ? WP_PLUGIN_DIR : dirname( $location['path'] );
        $suffix = wp_generate_password( 8, false );

        // Staging/old dirs live next to the package (same filesystem, so the
        // swap below is a fast rename) and are dot-prefixed so WordPress's
        // scanner ignores them while they briefly exist.
        $staging = $parent . '/.wp-puller-restore-' . $suffix;
        $old_dir = $parent . '/.wp-puller-old-' . $suffix;

        // 1. Build the restored copy in staging first. If anything fails here,
        //    the live package has not been touched.
        if ( ! $this->recursive_copy( $backup_path, $staging ) ) {
            $this->recursive_delete( $staging );
            return new WP_Error(
                'restore_failed',
                __( 'Failed to stage backup for restore.', 'wp-puller' )
            );
        }

        // 2. Move the current package aside (kept for rollback).
        if ( ( is_dir( $location['path'] ) || is_file( $location['path'] ) )
            && ! $wp_filesystem->move( $location['path'], $old_dir ) ) {
            $this->recursive_delete( $staging );
            return new WP_Error(
                'restore_failed',
                __( 'Failed to set the current package aside for restore.', 'wp-puller' )
            );
        }

        if ( 'file' === $location['kind'] ) {
            // Single-file package: copy the staged file into place.
            $staged_file = $staging . '/' . basename( $location['path'] );

            if ( ! is_file( $staged_file ) || ! $wp_filesystem->copy( $staged_file, $location['path'] ) ) {
                if ( is_dir( $old_dir ) || is_file( $old_dir ) ) {
                    $wp_filesystem->move( $old_dir, $location['path'] );
                }
                $this->recursive_delete( $staging );
                return new WP_Error(
                    'restore_failed',
                    __( 'Failed to restore the package file; the original was kept.', 'wp-puller' )
                );
            }

            $this->recursive_delete( $staging );
            $this->recursive_delete( $old_dir );
        } else {
            // 3. Move staging into place. On failure, roll the original back.
            if ( ! $wp_filesystem->move( $staging, $location['path'] ) ) {
                if ( is_dir( $old_dir ) || is_file( $old_dir ) ) {
                    $wp_filesystem->move( $old_dir, $location['path'] );
                }
                $this->recursive_delete( $staging );
                return new WP_Error(
                    'restore_failed',
                    __( 'Failed to activate the restored package; the original was kept.', 'wp-puller' )
                );
            }

            // 4. Success: discard the old copy.
            $this->recursive_delete( $old_dir );
        }

        return true;
    }

    /**
     * Get list of available backups for a theme.
     *
     * @param string $theme_slug Optional. Theme slug to filter by.
     * @return array
     */
    public function get_backups( $theme_slug = '' ) {
        $backup_dir = $this->get_backup_dir();

        if ( ! is_dir( $backup_dir ) ) {
            return array();
        }

        $backups = array();
        $dirs    = glob( $backup_dir . '/*', GLOB_ONLYDIR );

        if ( ! $dirs ) {
            return array();
        }

        foreach ( $dirs as $dir ) {
            $name = basename( $dir );

            if ( ! empty( $theme_slug ) && strpos( $name, $theme_slug . '_' ) !== 0 ) {
                continue;
            }

            $timestamp = filemtime( $dir );

            $backups[] = array(
                'name'      => $name,
                'path'      => $dir,
                'timestamp' => $timestamp,
                'datetime'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
                'size'      => $this->get_directory_size( $dir ),
            );
        }

        usort( $backups, function( $a, $b ) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $backups;
    }

    /**
     * Delete a backup.
     *
     * @param string $backup_name Backup directory name.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_backup( $backup_name ) {
        $backup_path = $this->get_backup_dir() . '/' . sanitize_file_name( $backup_name );

        if ( ! is_dir( $backup_path ) ) {
            return new WP_Error(
                'backup_not_found',
                __( 'Backup not found.', 'wp-puller' )
            );
        }

        if ( ! $this->recursive_delete( $backup_path ) ) {
            return new WP_Error(
                'delete_failed',
                __( 'Failed to delete backup.', 'wp-puller' )
            );
        }

        return true;
    }

    /**
     * Cleanup old backups, keeping only the most recent ones.
     *
     * @param string $theme_slug Theme slug.
     */
    private function cleanup_old_backups( $theme_slug ) {
        $max_backups = absint( get_option( 'wp_puller_backup_count', 3 ) );
        $backups     = $this->get_backups( $theme_slug );

        if ( count( $backups ) <= $max_backups ) {
            return;
        }

        $to_delete = array_slice( $backups, $max_backups );

        foreach ( $to_delete as $backup ) {
            $this->recursive_delete( $backup['path'] );
        }
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $source      Source directory.
     * @param string $destination Destination directory.
     * @return bool
     */
    private function recursive_copy( $source, $destination ) {
        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( ! is_dir( $source ) ) {
            return false;
        }

        if ( ! $wp_filesystem->is_dir( $destination ) ) {
            $wp_filesystem->mkdir( $destination, 0755 );
        }

        $dir = opendir( $source );

        if ( ! $dir ) {
            return false;
        }

        while ( false !== ( $file = readdir( $dir ) ) ) {
            if ( '.' === $file || '..' === $file ) {
                continue;
            }

            $src_path  = $source . '/' . $file;
            $dest_path = $destination . '/' . $file;

            if ( is_dir( $src_path ) ) {
                if ( ! $this->recursive_copy( $src_path, $dest_path ) ) {
                    closedir( $dir );
                    return false;
                }
            } else {
                if ( ! $wp_filesystem->copy( $src_path, $dest_path ) ) {
                    closedir( $dir );
                    return false;
                }
            }
        }

        closedir( $dir );

        return true;
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $path Directory path.
     * @return bool
     */
    private function recursive_delete( $path ) {
        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( ! is_dir( $path ) ) {
            return $wp_filesystem->delete( $path );
        }

        $dir = opendir( $path );

        if ( ! $dir ) {
            return false;
        }

        while ( false !== ( $file = readdir( $dir ) ) ) {
            if ( '.' === $file || '..' === $file ) {
                continue;
            }

            $full_path = $path . '/' . $file;

            if ( is_dir( $full_path ) ) {
                $this->recursive_delete( $full_path );
            } else {
                $wp_filesystem->delete( $full_path );
            }
        }

        closedir( $dir );

        return $wp_filesystem->rmdir( $path );
    }

    /**
     * Get total size of a directory.
     *
     * @param string $path Directory path.
     * @return int Size in bytes.
     */
    private function get_directory_size( $path ) {
        $size = 0;

        if ( ! is_dir( $path ) ) {
            return $size;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Format bytes to human readable string.
     *
     * @param int $bytes    Size in bytes.
     * @param int $decimals Number of decimal places.
     * @return string
     */
    public static function format_size( $bytes, $decimals = 2 ) {
        if ( $bytes < 1024 ) {
            return $bytes . ' B';
        }

        $units = array( 'B', 'KB', 'MB', 'GB' );
        $bytes = (float) $bytes;

        for ( $i = 0; $bytes >= 1024 && $i < count( $units ) - 1; $i++ ) {
            $bytes /= 1024;
        }

        return round( $bytes, $decimals ) . ' ' . $units[ $i ];
    }
}
