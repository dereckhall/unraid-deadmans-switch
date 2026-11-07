#!/bin/bash
# Dead Man's Switch - Post-install Setup

PLUGIN_DIR="/usr/local/emhttp/plugins/deadman-switch"
CONFIG_DIR="/boot/config/plugins/deadman-switch"

# Create persistent directories on flash
mkdir -p "$CONFIG_DIR/logs"

# Set script permissions
chmod +x "$PLUGIN_DIR/scripts"/*.sh 2>/dev/null

# Initialize config if it doesn't exist
if [ ! -f "$CONFIG_DIR/config.json" ]; then
    php -r "
        require_once '$PLUGIN_DIR/include/helpers.php';
        dms_load_config();
    "
fi

# Initialize state if it doesn't exist
if [ ! -f "$CONFIG_DIR/state.json" ]; then
    php -r "
        require_once '$PLUGIN_DIR/include/helpers.php';
        dms_load_state();
    "
fi

# Reinstall sanity check. The PLG remove script leaves this marker behind; it is
# never present on a plain boot (which also runs this script and must never
# disarm a switch that is meant to fire after downtime). After an explicit
# uninstall/reinstall whose deadline passed while the plugin was gone, disarm
# instead of triggering on the first cron run. Runs before the cron schedule is
# installed so check.sh cannot fire in between.
REMOVED_MARKER="$CONFIG_DIR/.removed"
if [ -f "$REMOVED_MARKER" ]; then
    # Exit 0 = a decision was reached (disarmed, still armed because the
    # deadline plus grace period has not passed, or not armed/triggered): the
    # marker is consumed. Anything else (state or config unreadable, disarm
    # write failed, PHP failure) keeps it so the next install.sh re-runs the check.
    if php -r 'require_once $argv[1];
        $c = dms_load_config(); $s = dms_load_state();
        if (dms_state_load_error() !== null || dms_config_load_error() !== null) {
            dms_log("Reinstalled after removal, but state.json or config.json is unreadable; the reinstall check is postponed to the next install or boot", "ERROR");
            echo "WARNING: Dead Man'"'"'s Switch state.json or config.json is unreadable; the reinstall check will run again at the next install or boot. Repair the file in /boot/config/plugins/deadman-switch/.\n";
            exit(2);
        }
        if (!$s["armed"] || $s["triggered"]) exit(0);
        $g = dms_get_grace_end($c, $s);
        if ($g !== null && time() >= $g) {
            $did = false;
            $ok = dms_update_state(function($s) use (&$did) {
                // Re-checked under the state lock
                if (!$s["armed"] || $s["triggered"]) return $s;
                $did = true;
                $s["armed"] = false; $s["paused"] = false; $s["pause_start"] = null; $s["pause_expires"] = null;
                $s["grace_period_start"] = null; $s["missed_count"] = 0; $s["last_notification_level"] = null;
                return $s;
            });
            if ($ok === false) {
                dms_log("Reinstalled after removal with the deadline plus grace period already passed, but the switch could NOT be disarmed (state write failed). It may trigger on the next cron run.", "ERROR");
                echo "WARNING: Dead Man'"'"'s Switch deadline passed while uninstalled and the switch could not be disarmed. Check the flash drive and disarm it in Settings.\n";
                exit(1);
            }
            if (!$did) exit(0);
            dms_log("Reinstalled after removal with the deadline plus grace period already passed: switch DISARMED to prevent an immediate trigger. Review Settings and re-arm.", "ERROR");
            echo "NOTICE: Dead Man'"'"'s Switch was DISARMED on reinstall (deadline passed while uninstalled). Re-arm it in Settings.\n";
            exit(0);
        }
        dms_log("Reinstalled after removal; switch is still ARMED (deadline plus grace period not yet passed)", "WARN");' -- "$PLUGIN_DIR/include/helpers.php"; then
        rm -f "$REMOVED_MARKER"
    fi
fi

# Write the cron schedule from the (sanitized, 5..1440) config. One code path:
# dms_update_cron() builds the line, and leaves the file and the log alone when
# it is already current. Unraid's update_cron reads *.cron from the config dir.
# An unreadable config.json would yield the defaults: keep the existing file.
php -r 'require_once $argv[1];
    $c = dms_load_config();   # load first: the error is only set by a load
    if (dms_config_load_error() === null) dms_update_cron($c["cron_interval_minutes"]);
    else dms_log("config.json unreadable at install; keeping the existing cron schedule", "ERROR");' -- "$PLUGIN_DIR/include/helpers.php"

# Remove legacy cron file if present (old versions wrote directly to /etc/cron.d/)
rm -f /etc/cron.d/deadman-switch

/usr/local/sbin/update_cron

# Start external API server (port 3801, bypasses nginx auth)
"$PLUGIN_DIR/scripts/start-api.sh"

# Log the install (version passed as $1 from PLG since PLG file isn't written yet at install time)
INSTALL_VERSION="${1:-unknown}"
php -r "
    require_once '$PLUGIN_DIR/include/helpers.php';
    dms_log('Plugin installed/updated - version $INSTALL_VERSION');
"

echo "Dead Man's Switch plugin installed successfully."
