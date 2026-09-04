<?php
/**
 * Admin page template.
 *
 * @package WP_Puller
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$packages      = $package_views; // array of views built in render_admin_page()
$webhook_info  = $webhook_info;
$logs          = $logs;
$global_pat    = $global_pat;
$global_status = $global_status;
$backup_count  = $backup_count;
?>

<div class="wrap wp-puller-wrap">
    <h1 class="wp-puller-title">
        <span class="dashicons dashicons-update"></span>
        <?php esc_html_e( 'WP Puller', 'wp-puller' ); ?>
        <span class="wp-puller-version">v<?php echo esc_html( WP_PULLER_VERSION ); ?></span>
    </h1>

    <div class="wp-puller-notice" id="wp-puller-notice" style="display: none;"></div>

    <?php if ( ! empty( $flash ) ) : ?>
        <div class="wp-puller-notice notice-<?php echo esc_attr( $flash['type'] ); ?>" style="display:block;"><?php echo esc_html( $flash['message'] ); ?></div>
    <?php elseif ( ! empty( $_GET['wp_puller_msg'] ) ) : ?>
        <div class="wp-puller-notice notice-success" style="display:block;"><?php echo esc_html( wp_unslash( $_GET['wp_puller_msg'] ) ); ?></div>
    <?php elseif ( ! empty( $_GET['wp_puller_err'] ) ) : ?>
        <div class="wp-puller-notice notice-error" style="display:block;"><?php echo esc_html( wp_unslash( $_GET['wp_puller_err'] ) ); ?></div>
    <?php endif; ?>

    <div class="wp-puller-grid">

        <!-- Global Settings Card -->
        <div class="wp-puller-card wp-puller-card-global">
            <div class="wp-puller-card-header">
                <h2><?php esc_html_e( 'Global Settings', 'wp-puller' ); ?></h2>
            </div>
            <div class="wp-puller-card-body">
                <form id="wp-puller-global-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="wp_puller_save_global">
                    <?php wp_nonce_field( 'wp_puller_save_global', 'wp_puller_global_nonce' ); ?>
                    <div class="wp-puller-field">
                        <label for="wp-puller-global-pat"><?php esc_html_e( 'GitHub Token (shared)', 'wp-puller' ); ?></label>
                        <input type="password" id="wp-puller-global-pat" name="global_pat" value="" placeholder="<?php esc_attr_e( 'ghp_xxxxx or github_pat_xxxxx', 'wp-puller' ); ?>" class="regular-text" autocomplete="off">
                        <p class="description"><?php echo $global_status['stored'] ? esc_html__( 'A token is already saved (encrypted). Enter a new one only to replace it; leave empty to keep the current token.', 'wp-puller' ) : esc_html__( 'Required for private repositories. Enter a fine-grained token (Contents + Metadata read) or a classic token (repo scope).', 'wp-puller' ); ?></p>
                        <p class="description"><?php esc_html_e( 'Used by every package unless a package overrides it. Required for private repositories.', 'wp-puller' ); ?></p>
                        <?php if ( $global_status['stored'] ) : ?>
                            <p class="description" style="margin-top: 4px;">
                                <strong><?php esc_html_e( 'Token Status:', 'wp-puller' ); ?></strong>
                                <?php if ( $global_status['decrypts'] ) : ?>
                                    <span style="color: #00a32a;"><?php echo esc_html( $global_status['message'] ); ?></span>
                                <?php else : ?>
                                    <span style="color: #d63638;"><?php echo esc_html( $global_status['message'] ); ?></span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="wp-puller-field">
                        <label for="wp-puller-backup-count"><?php esc_html_e( 'Backups to Keep', 'wp-puller' ); ?></label>
                        <select id="wp-puller-backup-count" name="backup_count">
                            <?php for ( $i = 1; $i <= 10; $i++ ) : ?>
                                <option value="<?php echo esc_attr( $i ); ?>" <?php selected( $backup_count, $i ); ?>><?php echo esc_html( $i ); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="wp-puller-field-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Global Settings', 'wp-puller' ); ?></button>
                    </div>
                </form>

                <hr>

                <h3><?php esc_html_e( 'Webhook (shared)', 'wp-puller' ); ?></h3>
                <p class="wp-puller-webhook-intro"><?php esc_html_e( 'One webhook handles every package. Point all your repositories at this URL and secret.', 'wp-puller' ); ?></p>
                <div class="wp-puller-webhook-field">
                    <label><?php esc_html_e( 'Payload URL', 'wp-puller' ); ?></label>
                    <div class="wp-puller-copy-field">
                        <input type="text" readonly value="<?php echo esc_attr( $webhook_info['url'] ); ?>" id="webhook-url">
                        <button type="button" class="button wp-puller-copy-btn" data-copy="webhook-url"><span class="dashicons dashicons-clipboard"></span></button>
                    </div>
                </div>
                <div class="wp-puller-webhook-field">
                    <label><?php esc_html_e( 'Secret', 'wp-puller' ); ?></label>
                    <div class="wp-puller-copy-field">
                        <input type="password" readonly value="<?php echo esc_attr( $webhook_info['secret'] ); ?>" id="webhook-secret">
                        <button type="button" class="button wp-puller-copy-btn" data-copy="webhook-secret"><span class="dashicons dashicons-clipboard"></span></button>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="wp_puller_regenerate_secret">
                            <?php wp_nonce_field( 'wp_puller_regenerate_secret', 'wp_puller_regen_nonce' ); ?>
                            <button type="submit" class="button" title="<?php esc_attr_e( 'Regenerate Secret', 'wp-puller' ); ?>"><span class="dashicons dashicons-update"></span></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Packages Card -->
        <div class="wp-puller-card wp-puller-card-packages">
            <div class="wp-puller-card-header">
                <h2><?php esc_html_e( 'Packages', 'wp-puller' ); ?></h2>
                <a href="<?php echo esc_url( add_query_arg( 'wp_puller_add', '1' ) ); ?>" class="button" id="wp-puller-add-package"><?php esc_html_e( 'Add Package', 'wp-puller' ); ?></a>
            </div>
            <div class="wp-puller-card-body">
                <div id="wp-puller-package-panel" style="display:none;"></div>

                <?php if ( empty( $packages ) ) : ?>
                    <p class="wp-puller-empty"><?php esc_html_e( 'No packages yet. Click "Add Package" to connect a repository.', 'wp-puller' ); ?></p>
                <?php else : ?>
                    <ul class="wp-puller-package-list" id="wp-puller-package-list">
                        <?php foreach ( $packages as $view ) : ?>
                            <?php
                                $pkg    = $view['pkg'];
                                $status = $view['status'];
                                $info   = $view['info'];
                                $pbacks = $view['backups'];
                            ?>
                            <li class="wp-puller-package-item" data-id="<?php echo esc_attr( $pkg['id'] ); ?>"
                                data-label="<?php echo esc_attr( $pkg['label'] ); ?>"
                                data-repo="<?php echo esc_attr( $pkg['repo_url'] ); ?>"
                                data-branch="<?php echo esc_attr( $pkg['branch'] ); ?>"
                                data-type="<?php echo esc_attr( $pkg['package_type'] ); ?>"
                                data-source="<?php echo esc_attr( $pkg['source_path'] ); ?>"
                                data-slug="<?php echo esc_attr( $pkg['plugin_slug'] ); ?>"
                                data-auto="<?php echo $pkg['auto_update'] ? '1' : '0'; ?>"
                                data-token="<?php echo esc_attr( $pkg['token_mode'] ); ?>"
                                data-webhook="<?php echo esc_attr( $pkg['webhook_mode'] ); ?>">
                                <div class="wp-puller-package-main">
                                    <div class="wp-puller-package-title">
                                        <strong><?php echo esc_html( $pkg['label'] ?: $pkg['repo_url'] ); ?></strong>
                                        <span class="wp-puller-badge wp-puller-badge-<?php echo 'plugin' === $pkg['package_type'] ? 'info' : 'success'; ?>"><?php echo 'plugin' === $pkg['package_type'] ? esc_html__( 'Plugin', 'wp-puller' ) : esc_html__( 'Theme', 'wp-puller' ); ?></span>
                                        <?php if ( ! empty( $status['is_configured'] ) ) : ?>
                                            <span class="wp-puller-badge wp-puller-badge-success"><?php esc_html_e( 'Connected', 'wp-puller' ); ?></span>
                                        <?php else : ?>
                                            <span class="wp-puller-badge wp-puller-badge-warning"><?php esc_html_e( 'Not Configured', 'wp-puller' ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="wp-puller-package-meta">
                                        <?php echo esc_html( $pkg['repo_url'] ); ?>
                                        <?php if ( $pkg['branch'] ) : ?> &middot; <code><?php echo esc_html( $pkg['branch'] ); ?></code><?php endif; ?>
                                        <?php if ( ! empty( $status['pinned_commit'] ) ) : ?> &middot; <span class="wp-puller-badge wp-puller-badge-info"><?php esc_html_e( 'Pinned', 'wp-puller' ); ?> <code><?php echo esc_html( substr( $status['pinned_commit'], 0, 7 ) ); ?></code></span><?php endif; ?>
                                        <?php if ( $status['short_commit'] ) : ?> &middot; <?php esc_html_e( 'Commit', 'wp-puller' ); ?> <code><?php echo esc_html( $status['short_commit'] ); ?></code><?php endif; ?>
                                        <?php if ( ! empty( $status['commit_message'] ) ) : ?> &middot; <span class="wp-puller-commit-msg"><?php echo esc_html( wp_trim_words( $status['commit_message'], 12, '…' ) ); ?></span><?php endif; ?>
                                    </div>
                                    <div class="wp-puller-package-actions">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="wp_puller_check_updates">
                                            <input type="hidden" name="pkg_id" value="<?php echo esc_attr( $pkg['id'] ); ?>">
                                            <?php wp_nonce_field( 'wp_puller_check_updates', 'wp_puller_check_nonce' ); ?>
                                            <button type="submit" class="button"><?php esc_html_e( 'Check', 'wp-puller' ); ?></button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="wp_puller_update_package">
                                            <input type="hidden" name="pkg_id" value="<?php echo esc_attr( $pkg['id'] ); ?>">
                                            <?php wp_nonce_field( 'wp_puller_update_package', 'wp_puller_update_nonce' ); ?>
                                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Update Now', 'wp-puller' ); ?></button>
                                        </form>
                                        <a href="<?php echo esc_url( add_query_arg( 'wp_puller_edit', $pkg['id'] ) ); ?>" class="button"><?php esc_html_e( 'Edit', 'wp-puller' ); ?></a>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="wp_puller_delete_package">
                                            <input type="hidden" name="pkg_id" value="<?php echo esc_attr( $pkg['id'] ); ?>">
                                            <?php wp_nonce_field( 'wp_puller_delete_package', 'wp_puller_delete_nonce' ); ?>
                                            <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this package?', 'wp-puller' ) ); ?>');"><span class="dashicons dashicons-trash"></span></button>
                                        </form>
                                    </div>
                                </div>
                                <div class="wp-puller-package-backups" id="wp-puller-backups-<?php echo esc_attr( $pkg['id'] ); ?>" style="display:block;">
                                    <?php if ( empty( $pbacks ) ) : ?>
                                        <p class="wp-puller-empty"><?php esc_html_e( 'No backups yet.', 'wp-puller' ); ?></p>
                                    <?php else : ?>
                                        <ul class="wp-puller-backup-list">
                                            <?php foreach ( $pbacks as $backup ) : ?>
                                                <li class="wp-puller-backup-item" data-name="<?php echo esc_attr( $backup['name'] ); ?>">
                                                    <div class="wp-puller-backup-info">
                                                        <span class="wp-puller-backup-name"><?php echo esc_html( $backup['name'] ); ?></span>
                                                        <span class="wp-puller-backup-meta"><?php echo esc_html( $backup['datetime'] ); ?> &middot; <?php echo esc_html( WP_Puller_Backup::format_size( $backup['size'] ) ); ?></span>
                                                    </div>
                                                    <div class="wp-puller-backup-actions">
                                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                                            <input type="hidden" name="action" value="wp_puller_restore_backup">
                                                            <input type="hidden" name="pkg_id" value="<?php echo esc_attr( $pkg['id'] ); ?>">
                                                            <input type="hidden" name="backup_name" value="<?php echo esc_attr( $backup['name'] ); ?>">
                                                            <?php wp_nonce_field( 'wp_puller_restore_backup', 'wp_puller_restore_nonce' ); ?>
                                                            <button type="submit" class="button button-small"><?php esc_html_e( 'Restore', 'wp-puller' ); ?></button>
                                                        </form>
                                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                                            <input type="hidden" name="action" value="wp_puller_delete_backup">
                                                            <input type="hidden" name="backup_name" value="<?php echo esc_attr( $backup['name'] ); ?>">
                                                            <?php wp_nonce_field( 'wp_puller_delete_backup', 'wp_puller_delete_backup_nonce' ); ?>
                                                            <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Are you sure?', 'wp-puller' ) ); ?>');"><span class="dashicons dashicons-trash"></span></button>
                                                        </form>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Activity Log Card -->
        <div class="wp-puller-card wp-puller-card-logs">
            <div class="wp-puller-card-header">
                <h2><?php esc_html_e( 'Activity Log', 'wp-puller' ); ?></h2>
                <?php if ( ! empty( $logs ) ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="wp_puller_clear_logs">
                        <?php wp_nonce_field( 'wp_puller_clear_logs', 'wp_puller_clear_logs_nonce' ); ?>
                        <button type="submit" class="button button-small"><?php esc_html_e( 'Clear', 'wp-puller' ); ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="wp-puller-card-body">
                <?php if ( empty( $logs ) ) : ?>
                    <p class="wp-puller-empty"><?php esc_html_e( 'No activity recorded yet.', 'wp-puller' ); ?></p>
                <?php else : ?>
                    <ul class="wp-puller-log-list" id="wp-puller-log-list">
                        <?php foreach ( $logs as $log ) : ?>
                            <li class="wp-puller-log-item wp-puller-log-<?php echo esc_attr( $log['status'] ); ?>">
                                <span class="wp-puller-log-indicator"></span>
                                <div class="wp-puller-log-content">
                                    <span class="wp-puller-log-message"><?php echo esc_html( $log['message'] ); ?></span>
                                    <span class="wp-puller-log-meta">
                                        <?php echo esc_html( human_time_diff( $log['timestamp'], time() ) ); ?> <?php esc_html_e( 'ago', 'wp-puller' ); ?>
                                        &middot; <?php echo esc_html( ucfirst( $log['source'] ) ); ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="wp-puller-footer">
        <p>
            <?php
            printf(
                /* translators: %s: GitHub link */
                esc_html__( 'WP Puller is open source. %s', 'wp-puller' ),
                '<a href="https://github.com/codician-team/wp-puller" target="_blank" rel="noopener">' . esc_html__( 'Star on GitHub', 'wp-puller' ) . '</a>'
            );
            ?>
        </p>
    </div>
</div>

<!-- Reusable package form (server-rendered, toggled by JS for add/edit) -->
    <?php
    $edit_pkg = null;
    if ( ! empty( $_GET['wp_puller_edit'] ) ) {
        $edit_pkg = WP_Puller::get_package( sanitize_key( wp_unslash( $_GET['wp_puller_edit'] ) ) );
    }
    $f = $edit_pkg ? $edit_pkg : array(
        'id' => '', 'label' => '', 'repo_url' => '', 'branch' => 'main', 'package_type' => 'plugin',
        'source_path' => '', 'plugin_slug' => '', 'auto_update' => true, 'token_mode' => 'global', 'webhook_mode' => 'global', 'commit' => '',
    );
    ?>
    <details class="wp-puller-pkg-details" id="wp-puller-pkg-form-wrap" <?php if ( $edit_pkg || ! empty( $_GET['wp_puller_add'] ) || empty( $packages ) ) echo 'open'; ?>>
        <summary><?php esc_html_e( 'Add / Edit Package', 'wp-puller' ); ?></summary>
        <form class="wp-puller-pkg-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="wp_puller_save_package">
            <?php wp_nonce_field( 'wp_puller_save_package', 'wp_puller_pkg_nonce' ); ?>
            <input type="hidden" name="pkg_id" value="<?php echo esc_attr( $f['id'] ); ?>">
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Label', 'wp-puller' ); ?></label>
                <input type="text" name="label" class="regular-text" value="<?php echo esc_attr( $f['label'] ); ?>" placeholder="<?php esc_attr_e( 'My Plugin', 'wp-puller' ); ?>">
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Repository URL', 'wp-puller' ); ?></label>
                <input type="url" name="repo_url" class="regular-text" value="<?php echo esc_attr( $f['repo_url'] ); ?>" placeholder="https://github.com/username/repo">
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Branch', 'wp-puller' ); ?></label>
                <input type="text" name="branch" class="regular-text" value="<?php echo esc_attr( $f['branch'] ); ?>">
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Commit (optional)', 'wp-puller' ); ?></label>
                <input type="text" name="commit" class="regular-text" value="<?php echo esc_attr( ! empty( $f['commit'] ) ? $f['commit'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Leave empty for latest, or paste a commit SHA to pin', 'wp-puller' ); ?>">
                <p class="description"><?php esc_html_e( 'Pin a specific commit to deploy. Leave empty to always deploy the latest commit of the branch.', 'wp-puller' ); ?></p>
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Package Type', 'wp-puller' ); ?></label>
                <select name="package_type">
                    <option value="plugin" <?php selected( $f['package_type'], 'plugin' ); ?>><?php esc_html_e( 'Plugin', 'wp-puller' ); ?></option>
                    <option value="theme" <?php selected( $f['package_type'], 'theme' ); ?>><?php esc_html_e( 'Theme', 'wp-puller' ); ?></option>
                </select>
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Repository Path', 'wp-puller' ); ?></label>
                <input type="text" name="source_path" class="regular-text" value="<?php echo esc_attr( $f['source_path'] ); ?>" placeholder="<?php esc_attr_e( 'Leave empty if package is at repo root', 'wp-puller' ); ?>">
                <p class="description"><?php esc_html_e( 'Subdirectory within the repository containing the package.', 'wp-puller' ); ?></p>
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Plugin Slug', 'wp-puller' ); ?></label>
                <input type="text" name="plugin_slug" class="regular-text" value="<?php echo esc_attr( $f['plugin_slug'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. my-plugin or my-plugin/my-plugin.php', 'wp-puller' ); ?>">
                <p class="description"><?php esc_html_e( 'Auto-filled after first deploy if empty.', 'wp-puller' ); ?></p>
            </div>
            <div class="wp-puller-field wp-puller-field-inline">
                <label><input type="checkbox" name="auto_update" value="1" <?php checked( ! empty( $f['auto_update'] ) ); ?>> <?php esc_html_e( 'Auto-update on webhook', 'wp-puller' ); ?></label>
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Token Mode', 'wp-puller' ); ?></label>
                <select name="token_mode">
                    <option value="global" <?php selected( $f['token_mode'], 'global' ); ?>><?php esc_html_e( 'Use global token', 'wp-puller' ); ?></option>
                    <option value="custom" <?php selected( $f['token_mode'], 'custom' ); ?>><?php esc_html_e( 'Custom token', 'wp-puller' ); ?></option>
                </select>
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Custom Token (only if mode = custom)', 'wp-puller' ); ?></label>
                <input type="password" name="pat" class="regular-text" placeholder="<?php esc_attr_e( 'ghp_xxxxx or github_pat_xxxxx', 'wp-puller' ); ?>" autocomplete="off">
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Webhook Mode', 'wp-puller' ); ?></label>
                <select name="webhook_mode">
                    <option value="global" <?php selected( $f['webhook_mode'], 'global' ); ?>><?php esc_html_e( 'Use global webhook secret', 'wp-puller' ); ?></option>
                    <option value="custom" <?php selected( $f['webhook_mode'], 'custom' ); ?>><?php esc_html_e( 'Custom webhook secret', 'wp-puller' ); ?></option>
                </select>
            </div>
            <div class="wp-puller-field">
                <label><?php esc_html_e( 'Custom Webhook Secret (only if mode = custom)', 'wp-puller' ); ?></label>
                <input type="password" name="webhook_secret" class="regular-text" placeholder="<?php esc_attr_e( 'Custom secret', 'wp-puller' ); ?>" autocomplete="off">
            </div>
            <div class="wp-puller-field-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Package', 'wp-puller' ); ?></button>
                <?php if ( $edit_pkg ) : ?>
                    <a href="<?php echo esc_url( remove_query_arg( 'wp_puller_edit' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'wp-puller' ); ?></a>
                <?php endif; ?>
            </div>
        </form>
    </details>
