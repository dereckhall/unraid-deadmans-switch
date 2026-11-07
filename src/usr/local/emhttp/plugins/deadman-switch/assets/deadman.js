// Dead Man's Switch - Frontend JavaScript
// Uses jQuery (provided by Unraid's Dynamix framework)

var DMS_API = '/plugins/deadman-switch/include/api.php';
var DMS_TABS = ['dashboard', 'notifications', 'actions', 'settings', 'logs'];

// Get CSRF token from state (set by PHP)
function dmsCsrf() {
    return (typeof dmsState !== 'undefined') ? dmsState.csrfToken : '';
}

// Tab switching
function dmsShowTab(tabName) {
    if (DMS_TABS.indexOf(tabName) === -1) tabName = 'dashboard';

    $('.dms-tab').removeClass('active');
    $('.dms-tab-content').removeClass('active');

    $('.dms-tab[onclick*="' + tabName + '"]').addClass('active');
    $('#dms-tab-' + tabName).addClass('active');

    if (tabName === 'logs') dmsLoadLogs();

    // Save tab state (storage may be unavailable: private mode, blocked site data)
    try { localStorage.setItem('dms-active-tab', tabName); } catch (e) {}
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

// Shared failure handler for every API call: HTTP errors, network errors,
// expired sessions (Unraid's CSRF check) and failed flash writes (HTTP 500).
// Unraid's csrf_terminate() exits with an EMPTY HTTP 200 body, which jQuery
// reports as a 'parsererror' on the expected JSON: that is a session expiry too
function dmsFail(xhr, textStatus) {
    var body = xhr.responseText || '';
    var msg = 'Request failed (HTTP ' + xhr.status + ')';
    try { var j = JSON.parse(body); msg = j.error || j.message || msg; }
    catch (e) {
        if (xhr.status === 0) msg = 'Network error';
        else if (xhr.status === 200 && (!/\S/.test(body) || textStatus === 'parsererror')) msg = 'Session expired - reload the page';
        else if (/csrf/i.test(body)) msg = 'Session expired - reload the page';
    }
    dmsToast(msg, 'error');
}

// Shared .done body: toast any warning, then success (and run onOk) or the error
function dmsHandle(resp, okMsg, onOk) {
    if (resp && resp.warning) dmsToast(resp.warning, 'info');
    if (resp && resp.success) { if (okMsg) dmsToast(okMsg, 'success'); if (onOk) onOk(resp); }
    else dmsToast((resp && (resp.message || resp.error)) || 'Failed', 'error');
}

// API helper - GET requests (no CSRF needed for reads)
function dmsGet(action, params) {
    var url = DMS_API + '?action=' + encodeURIComponent(action);
    if (params) {
        $.each(params, function(k, v) {
            url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
        });
    }
    return $.getJSON(url).fail(dmsFail);
}

// API helper - POST with form data (Unraid requires csrf_token in $_POST)
function dmsPost(action, data) {
    data = data || {};
    data.action = action;
    data.csrf_token = dmsCsrf();
    return $.post(DMS_API, data, null, 'json').fail(dmsFail);
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
    }, null, 'json').fail(dmsFail);
}

// Check-in
function dmsCheckIn() {
    var btn = $('#dms-checkin-btn');
    btn.prop('disabled', true).text('Checking in...');

    var ok = false;
    dmsPost('web_checkin').done(function(data) {
        dmsHandle(data, 'Check-in recorded successfully!', function() {
            ok = true;
            btn.text('CHECKED IN!').addClass('success');
            setTimeout(function() { location.reload(); }, 1500);
        });
    }).always(function() {
        if (!ok) btn.text('CHECK IN').prop('disabled', false);
    });
}

// Arm/Disarm
function dmsArm() {
    if (!confirm('Are you sure you want to ARM the dead man\'s switch?')) return;
    dmsPost('arm').done(function(data) {
        dmsHandle(data, 'Switch armed!', function() { location.reload(); });
    });
}

function dmsDisarm() {
    if (!confirm('Are you sure you want to DISARM the dead man\'s switch?')) return;
    dmsPost('disarm').done(function(data) {
        dmsHandle(data, null, function() {
            dmsToast('Switch disarmed', 'info');
            location.reload();
        });
    });
}

// Pause/Unpause
function dmsPause() {
    var hours = prompt('Pause for how many hours? The countdown is frozen while paused - the deadline moves out by the time spent paused.', '24');
    if (!hours) return;
    dmsPost('pause', { hours: hours }).done(function(data) {
        dmsHandle(data, null, function() {
            dmsToast('Timer paused', 'info');
            location.reload();
        });
    });
}

function dmsUnpause() {
    dmsPost('unpause').done(function(data) {
        dmsHandle(data, null, function() {
            dmsToast('Timer unpaused', 'info');
            location.reload();
        });
    });
}

// Copy text to clipboard
function dmsCopyText(el) {
    var text = el.textContent.trim();

    function ok() { dmsToast('Copied to clipboard', 'success'); }

    // navigator.clipboard only exists in secure contexts (HTTPS/localhost);
    // Unraid is usually reached over plain http://LAN-IP, so fall back
    function fallback() {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.left = '0';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        var copied = false;
        try { copied = document.execCommand('copy'); } catch (e) { copied = false; }
        document.body.removeChild(ta);
        if (copied) ok();
        else dmsToast('Copy failed - select the text manually', 'error');
    }

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(ok).catch(fallback);
    } else {
        fallback();
    }
}

// Delete API key
function dmsDeleteApiKey() {
    if (!confirm('Delete the API key? Any scripts or automations using it will stop working.')) return;
    dmsPostJson('save_config', { api_key: '' }).done(function(resp) {
        dmsHandle(resp, null, function() {
            dmsToast('API key deleted', 'info');
            location.reload();
        });
    });
}

// Generate API key
function dmsGenerateApiKey() {
    if (!confirm('Generate a new API key? The old key will stop working.')) return;
    dmsPost('generate_api_key').done(function(data) {
        dmsHandle(data, 'New API key generated', function() {
            setTimeout(function() { location.reload(); }, 500);
        });
    });
}

// POST a save_config. The server may answer needs_confirm ('dangerous' for a
// whole-array/pool deletion path, 'shorten' when a shorter interval or grace
// puts the deadline before the next cron run): ask, then re-post the same
// data with the matching flag (a re-post can ask for the other one in turn).
// Returns the FIRST request's jqXHR.
function dmsSaveConfig(data, okMsg) {
    return dmsPostJson('save_config', data).done(function(resp) {
        if (resp && resp.needs_confirm) {
            if (confirm(resp.message)) {
                data[resp.needs_confirm === 'dangerous' ? '_confirm_dangerous' : '_confirm'] = true;
                dmsSaveConfig(data, okMsg);
            } else dmsToast('Not saved', 'info');
            return;
        }
        dmsHandle(resp, okMsg);
    });
}

// Test webhook (saves notification settings first, then tests)
function dmsTestWebhook(type) {
    dmsToast('Saving settings and sending test...', 'info');
    // Wait for the save to land so the test uses the settings on screen
    dmsSaveNotifications().done(function(r) {
        // needs_confirm = not saved yet (a confirmed re-post saves without testing)
        if (r && r.success && !r.needs_confirm) dmsPost('test_webhook', { type: type }).done(function(d) {
            dmsToast(d.message || (d.success ? 'Test sent!' : 'Test failed'), d.success ? 'success' : 'error');
        });
    });
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

    return dmsSaveConfig(data, 'Notification settings saved!');
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

    return dmsSaveConfig(data, 'Settings saved!');
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

    return dmsSaveConfig(data, 'Actions saved!');
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
        // dmsHandle toasts data.warning (e.g. the run could not be recorded)
        dmsHandle(data, 'Dry run complete!', function() {
            var results = data.results || [];
            var text = 'Dry Run Results:\n\n';
            $.each(results, function(i, r) {
                text += '  ' + r.action + '\n';
                if (r.exists !== undefined) text += '    Exists: ' + (r.exists ? 'Yes' : 'No') + '\n';
                if (r.executable !== undefined) text += '    Executable: ' + (r.executable ? 'Yes' : 'No') + '\n';
                var via = r.danger_via ? ' (matches ' + r.danger_via + ')' : '';
                if (r.danger === 'forbidden') text += '    REFUSED: system root' + via + '\n';
                else if (r.danger === 'array_root') text += '    DANGEROUS: whole array/pool' + via + '\n';
                if (r.matches && r.matches.length) {
                    text += '    Matches:\n';
                    $.each(r.matches, function(j, m) { text += '      ' + m + '\n'; });
                    if (r.match_count > r.matches.length) text += '      ... and ' + (r.match_count - r.matches.length) + ' more\n';
                }
            });
            if (results.length === 0) text += '  No actions configured.\n';
            $('#dms-dry-run-output').text(text);
            $('#dms-dry-run-results').show();
        });
    });
}

// Logs
function dmsLoadLogs() {
    var filterVal = $('#dms-log-filter').val() || '';
    var params = { limit: 200 };
    if (filterVal) params.filter = filterVal;

    dmsGet('get_logs', params).done(function(data) {
        if (!data || !Array.isArray(data.logs)) {
            $('#dms-log-output').text('Could not load logs.');
        } else if (data.logs.length > 0) {
            $('#dms-log-output').text(data.logs.join('\n'));
        } else {
            $('#dms-log-output').text('No log entries found.');
        }
    }).fail(function() {
        $('#dms-log-output').text('Could not load logs.');
    });
}

function dmsClearLogs() {
    if (!confirm('Clear all logs?')) return;
    dmsPost('clear_logs').done(function(data) {
        dmsHandle(data, null, function() {
            dmsToast('Logs cleared', 'info');
            dmsLoadLogs();
        });
    });
}

// Countdown color for the elapsed percentage, following the configured
// warning thresholds (same comparisons as dms_get_warning_level())
function dmsCountdownColor(pct, t) {
    t = t || {};
    if (pct >= t.critical) return '#f44336';
    if (pct >= t.warning) return '#ff9800';
    if (pct >= t.reminder) return '#ffc107';
    return '';
}

// Initialization
$(function() {
    if (typeof dmsState === 'undefined') return;

    // Restore saved tab (dmsShowTab falls back to dashboard for unknown names)
    var savedTab = null;
    try { savedTab = localStorage.getItem('dms-active-tab'); } catch (e) {}
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
    if (!dmsState.armed || dmsState.paused || dmsState.remaining === null || dmsState.remaining <= 0) return;

    var countdownEl = $('#dms-countdown-value');
    if (!countdownEl.length) return;

    // Use wall-clock time to avoid drift from setInterval inaccuracy
    var startTime = Date.now();
    var startRemaining = dmsState.remaining;
    var dmsExpiredReload = false;

    function updateCountdown() {
        var elapsed = Math.floor((Date.now() - startTime) / 1000);
        var remaining = startRemaining - elapsed;

        if (remaining <= 0) {
            countdownEl.text('EXPIRED').css('color', '#f44336');
            // Reload once so the server renders the grace/triggered state
            if (!dmsExpiredReload) {
                dmsExpiredReload = true;
                setTimeout(function() { location.reload(); }, 2000);
            }
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

        // Color based on urgency: elapsed share of the full check-in interval
        if (dmsState.totalSeconds > 0) {
            var pct = Math.min(100, Math.max(0, (dmsState.totalSeconds - remaining) / dmsState.totalSeconds * 100));
            countdownEl.css('color', dmsCountdownColor(pct, dmsState.thresholds));
        }
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
});
