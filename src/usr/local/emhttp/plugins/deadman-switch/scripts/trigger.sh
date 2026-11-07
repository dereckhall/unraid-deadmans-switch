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
    # Mirror serious problems to syslog, like dms_log() does in PHP
    if [ "$level" = "ERROR" ] || [ "$level" = "CRITICAL" ]; then
        command -v logger >/dev/null 2>&1 && logger -t deadman-switch "[$level] $1" 2>/dev/null
    fi
    return 0
}

if [ ! -f "$CONFIG_FILE" ] || [ ! -f "$STATE_FILE" ]; then
    log "Config or state file missing, aborting trigger" "ERROR"
    exit 1
fi

# Once per boot. mkdir is atomic: overlapping cron runs cannot both pass.
# RAM: survives a dead flash; removed by arm/disarm or reboot. Checked before
# the banner so a refused run does not log a second TRIGGER ACTIVATED.
RUN_DIR="/var/run/deadman-switch"; MARKER="$RUN_DIR/triggered"; mkdir -p "$RUN_DIR"
if ! mkdir "$MARKER" 2>/dev/null; then
    if [ -d "$MARKER" ]; then
        log "Trigger already executed this boot (or a run is in progress); refusing to run actions again" "ERROR"
        exit 0
    fi
    # The RAM dir itself is unusable: never let that block the trigger; fall
    # back to the persisted triggered flag alone
    log "Could not create trigger marker $MARKER; proceeding without the once-per-boot guard" "ERROR"
fi

# Check if dry run (same loader as the action runner below; last line only, in
# case PHP prints a warning on stdout; anything but an explicit 0 = dry-run banner)
DRY_RUN=$(php -r 'require_once "/usr/local/emhttp/plugins/deadman-switch/include/helpers.php"; echo "\n", dms_load_config()["dry_run"] ? 1 : 0;' 2>/dev/null | tail -n 1)
[ "$DRY_RUN" = "0" ] || DRY_RUN=1

if [ "$DRY_RUN" = "1" ]; then
    log "=== TRIGGER ACTIVATED (DRY RUN MODE) ===" "WARN"
else
    log "=== TRIGGER ACTIVATED - EXECUTING ACTIONS ===" "CRITICAL"
fi

# Mark as triggered before running actions so a long action list can't cause
# repeat triggers on later cron cycles. On a dead flash the RAM marker above
# still holds: actions run once per boot, not never and not every cycle.
if ! php -r 'require_once "/usr/local/emhttp/plugins/deadman-switch/include/helpers.php"; exit(dms_update_state(function($s){ $s["triggered"]=true; $s["trigger_time"]=date("c"); return $s; }) === false ? 1 : 0);'; then
    log "Could not persist triggered=true to flash; actions will run ONCE this boot (RAM marker set)" "ERROR"
fi

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
        // A system root is never deleted, even from a config saved before
        // save-time validation existed. Whole-array paths run as configured.
        if (dms_classify_deletion_path($path) === 'forbidden') {
            dms_log("REFUSED (system root): $path", 'ERROR');
            $failures++;
            continue;
        }
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
