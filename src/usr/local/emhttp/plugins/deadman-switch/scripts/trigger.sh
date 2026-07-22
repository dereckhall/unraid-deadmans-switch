#!/bin/bash
# Dead Man's Switch - Trigger Script
# Executes configured actions (deletions, scripts)

CONFIG_DIR="/boot/config/plugins/deadman-switch"
CONFIG_FILE="$CONFIG_DIR/config.json"
STATE_FILE="$CONFIG_DIR/state.json"
LOG_FILE="$CONFIG_DIR/logs/deadman.log"
SCRIPT_DIR="$(dirname "$0")"

log() {
    local level="${2:-INFO}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $1" >> "$LOG_FILE"
}

if [ ! -f "$CONFIG_FILE" ] || [ ! -f "$STATE_FILE" ]; then
    log "Config or state file missing, aborting trigger" "ERROR"
    exit 1
fi

# Check if dry run
DRY_RUN=$(php -r "\$c = json_decode(file_get_contents('$CONFIG_FILE'), true); echo \$c['dry_run'] ? '1' : '0';")

if [ "$DRY_RUN" = "1" ]; then
    log "=== TRIGGER ACTIVATED (DRY RUN MODE) ===" "WARN"
else
    log "=== TRIGGER ACTIVATED - EXECUTING ACTIONS ===" "CRITICAL"
fi

# Mark as triggered before running actions so a long action list can't cause
# repeat triggers on later cron cycles
php << 'PHPEOF'
<?php
require_once '/usr/local/emhttp/plugins/deadman-switch/include/helpers.php';
dms_update_state(function($s) {
    $s['triggered'] = true;
    $s['trigger_time'] = date('c');
    return $s;
});
PHPEOF

# Execute all actions from PHP. The quoted heredoc means no shell interpolation:
# paths with quotes, apostrophes, colons, or spaces reach PHP intact, and
# escapeshellarg() protects the commands PHP runs.
php << 'PHPEOF'
<?php
require_once '/usr/local/emhttp/plugins/deadman-switch/include/helpers.php';

$config = dms_load_config();
$dry = !empty($config['dry_run']);
$log_arg = escapeshellarg(DMS_LOG_FILE);
$failures = 0;

foreach ($config['actions']['deletions'] ?? [] as $del) {
    $method = $del['method'] ?? 'standard';
    $matches = glob($del['path']);
    if (empty($matches)) $matches = [$del['path']];

    foreach ($matches as $path) {
        if ($dry) {
            dms_log("[DRY RUN] Would delete: $path (method: $method)", 'WARN');
            continue;
        }
        if (!file_exists($path) && !is_link($path)) {
            dms_log("Path not found: $path", 'WARN');
            continue;
        }

        dms_log("Deleting: $path (method: $method)", 'CRITICAL');
        $arg = escapeshellarg($path);

        if ($method === 'secure') {
            if (is_file($path)) {
                exec("shred -vfz -n 3 $arg >> $log_arg 2>&1 && rm -f $arg");
            } else {
                // -H follows a symlinked start path so its contents are
                // shredded rather than silently skipped
                exec("find -H $arg -type f -exec shred -vfz -n 3 {} \\; >> $log_arg 2>&1");
                exec("rm -rf $arg >> $log_arg 2>&1");
            }
        } else {
            exec("rm -rf $arg >> $log_arg 2>&1");
        }

        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            dms_log("Deleted: $path", 'CRITICAL');
        } else {
            dms_log("Failed to delete: $path", 'ERROR');
            $failures++;
        }
    }
}

foreach ($config['actions']['scripts'] ?? [] as $script) {
    $path = $script['path'] ?? '';
    if ($path === '') continue;
    $timeout = intval($script['timeout'] ?? 300);

    if ($dry) {
        dms_log("[DRY RUN] Would execute: $path (timeout: {$timeout}s)", 'WARN');
        continue;
    }
    if (!is_file($path)) {
        dms_log("Script not found: $path", 'ERROR');
        $failures++;
        continue;
    }
    if (!is_executable($path)) {
        dms_log("Script not executable (chmod +x to enable): $path", 'ERROR');
        $failures++;
        continue;
    }

    dms_log("Executing script: $path (timeout: {$timeout}s)", 'CRITICAL');
    exec('timeout ' . $timeout . ' bash ' . escapeshellarg($path) . " >> $log_arg 2>&1", $out, $code);

    if ($code === 0) {
        dms_log("Script completed: $path", 'CRITICAL');
    } elseif ($code === 124) {
        dms_log("Script timed out: $path", 'ERROR');
        $failures++;
    } else {
        dms_log("Script failed (exit $code): $path", 'ERROR');
        $failures++;
    }
}

if ($dry) {
    dms_update_config(function($c) {
        $c['has_completed_dry_run'] = true;
        return $c;
    });
    dms_log('=== DRY RUN COMPLETE ===', 'WARN');
} elseif ($failures > 0) {
    dms_log("=== ACTIONS COMPLETED WITH $failures FAILURE(S) - CHECK LOG ===", 'ERROR');
} else {
    dms_log('=== ALL ACTIONS EXECUTED ===', 'CRITICAL');
}
PHPEOF

# Send final notification (state is now triggered, so the rendered message
# and embed report the trigger accurately)
"$SCRIPT_DIR/notify.sh" "triggered"
