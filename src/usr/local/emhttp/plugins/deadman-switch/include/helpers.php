<?php
// Dead Man's Switch - Shared PHP Functions

// Use system timezone so logs/embeds match Unraid's configured time
$_tz_parts = explode('zoneinfo/', (string)@readlink('/etc/localtime'), 2);
if (isset($_tz_parts[1])) @date_default_timezone_set($_tz_parts[1]);

// DMS_CONFIG_DIR env override exists for testing only; unset on Unraid
define('DMS_CONFIG_DIR', getenv('DMS_CONFIG_DIR') ?: '/boot/config/plugins/deadman-switch');
define('DMS_CONFIG_FILE', DMS_CONFIG_DIR . '/config.json');
define('DMS_STATE_FILE', DMS_CONFIG_DIR . '/state.json');
define('DMS_LOG_DIR', DMS_CONFIG_DIR . '/logs');
define('DMS_LOG_FILE', DMS_LOG_DIR . '/deadman.log');
define('DMS_PLUGIN_DIR', '/usr/local/emhttp/plugins/deadman-switch');
define('DMS_RUN_DIR', '/var/run/deadman-switch');
define('DMS_VERSION', (function() {
    $plg = '/boot/config/plugins/deadman-switch.plg';
    if (file_exists($plg) && preg_match('/PLUGIN[^>]+version="([^"]+)"/', @file_get_contents($plg, false, null, 0, 512), $m)) {
        return $m[1];
    }
    return 'unknown';
})());

// Set by dms_load_state()/dms_load_config() when the file on flash is present
// but unreadable and no usable .bak exists; read via dms_*_load_error()
$GLOBALS['dms_load_error'] = ['state' => null, 'config' => null];

function dms_state_load_error() {
    return $GLOBALS['dms_load_error']['state'] ?? null;
}

function dms_config_load_error() {
    return $GLOBALS['dms_load_error']['config'] ?? null;
}

// Parse an ISO timestamp; null when empty or unparseable (never false/0, so
// arithmetic on a bad value can't silently produce an epoch-1970 deadline)
function dms_parse_time($iso) {
    $t = is_string($iso) && $iso !== '' ? strtotime($iso) : false;
    return $t === false ? null : $t;
}

// [array|null $data, 'ok'|'missing'|'recovered'|'corrupt', int $bytes]
function dms_load_json_file($file) {
    if (!file_exists($file)) return [null, 'missing', 0];
    $raw = @file_get_contents($file);
    $n = strlen((string)$raw);
    $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (is_array($data)) return [$data, 'ok', $n];
    $bak = @file_get_contents($file . '.bak');
    $bdata = ($bak !== false && $bak !== '') ? json_decode($bak, true) : null;
    return is_array($bdata) ? [$bdata, 'recovered', $n] : [null, 'corrupt', $n];
}

function dms_get_api_base($config = null) {
    if ($config === null) $config = dms_load_config();
    // A configured external URL must route to the external API (port 3801),
    // e.g. a reverse proxy or port forward; used so check-in links work off-LAN
    $external = rtrim(trim($config['external_url'] ?? ''), '/');
    if ($external !== '') return $external;
    $nginx = @parse_ini_file('/var/local/emhttp/nginx.ini');
    $host = $nginx['NGINX_LANIP'] ?? $nginx['NGINX_LANMDNS'] ?? 'localhost';
    return "http://$host:3801";
}

function dms_ensure_dirs() {
    $dirs = [DMS_CONFIG_DIR, DMS_LOG_DIR, DMS_RUN_DIR];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }
}

function dms_config_defaults() {
    return [
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
        ],
        'pause_max_hours'             => 720,
    ];
}

// Repair types and clamp numeric settings. Idempotent: f(f(x)) === f(x).
// Legacy configs can carry null for any key (a null wins over the default in
// array_replace_recursive), and a zero interval divides by zero in 'status'.
function dms_sanitize_config(array $c): array {
    $d = dms_config_defaults();
    $int = function($v, $def, $min, $max) { $n = intval($v ?? 0) ?: $def; return max($min, min($max, $n)); };
    foreach (['warning_thresholds', 'webhooks', 'uptime_kuma', 'actions'] as $k) {
        if (!is_array($c[$k] ?? null)) $c[$k] = $d[$k];
    }
    foreach (['discord', 'custom'] as $k) {
        $c['webhooks'][$k] = array_replace($d['webhooks'][$k], is_array($c['webhooks'][$k] ?? null) ? $c['webhooks'][$k] : []);
    }
    foreach (['deletions', 'scripts'] as $k) {
        if (!is_array($c['actions'][$k] ?? null)) $c['actions'][$k] = [];
    }
    $c['checkin_interval_days'] = $int($c['checkin_interval_days'] ?? null, 30, 1, 365);
    $c['grace_period_hours']    = $int($c['grace_period_hours'] ?? null, 48, 1, 720);
    $c['cron_interval_minutes'] = $int($c['cron_interval_minutes'] ?? null, 60, 5, 1440);
    $c['pause_max_hours']       = $int($c['pause_max_hours'] ?? null, 720, 1, 8760);
    foreach (['reminder' => 50, 'warning' => 75, 'critical' => 90, 'last_chance' => 95] as $t => $def) {
        $c['warning_thresholds'][$t] = $int($c['warning_thresholds'][$t] ?? null, $def, 1, 100);
    }
    $c['uptime_kuma']['warning_days'] = $int($c['uptime_kuma']['warning_days'] ?? null, 7, 1, 365);
    foreach ($c['actions']['scripts'] as $i => $s) {   // index loop: no reference, no unset($s) hazard
        if (!is_array($s)) { unset($c['actions']['scripts'][$i]); continue; }
        $c['actions']['scripts'][$i]['timeout'] = $int($s['timeout'] ?? null, 300, 10, 3600);
    }
    $c['actions']['scripts']   = array_values($c['actions']['scripts']);
    $c['actions']['deletions'] = array_values(array_filter($c['actions']['deletions'], 'is_array'));
    $c['dry_run']               = isset($c['dry_run']) ? (bool)$c['dry_run'] : true;   // null must never mean "off"
    $c['double_miss']           = (bool)($c['double_miss'] ?? false);
    $c['has_completed_dry_run'] = (bool)($c['has_completed_dry_run'] ?? false);
    return $c;
}

// Rules a saved config must satisfy (run on the sanitized, merged config).
// [] when valid, else one human-readable error per broken rule.
function dms_validate_config(array $c): array {
    $errors = [];
    $t = $c['warning_thresholds'] ?? [];
    $order = ['reminder', 'warning', 'critical', 'last_chance'];
    for ($i = 0; $i < count($order) - 1; $i++) {
        if (intval($t[$order[$i]] ?? 0) >= intval($t[$order[$i + 1]] ?? 0)) {
            $errors[] = 'Warning thresholds must increase: reminder < warning < critical < last chance (now ' .
                implode(' / ', array_map(function($k) use ($t) { return intval($t[$k] ?? 0) . '%'; }, $order)) . ')';
            break;
        }
    }
    $grace = intval($c['grace_period_hours'] ?? 0);
    $cron = intval($c['cron_interval_minutes'] ?? 0);
    if ($grace * 60 < $cron) {
        $errors[] = "Grace period ({$grace}h) must be at least as long as the cron check interval ($cron min), otherwise the grace notification can never be sent";
    }
    foreach ($c['actions']['deletions'] ?? [] as $d) {
        $p = is_array($d) ? (string)($d['path'] ?? '') : '';
        if ($p !== '' && dms_classify_deletion_path($p) === 'forbidden') {
            $errors[] = "Refusing to delete a system root: $p";
        }
    }
    return $errors;
}

// 'ok' | 'array_root' (a whole array/pool: allowed, confirmed on save) |
// 'forbidden' (a system root: refused on save and at trigger time).
// Classifies the literal path AND every current glob() match (the same glob
// trigger.sh expands) and returns the most severe.
function dms_classify_deletion_path($path) {
    $forbidden = ['/', '/boot', '/mnt', '/usr', '/etc', '/bin', '/sbin', '/lib', '/lib64', '/var',
                  '/proc', '/sys', '/dev', '/run', '/root', '/home', '/tmp', '/usr/local/emhttp'];
    $array_roots = ['/mnt/user', '/mnt/user0', '/mnt/cache', '/mnt/disks', '/mnt/remotes', '/mnt/addons'];
    $one = function($p) use ($forbidden, $array_roots) {
        $n = preg_replace('#/+#', '/', (string)$p);
        if ($n !== '/') $n = rtrim($n, '/');
        if ($n === '') return 'ok';
        $real = @realpath($n);
        if ($real !== false) $n = $real;
        if (in_array($n, $forbidden, true)) return 'forbidden';
        // Any direct child of /mnt (user, user0, cache, diskN, pools, disks, ...)
        if (in_array($n, $array_roots, true) || preg_match('#^/mnt/disk[0-9]+$#', $n) || preg_match('#^/mnt/[^/]+$#', $n)) return 'array_root';
        return 'ok';
    };
    $path = (string)$path;
    if (trim($path) === '') return 'ok';
    $rank = ['ok' => 0, 'array_root' => 1, 'forbidden' => 2];
    $worst = $one($path);
    foreach (glob($path) ?: [] as $m) {
        if ($worst === 'forbidden') break;
        $k = $one($m);
        if ($rank[$k] > $rank[$worst]) $worst = $k;
    }
    return $worst;
}

// Configured deletion paths that delete an entire array or pool
function dms_has_array_root_paths(array $c): array {
    $out = [];
    foreach ($c['actions']['deletions'] ?? [] as $d) {
        $p = is_array($d) ? (string)($d['path'] ?? '') : '';
        if ($p !== '' && dms_classify_deletion_path($p) === 'array_root') $out[] = $p;
    }
    return array_values(array_unique($out));
}

function dms_load_config() {
    static $corrupt_logged = false;
    dms_ensure_dirs();
    $GLOBALS['dms_load_error']['config'] = null;
    $defaults = dms_config_defaults();

    [$data, $status, $bytes] = dms_load_json_file(DMS_CONFIG_FILE);

    if ($status === 'missing') {
        dms_save_config($defaults);
        return $defaults;
    }

    if ($status === 'corrupt') {
        $msg = "config.json is present but not valid JSON ($bytes bytes) and no usable config.json.bak exists";
        $GLOBALS['dms_load_error']['config'] = $msg;
        if (!$corrupt_logged) {
            dms_log($msg, 'ERROR');
            $corrupt_logged = true;
        }
        return $defaults;
    }

    $config = array_replace_recursive($defaults, $data);

    if ($status === 'recovered') {
        // The .bak lags one save behind, so its actions list may predate the
        // last save: DISARM exactly like a state restore. The disarm is
        // persisted BEFORE config.json is rewritten, so until it has reached
        // disk config.json stays unreadable and every load retries it.
        $disarmed = dms_update_state('dms_disarm_after_restore');
        if ($disarmed === false) {
            // State unreadable or flash not writable: the restored actions
            // must not run while the switch may still be armed on disk. Park
            // (the evaluator reports state_unreadable: ERROR, syslog, alert)
            // and leave config.json and its .bak untouched.
            $msg = "config.json is unreadable ($bytes bytes) and config.json.bak is usable, but the switch could not be " .
                   "DISARMED (state.json unreadable or flash write failed), so the restore was not applied. " .
                   "The switch is NOT evaluated until the flash drive is writable.";
            $GLOBALS['dms_load_error']['config'] = $msg;
            dms_log($msg, 'ERROR');
            return dms_sanitize_config($config);
        }
        // Forensic copy of the corrupt bytes, then rewrite. Written directly
        // (not via dms_update_config, which would recurse); [] = leave the
        // good .bak alone
        @copy(DMS_CONFIG_FILE, DMS_CONFIG_FILE . '.corrupt');
        $ok = dms_write_json_atomic(DMS_CONFIG_FILE, $config, []);
        dms_log("config.json unreadable ($bytes bytes); restored from config.json.bak and the switch was DISARMED as a precaution" .
                " — the restored actions list may predate your last save. Review Actions and re-arm." .
                ($ok ? '' : ' (In memory only: rewriting config.json FAILED.)'), 'ERROR');
    }

    // Read-side repair only; the sanitized form reaches disk at the next save
    return dms_sanitize_config($config);
}

function dms_save_config($config) {
    dms_ensure_dirs();
    return dms_write_json_atomic(DMS_CONFIG_FILE, $config);
}

// Write via temp file + rename so concurrent readers never see a truncated file.
// $backup_keys decides when the previous (parseable) file is kept as .bak:
// null = on every write, [] = never, list = when any listed key changes.
function dms_write_json_atomic($file, $data, $backup_keys = null) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || $json === '') {
        dms_log('JSON encode failed for ' . basename($file) . ': ' . json_last_error_msg(), 'ERROR');
        return false;
    }
    $tmp = $file . '.tmp.' . getmypid();
    $w = @file_put_contents($tmp, $json, LOCK_EX);
    if ($w === false || $w !== strlen($json)) {
        @unlink($tmp);
        dms_log('Short write to ' . basename($tmp) . ' (' . var_export($w, true) . ' of ' . strlen($json) . ' bytes)', 'ERROR');
        return false;
    }
    // Previous good version -> .bak, only if the live file parses (a corrupt
    // file must never clobber a good .bak) and only on a semantic change
    if (file_exists($file) && is_array($cur = json_decode((string)@file_get_contents($file), true))) {
        $changed = $backup_keys === null;
        foreach ((array)$backup_keys as $k) {
            if (($cur[$k] ?? null) !== ($data[$k] ?? null)) { $changed = true; break; }
        }
        if ($changed) {
            if (!@copy($file, $file . '.bak.tmp')) {
                dms_log('Could not copy ' . basename($file) . ' to .bak.tmp; backup not refreshed', 'WARN');
            } elseif (!@rename($file . '.bak.tmp', $file . '.bak')) {
                @unlink($file . '.bak.tmp');
                dms_log('Could not rename .bak.tmp to ' . basename($file) . '.bak; backup not refreshed', 'WARN');
            }
        }
    }
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        dms_log('rename failed for ' . basename($file), 'ERROR');
        return false;
    }
    return true;
}

// Serialized read-modify-write. $fn receives the current state and returns the
// modified state. Uses a separate lock file because the data file is replaced
// by rename() and a lock on a replaced inode would not serialize writers.
function dms_update_state($fn) {
    dms_ensure_dirs();
    $lock = @fopen(DMS_STATE_FILE . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $state = dms_load_state();
    // Checked inside the lock, after the load: never overwrite an unreadable
    // state.json with defaults (that would silently disarm the switch)
    if (dms_state_load_error() !== null) {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        dms_log('Refusing to overwrite unreadable state.json', 'ERROR');
        return false;
    }
    $result = $fn($state);
    if (is_array($result)) $state = $result;
    // .bak only on a semantic change: token mints, notification levels and
    // failure markers must not churn the backup of the last good state
    $ok = dms_write_json_atomic(DMS_STATE_FILE, $state,
        ['armed', 'triggered', 'trigger_time', 'paused', 'pause_start', 'pause_expires', 'last_checkin', 'grace_period_start', 'missed_count']);
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (!$ok) dms_log('Failed to write state file (flash full or read-only?)', 'ERROR');
    return $ok ? $state : false;
}

function dms_update_config($fn) {
    dms_ensure_dirs();
    $lock = @fopen(DMS_CONFIG_FILE . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $config = dms_load_config();
    if (dms_config_load_error() !== null) {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        dms_log('Refusing to overwrite unreadable config.json', 'ERROR');
        return false;
    }
    $result = $fn($config);
    if (is_array($result)) $config = $result;
    $ok = dms_write_json_atomic(DMS_CONFIG_FILE, $config, null);
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (!$ok) dms_log('Failed to write config file (flash full or read-only?)', 'ERROR');
    return $ok ? $config : false;
}

function dms_load_state() {
    static $corrupt_logged = false;
    dms_ensure_dirs();
    $GLOBALS['dms_load_error']['state'] = null;
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
        'last_notify_failure'      => null,
        'recovered_at'             => null,
    ];

    [$data, $status, $bytes] = dms_load_json_file(DMS_STATE_FILE);

    if ($status === 'missing') {
        dms_save_state($defaults);
        return $defaults;
    }

    if ($status === 'corrupt') {
        $msg = "state.json is present but not valid JSON ($bytes bytes) and no usable state.json.bak exists";
        $GLOBALS['dms_load_error']['state'] = $msg;
        if (!$corrupt_logged) {
            dms_log($msg, 'ERROR');
            $corrupt_logged = true;
        }
        return $defaults;
    }

    $state = array_replace_recursive($defaults, $data);
    foreach (['checkin_history', 'quick_checkin_tokens'] as $k) {
        if (!is_array($state[$k])) $state[$k] = [];
    }

    if ($status === 'recovered') {
        // The .bak lags one semantic change behind (usually the previous
        // check-in), so its deadline may be stale or already past. Restore it
        // but DISARM as a precaution: a restore must never run actions. The
        // user verifies Last Check-in and re-arms.
        $state = dms_disarm_after_restore($state);
        // Forensic copy of the corrupt bytes, then rewrite. Written directly:
        // dms_update_state() would recurse into this loader.
        // [] = leave the good .bak alone
        @copy(DMS_STATE_FILE, DMS_STATE_FILE . '.corrupt');
        $ok = dms_write_json_atomic(DMS_STATE_FILE, $state, []);
        dms_log("state.json unreadable ($bytes bytes); restored from state.json.bak and DISARMED as a precaution" .
                " — re-arm in Settings after verifying it." .
                ($ok ? '' : ' (In memory only: rewriting state.json FAILED.)') .
                " last_checkin=" . ($state['last_checkin'] ?? 'never'), 'ERROR');
    }

    return $state;
}

// Shared by the state.json and config.json .bak restores. recovered_at drives
// the Settings banner, the evaluator's RECOVERED alert and Kuma "down"; it is
// cleared by a check-in, arm or disarm. triggered/trigger_time, last_checkin,
// history and tokens are left as they are.
function dms_disarm_after_restore(array $s) {
    $s['armed'] = false;
    $s['paused'] = false;
    $s['pause_start'] = null;
    $s['pause_expires'] = null;
    $s['grace_period_start'] = null;
    $s['missed_count'] = 0;
    $s['last_notification_level'] = null;
    $s['recovered_at'] = date('c');
    return $s;
}

function dms_save_state($state) {
    dms_ensure_dirs();
    return dms_write_json_atomic(DMS_STATE_FILE, $state);
}

function dms_log($message, $level = 'INFO') {
    dms_ensure_dirs();
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$level] $message\n";
    @file_put_contents(DMS_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    // Mirror serious problems to syslog: still visible if the flash log is
    // unwritable, and shows up under Tools > Syslog
    if ($level === 'ERROR' || $level === 'CRITICAL') {
        @openlog('deadman-switch', LOG_PID, LOG_USER);
        @syslog(LOG_ERR, $message);
        @closelog();
    }
}

// Pause seconds not yet credited to last_checkin. Counted from max(pause_start, last_checkin): a
// check-in during a pause already restarted the clock. Capped at pause_expires.
function dms_pause_credit_seconds($state, $now = null) {
    if (empty($state['paused'])) return 0;
    $now = $now ?? time();
    $start = dms_parse_time($state['pause_start'] ?? null);
    if ($start === null) return 0;
    $base = dms_parse_time($state['last_checkin'] ?? null);
    $from = $base !== null ? max($start, $base) : $start;
    $end = $now;
    $exp = dms_parse_time($state['pause_expires'] ?? null);
    if ($exp !== null) $end = min($end, $exp);
    return max(0, $end - $from);
}

// null when there is no check-in or it can't be parsed. Callers must treat
// null as "unknown", never as "expired". Frozen while paused: the uncredited
// pause time is added, so the deadline does not move until the pause ends.
function dms_get_deadline($config, $state) {
    $base = dms_parse_time($state['last_checkin'] ?? null);
    return $base === null ? null : $base + intval($config['checkin_interval_days']) * 86400 + dms_pause_credit_seconds($state);
}

// Use inside a dms_update_state() closure. Moves the pause credit into
// last_checkin and clears the pause.
// Invariant: dms_get_deadline(before) === dms_get_deadline(after).
function dms_apply_unpause(array $s) {
    $credit = dms_pause_credit_seconds($s);
    $base = dms_parse_time($s['last_checkin'] ?? null);
    if ($credit > 0 && $base !== null) $s['last_checkin'] = date('c', min(time(), $base + $credit));   // clamp = belt and braces
    $s['paused'] = false;
    $s['pause_start'] = null;
    $s['pause_expires'] = null;
    return $s;
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
    // Unparseable last_checkin: unknown, not expired (null <= 0 is true in PHP)
    if ($remaining === null) return 'none';
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
    if ($level === 'reminder') return 'armed_reminder';
    return 'armed_ok';
}

// Shared 'status' response for api.php and external-api.php (port 3801).
// Field order and HTTP codes are what Home Assistant / monitors consume:
// 410 triggered, 503 grace, 429 critical/last_chance, 299 >= 75% elapsed, else 200.
function dms_status_payload($config, $state) {
    $status = dms_get_status($config, $state);
    $remaining = dms_time_remaining($config, $state);
    $deadline = dms_get_deadline($config, $state);
    $grace_end = dms_get_grace_end($config, $state);
    $warning_level = dms_get_warning_level($config, $state);

    // The sanitized config guarantees an interval of at least 1 day
    $elapsed_pct = 0;
    if ($remaining !== null) {
        $total = max(1, intval($config['checkin_interval_days'])) * 86400;
        $elapsed_pct = round((($total - $remaining) / $total) * 100, 1);
    }

    $http = 200;
    if ($status === 'triggered') {
        $http = 410;
    } elseif ($status === 'grace_period') {
        $http = 503;
    } elseif (in_array($warning_level, ['critical', 'last_chance'])) {
        $http = 429;
    } elseif ($elapsed_pct >= 75) {
        $http = 299;
    }

    return ['http' => $http, 'body' => [
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
    ]];
}

function dms_generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

function dms_generate_api_key() {
    return 'dms_' . bin2hex(random_bytes(20));
}

// The token is added to freshly loaded state under lock, so a caller's stale
// copy can never clobber a check-in or another caller's token. Returns null
// when the token could not be saved (a link to it would never work).
function dms_create_quick_checkin_token() {
    $token = dms_generate_token(16);
    $r = dms_update_state(function($s) use ($token) {
        $s['quick_checkin_tokens'][$token] = [
            'created' => date('c'),
            'expires' => date('c', time() + 72 * 3600),
            'used'    => false,
        ];
        // Prune expired tokens (an unparseable expiry counts as expired)
        foreach ($s['quick_checkin_tokens'] as $t => $info) {
            $exp = dms_parse_time($info['expires'] ?? null);
            if ($exp === null || $exp < time()) {
                unset($s['quick_checkin_tokens'][$t]);
            }
        }
        return $s;
    });
    return $r === false ? null : $token;
}

// One link builder for templates and embeds. null = offer no link
// (disarmed, triggered, or the token mint failed)
function dms_make_checkin_link($config, $state) {
    if (empty($state['armed']) || !empty($state['triggered'])) return null;
    $token = dms_create_quick_checkin_token();
    return $token === null ? null : dms_get_api_base($config) . '/?action=quickcheckin&token=' . $token;
}

function dms_validate_quick_checkin_token($token, $state) {
    if (!isset($state['quick_checkin_tokens'][$token])) return false;
    $info = $state['quick_checkin_tokens'][$token];
    if (!is_array($info) || !empty($info['used'])) return false;
    $exp = dms_parse_time($info['expires'] ?? null);
    if ($exp === null || $exp < time()) return false;
    return true;
}

function dms_use_quick_checkin_token($token) {
    // Validate and mark used inside the locked update so a token can't be
    // spent twice by concurrent requests
    $ok = false;
    $r = dms_update_state(function($s) use ($token, &$ok) {
        if (isset($s['quick_checkin_tokens'][$token]) && is_array($s['quick_checkin_tokens'][$token])) {
            $info = $s['quick_checkin_tokens'][$token];
            $exp = dms_parse_time($info['expires'] ?? null);
            if (empty($info['used']) && $exp !== null && $exp >= time()) {
                $s['quick_checkin_tokens'][$token]['used'] = true;
                $ok = true;
            }
        }
        return $s;
    });
    // Spent only if the 'used' mark actually reached disk
    return $r !== false && $ok;
}

// trigger.sh creates this marker with mkdir; arm/disarm remove it
// after their state write succeeded
function dms_clear_trigger_marker() {
    $m = DMS_RUN_DIR . '/triggered';
    @rmdir($m);
    @unlink($m);
}

function dms_do_checkin($method = 'web') {
    $now = date('c');
    $result = dms_update_state(function($state) use ($now, $method) {
        $state['last_checkin'] = $now;
        $state['last_checkin_method'] = $method;
        $state['missed_count'] = 0;
        $state['last_notification_level'] = null;
        $state['grace_period_start'] = null;
        $state['recovered_at'] = null;   // user has seen and confirmed the state

        // Add to history (keep last 10)
        array_unshift($state['checkin_history'], [
            'time'   => $now,
            'method' => $method,
        ]);
        $state['checkin_history'] = array_slice($state['checkin_history'], 0, 10);
        return $state;
    });

    if ($result === false) {
        dms_log("Check-in via $method FAILED to write state", 'ERROR');
        return false;
    }
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

// A string as it appears between the quotes of a JSON string literal
function dms_json_escape($v) {
    return substr(json_encode((string)$v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
}

// $opts: 'checkin_link' => string|null (caller already minted; null = no
// link, never mint here), 'message' => text for {{message}}, 'escape' =>
// 'json' to JSON-escape string values (numbers stay bare). Without a supplied
// link, a token is minted only when the template actually uses {{checkin_link}}.
function dms_render_template($template, $config, $state, array $opts = []) {
    $remaining = dms_time_remaining($config, $state);
    $days = $remaining ? max(0, round($remaining / 86400, 1)) : 0;
    $hours = $remaining ? max(0, round($remaining / 3600)) : 0;
    $status = dms_get_status($config, $state);

    if (!array_key_exists('checkin_link', $opts) && strpos((string)$template, '{{checkin_link}}') !== false) {
        $checkin_link = dms_make_checkin_link($config, $state) ?? 'N/A';
    } else {
        $checkin_link = $opts['checkin_link'] ?? 'N/A';
    }

    $strings = [
        '{{status}}'          => strtoupper(str_replace('_', ' ', $status)),
        '{{checkin_link}}'    => $checkin_link,
        '{{last_checkin}}'    => $state['last_checkin'] ?? 'Never',
        '{{message}}'         => $opts['message'] ?? '',
    ];
    if (($opts['escape'] ?? null) === 'json') {
        $strings = array_map('dms_json_escape', $strings);
    }
    $vars = $strings + [
        '{{days_remaining}}'  => $days,
        '{{hours_remaining}}' => $hours,
    ];

    return str_replace(array_keys($vars), array_values($vars), (string)$template);
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

// $checkin_link: null = mint one here (only when armed and not triggered);
// a string = use it; false = the caller already decided there is no link
function dms_build_discord_embed($config, $state, $checkin_link = null) {
    $status = dms_get_status($config, $state);
    $remaining = dms_time_remaining($config, $state);
    $remaining_text = $remaining !== null ? dms_format_time_remaining($remaining) : 'N/A';

    // Severity tiers: green (ok), orange (warning), red (critical/grace/triggered)
    $severity = [
        'armed_ok'       => 'green',
        'armed_reminder' => 'yellow',
        'armed_warning'  => 'orange',
        'armed_critical' => 'red',
        'grace_period'   => 'red',
        'triggered'      => 'red',
        'paused'         => 'green',
        'disarmed'       => 'green',
    ];
    $tier = $severity[$status] ?? 'orange';

    $tier_colors = ['green' => 0x2ECC71, 'yellow' => 0xFFC107, 'orange' => 0xFF9800, 'red' => 0xE74C3C];
    $color = $tier_colors[$tier];

    // Emoji matches the sidebar color
    $tier_emoji = [
        'green'  => "\xE2\x9C\x85",  // ✅
        'yellow' => "\xF0\x9F\x94\x94", // 🔔
        'orange' => "\xE2\x9A\xA0\xEF\xB8\x8F", // ⚠️
        'red'    => "\xE2\x9D\x8C",  // ❌
    ];
    $e = $tier_emoji[$tier];

    $headlines = [
        'armed_ok'       => "$e Dead Man's Switch is OK. $e",
        'armed_reminder' => "$e Check-in reminder. $e",
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

    $last_ts = dms_parse_time($state['last_checkin'] ?? null);
    if ($last_ts !== null) {
        $fields[] = ['name' => 'Last Check-in', 'value' => date('l, F j, Y \a\t g:i A', $last_ts), 'inline' => false];
    }

    if ($status === 'grace_period') {
        $grace_end = dms_get_grace_end($config, $state);
        if ($grace_end) {
            $fields[] = ['name' => 'Actions Execute At', 'value' => date('l, F j, Y \a\t g:i A', $grace_end), 'inline' => false];
        }
    }

    $link = $checkin_link === null ? dms_make_checkin_link($config, $state) : $checkin_link;
    if (is_string($link) && $link !== '') {
        $fields[] = ['name' => 'Check In', 'value' => "[Click here to check in]($link)", 'inline' => false];
    }

    $hostname = gethostname() ?: 'Unraid';
    $logo_url = 'https://raw.githubusercontent.com/dereckhall/unraid-deadmans-switch/main/src/usr/local/emhttp/plugins/deadman-switch/assets/images/unraid-logo.png';

    return [
        'author'      => ['name' => $hostname, 'icon_url' => $logo_url],
        'description' => $headline,
        'color'       => $color,
        'fields'      => $fields,
        'timestamp'   => date('c'),
    ];
}

// $opts: 'checkin_link' => string|null, already minted by the caller (null =
// no link; nothing is minted here when the key is present), 'plain' => true
// to send $message as plain Discord text instead of the status embed
function dms_send_webhook($type, $webhook_config, $message, $config = null, $state = null, array $opts = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    switch ($type) {
        case 'discord':
            curl_setopt($ch, CURLOPT_URL, $webhook_config['url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            // Rich embed for status notifications; plain text for alerts
            // (first miss, clock anomaly, broadcasts) and without config/state
            if ($config && $state && empty($opts['plain'])) {
                // false tells the embed "caller decided: no link" (do not mint)
                $link = array_key_exists('checkin_link', $opts) ? ($opts['checkin_link'] ?? false) : null;
                $payload = ['embeds' => [dms_build_discord_embed($config, $state, $link)]];
            } else {
                $payload = ['content' => $message];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            break;

        case 'custom':
            curl_setopt($ch, CURLOPT_URL, $webhook_config['url']);
            // GET stays a bare ping: no body is built or sent
            if ($webhook_config['method'] === 'POST') {
                $tpl = trim((string)($webhook_config['body_template'] ?? ''));
                if ($tpl !== '' && $config && $state) {
                    $body = dms_render_template($tpl, $config, $state, $opts + ['message' => $message, 'escape' => 'json']);
                    if (preg_match('/^\s*[\[{]/', $tpl) && json_decode($body) === null) dms_log('Custom webhook body is not valid JSON after rendering', 'WARN');
                } else {
                    $body = $tpl !== '' ? $tpl : $message;
                }
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

    $link = dms_make_checkin_link($config, $state);   // one token per test, like notify.sh
    $result = dms_send_webhook($type, $webhooks[$type], $message, $config, $state, ['checkin_link' => $link]);
    dms_log("Test webhook sent: $type - " . ($result['success'] ? 'OK' : 'FAILED: ' . $result['message']));
    return $result;
}

// Plain-text message to every enabled webhook (no retries); records the
// outcome in state as last_notify_failure. Discord gets plain text; a custom
// POST body template is rendered with {{message}} = $msg. A missing state.json
// is not loaded (loading would recreate it as a fresh, disarmed one).
function dms_broadcast_plain($config, $msg, $state = null) {
    if ($state === null && file_exists(DMS_STATE_FILE)) $state = dms_load_state();
    $enabled = 0;
    $sent = 0;
    foreach (($config['webhooks'] ?? []) as $type => $wh) {
        if (empty($wh['enabled'])) continue;
        $enabled++;
        $r = dms_send_webhook($type, $wh, $msg, $config, $state, ['plain' => true]);
        if ($r['success']) $sent++;
        else dms_log("Plain broadcast via $type failed: {$r['message']}", 'WARN');
    }
    dms_record_notify_outcome($enabled, $sent);
    return ['enabled' => $enabled, 'sent' => $sent];
}

// last_notify_failure: the time of the FIRST failure in the current run of
// failures (every channel failed), cleared on any success. Written only on a
// transition, so a webhook outage costs no flash write per cron cycle.
// Skipped when state.json is unreadable (the locked writer would refuse anyway)
function dms_record_notify_outcome($enabled, $sent) {
    if ($enabled === 0) return;
    $cur = dms_load_state();
    if (dms_state_load_error() !== null) return;
    if (($sent > 0) === ($cur['last_notify_failure'] === null)) return;   // no transition
    dms_update_state(function($s) use ($sent) {
        if ($sent > 0) $s['last_notify_failure'] = null;
        elseif ($s['last_notify_failure'] === null) $s['last_notify_failure'] = date('c');
        return $s;
    });
}

// Is the External API URL really the DMS external API (port 3801)? Never
// called from the send path: only on save_config and once per boot from cron.
// 'ok' | 'unreachable' (DNS/connect/timeout: common hairpin-NAT case) | 'wrong_service' (answered, not the DMS API)
function dms_probe_external_url($url, &$http_code = null) {
    $ch = curl_init(rtrim($url, '/') . '/?action=health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_errno($ch); curl_close($ch);
    $http_code = $code;
    if ($body === false || $err !== 0 || $code === 0) return 'unreachable';
    $j = json_decode((string)$body, true);
    return ($code === 200 && is_array($j) && ($j['status'] ?? null) === 'ok') ? 'ok' : 'wrong_service';
}

function dms_send_uptime_kuma_heartbeat($config, $state) {
    $uk = $config['uptime_kuma'];
    if (!$uk['enabled'] || empty($uk['push_url'])) return;

    $remaining = dms_time_remaining($config, $state);
    $days_left = $remaining !== null ? round($remaining / 86400, 1) : null;
    $warning_days = $uk['warning_days'] ?? 7;

    // Determine up/down status for Uptime Kuma. An unreadable file means the
    // switch is not being evaluated: report down. (A corrupt config.json with
    // no .bak leaves push_url unknown, so syslog is the floor there.)
    if (dms_state_load_error() !== null || dms_config_load_error() !== null) {
        $uk_status = 'down';
        $msg = 'state/config unreadable';
    } elseif (!empty($state['recovered_at'])) {
        // A .bak restore disarmed the switch: not being evaluated until re-armed
        $uk_status = 'down';
        $msg = 'Restored from backup and DISARMED - re-arm required';
    } elseif (!$state['armed']) {
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
    } elseif ($days_left !== null && $days_left < $warning_days) {
        $uk_status = 'up';
        $msg = "{$days_left} days remaining - check in needed";
    } else {
        $uk_status = 'up';
        $msg = $days_left !== null ? "{$days_left} days remaining" : 'OK';
    }
    if (!empty($state['last_notify_failure'])) $msg .= ' (notifications failing since ' . $state['last_notify_failure'] . ')';

    // Rebuild the query string so status/msg/ping are always set, even when
    // the pasted push URL has no query parameters at all (a bare URL would
    // otherwise default to "up" on the Kuma side and never alert)
    $ping_val = $days_left !== null ? round($days_left * 24 * 60) : '';
    $parts = explode('?', $uk['push_url'], 2);
    parse_str($parts[1] ?? '', $params);
    $params['status'] = $uk_status;
    $params['msg'] = $msg;
    $params['ping'] = $ping_val;
    $url = $parts[0] . '?' . http_build_query($params);

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

    if ($minutes >= 60) {
        // */N in the minutes field is wrong past 59 (it would only match
        // minute 0) — schedule on the hour instead
        $hours = max(1, (int)round($minutes / 60));
        $schedule = ($hours === 1) ? "0 * * * *" : "0 */$hours * * *";
    } else {
        $schedule = "*/$minutes * * * *";
    }

    file_put_contents($cron_file, "$schedule $script >> $log 2>&1\n", LOCK_EX);
    exec('/usr/local/sbin/update_cron');
    dms_log("Cron schedule updated to every $minutes minutes");
}

function dms_execute_dry_run($config) {
    $results = [];

    foreach ($config['actions']['deletions'] as $deletion) {
        $path = $deletion['path'];
        $method = $deletion['method'] ?? 'standard';
        $matches = glob($path) ?: [];
        $exists = file_exists($path) || $matches;
        $results[] = [
            'type'        => 'deletion',
            'path'        => $path,
            'method'      => $method,
            'exists'      => (bool)$exists,
            'action'      => "Would delete: $path (method: $method)",
            'matches'     => array_slice($matches, 0, 20),
            'match_count' => count($matches),
            'danger'      => dms_classify_deletion_path($path),
        ];
    }

    foreach ($config['actions']['scripts'] as $script) {
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

    dms_log("Dry run completed - " . count($results) . " actions evaluated", 'INFO');
    return $results;
}

// Pages served by the quick check-in endpoint (external-api.php, port 3801).
// The confirm page POSTs back: GETs (link scanners, chat previews) never check in.
function dms_quick_checkin_page($status, $token = '') {
    $title = "Dead Man's Switch";
    $body = '';

    switch ($status) {
        case 'confirm':
            $action = htmlspecialchars('?action=quickcheckin&token=' . $token);
            $body = <<<HTML
            <div class="dms-card">
                <h1>$title</h1>
                <p>Click below to confirm your check-in.</p>
                <form method="post" action="$action"><button type="submit" class="dms-btn dms-btn-checkin">CONFIRM CHECK-IN</button></form>
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
        case 'inactive':
            $body = <<<HTML
            <div class="dms-card">
                <h1>$title</h1>
                <div class="dms-error">The switch is not armed or has already triggered — no check-in was recorded.</div>
                <p>Open the Unraid web UI to review the switch.</p>
            </div>
HTML;
            break;
        case 'error':
            $body = <<<HTML
            <div class="dms-card">
                <h1>$title</h1>
                <div class="dms-error">Could not save the check-in — the flash drive may be full or read-only.</div>
                <p>Check in from the Unraid web UI and check the flash drive.</p>
            </div>
HTML;
            break;
        case 'invalid':
        default:
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
        .dms-btn { display: inline-block; padding: 16px 48px; border: none; border-radius: 8px; cursor: pointer; font-family: inherit; text-decoration: none; font-weight: bold; font-size: 1.2em; margin: 20px 0; transition: all 0.2s; }
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
