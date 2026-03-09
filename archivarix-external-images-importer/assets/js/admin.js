(function($) {
    'use strict';

    let backgroundRunning = false;
    let logsPage = 1, logsSort = 'desc';

    $(document).ready(function() {
        initTabs();
        initScan();
        initProcess();
        initReset();
        initToggle();
        initLogs();
        checkBackground();
        
        loadLogs();
    });

    function initTabs() {
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            const id = $(this).attr('href');
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.aeii-tab-content').removeClass('active');
            $(id).addClass('active');
            if (id === '#statistics') loadLogs();
        });
    }

    function initScan() {
        $('#aeii-scan-btn').on('click', function() {
            if (backgroundRunning) return; // Don't scan if processing is in progress
            const $btn = $(this), $res = $('#aeii-scan-results');
            $btn.prop('disabled', true).addClass('disabled').text(aeiiData.strings.scanning);
            $res.html('<div class="aeii-spinner"></div>');
            
            $.post(aeiiData.ajax_url, {action: 'aeii_scan_posts', nonce: aeiiData.nonce}, function(r) {
                if (r.success) {
                    let summary = '<div class="aeii-scan-summary success"><h4>✓ ' + esc(aeiiData.strings.scan_complete) + '</h4><ul>' +
                        '<li>' + esc(aeiiData.strings.posts) + ': ' + r.data.total_posts + '</li>' +
                        '<li>' + esc(aeiiData.strings.images_to_process) + ': ' + r.data.total_images + '</li>' +
                        '<li>' + esc(aeiiData.strings.external) + ': ' + r.data.external_images + '</li>' +
                        '<li>' + esc(aeiiData.strings.local_404) + ': ' + r.data.local_missing_images + '</li>';
                    if (r.data.invalid_urls > 0) {
                        summary += '<li>' + esc(aeiiData.strings.invalid_urls) + ': ' + r.data.invalid_urls + '</li>';
                    }
                    summary += '</ul></div>';
                    $res.html(summary);
                    $('#aeii-process-btn').prop('disabled', !r.data.total_images);
                    if (r.data.total_images) {
                        $('#aeii-process-btn').removeClass('disabled');
                    }
                } else {
                    $res.html('<div class="aeii-error">' + esc(r.data?.message || aeiiData.strings.error) + '</div>');
                }
            }).fail(function() {
                $res.html('<div class="aeii-error">' + aeiiData.strings.error + '</div>');
            }).always(function() {
                // Only re-enable if not processing
                if (!backgroundRunning) {
                    $btn.prop('disabled', false).removeClass('disabled').text(aeiiData.strings.start_scan);
                }
            });
        });
    }

    function initProcess() {
        $('#aeii-process-btn').on('click', function() {
            if (backgroundRunning) return;
            if (!confirm(aeiiData.strings.confirm_process)) return;
            
            const $btn = $(this);
            $btn.prop('disabled', true).addClass('disabled');
            
            $.post(aeiiData.ajax_url, {action: 'aeii_start_background', nonce: aeiiData.nonce}, function(r) {
                if (r.success) {
                    backgroundRunning = true;
                    $('.aeii-background-status').show();
                    $('#aeii-scan-btn').prop('disabled', true).addClass('disabled');
                    pollBackground();
                } else {
                    alert(r.data?.message || aeiiData.strings.error);
                    $btn.prop('disabled', false).removeClass('disabled');
                }
            });
        });
        
        $('#aeii-stop-background-btn').on('click', function() {
            $.post(aeiiData.ajax_url, {action: 'aeii_stop_background', nonce: aeiiData.nonce}, function() {
                backgroundRunning = false;
                $('.aeii-background-status').hide();
                $('#aeii-scan-btn').prop('disabled', false).removeClass('disabled');
                $('#aeii-process-btn').prop('disabled', false).removeClass('disabled');
                clearInterval(window.bgPoll);
                window.bgPoll = null;
            });
        });
    }

    function checkBackground() {
        $.post(aeiiData.ajax_url, {action: 'aeii_get_queue_status', nonce: aeiiData.nonce}, function(r) {
            if (r.success) {
                // Update archive error status
                updateArchiveError(r.data.archive_error);
                
                if (r.data.running) {
                    backgroundRunning = true;
                    $('.aeii-background-status').show();
                    $('#aeii-scan-btn').prop('disabled', true).addClass('disabled');
                    $('#aeii-process-btn').prop('disabled', true).addClass('disabled');
                    pollBackground();
                }
            }
        });
    }

    function pollBackground() {
        if (window.bgPoll) return;
        window.bgPoll = setInterval(function() {
            $.post(aeiiData.ajax_url, {action: 'aeii_get_queue_status', nonce: aeiiData.nonce}, function(r) {
                if (!r.success) return;
                const d = r.data;
                $('#stat-success').text(d.statistics.success);
                $('#stat-cached').text(d.statistics.cached);
                $('#stat-failed').text(d.statistics.failed);
                $('#stat-removed').text(d.statistics.removed);
                $('#stat-placeholder').text(d.statistics.placeholder);
                
                // Update archive error status
                updateArchiveError(d.archive_error);
                
                if (d.total > 0) {
                    const pct = Math.round((d.position / d.total) * 100);
                    $('.aeii-progress-container').show();
                    $('.aeii-progress-fill').css('width', pct + '%');
                    $('.aeii-progress-text').text(d.position + ' / ' + d.total + ' (' + pct + '%)');
                }
                
                if (!d.running) {
                    backgroundRunning = false;
                    clearInterval(window.bgPoll);
                    window.bgPoll = null;
                    $('.aeii-background-status, .aeii-progress-container').hide();
                    $('#aeii-scan-btn').prop('disabled', false).removeClass('disabled');
                    $('#aeii-process-btn').prop('disabled', false).removeClass('disabled');
                    loadLogs();
                }
            });
        }, 3000);
    }
    
    /**
     * Update Web Archive error display
     */
    function updateArchiveError(errorStatus) {
        const $alert = $('#aeii-archive-error');
        
        if (!errorStatus || errorStatus.status === 'ok') {
            $alert.hide().empty();
            return;
        }
        
        let message = '';
        let isWarning = false;
        
        switch (errorStatus.type) {
            case '500':
                message = '<strong>⚠ Web Archive Error (500):</strong> ' + esc(aeiiData.strings.archive_error_500);
                break;
            case '429_retry':
                message = '<strong>⏳ Web Archive Rate Limit (429):</strong> ' + esc(errorStatus.message) + ' ' + esc(aeiiData.strings.archive_429_retry);
                isWarning = true;
                break;
            case '429_blocked':
                message = '<strong>⛔ Web Archive Blocked (429):</strong> ' + esc(aeiiData.strings.archive_429_blocked);
                break;
            default:
                message = esc(errorStatus.message) || esc(aeiiData.strings.error);
        }
        
        $alert
            .removeClass('warning')
            .addClass(isWarning ? 'warning' : '')
            .html(message)
            .show();
    }

    function initReset() {
        $('#aeii-reset-stats-btn').on('click', function() {
            if (!confirm(aeiiData.strings.confirm_reset)) return;
            $(this).prop('disabled', true);
            $.post(aeiiData.ajax_url, {action: 'aeii_reset_statistics', nonce: aeiiData.nonce}, function() {
                $('#stat-success, #stat-cached, #stat-failed, #stat-removed, #stat-placeholder').text('0');
                // Clear archive error display
                $('#aeii-archive-error').hide().empty();
                loadLogs();
            }).always(function() {
                $('#aeii-reset-stats-btn').prop('disabled', false);
            });
        });
        
        // Download logs button
        $('#aeii-download-logs-btn').on('click', function() {
            window.location.href = aeiiData.ajax_url + '?action=aeii_download_logs&nonce=' + aeiiData.nonce;
        });
    }

    function initToggle() {
        function toggle() {
            const hide = $('#keep_original_name').is(':checked');
            $('.aeii-rename-pattern-row').toggle(!hide).find('input').prop('disabled', hide);
        }
        toggle();
        $('#keep_original_name').on('change', toggle);
    }

    function initLogs() {
        $('#aeii-logs-sort').on('change', function() {
            logsSort = $(this).val();
            logsPage = 1;
            loadLogs();
        });
        
        $('#aeii-refresh-logs-btn').on('click', loadLogs);
        
        $('#aeii-logs-select-all').on('change', function() {
            $('.aeii-log-checkbox').prop('checked', $(this).is(':checked'));
            updateDelBtn();
        });
        
        $(document).on('change', '.aeii-log-checkbox', updateDelBtn);
        
        $('#aeii-delete-selected-logs-btn').on('click', function() {
            if (!confirm(aeiiData.strings.confirm_delete_logs)) return;
            const ids = [];
            $('.aeii-log-checkbox:checked').each(function() { ids.push($(this).val()); });
            if (ids.length) deleteLogs(ids);
        });
        
        $('#aeii-delete-all-logs-btn').on('click', function() {
            if (confirm(aeiiData.strings.confirm_delete_all)) deleteLogs([], true);
        });
        
        // Click on image URL - copy to clipboard
        $(document).on('click', '.aeii-copyable-url', function(e) {
            e.preventDefault();
            const url = $(this).data('url');
            copyToClipboard(url);
            showCopiedTooltip($(this));
        });
    }

    function updateDelBtn() {
        $('#aeii-delete-selected-logs-btn').prop('disabled', !$('.aeii-log-checkbox:checked').length);
    }

    function loadLogs() {
        const $body = $('#aeii-logs-body');
        $body.html('<tr><td colspan="7">' + aeiiData.strings.loading + '</td></tr>');
        
        $.post(aeiiData.ajax_url, {
            action: 'aeii_get_logs',
            nonce: aeiiData.nonce,
            sort: logsSort,
            page: logsPage
        }, function(r) {
            if (r.success) {
                renderLogs(r.data);
            } else {
                $body.html('<tr><td colspan="7">' + aeiiData.strings.error_loading_logs + '</td></tr>');
            }
        }).fail(function() {
            $body.html('<tr><td colspan="7">' + aeiiData.strings.error_loading_logs + '</td></tr>');
        });
    }

    function renderLogs(data) {
        const $body = $('#aeii-logs-body');
        const $pag = $('#aeii-logs-pagination');
        
        if (!data.logs || !data.logs.length) {
            $body.html('<tr><td colspan="7">' + aeiiData.strings.no_logs + '</td></tr>');
            $pag.empty();
            return;
        }
        
        let html = '';
        data.logs.forEach(function(log) {
            const shortOrigin = log.image_url && log.image_url.length > 40 ? log.image_url.substring(0, 40) + '...' : (log.image_url || '-');
            const shortNew = log.new_url && log.new_url.length > 40 ? log.new_url.substring(0, 40) + '...' : (log.new_url || '-');
            const cls = log.success ? 'success' : 'error';
            const icon = log.success ? '✓' : '✗';

            // Source/Action - clickable if source_url exists
            let sourceHtml = '';
            if (log.success && log.source) {
                if (log.source_url) {
                    sourceHtml = '<a href="' + esc(log.source_url) + '" target="_blank" class="aeii-source-link" title="' + esc(log.source_url) + '">' + esc(log.source) + '</a>';
                } else {
                    sourceHtml = esc(log.source);
                }
            } else if (!log.success && log.action) {
                sourceHtml = esc(log.action);
            } else {
                sourceHtml = '-';
            }

            // Image Origin URL - clickable to copy
            const originHtml = '<span class="aeii-copyable-url" data-url="' + esc(log.image_url || '') + '" title="Click to copy: ' + esc(log.image_url || '') + '">' + esc(shortOrigin) + '</span>';

            // Downloaded URL - clickable link if exists
            let newUrlHtml = '-';
            if (log.new_url) {
                newUrlHtml = '<a href="' + esc(log.new_url) + '" target="_blank" class="aeii-new-url-link" title="' + esc(log.new_url) + '">' + esc(shortNew) + '</a>';
            }

            html += '<tr class="aeii-log-row ' + cls + '">' +
                '<td><input type="checkbox" class="aeii-log-checkbox" value="' + esc(log.id || '') + '"></td>' +
                '<td>' + esc(log.date || '-') + '</td>' +
                '<td>' + (log.post_id ? '<a href="post.php?post=' + parseInt(log.post_id, 10) + '&action=edit" target="_blank">' + esc(log.post_title || 'Post') + '</a>' : '-') + '</td>' +
                '<td>' + originHtml + '</td>' +
                '<td>' + newUrlHtml + '</td>' +
                '<td><span class="aeii-status-badge ' + cls + '">' + icon + '</span></td>' +
                '<td>' + sourceHtml + '</td></tr>';
        });
        
        $body.html(html);
        
        // Enhanced pagination - show up to 20 pages with navigation
        if (data.total_pages > 1) {
            $pag.html(buildPagination(data.page, data.total_pages));
            
            // Pagination handlers
            $pag.find('.aeii-page-btn').on('click', function() {
                logsPage = $(this).data('page');
                loadLogs();
            });
            
            $pag.find('.aeii-page-prev').on('click', function() {
                if (logsPage > 1) {
                    logsPage--;
                    loadLogs();
                }
            });
            
            $pag.find('.aeii-page-next').on('click', function() {
                if (logsPage < data.total_pages) {
                    logsPage++;
                    loadLogs();
                }
            });
            
            $pag.find('.aeii-page-first').on('click', function() {
                logsPage = 1;
                loadLogs();
            });
            
            $pag.find('.aeii-page-last').on('click', function() {
                logsPage = data.total_pages;
                loadLogs();
            });
        } else {
            $pag.empty();
        }
    }
    
    /**
     * Build pagination with up to 20 pages and navigation
     */
    function buildPagination(currentPage, totalPages) {
        let html = '<div class="aeii-pagination-wrapper">';
        
        // Current position info
        html += '<span class="aeii-pagination-info">' + aeiiData.strings.page_of.replace('%1$d', currentPage).replace('%2$d', totalPages) + '</span>';
        
        // "First" button
        if (currentPage > 1) {
            html += '<button class="button aeii-page-first" title="First page">«</button> ';
        }
        
        // "Previous" button
        if (currentPage > 1) {
            html += '<button class="button aeii-page-prev" title="Previous page">‹</button> ';
        }
        
        // Determine range of visible pages (up to 20)
        const maxVisible = 20;
        let startPage = 1;
        let endPage = totalPages;
        
        if (totalPages > maxVisible) {
            // Center current page if possible
            const half = Math.floor(maxVisible / 2);
            startPage = Math.max(1, currentPage - half);
            endPage = startPage + maxVisible - 1;
            
            if (endPage > totalPages) {
                endPage = totalPages;
                startPage = Math.max(1, endPage - maxVisible + 1);
            }
        }
        
        // Ellipsis at start if needed
        if (startPage > 1) {
            html += '<span class="aeii-pagination-ellipsis">...</span> ';
        }
        
        // Page numbers
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? ' active' : '';
            html += '<button class="button aeii-page-btn' + activeClass + '" data-page="' + i + '">' + i + '</button> ';
        }
        
        // Ellipsis at end if needed
        if (endPage < totalPages) {
            html += '<span class="aeii-pagination-ellipsis">...</span> ';
        }
        
        // "Next" button
        if (currentPage < totalPages) {
            html += '<button class="button aeii-page-next" title="Next page">›</button> ';
        }
        
        // "Last" button
        if (currentPage < totalPages) {
            html += '<button class="button aeii-page-last" title="Last page">»</button>';
        }
        
        html += '</div>';
        return html;
    }
    
    /**
     * Copy text to clipboard
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }
    }
    
    /**
     * Show "Copied!" tooltip
     */
    function showCopiedTooltip($element) {
        const $tooltip = $('<span class="aeii-copied-tooltip">' + (aeiiData.strings.copied || 'Copied!') + '</span>');
        $element.css('position', 'relative').append($tooltip);
        
        setTimeout(function() {
            $tooltip.fadeOut(200, function() {
                $(this).remove();
            });
        }, 1000);
    }

    function deleteLogs(ids, all) {
        $.post(aeiiData.ajax_url, {
            action: 'aeii_delete_logs',
            nonce: aeiiData.nonce,
            ids: ids,
            delete_all: all ? 1 : 0
        }, loadLogs);
    }

    function esc(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

})(jQuery);
