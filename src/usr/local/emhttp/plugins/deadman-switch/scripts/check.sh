#!/bin/bash
# Dead Man's Switch - Cron Check Script
# Evaluates state and sends appropriate notifications

CONFIG_DIR="/boot/config/plugins/deadman-switch"
CONFIG_FILE="$CONFIG_DIR/config.json"
STATE_FILE="$CONFIG_DIR/state.json"
LOG_FILE="$CONFIG_DIR/logs/deadman.log"
SCRIPT_DIR="$(dirname "$0")"

log() {
    local level="${2:-INFO}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $1" >> "$LOG_FILE"
}

# Ensure log directory exists
mkdir -p "$CONFIG_DIR/logs"

# Ensure external API server is running (self-healing)
PID_FILE="$CONFIG_DIR/api-server.pid"
if [ ! -f "$PID_FILE" ] || ! kill -0 "$(cat "$PID_FILE" 2>/dev/null)" 2>/dev/null; then
    "$SCRIPT_DIR/start-api.sh" 2>/dev/null
fi

if [ ! -f "$CONFIG_FILE" ] || [ ! -f "$STATE_FILE" ]; then
    exit 0
fi

# Use PHP to evaluate state (reuses helpers.php logic)
RESULT=$(php -r "
    require_once '/usr/local/emhttp/plugins/deadman-switch/include/helpers.php';

    \$config = dms_load_config();
    \$state = dms_load_state();

    // Check if armed
    if (!\$state['armed']) {
        echo 'STATUS:disarmed';
        exit;
    }

    // Check if already triggered
    if (\$state['triggered']) {
        echo 'STATUS:triggered';
        exit;
    }

    // Check if paused
    if (\$state['paused']) {
        // Check if pause has expired
        if (\$state['pause_expires'] && strtotime(\$state['pause_expires']) < time()) {
            \$state['paused'] = false;
            \$state['pause_start'] = null;
            \$state['pause_expires'] = null;
            dms_save_state(\$state);
            echo 'UNPAUSE:auto' . \"\n\";
        } else {
            echo 'STATUS:paused';
            exit;
        }
    }

    // No check-in recorded yet
    if (!\$state['last_checkin']) {
        echo 'STATUS:no_checkin';
        exit;
    }

    // Clock sanity check: system time should never be before last check-in
    // Protects against BIOS battery failure / clock reset causing the switch to go inert
    if (time() < strtotime(\$state['last_checkin']) - 300) {
        if (\$state['last_notification_level'] !== 'clock_anomaly') {
            \$sys_time = date('Y-m-d H:i:s');
            \$checkin_time = date('Y-m-d H:i:s', strtotime(\$state['last_checkin']));
            \$msg = \"\xE2\x9A\xA0\xEF\xB8\x8F Dead Man's Switch: System clock anomaly detected. \" .
                    \"Clock (\$sys_time) is behind last check-in (\$checkin_time). \" .
                    \"Timer cannot function correctly until clock is corrected.\";
            foreach (\$config['webhooks'] as \$type => \$wh) {
                if (!\$wh['enabled']) continue;
                dms_send_webhook(\$type, \$wh, \$msg);
            }
            \$state['last_notification_level'] = 'clock_anomaly';
            dms_save_state(\$state);
        }
        echo 'STATUS:clock_anomaly';
        exit;
    }

    \$remaining = dms_time_remaining(\$config, \$state);
    \$total = \$config['checkin_interval_days'] * 86400;
    \$warning_level = dms_get_warning_level(\$config, \$state);
    \$last_level = \$state['last_notification_level'];

    // Timer expired
    if (\$remaining <= 0) {
        \$grace_end = dms_get_grace_end(\$config, \$state);

        // Start grace period if not already started
        if (!\$state['grace_period_start']) {
            \$state['grace_period_start'] = date('c');
            \$state['missed_count'] = (\$state['missed_count'] ?? 0) + 1;
            dms_save_state(\$state);
        }

        // Check double-miss requirement
        if (\$config['double_miss'] && \$state['missed_count'] < 2) {
            // Reset for next interval instead of triggering
            \$state['last_checkin'] = date('c');
            \$state['grace_period_start'] = null;
            \$state['last_notification_level'] = null;
            dms_save_state(\$state);
            echo 'NOTIFY:grace:First miss recorded (double-miss enabled). Timer reset.';
            exit;
        }

        // Grace period elapsed?
        if (time() >= \$grace_end) {
            echo 'TRIGGER:grace_expired';
            exit;
        }

        // In grace period - only notify once (when grace starts), not every cron cycle
        if (\$last_level !== 'grace') {
            \$state['last_notification_level'] = 'grace';
            dms_save_state(\$state);
            echo 'NOTIFY:grace:Grace period active. Actions will execute at ' . date('M j, Y g:i A', \$grace_end);
        } else {
            echo 'STATUS:grace_already_notified';
        }
        exit;
    }

    // Check warning thresholds - only notify if level has escalated
    \$level_order = ['none' => 0, 'reminder' => 1, 'warning' => 2, 'critical' => 3, 'last_chance' => 4];
    \$current_order = \$level_order[\$warning_level] ?? 0;
    \$last_order = \$level_order[\$last_level] ?? 0;

    if (\$current_order > 0 && \$current_order > \$last_order) {
        \$state['last_notification_level'] = \$warning_level;
        dms_save_state(\$state);
        \$days = round(\$remaining / 86400, 1);
        echo \"NOTIFY:\$warning_level:\$days days remaining\";
        exit;
    }

    echo 'STATUS:ok';
")

# Send Uptime Kuma heartbeat (runs every cron cycle regardless of state)
php -r "
    require_once '/usr/local/emhttp/plugins/deadman-switch/include/helpers.php';
    \$config = dms_load_config();
    \$state = dms_load_state();
    dms_send_uptime_kuma_heartbeat(\$config, \$state);
" 2>/dev/null

# Parse result
while IFS= read -r line; do
    ACTION=$(echo "$line" | cut -d: -f1)
    DETAIL=$(echo "$line" | cut -d: -f2-)

    case "$ACTION" in
        STATUS)
            log "Cron check: $DETAIL"
            ;;
        UNPAUSE)
            log "Pause expired, timer automatically resumed"
            ;;
        NOTIFY)
            LEVEL=$(echo "$DETAIL" | cut -d: -f1)
            MSG=$(echo "$DETAIL" | cut -d: -f2-)
            log "Sending $LEVEL notification: $MSG"
            "$SCRIPT_DIR/notify.sh" "$LEVEL"
            ;;
        TRIGGER)
            log "TRIGGER CONDITION MET: $DETAIL" "CRITICAL"
            "$SCRIPT_DIR/trigger.sh"
            ;;
    esac
done <<< "$RESULT"
