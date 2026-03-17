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

# Mark as triggered (with file locking)
php -r "
    \$f = fopen('$STATE_FILE', 'c+');
    if (flock(\$f, LOCK_EX)) {
        \$state = json_decode(stream_get_contents(\$f), true);
        \$state['triggered'] = true;
        \$state['trigger_time'] = date('c');
        ftruncate(\$f, 0);
        rewind(\$f);
        fwrite(\$f, json_encode(\$state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush(\$f);
        flock(\$f, LOCK_UN);
    }
    fclose(\$f);
"

# Read actions from config
ACTIONS_JSON=$(php -r "\$c = json_decode(file_get_contents('$CONFIG_FILE'), true); echo json_encode(\$c['actions']);")

# Execute file deletions
php -r "
    \$actions = json_decode('$ACTIONS_JSON', true);
    \$dry = '$DRY_RUN' === '1';

    foreach (\$actions['deletions'] ?? [] as \$del) {
        \$path = \$del['path'];
        \$method = \$del['method'] ?? 'standard';
        \$matches = glob(\$path);
        if (\$matches === false || empty(\$matches)) {
            echo \"DELETE:\$method:\$path\n\";
        } else {
            foreach (\$matches as \$match) {
                echo \"DELETE:\$method:\$match\n\";
            }
        }
    }
" | while IFS=: read -r action method path; do
    if [ "$action" != "DELETE" ]; then continue; fi

    if [ "$DRY_RUN" = "1" ]; then
        log "[DRY RUN] Would delete: $path (method: $method)" "WARN"
        continue
    fi

    if [ ! -e "$path" ]; then
        log "Path not found: $path" "WARN"
        continue
    fi

    log "Deleting: $path (method: $method)" "CRITICAL"

    if [ "$method" = "secure" ]; then
        if [ -f "$path" ]; then
            shred -vfz -n 3 "$path" 2>> "$LOG_FILE" && rm -f "$path"
        elif [ -d "$path" ]; then
            find "$path" -type f -exec shred -vfz -n 3 {} \; 2>> "$LOG_FILE"
            rm -rf "$path"
        fi
    else
        rm -rf "$path"
    fi

    if [ $? -eq 0 ]; then
        log "Deleted: $path" "CRITICAL"
    else
        log "Failed to delete: $path" "ERROR"
    fi
done

# Execute custom scripts
php -r "
    \$actions = json_decode('$ACTIONS_JSON', true);
    foreach (\$actions['scripts'] ?? [] as \$script) {
        echo \$script['path'] . ':' . (\$script['timeout'] ?? 300) . \"\n\";
    }
" | while IFS=: read -r script_path timeout; do
    [ -z "$script_path" ] && continue

    if [ "$DRY_RUN" = "1" ]; then
        log "[DRY RUN] Would execute: $script_path (timeout: ${timeout}s)" "WARN"
        continue
    fi

    if [ ! -f "$script_path" ]; then
        log "Script not found: $script_path" "ERROR"
        continue
    fi

    if [ ! -x "$script_path" ]; then
        log "Script not executable: $script_path" "ERROR"
        continue
    fi

    log "Executing script: $script_path (timeout: ${timeout}s)" "CRITICAL"
    timeout "$timeout" bash "$script_path" >> "$LOG_FILE" 2>&1
    EXIT_CODE=$?

    if [ $EXIT_CODE -eq 0 ]; then
        log "Script completed: $script_path" "CRITICAL"
    elif [ $EXIT_CODE -eq 124 ]; then
        log "Script timed out: $script_path" "ERROR"
    else
        log "Script failed (exit $EXIT_CODE): $script_path" "ERROR"
    fi
done

# Update dry run completion flag (with file locking)
if [ "$DRY_RUN" = "1" ]; then
    php -r "
        \$f = fopen('$CONFIG_FILE', 'c+');
        if (flock(\$f, LOCK_EX)) {
            \$c = json_decode(stream_get_contents(\$f), true);
            \$c['has_completed_dry_run'] = true;
            ftruncate(\$f, 0);
            rewind(\$f);
            fwrite(\$f, json_encode(\$c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush(\$f);
            flock(\$f, LOCK_UN);
        }
        fclose(\$f);
    "
    log "=== DRY RUN COMPLETE ===" "WARN"
else
    log "=== ALL ACTIONS EXECUTED ===" "CRITICAL"
fi

# Send final notification
"$SCRIPT_DIR/notify.sh" "Dead Man's Switch has been triggered. All configured actions have been executed." "CRITICAL"
