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

# Cap log growth on the flash drive (keep the newest entries). Truncate in
# place rather than mv: long-running processes (php -S, cron's >>) hold the
# inode open and would keep writing to an unlinked file after a rename.
for f in "$LOG_FILE" "$CONFIG_DIR/logs/cron.log" "$CONFIG_DIR/logs/api-server.log"; do
    if [ -f "$f" ] && [ "$(stat -c%s "$f" 2>/dev/null || echo 0)" -gt 1048576 ]; then
        tail -n 2000 "$f" > "$f.tmp" && cat "$f.tmp" > "$f"
        rm -f "$f.tmp"
    fi
done

# Ensure external API server is running (self-healing)
PID_FILE="$CONFIG_DIR/api-server.pid"
if [ ! -f "$PID_FILE" ] || ! kill -0 "$(cat "$PID_FILE" 2>/dev/null)" 2>/dev/null; then
    "$SCRIPT_DIR/start-api.sh" 2>/dev/null
fi

if [ ! -f "$CONFIG_FILE" ] || [ ! -f "$STATE_FILE" ]; then
    exit 0
fi

# Persist the highest notified level only after a delivery succeeded, so a
# failed send is retried on the next cron cycle. $2 is the epoch of the
# check-in the notification was computed from: if the user checked in while
# notify.sh was sending, last_checkin has moved and writing the old level
# would suppress the whole next interval's warnings.
persist_level() {
    php -r "
        require_once '/usr/local/emhttp/plugins/deadman-switch/include/helpers.php';
        \$lvl = \$argv[1];
        \$ref = intval(\$argv[2]);
        dms_update_state(function(\$s) use (\$lvl, \$ref) {
            if (\$s['last_checkin'] === null || strtotime(\$s['last_checkin']) !== \$ref) {
                return \$s;
            }
            \$s['last_notification_level'] = \$lvl;
            return \$s;
        });
    " -- "$1" "$2"
}

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
            \$updated = dms_update_state(function(\$s) {
                // The countdown is frozen while paused: credit the pause
                // duration (up to its scheduled expiry) to last_checkin
                if (\$s['pause_start'] && \$s['last_checkin']) {
                    \$end = \$s['pause_expires'] ? min(time(), strtotime(\$s['pause_expires'])) : time();
                    \$paused_secs = \$end - strtotime(\$s['pause_start']);
                    if (\$paused_secs > 0) {
                        \$s['last_checkin'] = date('c', min(time(), strtotime(\$s['last_checkin']) + \$paused_secs));
                    }
                }
                \$s['paused'] = false;
                \$s['pause_start'] = null;
                \$s['pause_expires'] = null;
                return \$s;
            });
            if (is_array(\$updated)) \$state = \$updated;
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
            \$enabled = 0;
            \$sent = 0;
            foreach (\$config['webhooks'] as \$type => \$wh) {
                if (!\$wh['enabled']) continue;
                \$enabled++;
                \$r = dms_send_webhook(\$type, \$wh, \$msg);
                if (\$r['success']) \$sent++;
            }
            // Only mark as notified once a send succeeded (or none configured),
            // so a failed alert is retried next cycle
            if (\$enabled === 0 || \$sent > 0) {
                dms_update_state(function(\$s) {
                    \$s['last_notification_level'] = 'clock_anomaly';
                    return \$s;
                });
            }
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

        // Start grace period if not already started. The callback rechecks
        // under the lock so an overlapping run can't double-count the miss.
        if (!\$state['grace_period_start']) {
            \$updated = dms_update_state(function(\$s) {
                if (!\$s['grace_period_start']) {
                    \$s['grace_period_start'] = date('c');
                    \$s['missed_count'] = (\$s['missed_count'] ?? 0) + 1;
                }
                return \$s;
            });
            if (is_array(\$updated)) \$state = \$updated;
        }

        // Check double-miss requirement
        if (\$config['double_miss'] && \$state['missed_count'] < 2) {
            // Send a plain message directly: the templated notification would
            // render from the reset state below and read as an all-clear
            \$msg = \"\xE2\x9A\xA0\xEF\xB8\x8F Dead Man's Switch: Missed check-in #1 recorded (double-miss protection is on). \" .
                   \"The timer has been reset \xE2\x80\x94 missing the NEXT deadline will trigger your configured actions.\";
            \$enabled = 0;
            \$sent = 0;
            foreach (\$config['webhooks'] as \$type => \$wh) {
                if (!\$wh['enabled']) continue;
                \$enabled++;
                \$r = dms_send_webhook(\$type, \$wh, \$msg);
                if (\$r['success']) \$sent++;
            }
            // Only reset the timer once the warning went out (or no channels
            // are configured): resetting on a failed send would let the next
            // miss trigger destructive actions with zero warning received.
            // The double-miss guard above keeps this from ever triggering
            // while the send keeps failing.
            if (\$enabled === 0 || \$sent > 0) {
                dms_update_state(function(\$s) {
                    \$s['last_checkin'] = date('c');
                    \$s['grace_period_start'] = null;
                    \$s['last_notification_level'] = null;
                    return \$s;
                });
                dms_log('First miss recorded (double-miss enabled). Timer reset.', 'WARN');
                echo 'STATUS:double_miss_first_miss';
            } else {
                dms_log('First-miss warning failed on all channels; retrying next cycle before resetting timer', 'WARN');
                echo 'STATUS:double_miss_notify_failed';
            }
            exit;
        }

        // Grace period elapsed?
        if (time() >= \$grace_end) {
            echo 'TRIGGER:grace_expired';
            exit;
        }

        // In grace period - notify once; the level is persisted by the caller
        // only after the notification actually goes out. The epoch field lets
        // the caller detect a check-in that landed while sending.
        if (\$last_level !== 'grace') {
            echo 'NOTIFY:grace:' . strtotime(\$state['last_checkin']) . ':Grace period active. Actions will execute at ' . date('M j, Y g:i A', \$grace_end);
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
        \$days = round(\$remaining / 86400, 1);
        echo 'NOTIFY:' . \$warning_level . ':' . strtotime(\$state['last_checkin']) . \":\$days days remaining\";
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
            log "Pause expired, timer automatically resumed (deadline extended by pause duration)"
            ;;
        NOTIFY)
            LEVEL=$(echo "$DETAIL" | cut -d: -f1)
            CHECKIN_REF=$(echo "$DETAIL" | cut -d: -f2)
            MSG=$(echo "$DETAIL" | cut -d: -f3-)
            log "Sending $LEVEL notification: $MSG"
            if "$SCRIPT_DIR/notify.sh" "$LEVEL"; then
                persist_level "$LEVEL" "$CHECKIN_REF"
            else
                log "Notification delivery failed; will retry next cron cycle" "WARN"
            fi
            ;;
        TRIGGER)
            log "TRIGGER CONDITION MET: $DETAIL" "CRITICAL"
            "$SCRIPT_DIR/trigger.sh"
            ;;
    esac
done <<< "$RESULT"
