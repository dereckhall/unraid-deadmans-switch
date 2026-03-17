// Dead Man's Switch - Frontend JavaScript
// Uses jQuery (provided by Unraid's Dynamix framework)

var DMS_API = '/plugins/deadman-switch/include/api.php';

// Get CSRF token from state (set by PHP)
function dmsCsrf() {
    return (typeof dmsState !== 'undefined') ? dmsState.csrfToken : '';
}

// Tab switching
function dmsShowTab(tabName) {
    $('.dms-tab').removeClass('active');
    $('.dms-tab-content').removeClass('active');

    $('.dms-tab[onclick*="' + tabName + '"]').addClass('active');
    $('#dms-tab-' + tabName).addClass('active');

    if (tabName === 'logs') dmsLoadLogs();

    // Save tab state
    localStorage.setItem('dms-active-tab', tabName);
}

// Toast notifications
function dmsToast(message, type) {
    type = type || 'info';
    var toast = $('<div class="dms-toast dms-toast-' + type + '"></div>').text(message);
    $('body').append(toast);
    setTimeout(function() {
        toast.css({ opacity: 0, transition: 'opacity 0.3s' });
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}

// API helper - GET requests (no CSRF needed for reads)
function dmsGet(action, params) {
    var url = DMS_API + '?action=' + encodeURIComponent(action);
    if (params) {
        $.each(params, function(k, v) {
            url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
        });
    }
    return $.getJSON(url);
}

// API helper - POST with form data (Unraid requires csrf_token in $_POST)
function dmsPost(action, data) {
    data = data || {};
    data.action = action;
    data.csrf_token = dmsCsrf();
    return $.post(DMS_API, data, null, 'json');
}

// API helper - POST with JSON payload encoded as form field
// Unraid's auto_prepend requires csrf_token in $_POST for all POST requests,
// so we can't send raw JSON body. Instead, send action + csrf_token as form
// fields and the JSON payload in a 'json_data' field.
function dmsPostJson(action, body) {
    return $.post(DMS_API, {
        action: action,
        csrf_token: dmsCsrf(),
        json_data: JSON.stringify(body)
    }, null, 'json');
}

// Check-in
function dmsCheckIn() {
    var btn = $('#dms-checkin-btn');
    btn.prop('disabled', true).text('Checking in...');

    dmsPost('web_checkin').done(function(data) {
        if (data.success) {
            btn.text('CHECKED IN!').addClass('success');
            dmsToast('Check-in recorded successfully!', 'success');
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            btn.text('CHECK IN').prop('disabled', false);
            dmsToast(data.message || 'Check-in failed', 'error');
        }
    }).fail(function() {
        btn.text('CHECK IN').prop('disabled', false);
        dmsToast('Network error', 'error');
    });
}

// Arm/Disarm
function dmsArm() {
    if (!confirm('Are you sure you want to ARM the dead man\'s switch?')) return;
    dmsPost('arm').done(function(data) {
        if (data.success) {
            dmsToast('Switch armed!', 'success');
            location.reload();
        } else {
            dmsToast(data.message || 'Failed to arm', 'error');
        }
    });
}

function dmsDisarm() {
    if (!confirm('Are you sure you want to DISARM the dead man\'s switch?')) return;
    dmsPost('disarm').done(function(data) {
        if (data.success) {
            dmsToast('Switch disarmed', 'info');
            location.reload();
        }
    });
}

// Pause/Unpause
function dmsPause() {
    var hours = prompt('Pause for how many hours?', '24');
    if (!hours) return;
    dmsPost('pause', { hours: hours }).done(function(data) {
        if (data.success) {
            dmsToast('Timer paused', 'info');
            location.reload();
        }
    });
}

function dmsUnpause() {
    dmsPost('unpause').done(function(data) {
        if (data.success) {
            dmsToast('Timer unpaused', 'info');
            location.reload();
        }
    });
}

// Copy text to clipboard
function dmsCopyText(el) {
    var text = el.textContent.trim();
    navigator.clipboard.writeText(text).then(function() {
        dmsToast('Copied to clipboard', 'success');
    }).catch(function() {
        // Fallback for older browsers
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        dmsToast('Copied to clipboard', 'success');
    });
}

// Delete API key
function dmsDeleteApiKey() {
    if (!confirm('Delete the API key? Any scripts or automations using it will stop working.')) return;
    dmsPostJson('save_config', { api_key: '' }).done(function(resp) {
        if (resp.success) {
            dmsToast('API key deleted', 'info');
            location.reload();
        }
    });
}

// Generate API key
function dmsGenerateApiKey() {
    if (!confirm('Generate a new API key? The old key will stop working.')) return;
    dmsPost('generate_api_key').done(function(data) {
        if (data.success) {
            dmsToast('New API key generated', 'success');
            setTimeout(function() { location.reload(); }, 500);
        }
    });
}

// Test webhook (saves notification settings first, then tests)
function dmsTestWebhook(type) {
    dmsToast('Saving settings and sending test...', 'info');
    dmsSaveNotifications();
    setTimeout(function() {
        dmsPost('test_webhook', { type: type }).done(function(data) {
            dmsToast(data.message || (data.success ? 'Test sent!' : 'Test failed'), data.success ? 'success' : 'error');
        }).fail(function(xhr) {
            var msg = 'Request failed';
            try { msg = JSON.parse(xhr.responseText).error || msg; } catch(e) {}
            dmsToast(msg, 'error');
        });
    }, 500);
}

// Save notifications
function dmsSaveNotifications() {
    var data = {
        warning_thresholds: {
            reminder: parseInt($('#cfg-thresh-reminder').val()),
            warning: parseInt($('#cfg-thresh-warning').val()),
            critical: parseInt($('#cfg-thresh-critical').val()),
            last_chance: parseInt($('#cfg-thresh-last_chance').val())
        },
        message_template: $('#cfg-message-template').val(),
        webhooks: {}
    };

    var types = ['discord', 'custom'];
    $.each(types, function(i, type) {
        data.webhooks[type] = { enabled: $('#cfg-wh-' + type + '-enabled').is(':checked') };
        $('[id^="cfg-wh-' + type + '-"]').each(function() {
            var field = this.id.replace('cfg-wh-' + type + '-', '');
            if (field === 'enabled') return;
            data.webhooks[type][field] = $(this).is(':checkbox') ? $(this).is(':checked') : $(this).val();
        });
    });

    data.uptime_kuma = {
        enabled: $('#cfg-uk-enabled').is(':checked'),
        push_url: $('#cfg-uk-push-url').val(),
        warning_days: parseInt($('#cfg-uk-warning-days').val()) || 7
    };

    dmsPostJson('save_config', data).done(function(resp) {
        dmsToast(resp.success ? 'Notification settings saved!' : 'Save failed', resp.success ? 'success' : 'error');
    });
}

// Save settings
function dmsSaveSettings() {
    var data = {
        checkin_interval_days: parseInt($('#cfg-interval').val()),
        grace_period_hours: parseInt($('#cfg-grace').val()),
        external_url: $('#cfg-external-url').val(),
        cron_interval_minutes: parseInt($('#cfg-cron-interval').val()),
        pause_max_hours: parseInt($('#cfg-pause-max').val()),
        dry_run: $('#cfg-dry-run').is(':checked'),
        double_miss: $('#cfg-double-miss').is(':checked')
    };

    dmsPostJson('save_config', data).done(function(resp) {
        dmsToast(resp.success ? 'Settings saved!' : 'Save failed', resp.success ? 'success' : 'error');
    });
}

// Save actions
function dmsSaveActions() {
    var deletions = [];
    $('#dms-deletions-list .dms-action-item').each(function() {
        var path = $(this).find('.dms-deletion-path').val().trim();
        if (path) {
            deletions.push({
                path: path,
                method: $(this).find('.dms-deletion-method').val()
            });
        }
    });

    var scripts = [];
    $('#dms-scripts-list .dms-action-item').each(function() {
        var path = $(this).find('.dms-script-path').val().trim();
        if (path) {
            scripts.push({
                path: path,
                timeout: parseInt($(this).find('.dms-script-timeout').val()) || 300
            });
        }
    });

    var data = {
        actions: {
            deletions: deletions,
            scripts: scripts
        }
    };

    dmsPostJson('save_config', data).done(function(resp) {
        dmsToast(resp.success ? 'Actions saved!' : 'Save failed', resp.success ? 'success' : 'error');
    });
}

// Add/remove deletions
function dmsAddDeletion() {
    var html = '<div class="dms-action-item">' +
        '<input type="text" class="dms-input dms-deletion-path" placeholder="/mnt/user/share/path">' +
        '<select class="dms-input dms-input-sm dms-deletion-method">' +
        '<option value="standard">Standard (rm -rf)</option>' +
        '<option value="secure">Secure (shred + rm)</option>' +
        '</select>' +
        '<button class="dms-btn dms-btn-danger dms-btn-sm" onclick="dmsRemoveDeletion(this)">Remove</button>' +
        '</div>';
    $('#dms-deletions-list').append(html);
}

function dmsRemoveDeletion(btn) {
    $(btn).closest('.dms-action-item').remove();
}

// Add/remove scripts
function dmsAddScript() {
    var html = '<div class="dms-action-item">' +
        '<input type="text" class="dms-input dms-script-path" placeholder="/boot/config/plugins/deadman-switch/my-script.sh">' +
        '<label>Timeout (s): <input type="number" class="dms-input dms-input-sm dms-script-timeout" value="300" min="10" max="3600"></label>' +
        '<button class="dms-btn dms-btn-danger dms-btn-sm" onclick="dmsRemoveScript(this)">Remove</button>' +
        '</div>';
    $('#dms-scripts-list').append(html);
}

function dmsRemoveScript(btn) {
    $(btn).closest('.dms-action-item').remove();
}

// Dry run
function dmsDryRun() {
    dmsToast('Running dry test...', 'info');
    dmsPost('dry_run').done(function(data) {
        if (data.success) {
            var text = 'Dry Run Results:\n\n';
            $.each(data.results, function(i, r) {
                text += '  ' + r.action + '\n';
                if (r.exists !== undefined) text += '    Exists: ' + (r.exists ? 'Yes' : 'No') + '\n';
                if (r.executable !== undefined) text += '    Executable: ' + (r.executable ? 'Yes' : 'No') + '\n';
            });
            if (data.results.length === 0) text += '  No actions configured.\n';
            $('#dms-dry-run-output').text(text);
            $('#dms-dry-run-results').show();
            dmsToast('Dry run complete!', 'success');
        } else {
            dmsToast(data.message || data.error || 'Dry run failed', 'error');
        }
    }).fail(function(xhr) {
        var msg = 'Request failed';
        try { msg = JSON.parse(xhr.responseText).error || msg; } catch(e) {}
        dmsToast(msg, 'error');
    });
}

// Logs
function dmsLoadLogs() {
    var filterVal = $('#dms-log-filter').val() || '';
    var params = { limit: 200 };
    if (filterVal) params.filter = filterVal;

    dmsGet('get_logs', params).done(function(data) {
        if (data.logs && data.logs.length > 0) {
            $('#dms-log-output').text(data.logs.join('\n'));
        } else {
            $('#dms-log-output').text('No log entries found.');
        }
    });
}

function dmsClearLogs() {
    if (!confirm('Clear all logs?')) return;
    dmsPost('clear_logs').done(function(data) {
        if (data.success) {
            dmsToast('Logs cleared', 'info');
            dmsLoadLogs();
        }
    });
}

// Initialization
$(function() {
    if (typeof dmsState === 'undefined') return;

    // Restore saved tab
    var savedTab = localStorage.getItem('dms-active-tab');
    if (savedTab) {
        dmsShowTab(savedTab);
    }

    // Log filter debounce
    var debounceTimer;
    $('#dms-log-filter').on('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(dmsLoadLogs, 300);
    });

    // Live countdown timer
    if (!dmsState.armed || !dmsState.remaining || dmsState.paused) return;

    var countdownEl = $('#dms-countdown-value');
    if (!countdownEl.length) return;

    // Use wall-clock time to avoid drift from setInterval inaccuracy
    var startTime = Date.now();
    var startRemaining = dmsState.remaining;

    function updateCountdown() {
        var elapsed = Math.floor((Date.now() - startTime) / 1000);
        var remaining = startRemaining - elapsed;

        if (remaining <= 0) {
            countdownEl.text('EXPIRED').css('color', '#f44336');
            return;
        }

        var d = Math.floor(remaining / 86400);
        var h = Math.floor((remaining % 86400) / 3600);
        var m = Math.floor((remaining % 3600) / 60);
        var s = remaining % 60;

        var parts = [];
        if (d > 0) parts.push(d + 'd');
        if (h > 0 || d > 0) parts.push(h + 'h');
        parts.push(m + 'm');
        parts.push(s + 's');

        countdownEl.text(parts.join(' '));

        // Color based on urgency
        var total = startRemaining;
        var pct = ((total - remaining) / total) * 100;
        if (pct > 90) {
            countdownEl.css('color', '#f44336');
        } else if (pct > 75) {
            countdownEl.css('color', '#ff9800');
        }
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
});
