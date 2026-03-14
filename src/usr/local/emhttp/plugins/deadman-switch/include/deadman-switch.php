<?php
// Dead Man's Switch - Main UI Page

require_once __DIR__ . '/helpers.php';

// Ensure $var is available for CSRF token
if (!isset($var)) {
    $var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
}

$config = dms_load_config();
$state = dms_load_state();
$status = dms_get_status($config, $state);
$warning_level = dms_get_warning_level($config, $state);
$remaining = dms_time_remaining($config, $state);
$deadline = dms_get_deadline($config, $state);
$grace_end = dms_get_grace_end($config, $state);

$status_colors = [
    'disarmed'       => '#6c757d',
    'armed_ok'       => '#4caf50',
    'armed_warning'  => '#ff9800',
    'armed_critical' => '#f44336',
    'grace_period'   => '#d32f2f',
    'triggered'      => '#b71c1c',
    'paused'         => '#2196f3',
];

$status_labels = [
    'disarmed'       => 'DISARMED',
    'armed_ok'       => 'ARMED - OK',
    'armed_warning'  => 'WARNING',
    'armed_critical' => 'CRITICAL',
    'grace_period'   => 'GRACE PERIOD',
    'triggered'      => 'TRIGGERED',
    'paused'         => 'PAUSED',
];

$status_color = $status_colors[$status] ?? '#6c757d';
$status_label = $status_labels[$status] ?? strtoupper($status);

// Docker containers list for action config
$docker_containers = [];
$docker_volumes = [];
if (file_exists('/var/run/docker.sock')) {
    exec('docker ps -a --format "{{.Names}}" 2>/dev/null', $docker_containers);
    exec('docker volume ls --format "{{.Name}}" 2>/dev/null', $docker_volumes);
}
?>

<link rel="stylesheet" href="<?autov('/plugins/deadman-switch/assets/style.css')?>">

<div id="dms-app">
    <!-- Tab Navigation -->
    <div class="dms-tabs">
        <button class="dms-tab active" onclick="dmsShowTab('dashboard')">Dashboard</button>
        <button class="dms-tab" onclick="dmsShowTab('notifications')">Notifications</button>
        <button class="dms-tab" onclick="dmsShowTab('actions')">Actions</button>
        <button class="dms-tab" onclick="dmsShowTab('settings')">Settings</button>
        <button class="dms-tab" onclick="dmsShowTab('logs')">Logs</button>
    </div>

    <!-- DASHBOARD TAB -->
    <div id="dms-tab-dashboard" class="dms-tab-content active">
        <div class="dms-status-card" style="border-color: <?= $status_color ?>">
            <div class="dms-status-badge" id="dms-status-badge" style="background: <?= $status_color ?>">
                <?= $status_label ?>
            </div>

            <?php if ($state['armed'] && $remaining !== null): ?>
            <div class="dms-countdown" id="dms-countdown">
                <div class="dms-countdown-value" id="dms-countdown-value"><?= dms_format_time_remaining($remaining) ?></div>
                <div class="dms-countdown-label">remaining until deadline</div>
            </div>
            <?php elseif ($status === 'grace_period'): ?>
            <div class="dms-countdown dms-grace">
                <div class="dms-countdown-value" id="dms-countdown-value">GRACE PERIOD</div>
                <div class="dms-countdown-label">Actions will execute when grace period ends</div>
            </div>
            <?php elseif ($status === 'paused'): ?>
            <div class="dms-countdown dms-paused">
                <div class="dms-countdown-value">PAUSED</div>
                <div class="dms-countdown-label">Paused until <?= $state['pause_expires'] ? date('M j, Y g:i A', strtotime($state['pause_expires'])) : 'manually resumed' ?></div>
            </div>
            <?php endif; ?>

            <?php if ($state['armed'] && !$state['triggered']): ?>
            <button id="dms-checkin-btn" class="dms-btn-checkin" onclick="dmsCheckIn()">
                CHECK IN
            </button>
            <?php endif; ?>

            <div class="dms-info-grid">
                <div class="dms-info-item">
                    <span class="dms-info-label">Last Check-in:</span>
                    <span class="dms-info-value" id="dms-last-checkin">
                        <?= $state['last_checkin'] ? date('M j, Y g:i A', strtotime($state['last_checkin'])) : 'Never' ?>
                    </span>
                </div>
                <div class="dms-info-item">
                    <span class="dms-info-label">Check-in Method:</span>
                    <span class="dms-info-value"><?= $state['last_checkin_method'] ?? 'N/A' ?></span>
                </div>
                <div class="dms-info-item">
                    <span class="dms-info-label">Deadline:</span>
                    <span class="dms-info-value"><?= $deadline ? date('M j, Y g:i A', $deadline) : 'N/A' ?></span>
                </div>
                <div class="dms-info-item">
                    <span class="dms-info-label">Interval:</span>
                    <span class="dms-info-value"><?= $config['checkin_interval_days'] ?> days</span>
                </div>
                <div class="dms-info-item">
                    <span class="dms-info-label">Dry Run:</span>
                    <span class="dms-info-value"><?= $config['dry_run'] ? '<span style="color:#ff9800">ENABLED</span>' : '<span style="color:#4caf50">OFF</span>' ?></span>
                </div>
                <div class="dms-info-item">
                    <span class="dms-info-label">Configured Actions:</span>
                    <span class="dms-info-value"><?= count($config['actions']['deletions']) + count($config['actions']['scripts']) ?></span>
                </div>
            </div>

            <div class="dms-controls">
                <?php if ($state['armed']): ?>
                    <button class="dms-btn dms-btn-danger" onclick="dmsDisarm()">DISARM</button>
                    <?php if (!$state['paused']): ?>
                        <button class="dms-btn dms-btn-info" onclick="dmsPause()">PAUSE</button>
                    <?php else: ?>
                        <button class="dms-btn dms-btn-info" onclick="dmsUnpause()">UNPAUSE</button>
                    <?php endif; ?>
                <?php else: ?>
                    <button class="dms-btn dms-btn-arm" onclick="dmsArm()">ARM SWITCH</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Check-in History -->
        <div class="dms-section">
            <h3>Recent Check-ins</h3>
            <table class="dms-table">
                <thead>
                    <tr><th>Time</th><th>Method</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($state['checkin_history'])): ?>
                        <tr><td colspan="2" style="text-align:center;color:#888">No check-ins recorded</td></tr>
                    <?php else: ?>
                        <?php foreach ($state['checkin_history'] as $entry): ?>
                        <tr>
                            <td><?= date('M j, Y g:i A', strtotime($entry['time'])) ?></td>
                            <td><?= htmlspecialchars($entry['method']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- NOTIFICATIONS TAB -->
    <div id="dms-tab-notifications" class="dms-tab-content">
        <div class="dms-section">
            <h3>Warning Thresholds</h3>
            <p class="dms-help">Percentage of check-in interval elapsed before each warning level triggers.</p>
            <div class="dms-form-grid">
                <label>Reminder (%):
                    <input type="number" id="cfg-thresh-reminder" value="<?= $config['warning_thresholds']['reminder'] ?>" min="1" max="100" class="dms-input dms-input-sm">
                </label>
                <label>Warning (%):
                    <input type="number" id="cfg-thresh-warning" value="<?= $config['warning_thresholds']['warning'] ?>" min="1" max="100" class="dms-input dms-input-sm">
                </label>
                <label>Critical (%):
                    <input type="number" id="cfg-thresh-critical" value="<?= $config['warning_thresholds']['critical'] ?>" min="1" max="100" class="dms-input dms-input-sm">
                </label>
                <label>Last Chance (%):
                    <input type="number" id="cfg-thresh-last_chance" value="<?= $config['warning_thresholds']['last_chance'] ?>" min="1" max="100" class="dms-input dms-input-sm">
                </label>
            </div>
        </div>

        <div class="dms-section">
            <h3>Message Template</h3>
            <p class="dms-help">Variables: {{status}}, {{days_remaining}}, {{hours_remaining}}, {{checkin_link}}, {{last_checkin}}</p>
            <textarea id="cfg-message-template" class="dms-textarea" rows="4"><?= htmlspecialchars($config['message_template']) ?></textarea>
        </div>

        <!-- Discord -->
        <div class="dms-section">
            <h3>Discord</h3>
            <div class="dms-webhook-config">
                <label class="dms-toggle">
                    <input type="checkbox" id="cfg-wh-discord-enabled" <?= $config['webhooks']['discord']['enabled'] ? 'checked' : '' ?>>
                    <span>Enable Discord notifications</span>
                </label>
                <label>Webhook URL:
                    <input type="url" id="cfg-wh-discord-url" value="<?= htmlspecialchars($config['webhooks']['discord']['url']) ?>" class="dms-input" placeholder="https://discord.com/api/webhooks/...">
                </label>
                <button class="dms-btn dms-btn-sm" onclick="dmsTestWebhook('discord')">Test</button>
            </div>
        </div>

        <!-- Custom Webhook -->
        <div class="dms-section">
            <h3>Custom Webhook</h3>
            <div class="dms-webhook-config">
                <label class="dms-toggle">
                    <input type="checkbox" id="cfg-wh-custom-enabled" <?= $config['webhooks']['custom']['enabled'] ? 'checked' : '' ?>>
                    <span>Enable Custom webhook</span>
                </label>
                <label>URL:
                    <input type="url" id="cfg-wh-custom-url" value="<?= htmlspecialchars($config['webhooks']['custom']['url']) ?>" class="dms-input">
                </label>
                <label>Method:
                    <select id="cfg-wh-custom-method" class="dms-input dms-input-sm">
                        <option value="POST" <?= ($config['webhooks']['custom']['method'] ?? 'POST') === 'POST' ? 'selected' : '' ?>>POST</option>
                        <option value="GET" <?= ($config['webhooks']['custom']['method'] ?? 'POST') === 'GET' ? 'selected' : '' ?>>GET</option>
                    </select>
                </label>
                <label>Body Template (JSON):
                    <textarea id="cfg-wh-custom-body_template" class="dms-textarea" rows="3"><?= htmlspecialchars($config['webhooks']['custom']['body_template']) ?></textarea>
                </label>
                <button class="dms-btn dms-btn-sm" onclick="dmsTestWebhook('custom')">Test</button>
            </div>
        </div>

        <!-- Uptime Kuma -->
        <div class="dms-section">
            <h3>Uptime Kuma</h3>
            <p class="dms-help">Push heartbeat to Uptime Kuma on every cron cycle. Reports "down" when fewer than the configured warning days remain.</p>
            <div class="dms-webhook-config">
                <label class="dms-toggle">
                    <input type="checkbox" id="cfg-uk-enabled" <?= $config['uptime_kuma']['enabled'] ? 'checked' : '' ?>>
                    <span>Enable Uptime Kuma push monitor</span>
                </label>
                <label>Push URL:
                    <input type="url" id="cfg-uk-push-url" value="<?= htmlspecialchars($config['uptime_kuma']['push_url']) ?>" class="dms-input" placeholder="https://uptime-kuma.example.com/api/push/xxxxxxxxxx">
                </label>
                <label>Warning Threshold (days):
                    <input type="number" id="cfg-uk-warning-days" value="<?= $config['uptime_kuma']['warning_days'] ?>" min="1" max="365" class="dms-input dms-input-sm">
                </label>
                <p class="dms-help">When fewer than this many days remain, the heartbeat reports "down" so Uptime Kuma alerts you.</p>
            </div>
        </div>

        <button class="dms-btn dms-btn-primary" onclick="dmsSaveNotifications()">Save Notification Settings</button>
    </div>

    <!-- ACTIONS TAB -->
    <div id="dms-tab-actions" class="dms-tab-content">
        <div class="dms-section">
            <h3>File/Directory Deletion</h3>
            <p class="dms-help">Paths to delete when the switch triggers. Supports glob patterns.</p>
            <div id="dms-deletions-list">
                <?php foreach ($config['actions']['deletions'] as $i => $deletion): ?>
                <div class="dms-action-item" data-index="<?= $i ?>">
                    <input type="text" class="dms-input dms-deletion-path" value="<?= htmlspecialchars($deletion['path']) ?>" placeholder="/mnt/user/share/path">
                    <select class="dms-input dms-input-sm dms-deletion-method">
                        <option value="standard" <?= ($deletion['method'] ?? 'standard') === 'standard' ? 'selected' : '' ?>>Standard (rm -rf)</option>
                        <option value="secure" <?= ($deletion['method'] ?? 'standard') === 'secure' ? 'selected' : '' ?>>Secure (shred + rm)</option>
                    </select>
                    <button class="dms-btn dms-btn-danger dms-btn-sm" onclick="dmsRemoveDeletion(this)">Remove</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button class="dms-btn dms-btn-sm" onclick="dmsAddDeletion()">+ Add Path</button>
        </div>

        <div class="dms-section">
            <h3>Custom Scripts</h3>
            <p class="dms-help">Bash scripts to execute in order when triggered.</p>
            <div id="dms-scripts-list">
                <?php foreach ($config['actions']['scripts'] as $i => $script): ?>
                <div class="dms-action-item" data-index="<?= $i ?>">
                    <input type="text" class="dms-input dms-script-path" value="<?= htmlspecialchars($script['path']) ?>" placeholder="/boot/config/plugins/deadman-switch/my-script.sh">
                    <label>Timeout (s):
                        <input type="number" class="dms-input dms-input-sm dms-script-timeout" value="<?= $script['timeout'] ?? 300 ?>" min="10" max="3600">
                    </label>
                    <button class="dms-btn dms-btn-danger dms-btn-sm" onclick="dmsRemoveScript(this)">Remove</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button class="dms-btn dms-btn-sm" onclick="dmsAddScript()">+ Add Script</button>
        </div>

        <div class="dms-section">
            <h3>Docker Containers</h3>
            <p class="dms-help">Containers to stop/remove when triggered.</p>

            <h4>Stop Containers</h4>
            <div id="dms-docker-stop">
                <?php foreach ($docker_containers as $container): ?>
                <label class="dms-toggle">
                    <input type="checkbox" class="dms-docker-stop-cb" value="<?= htmlspecialchars($container) ?>"
                        <?= in_array($container, $config['actions']['docker']['stop']) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($container) ?></span>
                </label>
                <?php endforeach; ?>
                <?php if (empty($docker_containers)): ?>
                <p class="dms-muted">No Docker containers found.</p>
                <?php endif; ?>
            </div>

            <h4>Remove Containers</h4>
            <div id="dms-docker-remove">
                <?php foreach ($docker_containers as $container): ?>
                <label class="dms-toggle">
                    <input type="checkbox" class="dms-docker-remove-cb" value="<?= htmlspecialchars($container) ?>"
                        <?= in_array($container, $config['actions']['docker']['remove']) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($container) ?></span>
                </label>
                <?php endforeach; ?>
                <?php if (empty($docker_containers)): ?>
                <p class="dms-muted">No Docker containers found.</p>
                <?php endif; ?>
            </div>

            <h4>Delete Volumes</h4>
            <div id="dms-docker-volumes">
                <?php foreach ($docker_volumes as $volume): ?>
                <label class="dms-toggle">
                    <input type="checkbox" class="dms-docker-volume-cb" value="<?= htmlspecialchars($volume) ?>"
                        <?= in_array($volume, $config['actions']['docker']['volumes']) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($volume) ?></span>
                </label>
                <?php endforeach; ?>
                <?php if (empty($docker_volumes)): ?>
                <p class="dms-muted">No Docker volumes found.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="dms-actions-footer">
            <button class="dms-btn dms-btn-primary" onclick="dmsSaveActions()">Save Actions</button>
            <button class="dms-btn dms-btn-warning" onclick="dmsDryRun()">Run Dry Test</button>
        </div>

        <div id="dms-dry-run-results" class="dms-section" style="display:none">
            <h3>Dry Run Results</h3>
            <pre id="dms-dry-run-output"></pre>
        </div>
    </div>

    <!-- SETTINGS TAB -->
    <div id="dms-tab-settings" class="dms-tab-content">
        <div class="dms-section">
            <h3>General Settings</h3>
            <div class="dms-form-grid">
                <label>Check-in Interval (days):
                    <input type="number" id="cfg-interval" value="<?= $config['checkin_interval_days'] ?>" min="1" max="365" class="dms-input dms-input-sm">
                </label>
                <label>Grace Period (hours):
                    <input type="number" id="cfg-grace" value="<?= $config['grace_period_hours'] ?>" min="1" max="720" class="dms-input dms-input-sm">
                </label>
                <label>External URL:
                    <input type="url" id="cfg-external-url" value="<?= htmlspecialchars($config['external_url']) ?>" class="dms-input" placeholder="http://192.168.1.50 or https://unraid.mydomain.com">
                </label>
                <label>Cron Check Interval (minutes):
                    <input type="number" id="cfg-cron-interval" value="<?= $config['cron_interval_minutes'] ?>" min="5" max="1440" class="dms-input dms-input-sm">
                </label>
                <label>Max Pause Duration (hours):
                    <input type="number" id="cfg-pause-max" value="<?= $config['pause_max_hours'] ?>" min="1" max="8760" class="dms-input dms-input-sm">
                </label>
            </div>
        </div>

        <div class="dms-section">
            <h3>Safety Options</h3>
            <label class="dms-toggle">
                <input type="checkbox" id="cfg-dry-run" <?= $config['dry_run'] ? 'checked' : '' ?>>
                <span>Dry Run Mode (log actions without executing)</span>
            </label>
            <label class="dms-toggle">
                <input type="checkbox" id="cfg-double-miss" <?= $config['double_miss'] ? 'checked' : '' ?>>
                <span>Require two consecutive missed check-ins before triggering</span>
            </label>
        </div>

        <div class="dms-section">
            <h3>API Key</h3>
            <div class="dms-api-key-section">
                <code id="dms-api-key-display"><?= $config['api_key'] ? htmlspecialchars($config['api_key']) : 'No API key generated' ?></code>
                <button class="dms-btn dms-btn-sm" onclick="dmsGenerateApiKey()">Generate New Key</button>
            </div>
            <?php if ($config['api_key']): ?>
            <p class="dms-help" style="margin-top:10px">
                To check in remotely, send a GET request to the check-in URL from any device, script, or automation (e.g. <code>curl "URL"</code>).
            </p>
            <p class="dms-help">
                Check-in URL: <code><?= htmlspecialchars($config['external_url']) ?>/plugins/deadman-switch/include/api.php?action=checkin&amp;key=<?= htmlspecialchars($config['api_key']) ?></code>
            </p>
            <p class="dms-help">
                Status URL: <code><?= htmlspecialchars($config['external_url']) ?>/plugins/deadman-switch/include/api.php?action=status&amp;key=<?= htmlspecialchars($config['api_key']) ?></code>
            </p>
            <?php endif; ?>
        </div>

        <button class="dms-btn dms-btn-primary" onclick="dmsSaveSettings()">Save Settings</button>

        <p class="dms-help" style="margin-top:20px;text-align:right;color:#666">Dead Man's Switch v<?= DMS_VERSION ?></p>
    </div>

    <!-- LOGS TAB -->
    <div id="dms-tab-logs" class="dms-tab-content">
        <div class="dms-section">
            <div class="dms-log-controls">
                <input type="text" id="dms-log-filter" placeholder="Filter logs..." class="dms-input" style="max-width:300px">
                <button class="dms-btn dms-btn-sm" onclick="dmsLoadLogs()">Refresh</button>
                <button class="dms-btn dms-btn-danger dms-btn-sm" onclick="dmsClearLogs()">Clear Logs</button>
            </div>
            <pre id="dms-log-output" class="dms-log-viewer">Loading...</pre>
        </div>
    </div>
</div>

<script>
    // Pass PHP state to JS
    var dmsState = {
        armed: <?= json_encode($state['armed']) ?>,
        status: <?= json_encode($status) ?>,
        deadline: <?= $deadline ? json_encode($deadline) : 'null' ?>,
        graceEnd: <?= $grace_end ? json_encode($grace_end) : 'null' ?>,
        remaining: <?= $remaining !== null ? json_encode($remaining) : 'null' ?>,
        paused: <?= json_encode($state['paused']) ?>,
        csrfToken: <?= json_encode($var['csrf_token'] ?? '') ?>
    };
</script>
<script src="<?autov('/plugins/deadman-switch/assets/deadman.js')?>"></script>
