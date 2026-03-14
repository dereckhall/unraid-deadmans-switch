<?php
// Dead Man's Switch - Shared PHP Functions

define('DMS_CONFIG_DIR', '/boot/config/plugins/deadman-switch');
define('DMS_CONFIG_FILE', DMS_CONFIG_DIR . '/config.json');
define('DMS_STATE_FILE', DMS_CONFIG_DIR . '/state.json');
define('DMS_LOG_DIR', DMS_CONFIG_DIR . '/logs');
define('DMS_LOG_FILE', DMS_LOG_DIR . '/deadman.log');
define('DMS_PLUGIN_DIR', '/usr/local/emhttp/plugins/deadman-switch');
define('DMS_VERSION', '2026.03.14');

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
        'abort_code'                  => '',
        'dry_run'                     => true,
        'double_miss'                 => false,
        'has_completed_dry_run'       => false,
        'cron_interval_minutes'       => 60,
        'monitoring_warning_pct'      => 50,
        'warning_thresholds'          => [
            'reminder'    => 50,
            'warning'     => 75,
            'critical'    => 90,
            'last_chance'  => 95,
        ],
        'webhooks'                    => [
            'discord'   => ['enabled' => false, 'url' => ''],
            'telegram'  => ['enabled' => false, 'token' => '', 'chat_id' => ''],
            'slack'     => ['enabled' => false, 'url' => ''],
            'pushover'  => ['enabled' => false, 'user_key' => '', 'app_token' => ''],
            'gotify'    => ['enabled' => false, 'url' => '', 'token' => ''],
            'ntfy'      => ['enabled' => false, 'url' => '', 'topic' => ''],
            'custom'    => ['enabled' => false, 'url' => '', 'method' => 'POST', 'body_template' => ''],
        ],
        'message_template'            => "Dead Man's Switch: {{status}}\nTime remaining: {{days_remaining}} days ({{hours_remaining}} hours)\nLast check-in: {{last_checkin}}\nCheck in now: {{checkin_link}}",
        'actions'                     => [
            'deletions' => [],
            'scripts'   => [],
            'docker'    => ['stop' => [], 'remove' => [], 'volumes' => []],
        ],
        'trusted_contacts'            => [],
        'max_postponements'           => 3,
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
    file_put_contents(DMS_CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        'trusted_contact_tokens'   => [],
        'postponements_used'       => 0,
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
    file_put_contents(DMS_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

function dms_create_trusted_contact_token($contact_index, $state) {
    $token = dms_generate_token(16);
    $state['trusted_contact_tokens'][$token] = [
        'contact_index' => $contact_index,
        'created'       => date('c'),
        'expires'       => date('c', time() + 86400 * 7),
        'used'          => false,
    ];
    dms_save_state($state);
    return $token;
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

    $external_url = rtrim($config['external_url'], '/');
    $token = dms_create_quick_checkin_token($state);
    $checkin_link = $external_url . "/plugins/deadman-switch/include/api.php?action=quickcheckin&token=$token";

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

function dms_send_webhook($type, $webhook_config, $message) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    switch ($type) {
        case 'discord':
            curl_setopt($ch, CURLOPT_URL, $webhook_config['url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['content' => $message]));
            break;

        case 'telegram':
            $url = "https://api.telegram.org/bot{$webhook_config['token']}/sendMessage";
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'chat_id'    => $webhook_config['chat_id'],
                'text'       => $message,
                'parse_mode' => 'HTML',
            ]));
            break;

        case 'slack':
            curl_setopt($ch, CURLOPT_URL, $webhook_config['url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $message]));
            break;

        case 'pushover':
            curl_setopt($ch, CURLOPT_URL, 'https://api.pushover.net/1/messages.json');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'token'   => $webhook_config['app_token'],
                'user'    => $webhook_config['user_key'],
                'message' => $message,
                'title'   => "Dead Man's Switch",
            ]);
            break;

        case 'gotify':
            $url = rtrim($webhook_config['url'], '/') . '/message?token=' . urlencode($webhook_config['token']);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'title'   => "Dead Man's Switch",
                'message' => $message,
                'priority' => 8,
            ]));
            break;

        case 'ntfy':
            $url = rtrim($webhook_config['url'], '/') . '/' . $webhook_config['topic'];
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Title: Dead Man\'s Switch',
                'Priority: high',
            ]);
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

    $message = "Dead Man's Switch test notification. If you see this, your webhook is configured correctly.";

    $result = dms_send_webhook($type, $webhooks[$type], $message);
    dms_log("Test webhook sent: $type - " . ($result['success'] ? 'OK' : 'FAILED: ' . $result['message']));
    return $result;
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
