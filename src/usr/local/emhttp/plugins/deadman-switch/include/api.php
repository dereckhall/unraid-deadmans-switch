<?php
// Dead Man's Switch - API Endpoint Handler

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

// Unraid's auto_prepend_file handles CSRF validation for all POST requests.
// It requires csrf_token in $_POST and calls exit() if invalid.
// By the time we reach this code, CSRF is already validated for POST requests.

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$key = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? '');
$token = $_GET['token'] ?? '';

// Unraid's CSRF check only covers POST, so mutating actions must not be
// reachable via GET (a bare link could otherwise disarm the switch)
$mutating_actions = ['arm', 'disarm', 'pause', 'unpause', 'web_checkin',
                     'save_config', 'generate_api_key', 'test_webhook',
                     'dry_run', 'clear_logs'];
if (in_array($action, $mutating_actions) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'This action requires POST']);
    exit;
}

$config = dms_load_config();
$state = dms_load_state();

function dms_valid_api_key($config, $key) {
    return $config['api_key'] && hash_equals((string)$config['api_key'], (string)$key);
}

switch ($action) {
    case 'health':
        echo json_encode(['status' => 'ok', 'version' => DMS_VERSION]);
        break;

    case 'status':
        if (!dms_valid_api_key($config, $key)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            break;
        }
        $status = dms_get_status($config, $state);
        $remaining = dms_time_remaining($config, $state);
        $deadline = dms_get_deadline($config, $state);
        $grace_end = dms_get_grace_end($config, $state);
        $warning_level = dms_get_warning_level($config, $state);

        // Set HTTP status code for simple monitors
        $elapsed_pct = 0;
        if ($remaining !== null) {
            $total = $config['checkin_interval_days'] * 86400;
            $elapsed_pct = (($total - $remaining) / $total) * 100;
        }

        if ($status === 'triggered') {
            http_response_code(410);
        } elseif ($status === 'grace_period') {
            http_response_code(503);
        } elseif (in_array($warning_level, ['critical', 'last_chance'])) {
            http_response_code(429);
        } elseif ($elapsed_pct >= 75) {
            http_response_code(299);
        }
        // else 200

        echo json_encode([
            'status'                                  => $status,
            'armed'                                   => $state['armed'],
            'days_remaining'                          => $remaining !== null ? round($remaining / 86400, 1) : null,
            'hours_remaining'                         => $remaining !== null ? round($remaining / 3600) : null,
            'check_in_interval_days'                  => $config['checkin_interval_days'],
            'last_checkin'                             => $state['last_checkin'],
            'next_deadline'                            => $deadline ? date('c', $deadline) : null,
            'grace_period_ends'                        => ($status === 'grace_period' && $grace_end) ? date('c', $grace_end) : null,
            'warning_level'                            => $warning_level,
            'paused'                                   => $state['paused'],
            'pause_expires'                            => $state['pause_expires'],
            'configured_actions_count'                 => count($config['actions']['deletions']) + count($config['actions']['scripts']),
            'dry_run'                                  => $config['dry_run'],
            'version'                                  => DMS_VERSION,
        ]);
        break;

    case 'checkin':
        if (!dms_valid_api_key($config, $key)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            break;
        }
        if (!$state['armed']) {
            echo json_encode(['success' => false, 'message' => 'Switch is not armed']);
            break;
        }
        dms_do_checkin('api');
        echo json_encode(['success' => true, 'message' => 'Check-in recorded']);
        break;

    case 'quickcheckin':
        if (!$token) {
            http_response_code(400);
            echo json_encode(['error' => 'Token required']);
            break;
        }
        if (!dms_validate_quick_checkin_token($token, $state)) {
            header('Content-Type: text/html; charset=utf-8');
            echo dms_quick_checkin_page('invalid');
            break;
        }
        // Show confirmation page on GET
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['confirm'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo dms_quick_checkin_page('confirm', $token);
            break;
        }
        // Process check-in
        dms_use_quick_checkin_token($token, $state);
        dms_do_checkin('quick_link');
        header('Content-Type: text/html; charset=utf-8');
        echo dms_quick_checkin_page('success');
        break;

    // Internal AJAX actions (called from the plugin UI, protected by Unraid auth)
    case 'web_checkin':
        if (!$state['armed']) {
            echo json_encode(['success' => false, 'message' => 'Switch is not armed']);
            break;
        }
        dms_do_checkin('web');
        echo json_encode(['success' => true, 'message' => 'Check-in recorded']);
        break;

    case 'arm':
        if (!$config['has_completed_dry_run'] && !$config['dry_run']) {
            echo json_encode(['success' => false, 'message' => 'Must complete a dry run before arming']);
            break;
        }
        $now = date('c');
        dms_update_state(function($s) use ($now) {
            $s['armed'] = true;
            $s['triggered'] = false;
            $s['trigger_time'] = null;
            $s['grace_period_start'] = null;
            $s['missed_count'] = 0;
            $s['last_notification_level'] = null;
            $s['last_checkin'] = $now;
            $s['paused'] = false;
            $s['pause_start'] = null;
            $s['pause_expires'] = null;
            array_unshift($s['checkin_history'], ['time' => $now, 'method' => 'arm']);
            $s['checkin_history'] = array_slice($s['checkin_history'], 0, 10);
            return $s;
        });
        dms_log("Switch ARMED");
        echo json_encode(['success' => true]);
        break;

    case 'disarm':
        dms_update_state(function($s) {
            $s['armed'] = false;
            $s['triggered'] = false;
            $s['trigger_time'] = null;
            $s['grace_period_start'] = null;
            $s['missed_count'] = 0;
            $s['last_notification_level'] = null;
            return $s;
        });
        dms_log("Switch DISARMED");
        echo json_encode(['success' => true]);
        break;

    case 'pause':
        $hours = intval($_POST['hours'] ?? 24);
        $max = $config['pause_max_hours'];
        if ($hours > $max) $hours = $max;
        if ($hours < 1) $hours = 1;
        $state = dms_update_state(function($s) use ($hours) {
            $s['paused'] = true;
            $s['pause_start'] = date('c');
            $s['pause_expires'] = date('c', time() + ($hours * 3600));
            return $s;
        });
        if (!is_array($state)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to write state file']);
            break;
        }
        dms_log("Timer PAUSED for $hours hours");
        echo json_encode(['success' => true, 'pause_expires' => $state['pause_expires'] ?? null]);
        break;

    case 'unpause':
        dms_update_state(function($s) {
            // The countdown is frozen while paused: credit the pause duration
            // to last_checkin so the deadline moves out by the time spent paused
            if ($s['paused'] && $s['pause_start'] && $s['last_checkin']) {
                $paused_secs = time() - strtotime($s['pause_start']);
                if ($paused_secs > 0) {
                    $s['last_checkin'] = date('c', min(time(), strtotime($s['last_checkin']) + $paused_secs));
                }
            }
            $s['paused'] = false;
            $s['pause_start'] = null;
            $s['pause_expires'] = null;
            return $s;
        });
        dms_log("Timer UNPAUSED (deadline extended by pause duration)");
        echo json_encode(['success' => true]);
        break;

    case 'save_config':
        // Accept JSON config data via json_data form field
        // (Unraid's CSRF check requires form-encoded POST, so JSON is sent as a field)
        $input = json_decode($_POST['json_data'] ?? '', true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid input']);
            break;
        }
        $old_cron = $config['cron_interval_minutes'] ?? 60;
        $old_interval = $config['checkin_interval_days'];
        $config = dms_update_config(function($c) use ($input) {
            $c = array_replace_recursive($c, $input);
            // List-valued keys must replace wholesale: a recursive merge keys
            // numeric arrays by index, so entries removed in the UI would
            // survive the save (and still be deleted on trigger)
            if (isset($input['actions']['deletions']) && is_array($input['actions']['deletions'])) {
                $c['actions']['deletions'] = array_values($input['actions']['deletions']);
            }
            if (isset($input['actions']['scripts']) && is_array($input['actions']['scripts'])) {
                $c['actions']['scripts'] = array_values($input['actions']['scripts']);
            }
            // Clamp numeric settings server-side: a cleared UI field arrives
            // as null and would otherwise zero the interval, making the
            // deadline equal to the last check-in (instant grace period)
            $c['checkin_interval_days'] = max(1, min(365, intval($c['checkin_interval_days'] ?? 0) ?: 30));
            $c['grace_period_hours']    = max(1, min(720, intval($c['grace_period_hours'] ?? 0) ?: 48));
            $c['cron_interval_minutes'] = max(5, min(1440, intval($c['cron_interval_minutes'] ?? 0) ?: 60));
            $c['pause_max_hours']       = max(1, min(8760, intval($c['pause_max_hours'] ?? 0) ?: 720));
            foreach (['reminder' => 50, 'warning' => 75, 'critical' => 90, 'last_chance' => 95] as $t => $def) {
                $c['warning_thresholds'][$t] = max(1, min(100, intval($c['warning_thresholds'][$t] ?? 0) ?: $def));
            }
            return $c;
        });
        if (!is_array($config)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to write config file (flash full or read-only?)']);
            break;
        }

        // A changed interval shifts the warning thresholds; clear the recorded
        // notification level so a now-lower level isn't suppressed forever
        if (($config['checkin_interval_days'] ?? $old_interval) != $old_interval) {
            dms_update_state(function($s) {
                $s['last_notification_level'] = null;
                return $s;
            });
        }

        // Update cron schedule if interval changed
        $new_cron = $config['cron_interval_minutes'] ?? 60;
        if ($new_cron != $old_cron) {
            dms_update_cron($new_cron);
        }

        dms_log("Configuration updated");
        echo json_encode(['success' => true]);
        break;

    case 'generate_api_key':
        $new_key = dms_generate_api_key();
        dms_update_config(function($c) use ($new_key) {
            $c['api_key'] = $new_key;
            return $c;
        });
        dms_log("New API key generated");
        echo json_encode(['success' => true, 'api_key' => $new_key]);
        break;

    case 'test_webhook':
        $type = $_POST['type'] ?? ($_GET['type'] ?? '');
        $result = dms_test_webhook($config, $type);
        echo json_encode($result);
        break;

    case 'dry_run':
        $result = dms_execute_dry_run($config);
        dms_update_config(function($c) {
            $c['has_completed_dry_run'] = true;
            return $c;
        });
        echo json_encode(['success' => true, 'results' => $result]);
        break;

    case 'get_state':
        // Require API key - this endpoint exposes internal state
        if (!dms_valid_api_key($config, $key)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            break;
        }
        echo json_encode([
            'state'  => $state,
            'status' => dms_get_status($config, $state),
            'warning_level' => dms_get_warning_level($config, $state),
            'time_remaining' => dms_time_remaining($config, $state),
            'deadline' => dms_get_deadline($config, $state) ? date('c', dms_get_deadline($config, $state)) : null,
            'grace_end' => dms_get_grace_end($config, $state) ? date('c', dms_get_grace_end($config, $state)) : null,
        ]);
        break;

    case 'get_logs':
        // Allow with API key or from Unraid UI (Unraid's nginx auth protects UI pages)
        $limit = intval($_GET['limit'] ?? 100);
        $filter = $_GET['filter'] ?? null;
        echo json_encode(['logs' => dms_get_log_entries($limit, $filter)]);
        break;

    case 'clear_logs':
        if (file_exists(DMS_LOG_FILE)) {
            file_put_contents(DMS_LOG_FILE, '');
        }
        dms_log("Logs cleared");
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}

// HTML page rendering functions (used by quickcheckin endpoint)

function dms_quick_checkin_page($status, $token = '') {
    $confirm_url = '';
    if ($token) {
        $confirm_url = "?action=quickcheckin&token=" . htmlspecialchars($token) . "&confirm=1";
    }

    $title = "Dead Man's Switch";
    $body = '';

    switch ($status) {
        case 'confirm':
            $body = <<<HTML
            <div class="dms-card">
                <h1>$title</h1>
                <p>Click below to confirm your check-in.</p>
                <a href="$confirm_url" class="dms-btn dms-btn-checkin">CONFIRM CHECK-IN</a>
            </div>
HTML;
            break;
        case 'success':
            $body = <<<HTML
            <div class="dms-card">
                <h1>$title</h1>
                <div class="dms-success">Check-in successful!</div>
                <p>Your dead man's switch timer has been reset.</p>
            </div>
HTML;
            break;
        case 'invalid':
            $body = <<<HTML
            <div class="dms-card">
                <h1>$title</h1>
                <div class="dms-error">This check-in link is invalid or has expired.</div>
                <p>Please use the Unraid web UI or request a new check-in link.</p>
            </div>
HTML;
            break;
    }

    return dms_standalone_page($body);
}

function dms_standalone_page($body) {
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dead Man's Switch</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1c1b29; color: #e0e0e0; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .dms-card { background: #2b2a3d; border-radius: 12px; padding: 40px; max-width: 480px; width: 100%; text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,0.3); }
        h1 { color: #ff8c00; margin-bottom: 20px; font-size: 1.5em; }
        p { margin: 15px 0; color: #b0b0b0; line-height: 1.5; }
        .dms-btn { display: inline-block; padding: 16px 48px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 1.2em; margin: 20px 0; transition: all 0.2s; }
        .dms-btn-checkin { background: #4caf50; color: white; }
        .dms-btn-checkin:hover { background: #45a049; transform: scale(1.05); }
        .dms-btn-warning { background: #ff9800; color: white; }
        .dms-btn-warning:hover { background: #f57c00; transform: scale(1.05); }
        .dms-success { background: #1b5e20; color: #a5d6a7; padding: 16px; border-radius: 8px; margin: 20px 0; font-size: 1.2em; }
        .dms-error { background: #b71c1c; color: #ef9a9a; padding: 16px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>
    $body
</body>
</html>
HTML;
}
