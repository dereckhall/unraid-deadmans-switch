<?php
// Dead Man's Switch - Shared PHP Functions

define('DMS_CONFIG_DIR', '/boot/config/plugins/deadman-switch');
define('DMS_CONFIG_FILE', DMS_CONFIG_DIR . '/config.json');
define('DMS_STATE_FILE', DMS_CONFIG_DIR . '/state.json');
define('DMS_LOG_DIR', DMS_CONFIG_DIR . '/logs');
define('DMS_LOG_FILE', DMS_LOG_DIR . '/deadman.log');
define('DMS_PLUGIN_DIR', '/usr/local/emhttp/plugins/deadman-switch');
define('DMS_VERSION', (function() {
    $plg = '/boot/config/plugins/deadman-switch.plg';
    if (file_exists($plg) && preg_match('/version="([^"]+)"/', @file_get_contents($plg, false, null, 0, 512), $m)) {
        return $m[1];
    }
    return 'unknown';
})());

function dms_get_api_base() {
    $nginx = @parse_ini_file('/var/local/emhttp/nginx.ini');
    $host = $nginx['NGINX_LANIP'] ?? $nginx['NGINX_LANMDNS'] ?? 'localhost';
    return "http://$host:3801";
}

function dms_ensure_dirs() {
    $dirs = [DMS_CONFIG_DIR, DMS_LOG_DIR];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }
}

function dms_load_config() {
    dms_ensure_dirs();
    $defaults = [
        'checkin_interval_days'       => 30,
        'grace_period_hours'          => 48,
        'external_url'                => '',
        'api_key'                     => '',
        'dry_run'                     => true,
        'double_miss'                 => false,
        'has_completed_dry_run'       => false,
        'cron_interval_minutes'       => 60,
        'warning_thresholds'          => [
            'reminder'    => 50,
            'warning'     => 75,
            'critical'    => 90,
            'last_chance'  => 95,
        ],
        'webhooks'                    => [
            'discord'   => ['enabled' => false, 'url' => ''],
            'custom'    => ['enabled' => false, 'url' => '', 'method' => 'POST', 'body_template' => ''],
        ],
        'uptime_kuma'                 => [
            'enabled'       => false,
            'push_url'      => '',
            'warning_days'  => 7,
        ],
        'message_template'            => "Dead Man's Switch: {{status}}\nTime remaining: {{days_remaining}} days ({{hours_remaining}} hours)\nLast check-in: {{last_checkin}}\nCheck in now: {{checkin_link}}",
        'actions'                     => [
            'deletions' => [],
            'scripts'   => [],
            'docker'    => ['stop' => [], 'remove' => [], 'volumes' => []],
        ],
        'pause_max_hours'             => 720,
    ];

    if (!file_exists(DMS_CONFIG_FILE)) {
        dms_save_config($defaults);
        return $defaults;
    }

    $config = json_decode(file_get_contents(DMS_CONFIG_FILE), true);
    if (!is_array($config)) {
        return $defaults;
    }

    return array_replace_recursive($defaults, $config);
}

function dms_save_config($config) {
    dms_ensure_dirs();
    file_put_contents(DMS_CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function dms_load_state() {
    dms_ensure_dirs();
    $defaults = [
        'armed'                    => false,
        'last_checkin'             => null,
        'last_checkin_method'      => null,
        'checkin_history'          => [],
        'triggered'                => false,
        'trigger_time'             => null,
        'grace_period_start'       => null,
        'paused'                   => false,
        'pause_start'              => null,
        'pause_expires'            => null,
        'missed_count'             => 0,
        'last_notification_level'  => null,
        'quick_checkin_tokens'     => [],
    ];

    if (!file_exists(DMS_STATE_FILE)) {
        dms_save_state($defaults);
        return $defaults;
    }

    $state = json_decode(file_get_contents(DMS_STATE_FILE), true);
    if (!is_array($state)) {
        return $defaults;
    }

    return array_replace_recursive($defaults, $state);
}

function dms_save_state($state) {
    dms_ensure_dirs();
    file_put_contents(DMS_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function dms_log($message, $level = 'INFO') {
    dms_ensure_dirs();
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$level] $message\n";
    file_put_contents(DMS_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function dms_get_deadline($config, $state) {
    if (!$state['last_checkin']) return null;
    $interval_seconds = $config['checkin_interval_days'] * 86400;
    return strtotime($state['last_checkin']) + $interval_seconds;
}

function dms_get_grace_end($config, $state) {
    $deadline = dms_get_deadline($config, $state);
    if (!$deadline) return null;
    return $deadline + ($config['grace_period_hours'] * 3600);
}

function dms_time_remaining($config, $state) {
    $deadline = dms_get_deadline($config, $state);
    if (!$deadline) return null;
    return $deadline - time();
}

function dms_get_warning_level($config, $state) {
    if (!$state['armed'] || !$state['last_checkin'] || $state['paused']) {
        return 'none';
    }
    if ($state['triggered']) {
        return 'triggered';
    }

    $remaining = dms_time_remaining($config, $state);
    $total = $config['checkin_interval_days'] * 86400;

    if ($remaining <= 0) {
        $grace_end = dms_get_grace_end($config, $state);
        if (time() >= $grace_end) {
            return 'triggered';
        }
        return 'grace';
    }

    $elapsed_pct = (($total - $remaining) / $total) * 100;
    $thresholds = $config['warning_thresholds'];

    if ($elapsed_pct >= $thresholds['last_chance']) return 'last_chance';
    if ($elapsed_pct >= $thresholds['critical']) return 'critical';
    if ($elapsed_pct >= $thresholds['warning']) return 'warning';
    if ($elapsed_pct >= $thresholds['reminder']) return 'reminder';

    return 'none';
}

function dms_get_status($config, $state) {
    if ($state['triggered']) return 'triggered';
    if (!$state['armed']) return 'disarmed';
    if ($state['paused']) return 'paused';

    $level = dms_get_warning_level($config, $state);
    if ($level === 'grace') return 'grace_period';
    if ($level === 'triggered') return 'triggered';
    if (in_array($level, ['critical', 'last_chance'])) return 'armed_critical';
    if ($level === 'warning') return 'armed_warning';
    return 'armed_ok';
}

function dms_generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

function dms_generate_api_key() {
    return 'dms_' . bin2hex(random_bytes(20));
}

function dms_create_quick_checkin_token($state) {
    $token = dms_generate_token(16);
    $state['quick_checkin_tokens'][$token] = [
        'created' => date('c'),
        'expires' => date('c', time() + 86400),
        'used'    => false,
    ];
    // Prune expired tokens
    foreach ($state['quick_checkin_tokens'] as $t => $info) {
        if (strtotime($info['expires']) < time()) {
            unset($state['quick_checkin_tokens'][$t]);
        }
    }
    dms_save_state($state);
    return $token;
}

function dms_validate_quick_checkin_token($token, $state) {
    if (!isset($state['quick_checkin_tokens'][$token])) return false;
    $info = $state['quick_checkin_tokens'][$token];
    if ($info['used']) return false;
    if (strtotime($info['expires']) < time()) return false;
    return true;
}

function dms_use_quick_checkin_token($token, &$state) {
    if (!dms_validate_quick_checkin_token($token, $state)) return false;
    $state['quick_checkin_tokens'][$token]['used'] = true;
    dms_save_state($state);
    return true;
}

function dms_do_checkin($method = 'web') {
    $state = dms_load_state();
    $now = date('c');

    $state['last_checkin'] = $now;
    $state['last_checkin_method'] = $method;
    $state['missed_count'] = 0;
    $state['last_notification_level'] = null;
    $state['grace_period_start'] = null;

    // Add to history (keep last 10)
    array_unshift($state['checkin_history'], [
        'time'   => $now,
        'method' => $method,
    ]);
    $state['checkin_history'] = array_slice($state['checkin_history'], 0, 10);

    dms_save_state($state);
    dms_log("Check-in recorded via $method");
    return true;
}

function dms_format_time_remaining($seconds) {
    if ($seconds <= 0) return 'Expired';
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);

    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($days == 0 && $minutes > 0) $parts[] = "{$minutes}m";

    return implode(' ', $parts);
}

function dms_render_template($template, $config, $state) {
    $remaining = dms_time_remaining($config, $state);
    $days = $remaining ? max(0, round($remaining / 86400, 1)) : 0;
    $hours = $remaining ? max(0, round($remaining / 3600)) : 0;
    $status = dms_get_status($config, $state);

    $api_base = dms_get_api_base();
    $token = dms_create_quick_checkin_token($state);
    $checkin_link = "$api_base/?action=quickcheckin&token=$token";

    $vars = [
        '{{status}}'          => strtoupper(str_replace('_', ' ', $status)),
        '{{days_remaining}}'  => $days,
        '{{hours_remaining}}' => $hours,
        '{{checkin_link}}'    => $checkin_link,
        '{{last_checkin}}'    => $state['last_checkin'] ?? 'Never',
    ];

    return str_replace(array_keys($vars), array_values($vars), $template);
}

function dms_get_log_entries($limit = 100, $filter = null) {
    if (!file_exists(DMS_LOG_FILE)) return [];

    $lines = file(DMS_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_reverse($lines);

    if ($filter) {
        $lines = array_filter($lines, function($line) use ($filter) {
            return stripos($line, $filter) !== false;
        });
    }

    return array_slice($lines, 0, $limit);
}

function dms_send_unraid_notification($subject, $description, $importance = 'normal', $message = '') {
    $notify = '/usr/local/emhttp/webGui/scripts/notify';
    if (!file_exists($notify)) return;

    $cmd = $notify .
        ' -e ' . escapeshellarg("Dead Man's Switch") .
        ' -s ' . escapeshellarg($subject) .
        ' -d ' . escapeshellarg($description) .
        ' -i ' . escapeshellarg($importance) .
        ' -l ' . escapeshellarg('/Settings/deadman-switch');

    if ($message) {
        $cmd .= ' -m ' . escapeshellarg($message);
    }

    exec($cmd);
}

function dms_build_discord_embed($config, $state) {
    $status = dms_get_status($config, $state);
    $warning_level = dms_get_warning_level($config, $state);
    $remaining = dms_time_remaining($config, $state);
    $remaining_text = $remaining !== null ? dms_format_time_remaining($remaining) : 'N/A';

    // Severity tiers: green (ok), orange (warning), red (critical/grace/triggered)
    $severity = [
        'armed_ok'       => 'green',
        'armed_warning'  => 'orange',
        'armed_critical' => 'red',
        'grace_period'   => 'red',
        'triggered'      => 'red',
        'paused'         => 'green',
        'disarmed'       => 'green',
    ];
    $tier = $severity[$status] ?? 'orange';

    $tier_colors = ['green' => 0x2ECC71, 'orange' => 0xFF9800, 'red' => 0xE74C3C];
    $color = $tier_colors[$tier];

    // Emoji matches the sidebar color
    $tier_emoji = [
        'green'  => "\xE2\x9C\x85",  // ✅
        'orange' => "\xE2\x9A\xA0\xEF\xB8\x8F", // ⚠️
        'red'    => "\xE2\x9D\x8C",  // ❌
    ];
    $e = $tier_emoji[$tier];

    $headlines = [
        'armed_ok'       => "$e Dead Man's Switch is OK. $e",
        'armed_warning'  => "$e Check-in deadline is approaching. $e",
        'armed_critical' => "$e Check-in urgently needed! $e",
        'grace_period'   => "$e Deadline missed. Grace period active. $e",
        'triggered'      => "$e Dead Man's Switch has been TRIGGERED. $e",
        'paused'         => "$e Dead Man's Switch is paused. $e",
        'disarmed'       => "$e Dead Man's Switch is disarmed. $e",
    ];
    $headline = $headlines[$status] ?? "$e Dead Man's Switch";

    $fields = [];

    if ($remaining !== null && $remaining > 0) {
        $fields[] = ['name' => 'Time Remaining', 'value' => $remaining_text, 'inline' => false];
    }

    $deadline = dms_get_deadline($config, $state);
    if ($deadline) {
        $fields[] = ['name' => 'Deadline', 'value' => date('l, F j, Y \a\t g:i A', $deadline), 'inline' => false];
    }

    if ($state['last_checkin']) {
        $fields[] = ['name' => 'Last Check-in', 'value' => date('l, F j, Y \a\t g:i A', strtotime($state['last_checkin'])), 'inline' => false];
    }

    if ($status === 'grace_period') {
        $grace_end = dms_get_grace_end($config, $state);
        if ($grace_end) {
            $fields[] = ['name' => 'Actions Execute At', 'value' => date('l, F j, Y \a\t g:i A', $grace_end), 'inline' => false];
        }
    }

    if ($state['armed'] && !$state['triggered']) {
        $api_base = dms_get_api_base();
        $token = dms_create_quick_checkin_token($state);
        $checkin_link = "$api_base/?action=quickcheckin&token=$token";
        $fields[] = ['name' => 'Check In', 'value' => "[Click here to check in]($checkin_link)", 'inline' => false];
    }

    $hostname = gethostname() ?: 'Unraid';

    return [
        'author'      => ['name' => $hostname],
        'description' => $headline,
        'color'       => $color,
        'fields'      => $fields,
        'timestamp'   => date('c'),
    ];
}

function dms_send_webhook($type, $webhook_config, $message, $config = null, $state = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    switch ($type) {
        case 'discord':
            curl_setopt($ch, CURLOPT_URL, $webhook_config['url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            // Use rich embed if config/state available, fall back to plain text
            if ($config && $state) {
                $payload = ['embeds' => [dms_build_discord_embed($config, $state)]];
            } else {
                $payload = ['content' => $message];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            break;

        case 'custom':
            curl_setopt($ch, CURLOPT_URL, $webhook_config['url']);
            $body = $webhook_config['body_template'] ?: $message;
            if ($webhook_config['method'] === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            break;

        default:
            curl_close($ch);
            return ['success' => false, 'message' => "Unknown webhook type: $type"];
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'message' => "cURL error: $error"];
    }
    if ($http_code >= 400) {
        return ['success' => false, 'message' => "HTTP $http_code: $response"];
    }

    return ['success' => true, 'message' => 'Webhook sent successfully'];
}

function dms_test_webhook($config, $type) {
    $webhooks = $config['webhooks'];
    if (!isset($webhooks[$type]) || !$webhooks[$type]['enabled']) {
        return ['success' => false, 'message' => "Webhook '$type' is not enabled"];
    }

    $state = dms_load_state();
    $message = "Dead Man's Switch test notification. If you see this, your webhook is configured correctly.";

    $result = dms_send_webhook($type, $webhooks[$type], $message, $config, $state);
    dms_log("Test webhook sent: $type - " . ($result['success'] ? 'OK' : 'FAILED: ' . $result['message']));
    return $result;
}

function dms_send_uptime_kuma_heartbeat($config, $state) {
    $uk = $config['uptime_kuma'];
    if (!$uk['enabled'] || empty($uk['push_url'])) return;

    $remaining = dms_time_remaining($config, $state);
    $days_left = $remaining !== null ? round($remaining / 86400, 1) : null;
    $warning_days = $uk['warning_days'] ?? 7;
    $status = dms_get_status($config, $state);

    // Determine up/down status for Uptime Kuma
    if (!$state['armed']) {
        $uk_status = 'up';
        $msg = 'Disarmed';
    } elseif ($state['paused']) {
        $uk_status = 'up';
        $msg = 'Paused';
    } elseif ($state['triggered']) {
        $uk_status = 'down';
        $msg = 'TRIGGERED';
    } elseif ($remaining !== null && $remaining <= 0) {
        $uk_status = 'down';
        $msg = 'Grace period active';
    } elseif ($days_left !== null && $days_left <= $warning_days) {
        $uk_status = 'down';
        $msg = "{$days_left} days remaining - check in needed";
    } else {
        $uk_status = 'up';
        $msg = $days_left !== null ? "{$days_left} days remaining" : 'OK';
    }

    // Replace status/msg/ping values in the pasted Uptime Kuma push URL
    $url = $uk['push_url'];
    $ping_val = $days_left !== null ? round($days_left * 24 * 60) : '';
    $url = preg_replace('/([?&])status=[^&]*/', '${1}status=' . $uk_status, $url);
    $url = preg_replace('/([?&])msg=[^&]*/', '${1}msg=' . urlencode($msg), $url);
    $url = preg_replace('/([?&])ping=[^&]*/', '${1}ping=' . urlencode($ping_val), $url);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        dms_log("Uptime Kuma heartbeat failed: $error", 'WARN');
    }
}

function dms_update_cron($minutes = 60) {
    $minutes = max(5, min(1440, intval($minutes)));
    $cron_file = DMS_CONFIG_DIR . '/deadman-switch.cron';
    $script = DMS_PLUGIN_DIR . '/scripts/check.sh';
    $log = DMS_CONFIG_DIR . '/logs/cron.log';

    if ($minutes == 60) {
        $schedule = "0 * * * *";
    } else {
        $schedule = "*/$minutes * * * *";
    }

    file_put_contents($cron_file, "$schedule $script >> $log 2>&1\n", LOCK_EX);
    exec('/usr/local/sbin/update_cron');
    dms_log("Cron schedule updated to every $minutes minutes");
}

function dms_execute_dry_run($config) {
    $results = [];

    foreach ($config['actions']['deletions'] as $i => $deletion) {
        $path = $deletion['path'];
        $method = $deletion['method'] ?? 'standard';
        $exists = file_exists($path) || glob($path);
        $results[] = [
            'type'   => 'deletion',
            'path'   => $path,
            'method' => $method,
            'exists' => (bool)$exists,
            'action' => "Would delete: $path (method: $method)",
        ];
    }

    foreach ($config['actions']['scripts'] as $i => $script) {
        $exists = file_exists($script['path']);
        $executable = $exists && is_executable($script['path']);
        $results[] = [
            'type'       => 'script',
            'path'       => $script['path'],
            'exists'     => $exists,
            'executable' => $executable,
            'action'     => "Would execute: {$script['path']} (timeout: " . ($script['timeout'] ?? 300) . "s)",
        ];
    }

    if (!empty($config['actions']['docker']['stop'])) {
        $results[] = [
            'type'       => 'docker_stop',
            'containers' => $config['actions']['docker']['stop'],
            'action'     => 'Would stop containers: ' . implode(', ', $config['actions']['docker']['stop']),
        ];
    }
    if (!empty($config['actions']['docker']['remove'])) {
        $results[] = [
            'type'       => 'docker_remove',
            'containers' => $config['actions']['docker']['remove'],
            'action'     => 'Would remove containers: ' . implode(', ', $config['actions']['docker']['remove']),
        ];
    }
    if (!empty($config['actions']['docker']['volumes'])) {
        $results[] = [
            'type'    => 'docker_volumes',
            'volumes' => $config['actions']['docker']['volumes'],
            'action'  => 'Would delete volumes: ' . implode(', ', $config['actions']['docker']['volumes']),
        ];
    }

    dms_log("Dry run completed - " . count($results) . " actions evaluated", 'INFO');
    return $results;
}
