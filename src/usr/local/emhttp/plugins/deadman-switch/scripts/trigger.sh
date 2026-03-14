#!/bin/bash
# Dead Man's Switch - Trigger Script
# Executes configured actions (deletions, scripts, docker)

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

# Mark as triggered
php -r "
    \$state = json_decode(file_get_contents('$STATE_FILE'), true);
    \$state['triggered'] = true;
    \$state['trigger_time'] = date('c');
    file_put_contents('$STATE_FILE', json_encode(\$state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        echo \"DELETE:\$method:\$path\n\";
    }
" | while IFS=: read -r action method path; do
    if [ "$action" != "DELETE" ]; then continue; fi

    if [ "$DRY_RUN" = "1" ]; then
        log "[DRY RUN] Would delete: $path (method: $method)" "WARN"
        continue
    fi

    log "Deleting: $path (method: $method)" "CRITICAL"

    # Expand globs
    shopt -s nullglob
    for target in $path; do
        if [ ! -e "$target" ]; then
            log "Path not found: $target" "WARN"
            continue
        fi

        if [ "$method" = "secure" ]; then
            if [ -f "$target" ]; then
                shred -vfz -n 3 "$target" 2>> "$LOG_FILE" && rm -f "$target"
            elif [ -d "$target" ]; then
                find "$target" -type f -exec shred -vfz -n 3 {} \; 2>> "$LOG_FILE"
                rm -rf "$target"
            fi
        else
            rm -rf "$target"
        fi

        if [ $? -eq 0 ]; then
            log "Deleted: $target" "CRITICAL"
        else
            log "Failed to delete: $target" "ERROR"
        fi
    done
    shopt -u nullglob
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

# Docker actions
php -r "
    \$actions = json_decode('$ACTIONS_JSON', true);
    \$docker = \$actions['docker'] ?? [];
    echo 'STOP:' . implode(',', \$docker['stop'] ?? []) . \"\n\";
    echo 'REMOVE:' . implode(',', \$docker['remove'] ?? []) . \"\n\";
    echo 'VOLUMES:' . implode(',', \$docker['volumes'] ?? []) . \"\n\";
" | while IFS=: read -r action items; do
    [ -z "$items" ] && continue

    IFS=',' read -ra LIST <<< "$items"
    for item in "${LIST[@]}"; do
        [ -z "$item" ] && continue

        case "$action" in
            STOP)
                if [ "$DRY_RUN" = "1" ]; then
                    log "[DRY RUN] Would stop container: $item" "WARN"
                else
                    log "Stopping container: $item" "CRITICAL"
                    docker stop "$item" 2>> "$LOG_FILE"
                fi
                ;;
            REMOVE)
                if [ "$DRY_RUN" = "1" ]; then
                    log "[DRY RUN] Would remove container: $item" "WARN"
                else
                    log "Removing container: $item" "CRITICAL"
                    docker rm -f "$item" 2>> "$LOG_FILE"
                fi
                ;;
            VOLUMES)
                if [ "$DRY_RUN" = "1" ]; then
                    log "[DRY RUN] Would delete volume: $item" "WARN"
                else
                    log "Deleting volume: $item" "CRITICAL"
                    docker volume rm "$item" 2>> "$LOG_FILE"
                fi
                ;;
        esac
    done
done

# Update dry run completion flag
if [ "$DRY_RUN" = "1" ]; then
    php -r "
        \$c = json_decode(file_get_contents('$CONFIG_FILE'), true);
        \$c['has_completed_dry_run'] = true;
        file_put_contents('$CONFIG_FILE', json_encode(\$c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    "
    log "=== DRY RUN COMPLETE ===" "WARN"
else
    log "=== ALL ACTIONS EXECUTED ===" "CRITICAL"
fi

# Send final notification
"$SCRIPT_DIR/notify.sh" "Dead Man's Switch has been triggered. All configured actions have been executed." "CRITICAL"
