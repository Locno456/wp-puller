/**
 * WP Puller Admin JavaScript
 *
 * @package WP_Puller
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var WPPuller = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Global settings.
            $('#wp-puller-global-form').on('submit', this.saveGlobal.bind(this));
            $('#wp-puller-regenerate-secret').on('click', this.regenerateSecret.bind(this));
            $(document).on('click', '.wp-puller-copy-btn', this.copyToClipboard.bind(this));

            // Package list actions.
            $('#wp-puller-add-package').on('click', this.addPackage.bind(this));
            $(document).on('click', '.wp-puller-pkg-edit', this.editPackage.bind(this));
            $(document).on('click', '.wp-puller-pkg-cancel', this.cancelPackage.bind(this));
            $(document).on('click', '.wp-puller-pkg-check', this.checkUpdates.bind(this));
            $(document).on('click', '.wp-puller-pkg-update', this.updatePackage.bind(this));
            $(document).on('click', '.wp-puller-pkg-backups', this.toggleBackups.bind(this));
            $(document).on('click', '.wp-puller-pkg-delete', this.deletePackage.bind(this));

            // Package form (add/edit) - delegated.
            $(document).on('submit', '.wp-puller-pkg-form', this.savePackage.bind(this));
            $(document).on('change', '.wp-puller-pkg-form [name="token_mode"]', this.toggleCustomField.bind(this));
            $(document).on('change', '.wp-puller-pkg-form [name="webhook_mode"]', this.toggleCustomField.bind(this));
            $(document).on('click', '.wp-puller-pkg-form [data-role="test"]', this.testConnection.bind(this));

            // Backups.
            $(document).on('click', '.wp-puller-restore-backup', this.restoreBackup.bind(this));
            $(document).on('click', '.wp-puller-delete-backup', this.deleteBackup.bind(this));

            // Logs.
            $('#wp-puller-clear-logs').on('click', this.clearLogs.bind(this));
        },

        toggleCustomField: function(e) {
            var $select = $(e.currentTarget);
            var $form = $select.closest('form');
            var name = $select.attr('name');
            var target = (name === 'token_mode') ? 'pat' : 'webhook_secret';
            var custom = ('custom' === $select.val());
            $form.find('[name="' + target + '"]').toggle(custom);
        },

        addPackage: function() {
            var $wrap = $('#wp-puller-pkg-form-wrap');
            var $form = $wrap.find('form');
            $form[0].reset();
            $form.find('[name="pkg_id"]').val('');
            $form.find('[name="package_type"]').val('plugin');
            $form.find('[name="auto_update"]').prop('checked', true);
            $form.find('[name="token_mode"]').val('global');
            $form.find('[name="webhook_mode"]').val('global');
            $form.find('[name="pat"]').val('').hide();
            $form.find('[name="webhook_secret"]').val('').hide();
            $wrap.show();
            $('html, body').animate({ scrollTop: $wrap.offset().top - 50 }, 300);
        },

        editPackage: function(e) {
            var $item = $(e.currentTarget).closest('.wp-puller-package-item');
            var data = $item.data();
            var $wrap = $('#wp-puller-pkg-form-wrap');
            var $form = $wrap.find('form');
            $form.find('[name="pkg_id"]').val(data.id);
            $form.find('[name="label"]').val(data.label);
            $form.find('[name="repo_url"]').val(data.repo);
            $form.find('[name="branch"]').val(data.branch);
            $form.find('[name="package_type"]').val(data.type);
            $form.find('[name="source_path"]').val(data.source);
            $form.find('[name="plugin_slug"]').val(data.slug);
            $form.find('[name="auto_update"]').prop('checked', '1' === data.auto);
            $form.find('[name="token_mode"]').val(data.token);
            $form.find('[name="webhook_mode"]').val(data.webhook);
            $form.find('[name="token_mode"]').trigger('change');
            $form.find('[name="webhook_mode"]').trigger('change');
            $wrap.show();
            $('html, body').animate({ scrollTop: $wrap.offset().top - 50 }, 300);
        },

        cancelPackage: function() {
            $('#wp-puller-pkg-form-wrap').hide();
        },

        saveGlobal: function(e) {
            e.preventDefault();
            var $btn = $(e.currentTarget).find('[type="submit"]');
            this.setLoading($btn, true);
            this.post('wp_puller_save_global', {
                global_pat: $('#wp-puller-global-pat').val(),
                backup_count: $('#wp-puller-backup-count').val()
            }, function(response) {
                if (response.success) {
                    WPPuller.showNotice(response.data.message, 'success');
                } else {
                    WPPuller.showNotice(response.data.message, 'error');
                }
            }, function() {
                WPPuller.setLoading($btn, false);
            });
        },

        savePackage: function(e) {
            e.preventDefault();
            var $form = $(e.currentTarget);
            var $btn = $form.find('[type="submit"]');
            this.setLoading($btn, true);

            this.post('wp_puller_save_package', {
                pkg_id: $form.find('[name="pkg_id"]').val(),
                label: $form.find('[name="label"]').val(),
                repo_url: $form.find('[name="repo_url"]').val(),
                branch: $form.find('[name="branch"]').val(),
                package_type: $form.find('[name="package_type"]').val(),
                source_path: $form.find('[name="source_path"]').val(),
                plugin_slug: $form.find('[name="plugin_slug"]').val(),
                auto_update: $form.find('[name="auto_update"]').is(':checked') ? 'true' : 'false',
                token_mode: $form.find('[name="token_mode"]').val(),
                pat: $form.find('[name="pat"]').val(),
                webhook_mode: $form.find('[name="webhook_mode"]').val(),
                webhook_secret: $form.find('[name="webhook_secret"]').val()
            }, function(response) {
                if (response.success) {
                    WPPuller.showNotice(response.data.message, 'success');
                    setTimeout(function() { location.reload(); }, 1200);
                } else {
                    WPPuller.showNotice(response.data.message, 'error');
                    WPPuller.setLoading($btn, false);
                }
            }, function() {
                WPPuller.setLoading($btn, false);
            });
        },

        testConnection: function(e) {
            e.preventDefault();
            var $form = $(e.currentTarget).closest('form');
            var repoUrl = $form.find('[name="repo_url"]').val();
            var $result = $form.find('.pkg-test-result');

            if (!repoUrl) {
                $result.text('Please enter a repository URL.').css('color', '#d63638');
                return;
            }

            $result.text(wpPuller.strings.testing).css('color', '');

            this.post('wp_puller_test_connection', { repo_url: repoUrl }, function(response) {
                if (response.success) {
                    var msg = wpPuller.strings.connected;
                    if (response.data.repo) {
                        msg += ' ' + response.data.repo.full_name;
                        if (response.data.repo.private) { msg += ' (Private)'; }
                        if (response.data.repo.default_branch && !$form.find('[name="branch"]').val()) {
                            $form.find('[name="branch"]').val(response.data.repo.default_branch);
                        }
                    }
                    $result.text(msg).css('color', '#00a32a');
                } else {
                    $result.text(response.data.message).css('color', '#d63638');
                }
            }, function() {});
        },

        checkUpdates: function(e) {
            var $btn = $(e.currentTarget);
            var id = $btn.data('id');
            this.setLoading($btn, true);
            this.post('wp_puller_check_updates', { pkg_id: id }, function(response) {
                if (response.success) {
                    var d = response.data;
                    if (d.is_new_setup) {
                        WPPuller.showNotice('Ready to install. Click "Update Now" to pull from GitHub.', 'success');
                    } else if (d.update_available) {
                        WPPuller.showNotice('Update available! Latest: ' + d.latest_commit.short_sha, 'success');
                    } else {
                        WPPuller.showNotice('Package is up to date (' + d.latest_commit.short_sha + ').', 'success');
                    }
                } else {
                    WPPuller.showNotice(response.data.message, 'error');
                }
            }, function() { WPPuller.setLoading($btn, false); });
        },

        updatePackage: function(e) {
            var $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            this.post('wp_puller_update_package', { pkg_id: $btn.data('id') }, function(response) {
                if (response.success) {
                    WPPuller.showNotice(wpPuller.strings.updated, 'success');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    WPPuller.showNotice(response.data.message, 'error');
                    WPPuller.setLoading($btn, false);
                }
            }, function() { WPPuller.setLoading($btn, false); });
        },

        toggleBackups: function(e) {
            var id = $(e.currentTarget).data('id');
            $('#wp-puller-backups-' + id).toggle();
        },

        deletePackage: function(e) {
            var id = $(e.currentTarget).data('id');
            if (!confirm(wpPuller.strings.confirmDeletePkg)) { return; }
            var $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            this.post('wp_puller_delete_package', { pkg_id: id }, function(response) {
                if (response.success) {
                    WPPuller.showNotice(response.data.message, 'success');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    WPPuller.showNotice(response.data.message, 'error');
                    WPPuller.setLoading($btn, false);
                }
            }, function() { WPPuller.setLoading($btn, false); });
        },

        restoreBackup: function(e) {
            var $btn = $(e.currentTarget);
            var backupName = $btn.data('name');
            var pkgId = $btn.data('id');
            if (!confirm(wpPuller.strings.confirmRestore)) { return; }
            this.setLoading($btn, true);
            this.post('wp_puller_restore_backup', { backup_name: backupName, pkg_id: pkgId }, function(response) {
                if (response.success) {
                    WPPuller.showNotice(wpPuller.strings.restored, 'success');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    WPPuller.showNotice(response.data.message, 'error');
                    WPPuller.setLoading($btn, false);
                }
            }, function() { WPPuller.setLoading($btn, false); });
        },

        deleteBackup: function(e) {
            var $btn = $(e.currentTarget);
            var backupName = $btn.data('name');
            if (!confirm(wpPuller.strings.confirmDelete)) { return; }
            this.setLoading($btn, true);
            this.post('wp_puller_delete_backup', { backup_name: backupName }, function(response) {
                if (response.success) {
                    $btn.closest('.wp-puller-backup-item').fadeOut(function() {
                        $(this).remove();
                        if ($('#wp-puller-backup-list li').length === 0) {
                            $('#wp-puller-backup-list').replaceWith('<p class="wp-puller-empty">No backups yet.</p>');
                        }
                    });
                    WPPuller.showNotice(wpPuller.strings.deleted, 'success');
                } else {
                    WPPuller.showNotice(response.data.message, 'error');
                }
            }, function() { WPPuller.setLoading($btn, false); });
        },

        regenerateSecret: function(e) {
            if (!confirm(wpPuller.strings.confirmRegenerate)) { return; }
            var $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            this.post('wp_puller_regenerate_secret', {}, function(response) {
                if (response.success) {
                    $('#webhook-secret').val(response.data.secret);
                    WPPuller.showNotice(response.data.message, 'success');
                } else {
                    WPPuller.showNotice(response.data.message, 'error');
                }
            }, function() { WPPuller.setLoading($btn, false); });
        },

        clearLogs: function(e) {
            var $btn = $(e.currentTarget);
            this.setLoading($btn, true);
            this.post('wp_puller_clear_logs', {}, function(response) {
                if (response.success) {
                    $('#wp-puller-log-list').replaceWith('<p class="wp-puller-empty">No activity recorded yet.</p>');
                    $btn.remove();
                } else {
                    WPPuller.showNotice(response.data.message, 'error');
                }
            }, function() { WPPuller.setLoading($btn, false); });
        },

        copyToClipboard: function(e) {
            var $btn = $(e.currentTarget);
            var inputId = $btn.data('copy');
            var $input = $('#' + inputId);
            var text = $input.val();
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    WPPuller.setCopiedState($btn);
                }).catch(function() {
                    WPPuller.copyFallback($input, $btn);
                });
            } else {
                WPPuller.copyFallback($input, $btn);
            }
        },

        copyFallback: function($input, $btn) {
            $input.select();
            try { document.execCommand('copy'); WPPuller.setCopiedState($btn); }
            catch (err) { window.prompt('Copy to clipboard:', $input.val()); }
        },

        setCopiedState: function($btn) {
            $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');
            setTimeout(function() {
                $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 1500);
        },

        post: function(action, data, success, complete) {
            $.ajax({
                url: wpPuller.ajaxUrl,
                type: 'POST',
                data: $.extend({ action: action, nonce: wpPuller.nonce }, data),
                success: success,
                error: function() { WPPuller.showNotice(wpPuller.strings.error, 'error'); },
                complete: complete || function() {}
            });
        },

        setLoading: function($btn, loading) {
            if (loading) {
                $btn.addClass('wp-puller-btn-loading').prop('disabled', true);
            } else {
                $btn.removeClass('wp-puller-btn-loading').prop('disabled', false);
            }
        },

        showNotice: function(message, type) {
            var $notice = $('#wp-puller-notice');
            var className = 'notice-' + (type || 'info');
            $notice.removeClass('notice-success notice-error notice-info').addClass(className).html(this.escapeHtml(message)).fadeIn();
            setTimeout(function() { $notice.fadeOut(); }, 5000);
            $('html, body').animate({ scrollTop: $('.wp-puller-wrap').offset().top - 50 }, 300);
        },

        escapeHtml: function(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        WPPuller.init();
    });

})(jQuery);
