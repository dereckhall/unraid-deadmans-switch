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
    # Mirror serious problems to syslog, like dms_log() does in PHP
    if [ "$level" = "ERROR" ] || [ "$level" = "CRITICAL" ]; then
        command -v logger >/dev/null 2>&1 && logger -t deadman-switch "[$level] $1" 2>/dev/null
    fi
    return 0
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
. "$SCRIPT_DIR/api-common.sh"
API_PID=$(cat "$PID_FILE" 2>/dev/null)
api_pid_is_ours "$API_PID" || "$SCRIPT_DIR/start-api.sh" 2>/dev/null

# Per-boot RAM markers (alert once-ness, trigger.sh's 'triggered' marker);
# RUN_DIR comes from api-common.sh
mkdir -p "$RUN_DIR"
alert_once() {   # <name> <message> [nostate]: broadcast once per boot (RAM marker)
    local marker="$RUN_DIR/alert.$1"; [ -e "$marker" ] && return 0
    # With an unreadable config, zero enabled webhooks is not success: the
    # alert is retried each cycle (syslog + Kuma down are the floor).
    # nostate: state.json is known to be unreadable or untrustworthy here, so
    # it is not loaded and every channel gets the raw message text
    if php -r 'require_once "/usr/local/emhttp/plugins/deadman-switch/include/helpers.php";
        $cfg = dms_load_config();
        $r = dms_broadcast_plain($cfg, $argv[1], ($argv[2] ?? "") === "nostate" ? false : null);
        exit(($r["sent"] > 0 || ($r["enabled"] === 0 && dms_config_load_error() === null)) ? 0 : 1);' -- "$2" "${3:-}" 2>/dev/null; then touch "$marker"; fi
}

# Not silent: syslog always, the flash log if it is writable. No webhook alert
# here: loading a missing state.json would recreate it as a fresh, disarmed one
if [ ! -f "$CONFIG_FILE" ] || [ ! -f "$STATE_FILE" ]; then
    MISSING_MSG="config.json or state.json missing under $CONFIG_DIR - the switch is NOT being evaluated (flash drive unmounted?)"
    command -v logger >/dev/null 2>&1 && logger -t deadman-switch "ERROR: $MISSING_MSG" 2>/dev/null
    { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [ERROR] $MISSING_MSG" >> "$LOG_FILE"; } 2>/dev/null
    exit 0
fi

# External API URL check, once per boot (the probe can take up to 5 s). A URL
# that answers but is not the DMS API (e.g. the Unraid web UI, as allowed
# before 2026.07.22a) breaks every check-in link: log ERROR and alert. An
# unreachable URL is normal without NAT loopback: INFO only. Not marked done
# while config.json is unreadable or a wrong_service alert is still unsent.
if [ ! -e "$RUN_DIR/probe.external-url" ]; then
    PROBE=$(php -r 'require_once "/usr/local/emhttp/plugins/deadman-switch/include/helpers.php";
        $c = dms_load_config();
        if (dms_config_load_error() !== null) { echo "skip"; exit; }
        $u = trim((string)($c["external_url"] ?? ""));
        if ($u === "") { echo "none"; exit; }
        $code = 0; $r = dms_probe_external_url($u, $code);
        echo $r, " ", $code, " ", $u;' 2>/dev/null | tail -n 1)
    read -r PROBE_RESULT PROBE_CODE PROBE_URL <<< "$PROBE"
    case "$PROBE_RESULT" in
        ok|none)
            touch "$RUN_DIR/probe.external-url"
            ;;
        unreachable)
            log "External API URL $PROBE_URL could not be reached from this server (expected if your network lacks NAT loopback; otherwise check the proxy)"
            touch "$RUN_DIR/probe.external-url"
            ;;
        wrong_service)
            log "External API URL $PROBE_URL answers (HTTP $PROBE_CODE) but is not the Dead Man's Switch API; check-in links in notifications will not work" ERROR
            alert_once external-url "External API URL $PROBE_URL answers but does not reach the Dead Man's Switch API on $(hostname). Check-in links in notifications will not work. Fix it under Settings or clear it to use the LAN address."
            [ -e "$RUN_DIR/alert.external-url" ] && touch "$RUN_DIR/probe.external-url"
            ;;
    esac
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

    // Fail closed: an unreadable file means we don't know the real state, and
    // an unparseable last_checkin would give a null deadline (null <= 0 and
    // time() >= null both hold in PHP, i.e. a wrong TRIGGER)
    if (dms_state_load_error() !== null || dms_config_load_error() !== null) { echo 'STATUS:state_unreadable'; exit; }

    // A .bak restore (which also disarms) is reported before the armed check;
    // epoch first because the ISO last_checkin contains colons
    if (!empty(\$state['recovered_at'])) echo 'RECOVERED:' . (dms_parse_time(\$state['recovered_at']) ?? time()) . ':' . (\$state['last_checkin'] ?? 'none') . \"\n\";

    // Check if armed
    if (!\$state['armed']) {
        echo 'STATUS:disarmed';
        exit;
    }

    // After the armed check: a disarmed switch cannot trigger, so a garbage
    // last_checkin there is not an error every cycle (arming rewrites it)
    if (\$state['last_checkin'] !== null && dms_parse_time(\$state['last_checkin']) === null) { echo 'STATUS:state_invalid'; exit; }

    // Check if already triggered
    if (\$state['triggered']) {
        echo 'STATUS:triggered';
        exit;
    }

    // Check if paused
    if (\$state['paused']) {
        // Check if pause has expired
        \$pause_exp = dms_parse_time(\$state['pause_expires'] ?? null);
        if (\$pause_exp !== null && \$pause_exp < time()) {
            \$updated = dms_update_state(function(\$s) {
                // The countdown is frozen while paused: credit the pause
                // duration (up to its scheduled expiry) to last_checkin
                return dms_apply_unpause(\$s);
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
            \$r = dms_broadcast_plain(\$config, \$msg);
            // Only mark as notified once a send succeeded (or none configured),
            // so a failed alert is retried next cycle
            if (\$r['enabled'] === 0 || \$r['sent'] > 0) {
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
    \$warning_level = dms_get_warning_level(\$config, \$state);
    \$last_level = \$state['last_notification_level'];

    // Belt and braces: a null remaining would pass <= 0 below (wrong TRIGGER)
    if (\$remaining === null) { echo 'STATUS:state_invalid'; exit; }

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
            if (is_array(\$updated)) {
                \$state = \$updated;
            } else {
                // Flash not writable: count this miss in memory so a recorded
                // first miss (missed_count 1 on disk) still reaches the
                // trigger path on the second miss
                \$state['missed_count'] = intval(\$state['missed_count'] ?? 0) + 1;
                dms_log('Flash write failed; counting this missed deadline (#' . \$state['missed_count'] . ') in memory for this run', 'WARN');
            }
        }

        // Check double-miss requirement
        if (\$config['double_miss'] && \$state['missed_count'] < 2) {
            // The reset below must reach the flash drive, or the switch would
            // announce a reset it never made and later miss #2 could not be
            // counted. Probe with a no-op locked write BEFORE broadcasting so
            // a dead flash parks the switch without a misleading webhook
            if (dms_update_state(function(\$s) { return \$s; }) === false) {
                dms_log('First-miss reset could NOT be persisted (flash write failed); double-miss cannot be honored until the flash drive is writable. The switch is parked and will NOT trigger.', 'ERROR');
                echo 'STATUS:double_miss_reset_failed';
                exit;
            }
            // Send a plain message directly: the templated notification would
            // render from the reset state below and read as an all-clear
            \$msg = \"\xE2\x9A\xA0\xEF\xB8\x8F Dead Man's Switch: Missed check-in #1 recorded (double-miss protection is on). \" .
                   \"The timer has been reset \xE2\x80\x94 missing the NEXT deadline will trigger your configured actions.\";
            \$r = dms_broadcast_plain(\$config, \$msg);
            // Only reset the timer once the warning went out (or no channels
            // are configured): resetting on a failed send would let the next
            // miss trigger destructive actions with zero warning received.
            // Bounded by the grace period: a webhook that stays broken must
            // not park the switch forever (Kuma down + the Settings banner
            // cover the retry window). Only miss #2 can reach TRIGGER.
            if (\$r['enabled'] === 0 || \$r['sent'] > 0 || time() >= \$grace_end) {
                if (\$r['sent'] === 0 && \$r['enabled'] > 0) dms_log('First-miss warning undeliverable for the whole grace period; resetting timer anyway. CHECK YOUR WEBHOOKS.', 'ERROR');
                \$reset = dms_update_state(function(\$s) {
                    \$s['last_checkin'] = date('c');
                    \$s['grace_period_start'] = null;
                    \$s['last_notification_level'] = null;
                    return \$s;
                });
                if (\$reset === false) {
                    // The flash failed between the probe and this write
                    dms_log('First-miss reset could NOT be persisted (flash write failed); double-miss cannot be honored until the flash drive is writable. The switch is parked and will NOT trigger.', 'ERROR');
                    echo 'STATUS:double_miss_reset_failed';
                    exit;
                }
                dms_log('First miss recorded (double-miss enabled). Timer reset.', 'WARN');
                echo 'STATUS:double_miss_first_miss';
            } else {
                dms_log('First-miss warning failed on all channels; retrying until the grace period ends before resetting timer', 'WARN');
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
" 2>&1); RC=$?

# Send Uptime Kuma heartbeat (runs every cron cycle regardless of state)
php -r "
    require_once '/usr/local/emhttp/plugins/deadman-switch/include/helpers.php';
    \$config = dms_load_config();
    \$state = dms_load_state();
    dms_send_uptime_kuma_heartbeat(\$config, \$state);
" 2>/dev/null

# Parse result. One event per line; PHP warnings/fatals also land on stdout
SEEN=0; ERRS=0; HN=$(hostname)
while IFS= read -r line; do
    [ -z "$line" ] && continue
    ACTION=${line%%:*}
    DETAIL=${line#*:}

    case "$ACTION" in
        STATUS)
            SEEN=1
            case "$DETAIL" in
                state_unreadable)
                    log "state.json/config.json unreadable — the switch is NOT being evaluated" ERROR
                    alert_once state-unreadable "Dead Man's Switch on $HN: state.json or config.json on the flash drive is unreadable. The switch is not being evaluated until it is repaired (see Settings > Dead Man's Switch)." nostate
                    ;;
                state_invalid)
                    log "last_checkin is not a parseable timestamp — the switch is NOT being evaluated" ERROR
                    alert_once state-invalid "Dead Man's Switch on $HN: state.json holds an unparseable last_checkin. Check in, or disarm and re-arm, to repair it." nostate
                    ;;
                double_miss_reset_failed)
                    log "Double-miss first-miss reset could not be written to the flash drive — the switch is parked and will NOT trigger" ERROR
                    alert_once double-miss-reset-failed "Dead Man's Switch on $HN: the flash drive cannot be written, so the missed check-in or the timer reset could not be recorded. Double-miss protection cannot be honored and the switch will NOT trigger until the flash drive is writable. Check the server." nostate
                    ;;
                clock_anomaly)
                    log "System clock is behind the last check-in - the switch is NOT being evaluated until the clock is corrected" ERROR
                    ;;
                *)
                    log "Cron check: $DETAIL"
                    ;;
            esac
            ;;
        RECOVERED)
            SEEN=1
            REC_EPOCH=${DETAIL%%:*}
            REC_CHECKIN=${DETAIL#*:}
            REC_AT=$(date -d @"$REC_EPOCH" '+%Y-%m-%d %H:%M:%S' 2>/dev/null) || REC_AT="$REC_EPOCH"
            [ -n "$REC_AT" ] || REC_AT="$REC_EPOCH"
            log "State or settings were restored from a backup at $REC_AT and the switch was DISARMED as a precaution. last_checkin is now $REC_CHECKIN — verify Last Check-in and the Actions tab, then re-arm in Settings" ERROR
            alert_once state-recovered "Dead Man's Switch on $HN: state or settings were restored from a backup at $REC_AT and the switch was DISARMED as a precaution. Last check-in is now $REC_CHECKIN. Verify Last Check-in and the Actions tab in Settings > Dead Man's Switch, then re-arm the switch." nostate
            ;;
        UNPAUSE)
            SEEN=1
            log "Pause expired, timer automatically resumed (remaining time preserved)"
            ;;
        NOTIFY)
            SEEN=1
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
            SEEN=1
            log "TRIGGER CONDITION MET: $DETAIL" "CRITICAL"
            if [ -d "$RUN_DIR/triggered" ]; then
                log "Trigger already executed or in progress this boot but state could not be persisted; skipping repeat" ERROR
            else
                "$SCRIPT_DIR/trigger.sh"
            fi
            ;;
        *)
            ERRS=$((ERRS+1))
            [ "$ERRS" -le 5 ] && log "Unrecognized evaluator output: $line" ERROR
            ;;
    esac
done <<< "$RESULT"
[ "$ERRS" -gt 5 ] && log "... $((ERRS-5)) more evaluator output line(s) suppressed" ERROR

if [ "$RC" -ne 0 ] || [ "$SEEN" -eq 0 ]; then
    log "Evaluator failed (exit $RC) — the switch is NOT being evaluated" ERROR
    alert_once evaluator-failed "Dead Man's Switch: the cron evaluator is failing on $HN (exit $RC). The switch is not being evaluated until this is fixed. See Settings > Dead Man's Switch > Logs." nostate
else
    rm -f "$RUN_DIR/alert.evaluator-failed"
fi
