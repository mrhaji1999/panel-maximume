/**
 * User Cards Bridge Admin JavaScript
 */

(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        UCBAdmin.init();
    });

    // Main admin object
    window.UCBAdmin = {
        
        init: function() {
            this.initTabs();
            this.initModals();
            this.initForms();
            this.initButtons();
            this.initLogs();
            this.initSettings();
        },

        // Tab functionality
        initTabs: function() {
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                
                var $this = $(this);
                var target = $this.attr('href');
                
                // Update active tab
                $('.nav-tab').removeClass('nav-tab-active');
                $this.addClass('nav-tab-active');
                
                // Show target content
                $('.tab-content').removeClass('active');
                $(target).addClass('active');
            });
        },

        // Modal functionality
        initModals: function() {
            // Close modal on close button click
            $('.ucb-modal-close').on('click', function() {
                $(this).closest('.ucb-modal').hide();
            });

            // Close modal on background click
            $('.ucb-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            // View context button
            $('.ucb-view-context').on('click', function() {
                var context = $(this).data('context');
                $('#ucb-context-content').text(context);
                $('#ucb-context-modal').show();
            });
        },

        // Form functionality
        initForms: function() {
            // CORS origins management
            $('#add-cors-origin').on('click', function() {
                var row = '<div class="cors-origin-row">' +
                         '<input type="url" name="ucb_cors_origins[]" value="" class="regular-text" placeholder="https://example.com">' +
                         '<button type="button" class="button remove-cors-origin">' + ucbAdmin.strings.remove + '</button>' +
                         '</div>';
                $('#cors-origins-container').append(row);
            });

            // Remove CORS origin
            $(document).on('click', '.remove-cors-origin', function() {
                $(this).parent().remove();
            });

            // Add API key row
            $('#add-bridge-key').on('click', function() {
                var container = $('#ucb-bridge-keys');
                var index = container.find('.ucb-bridge-row').length;
                var template = $('#ucb-bridge-key-template').html();
                if (template) {
                    container.append(template.replace(/__index__/g, index));
                }
            });

            // Add destination row
            $('#add-bridge-destination').on('click', function() {
                var container = $('#ucb-bridge-destinations');
                var index = container.find('.ucb-bridge-row').length;
                var template = $('#ucb-bridge-destination-template').html();
                if (template) {
                    container.append(template.replace(/__index__/g, index));
                }
            });

            // Remove dynamic rows
            $(document).on('click', '.remove-bridge-row', function() {
                $(this).closest('.ucb-bridge-row').remove();
            });

            // Generate webhook secret
            $('#generate-webhook-secret').on('click', function() {
                var secret = Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
                $('#ucb_webhook_secret').val(secret);
            });
        },

        // Button functionality
        initButtons: function() {
            // Test SMS configuration
            $('#test-sms-config').on('click', function() {
                UCBAdmin.testSMSConfig();
            });

            // Test SMS from dashboard
            $('#ucb-test-sms').on('click', function() {
                UCBAdmin.testSMS();
            });

            // Cleanup logs
            $('#ucb-cleanup-logs, #ucb-cleanup-old-logs').on('click', function() {
                UCBAdmin.cleanupLogs();
            });

            // Export logs
            $('#ucb-export-logs').on('click', function() {
                UCBAdmin.exportLogs();
            });
        },

        // Logs functionality
        initLogs: function() {
            // Delete log entry
            $('.ucb-delete-log').on('click', function() {
                UCBAdmin.deleteLog($(this));
            });
        },

        // Settings functionality
        initSettings: function() {
            // Auto-save settings on change
            $('.ucb-settings-tabs input, .ucb-settings-tabs select').on('change', function() {
                UCBAdmin.autoSaveSettings();
            });
        },

        // Test SMS configuration
        testSMSConfig: function() {
            var $button = $('#test-sms-config');
            var $result = $('#sms-test-result');
            
            $button.prop('disabled', true).text(ucbAdmin.strings.testing);
            $result.html('');

            $.post(ucbAdmin.ajaxUrl, {
                action: 'ucb_test_sms',
                nonce: ucbAdmin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                } else {
                    $result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                }
            })
            .fail(function() {
                $result.html('<div class="notice notice-error"><p>' + ucbAdmin.strings.error + '</p></div>');
            })
            .always(function() {
                $button.prop('disabled', false).text(ucbAdmin.strings.testSMS);
            });
        },

        // Test SMS from dashboard
        testSMS: function() {
            if (confirm(ucbAdmin.strings.confirmTestSMS)) {
                $.post(ucbAdmin.ajaxUrl, {
                    action: 'ucb_test_sms',
                    nonce: ucbAdmin.nonce
                })
                .done(function(response) {
                    if (response.success) {
                        alert(ucbAdmin.strings.testSMSSuccess);
                    } else {
                        alert(ucbAdmin.strings.testSMSError + response.data.message);
                    }
                })
                .fail(function() {
                    alert(ucbAdmin.strings.error);
                });
            }
        },

        // Cleanup logs
        cleanupLogs: function() {
            if (confirm(ucbAdmin.strings.confirmCleanup)) {
                var $button = $('#ucb-cleanup-logs, #ucb-cleanup-old-logs');
                $button.prop('disabled', true).text(ucbAdmin.strings.cleaning);

                $.post(ucbAdmin.ajaxUrl, {
                    action: 'ucb_cleanup_logs',
                    nonce: ucbAdmin.nonce
                })
                .done(function(response) {
                    if (response.success) {
                        alert(ucbAdmin.strings.cleanupSuccess);
                        location.reload();
                    } else {
                        alert(ucbAdmin.strings.cleanupError + response.data.message);
                    }
                })
                .fail(function() {
                    alert(ucbAdmin.strings.error);
                })
                .always(function() {
                    $button.prop('disabled', false).text(ucbAdmin.strings.cleanupLogs);
                });
            }
        },

        // Export logs
        exportLogs: function() {
            var params = new URLSearchParams(window.location.search);
            params.set('action', 'ucb_export_logs');
            params.set('nonce', ucbAdmin.nonce);
            
            window.location.href = ucbAdmin.ajaxUrl + '?' + params.toString();
        },

        // Delete log entry
        deleteLog: function($button) {
            if (confirm(ucbAdmin.strings.confirmDelete)) {
                var logId = $button.data('log-id');
                var $row = $button.closest('tr');

                $.post(ucbAdmin.ajaxUrl, {
                    action: 'ucb_delete_log',
                    log_id: logId,
                    nonce: ucbAdmin.nonce
                })
                .done(function(response) {
                    if (response.success) {
                        $row.fadeOut();
                    } else {
                        alert(ucbAdmin.strings.deleteError + response.data.message);
                    }
                })
                .fail(function() {
                    alert(ucbAdmin.strings.error);
                });
            }
        },

        // Auto-save settings
        autoSaveSettings: function() {
            // Debounce auto-save
            clearTimeout(this.autoSaveTimeout);
            this.autoSaveTimeout = setTimeout(function() {
                UCBAdmin.saveSettings();
            }, 2000);
        },

        // Save settings
        saveSettings: function() {
            var formData = $('.ucb-settings-tabs form').serialize();
            
            $.post(ucbAdmin.ajaxUrl, {
                action: 'ucb_save_settings',
                data: formData,
                nonce: ucbAdmin.nonce
            })
            .done(function(response) {
                if (response.success) {
                    UCBAdmin.showNotice('success', ucbAdmin.strings.settingsSaved);
                } else {
                    UCBAdmin.showNotice('error', response.data.message);
                }
            })
            .fail(function() {
                UCBAdmin.showNotice('error', ucbAdmin.strings.error);
            });
        },

        // Show notice
        showNotice: function(type, message) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after($notice);
            
            setTimeout(function() {
                $notice.fadeOut();
            }, 3000);
        },

        // Load recent activity
        loadRecentActivity: function() {
            $.get(ucbAdmin.apiUrl + 'logs')
            .done(function(response) {
                if (response.success) {
                    var html = '<ul>';
                    response.data.logs.slice(0, 10).forEach(function(log) {
                        html += '<li><strong>' + log.level + ':</strong> ' + log.message + ' <small>(' + log.created_at + ')</small></li>';
                    });
                    html += '</ul>';
                    $('#ucb-activity-feed').html(html);
                }
            })
            .fail(function() {
                $('#ucb-activity-feed').html('<p>' + ucbAdmin.strings.error + '</p>');
            });
        },

        // Refresh dashboard data
        refreshDashboard: function() {
            UCBAdmin.loadRecentActivity();
        },

        // Initialize real-time updates
        initRealTimeUpdates: function() {
            // Refresh dashboard every 30 seconds
            setInterval(function() {
                UCBAdmin.refreshDashboard();
            }, 30000);
        }
    };

    // Initialize real-time updates if on dashboard
    if ($('#ucb-dashboard').length) {
        UCBAdmin.initRealTimeUpdates();
    }

})(jQuery);
