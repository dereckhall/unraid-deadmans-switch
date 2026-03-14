#!/bin/bash
# Dead Man's Switch - Check-in Script
# Records a check-in from the command line

CONFIG_DIR="/boot/config/plugins/deadman-switch"
STATE_FILE="$CONFIG_DIR/state.json"
LOG_FILE="$CONFIG_DIR/logs/deadman.log"

log() {
    local level="${2:-INFO}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $1" >> "$LOG_FILE"
}

if [ ! -f "$STATE_FILE" ]; then
    echo "Error: State file not found. Is the plugin installed?"
    exit 1
fi

METHOD="${1:-cli}"
NOW=$(date -Iseconds)

# Update state file using PHP for proper JSON handling
php -r "
    \$state = json_decode(file_get_contents('$STATE_FILE'), true);
    \$state['last_checkin'] = '$NOW';
    \$state['last_checkin_method'] = '$METHOD';
    \$state['missed_count'] = 0;
    \$state['last_notification_level'] = null;
    \$state['grace_period_start'] = null;
    array_unshift(\$state['checkin_history'], ['time' => '$NOW', 'method' => '$METHOD']);
    \$state['checkin_history'] = array_slice(\$state['checkin_history'], 0, 10);
    file_put_contents('$STATE_FILE', json_encode(\$state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
"

log "Check-in recorded via $METHOD"
echo "Check-in recorded at $NOW (method: $METHOD)"
