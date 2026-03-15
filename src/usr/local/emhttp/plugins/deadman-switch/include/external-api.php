<?php
// Dead Man's Switch - External API Endpoint
// Runs on a separate port (3801) outside Unraid's nginx auth.
// Only exposes API-key-protected and token-protected endpoints.

require_once __DIR__ . '/helpers.php';

// CORS headers for Home Assistant and other clients
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$key = $_GET['key'] ?? '';
$token = $_GET['token'] ?? '';

$config = dms_load_config();
$state = dms_load_state();

// Allowed external actions and their auth requirements
$public_actions = ['health'];
$key_actions = ['status', 'checkin', 'get_state'];
$token_actions = ['quickcheckin'];

if (!in_array($action, array_merge($public_actions, $key_actions, $token_actions))) {
    http_response_code(403);
    echo json_encode(['error' => 'Action not available on external API']);
    exit;
}

// Validate API key for key-protected actions
if (in_array($action, $key_actions)) {
    if (!$config['api_key'] || $key !== $config['api_key']) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }
}

switch ($action) {
    case 'health':
        echo json_encode(['status' => 'ok', 'version' => DMS_VERSION]);
        break;

    case 'status':
        $status = dms_get_status($config, $state);
        $remaining = dms_time_remaining($config, $state);
        $deadline = dms_get_deadline($config, $state);
        $grace_end = dms_get_grace_end($config, $state);
        $warning_level = dms_get_warning_level($config, $state);

        // Elapsed percentage
        $elapsed_pct = 0;
        if ($remaining !== null) {
            $total = $config['checkin_interval_days'] * 86400;
            $elapsed_pct = round((($total - $remaining) / $total) * 100, 1);
        }

        // HTTP status codes for simple monitors
        if ($status === 'triggered') {
            http_response_code(410);
        } elseif ($status === 'grace_period') {
            http_response_code(503);
        } elseif (in_array($warning_level, ['critical', 'last_chance'])) {
            http_response_code(429);
        } elseif ($elapsed_pct >= 75) {
            http_response_code(299);
        }

        echo json_encode([
            // Core status
            'status'                 => $status,
            'armed'                  => $state['armed'],
            'paused'                 => $state['paused'],
            'dry_run'                => $config['dry_run'],
            'warning_level'          => $warning_level,

            // Timing
            'seconds_remaining'      => $remaining !== null ? max(0, $remaining) : null,
            'minutes_remaining'      => $remaining !== null ? max(0, round($remaining / 60)) : null,
            'hours_remaining'        => $remaining !== null ? max(0, round($remaining / 3600, 1)) : null,
            'days_remaining'         => $remaining !== null ? max(0, round($remaining / 86400, 1)) : null,
            'elapsed_pct'            => $elapsed_pct,
            'time_remaining_display' => $remaining !== null ? dms_format_time_remaining(max(0, $remaining)) : null,

            // Dates (ISO 8601)
            'last_checkin'           => $state['last_checkin'],
            'last_checkin_method'    => $state['last_checkin_method'],
            'next_deadline'          => $deadline ? date('c', $deadline) : null,
            'grace_period_ends'      => ($status === 'grace_period' && $grace_end) ? date('c', $grace_end) : null,
            'pause_expires'          => $state['pause_expires'],

            // Config context
            'checkin_interval_days'  => $config['checkin_interval_days'],
            'grace_period_hours'     => $config['grace_period_hours'],
            'actions_configured'     => count($config['actions']['deletions']) + count($config['actions']['scripts']),

            // Meta
            'version'                => DMS_VERSION,
            'timestamp'              => date('c'),
        ]);
        break;

    case 'checkin':
        if (!$state['armed']) {
            echo json_encode(['success' => false, 'message' => 'Switch is not armed']);
            break;
        }
        dms_do_checkin('api');
        // Return fresh state after check-in
        $state = dms_load_state();
        $remaining = dms_time_remaining($config, $state);
        $deadline = dms_get_deadline($config, $state);
        echo json_encode([
            'success'                => true,
            'message'                => 'Check-in recorded',
            'last_checkin'           => $state['last_checkin'],
            'next_deadline'          => $deadline ? date('c', $deadline) : null,
            'days_remaining'         => $remaining !== null ? round($remaining / 86400, 1) : null,
            'time_remaining_display' => $remaining !== null ? dms_format_time_remaining($remaining) : null,
        ]);
        break;

    case 'get_state':
        $remaining = dms_time_remaining($config, $state);
        echo json_encode([
            'state'          => $state,
            'status'         => dms_get_status($config, $state),
            'warning_level'  => dms_get_warning_level($config, $state),
            'time_remaining' => $remaining,
            'deadline'       => dms_get_deadline($config, $state) ? date('c', dms_get_deadline($config, $state)) : null,
            'grace_end'      => dms_get_grace_end($config, $state) ? date('c', dms_get_grace_end($config, $state)) : null,
        ]);
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
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['confirm'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo dms_quick_checkin_page('confirm', $token);
            break;
        }
        dms_use_quick_checkin_token($token, $state);
        dms_do_checkin('quick_link');
        header('Content-Type: text/html; charset=utf-8');
        echo dms_quick_checkin_page('success');
        break;
}

// Quick checkin page rendering (same as api.php)
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
