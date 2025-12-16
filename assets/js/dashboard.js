/**
 * Dashboard JavaScript for GForm Spamfighter
 * No external dependencies - pure WordPress/jQuery
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        // View details modal
        $('.gform-view-details').on('click', function () {
            const logId = $(this).data('log-id');
            showLogDetails(logId);
        });

        // Simple bar animation
        $('.gform-chart-bar-fill').each(function () {
            const $bar = $(this);
            const width = $bar.css('width');
            $bar.css('width', '0');
            setTimeout(function () {
                $bar.css('width', width);
            }, 100);
        });

        // Select all checkboxes on logs table
        $('#gform-spamfighter-select-all').on('click', function () {
            const checked = $(this).prop('checked');
            $('input[name="log_ids[]"]').prop('checked', checked);
        });
    });

    /**
     * Show log details in modal
     */
    function showLogDetails(logId) {
        // Create modal backdrop
        const $backdrop = $('<div>')
            .attr('id', 'gform-modal-backdrop')
            .css({
                'position': 'fixed',
                'top': 0,
                'left': 0,
                'width': '100%',
                'height': '100%',
                'background': 'rgba(0,0,0,0.7)',
                'z-index': 100000,
                'display': 'flex',
                'align-items': 'center',
                'justify-content': 'center'
            });

        // Create modal
        const $modal = $('<div>')
            .attr('id', 'gform-modal')
            .css({
                'background': '#fff',
                'max-width': '800px',
                'width': '90%',
                'max-height': '80vh',
                'overflow-y': 'auto',
                'border-radius': '8px',
                'box-shadow': '0 4px 20px rgba(0,0,0,0.3)',
                'position': 'relative'
            })
            .html('<div style="padding:30px;"><p>Loading...</p></div>');

        $backdrop.append($modal);
        $('body').append($backdrop);

        // Close on backdrop click
        $backdrop.on('click', function (e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Load log details via AJAX
        $.ajax({
            url: gformSpamfighter.ajax_url,
            type: 'POST',
            data: {
                action: 'gform_spamfighter_get_log_details',
                log_id: logId,
                nonce: gformSpamfighter.nonce
            },
            success: function (response) {
                if (response.success) {
                    renderLogDetails(response.data, $modal);
                } else {
                    $modal.html('<div style="padding:30px;"><p style="color:red;">Error: ' + response.data.message + '</p></div>');
                }
            },
            error: function () {
                $modal.html('<div style="padding:30px;"><p style="color:red;">Error loading log details</p></div>');
            }
        });
    }

    /**
     * Render log details in modal
     */
    function renderLogDetails(log, $modal) {
        let html = '<div style="padding:30px;">';

        // Header
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">';
        html += '<h2 style="margin:0;">Spam Log Details</h2>';
        html += '<button class="button" onclick="jQuery(\'#gform-modal-backdrop\').remove()">✕ Close</button>';
        html += '</div>';

        // Meta info
        html += '<table class="widefat" style="margin-bottom:20px;">';
        html += '<tr><th style="width:200px;">Form</th><td>' + escapeHtml(log.form_name) + ' (ID: ' + log.form_id + ')</td></tr>';
        html += '<tr><th>Date/Time</th><td>' + escapeHtml(log.created_at) + '</td></tr>';
        html += '<tr><th>Spam Score</th><td><strong>' + parseFloat(log.spam_score).toFixed(2) + '</strong></td></tr>';
        html += '<tr><th>Detection Method</th><td>' + escapeHtml(log.detection_method) + '</td></tr>';
        html += '<tr><th>Action Taken</th><td><span style="' + getActionStyle(log.action_taken) + '">' + escapeHtml(log.action_taken) + '</span></td></tr>';
        html += '<tr><th>IP Address</th><td>' + escapeHtml(log.user_ip) + '</td></tr>';
        html += '<tr><th>User Agent</th><td style="font-size:11px;word-break:break-all;">' + escapeHtml(log.user_agent) + '</td></tr>';
        if (log.site_id) {
            html += '<tr><th>Site ID</th><td>' + log.site_id + ' (' + escapeHtml(log.site_locale) + ')</td></tr>';
        }
        html += '</table>';

        // Submitted data
        html += '<h3>Submitted Data</h3>';
        html += '<table class="widefat">';
        if (log.entry_data && typeof log.entry_data === 'object') {
            for (const [key, value] of Object.entries(log.entry_data)) {
                if (key === '_grouped') continue; // Skip internal data
                html += '<tr><th style="width:200px;">' + escapeHtml(key) + '</th><td>' + escapeHtml(String(value)) + '</td></tr>';
            }
        }
        html += '</table>';

        // Detection details
        html += '<h3 style="margin-top:20px;">Detection Details</h3>';
        html += '<pre style="background:#f5f5f5;padding:15px;border-radius:4px;overflow:auto;max-height:300px;">';
        html += escapeHtml(JSON.stringify(log.detection_details, null, 2));
        html += '</pre>';

        html += '</div>';

        $modal.html(html);
    }

    /**
     * Get style for action badge
     */
    function getActionStyle(action) {
        if (action === 'soft_warning') {
            return 'background:#ffc107;color:#000;padding:4px 8px;border-radius:3px;font-weight:bold;';
        } else if (action === 'rejected') {
            return 'background:#dc3545;color:#fff;padding:4px 8px;border-radius:3px;font-weight:bold;';
        } else {
            return 'background:#6c757d;color:#fff;padding:4px 8px;border-radius:3px;font-weight:bold;';
        }
    }

    /**
     * Close modal
     */
    function closeModal() {
        $('#gform-modal-backdrop').remove();
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})(jQuery);
