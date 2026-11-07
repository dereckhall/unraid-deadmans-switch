<?php
// Dead Man's Switch - External API Endpoint
// Runs on a separate port (3801) outside Unraid's nginx auth.
// Only exposes API-key-protected and token-protected endpoints.

require_once __DIR__ . '/helpers.php';

// CORS headers for Home Assistant and other clients
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
// Prefer the X-Api-Key header: a key in the query string lands in browser history and reverse-proxy access logs
$key = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? '');
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
    if (!$config['api_key'] || !hash_equals((string)$config['api_key'], (string)$key)) {
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
        $p = dms_status_payload($config, $state);
        http_response_code($p['http']);
        echo json_encode($p['body']);
        break;

    case 'checkin':
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
        // GET shows the confirm page; only its POST records the check-in, so
        // link scanners and chat previews can never spend the token
        $m = $_SERVER['REQUEST_METHOD'];
        if ($m !== 'GET' && $m !== 'POST') { http_response_code(405); header('Allow: GET, POST'); echo json_encode(['error' => 'Method not allowed']); break; }
        if (!is_string($token) || $token === '') { http_response_code(400); echo json_encode(['error' => 'Token required']); break; }
        header('Content-Type: text/html; charset=utf-8');
        if (!dms_validate_quick_checkin_token($token, $state)) { http_response_code(410); echo dms_quick_checkin_page('invalid'); break; }
        if (!$state['armed'] || $state['triggered']) { echo dms_quick_checkin_page('inactive'); break; }     // token left unspent
        if ($m === 'GET') { echo dms_quick_checkin_page('confirm', $token); break; }                          // &confirm=1 GETs land here too
        if (!dms_use_quick_checkin_token($token)) { http_response_code(410); echo dms_quick_checkin_page('invalid'); break; }   // lost the race / write failed
        if (!dms_do_checkin('quick_link')) { http_response_code(500); echo dms_quick_checkin_page('error'); break; }
        echo dms_quick_checkin_page('success');
        break;
}
