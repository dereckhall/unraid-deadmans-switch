<?php
// Dead Man's Switch - API Endpoint Handler

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

// Unraid's auto_prepend_file handles CSRF validation for all POST requests.
// It requires csrf_token in $_POST and calls exit() if invalid.
// By the time we reach this code, CSRF is already validated for POST requests.

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$key = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? '');

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
        $p = dms_status_payload($config, $state);
        http_response_code($p['http']);
        echo json_encode($p['body']);
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
        if ($state['triggered']) {
            echo json_encode(['success' => false, 'message' => 'Switch has triggered; disarm and re-arm first']);
            break;
        }
        if (dms_do_checkin('api') === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => dms_state_load_error() ?? 'Failed to write state file (flash full or read-only?)']);
            break;
        }
        echo json_encode(['success' => true, 'message' => 'Check-in recorded']);
        break;

    // quickcheckin lives only on the port-3801 API (external-api.php): check-in
    // links always target it, and Unraid's CSRF prepend would reject the
    // confirm page's POST here

    // Internal AJAX actions (called from the plugin UI, protected by Unraid auth)
    case 'web_checkin':
        if (!$state['armed']) {
            echo json_encode(['success' => false, 'message' => 'Switch is not armed']);
            break;
        }
        if ($state['triggered']) {
            echo json_encode(['success' => false, 'message' => 'Switch has triggered; disarm and re-arm first']);
            break;
        }
        if (dms_do_checkin('web') === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => dms_state_load_error() ?? 'Failed to write state file (flash full or read-only?)']);
            break;
        }
        echo json_encode(['success' => true, 'message' => 'Check-in recorded']);
        break;

    case 'arm':
        if (!$config['has_completed_dry_run'] && !$config['dry_run']) {
            echo json_encode(['success' => false, 'message' => 'Must complete a dry run before arming']);
            break;
        }
        $now = date('c');
        $r = dms_update_state(function($s) use ($now) {
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
            $s['recovered_at'] = null;
            array_unshift($s['checkin_history'], ['time' => $now, 'method' => 'arm']);
            $s['checkin_history'] = array_slice($s['checkin_history'], 0, 10);
            return $s;
        });
        if ($r === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => dms_state_load_error() ?? 'Failed to write state file (flash full or read-only?)']);
            break;
        }
        dms_clear_trigger_marker();
        dms_log("Switch ARMED");
        echo json_encode(['success' => true]);
        break;

    case 'disarm':
        $r = dms_update_state(function($s) {
            $s['armed'] = false;
            $s['triggered'] = false;
            $s['trigger_time'] = null;
            $s['grace_period_start'] = null;
            $s['missed_count'] = 0;
            $s['last_notification_level'] = null;
            $s['recovered_at'] = null;
            return $s;
        });
        if ($r === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => dms_state_load_error() ?? 'Failed to write state file (flash full or read-only?)']);
            break;
        }
        dms_clear_trigger_marker();
        dms_log("Switch DISARMED");
        echo json_encode(['success' => true]);
        break;

    case 'pause':
        $hours = intval($_POST['hours'] ?? 24);
        $max = $config['pause_max_hours'];
        if ($hours > $max) $hours = $max;
        if ($hours < 1) $hours = 1;
        if (!$state['armed'] || $state['triggered']) {
            echo json_encode(['success' => false, 'message' => $state['triggered'] ? 'Switch has triggered' : 'Switch is not armed']);
            break;
        }
        $state = dms_update_state(function($s) use ($hours) {
            $now = time();
            if (!empty($s['paused'])) {
                $exp = dms_parse_time($s['pause_expires'] ?? null);
                if ($exp === null || $exp >= $now) {
                    // Live pause: extend only, the frozen deadline stays put. A
                    // missing pause_start would accrue no credit: start it now
                    if (dms_parse_time($s['pause_start'] ?? null) === null) $s['pause_start'] = date('c', $now);
                    $s['pause_expires'] = date('c', $now + $hours * 3600);
                    return $s;
                }
                // Expired but cron has not run yet: credit up to the old expiry, start fresh
                $s = dms_apply_unpause($s);
            }
            $s['paused'] = true;
            $s['pause_start'] = date('c', $now);
            $s['pause_expires'] = date('c', $now + $hours * 3600);
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
        if (!$state['paused']) {
            echo json_encode(['success' => false, 'message' => 'Not paused']);
            break;
        }
        // The countdown is frozen while paused: dms_apply_unpause() moves the
        // pause credit into last_checkin so the deadline does not change
        $r = dms_update_state(function($s) {
            return $s['paused'] ? dms_apply_unpause($s) : $s;
        });
        if ($r === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to write state file']);
            break;
        }
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
        $confirm = !empty($input['_confirm']);
        $confirm_dangerous = !empty($input['_confirm_dangerous']);
        unset($input['_confirm'], $input['_confirm_dangerous']);
        $old_cron = $config['cron_interval_minutes'] ?? 60;
        $old_interval = $config['checkin_interval_days'];
        $old_external_url = trim((string)($config['external_url'] ?? ''));
        // The merge runs twice: once on a preview (checks only, nothing
        // written) and again inside the config lock against the freshly
        // loaded config, so a concurrent save on another tab is never lost
        $merge = function(array $c) use ($input) {
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
            // Repair types and clamp numeric settings server-side: a cleared
            // UI field arrives as null and would otherwise zero the interval,
            // making the deadline equal to the last check-in (instant grace)
            return dms_sanitize_config($c);
        };
        $preview = $merge($config);
        if ($errors = dms_validate_config($preview)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => implode("\n", $errors)]);
            break;
        }
        // Confirm whole-array/pool paths once, when they are added: paths
        // already saved (and confirmed) must not re-prompt on every save of
        // every tab
        $dangerous = array_values(array_diff(dms_has_array_root_paths($preview), dms_has_array_root_paths($config)));
        if ($dangerous && !$confirm_dangerous) {
            echo json_encode(['success' => false, 'needs_confirm' => 'dangerous',
                'message' => "These paths delete an entire array or pool:\n" . implode("\n", $dangerous) . "\nContinue?"]);
            break;
        }
        // Shortening the interval or grace on an armed switch so the deadline
        // lands before the next cron run: say what the next check will do
        if ($state['armed'] && !$state['triggered'] && $state['last_checkin'] && !$confirm) {
            $base = dms_parse_time($state['last_checkin']);
            $shrinks = $preview['checkin_interval_days'] < $config['checkin_interval_days'] ||
                       $preview['grace_period_hours'] < $config['grace_period_hours'];
            if ($base !== null && $shrinks) {
                $new_deadline = $base + $preview['checkin_interval_days'] * 86400 + dms_pause_credit_seconds($state);
                $new_grace_end = $new_deadline + $preview['grace_period_hours'] * 3600;
                $next_cron = time() + $preview['cron_interval_minutes'] * 60;
                if ($new_deadline <= $next_cron) {
                    $ago = round((time() - $base) / 86400, 1);
                    $when = $new_deadline <= time() ? 'has already passed' : 'passes before the next cron run';
                    if (!empty($preview['double_miss']) && intval($state['missed_count']) < 1) $then = 'count a missed check-in and reset the timer (double-miss protection)';
                    elseif ($new_grace_end <= $next_cron) $then = 'TRIGGER';
                    else $then = 'enter its grace period';
                    echo json_encode(['success' => false, 'needs_confirm' => 'shorten',
                        'message' => "Your last check-in was $ago days ago. With these settings the deadline $when and the switch will $then on the next check. Check in first, or continue anyway?"]);
                    break;
                }
            }
        }
        // Inside the lock: re-merge onto the fresh config and re-validate, so
        // no save can leave an invalid config on disk
        $rejected = null;
        $config = dms_update_config(function($c) use ($merge, &$rejected) {
            $m = $merge($c);
            if ($e = dms_validate_config($m)) {
                $rejected = $e;
                return $c;
            }
            return $m;
        });
        if (!is_array($config)) {
            http_response_code(500);
            $load_err = dms_config_load_error();
            echo json_encode(['error' => $load_err ?? 'Failed to write config file (flash full or read-only?)']);
            break;
        }
        if ($rejected) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => "Settings changed concurrently; please retry.\n" . implode("\n", $rejected)]);
            break;
        }

        // A changed interval shifts the warning thresholds; clear the recorded
        // notification level so a now-lower level isn't suppressed forever
        if (($config['checkin_interval_days'] ?? $old_interval) != $old_interval) {
            $r = dms_update_state(function($s) {
                $s['last_notification_level'] = null;
                return $s;
            });
            // The config save itself succeeded; a stale level only delays warnings
            if ($r === false) dms_log('Interval changed but the recorded notification level could not be cleared; warnings below the last level stay suppressed until the next check-in', 'WARN');
        }

        // Update cron schedule if interval changed
        $new_cron = $config['cron_interval_minutes'] ?? 60;
        if ($new_cron != $old_cron) {
            dms_update_cron($new_cron);
        }

        dms_log("Configuration updated");
        $resp = ['success' => true];
        // Verify a changed External API URL after the write; never blocks the
        // save. Only when it changed: the Settings tab sends it on every save
        // and an unreachable URL costs up to 5 s
        $new_external_url = trim((string)($config['external_url'] ?? ''));
        if (array_key_exists('external_url', $input) && $new_external_url !== '' && $new_external_url !== $old_external_url) {
            $probe_code = 0;
            $probe = dms_probe_external_url(trim((string)$config['external_url']), $probe_code);
            if ($probe === 'wrong_service') {
                $resp['warning'] = "External API URL answered but is not the Dead Man's Switch API (HTTP $probe_code). It must route to port 3801, not the Unraid web UI; check-in links will not work until it does.";
            } elseif ($probe === 'unreachable') {
                $resp['warning'] = "External API URL could not be reached from this server. Expected if your network lacks NAT loopback; otherwise check the proxy.";
            }
        }
        echo json_encode($resp);
        break;

    case 'generate_api_key':
        $new_key = dms_generate_api_key();
        $r = dms_update_config(function($c) use ($new_key) {
            $c['api_key'] = $new_key;
            return $c;
        });
        if ($r === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => dms_config_load_error() ?? 'Failed to write config file (flash full or read-only?)']);
            break;
        }
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
        $r = dms_update_config(function($c) {
            $c['has_completed_dry_run'] = true;
            return $c;
        });
        if ($r === false) {
            // The dry run itself ran; only recording it failed (arming stays blocked)
            echo json_encode(['success' => true, 'warning' => 'Dry run ran but could not be recorded', 'results' => $result]);
            break;
        }
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
