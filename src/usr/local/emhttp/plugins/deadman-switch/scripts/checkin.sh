#!/bin/bash
# Dead Man's Switch - Check-in Script
# Records a check-in from the command line

CONFIG_DIR="/boot/config/plugins/deadman-switch"
STATE_FILE="$CONFIG_DIR/state.json"

if [ ! -f "$STATE_FILE" ]; then
    echo "Error: State file not found. Is the plugin installed?"
    exit 1
fi

METHOD="${1:-cli}"

# The method reaches PHP via argv, never string interpolation. Same guard as
# the API and web UI: no check-in on a disarmed or triggered switch (exit 2)
php -r '
    require_once "/usr/local/emhttp/plugins/deadman-switch/include/helpers.php";
    $s = dms_load_state();
    if (dms_state_load_error() !== null) { fwrite(STDERR, "ERROR: " . dms_state_load_error() . "\n"); exit(1); }
    if (!$s["armed"]) { echo "Switch is not armed\n"; exit(2); }
    if ($s["triggered"]) { echo "Switch has triggered; disarm and re-arm first\n"; exit(2); }
    exit(dms_do_checkin($argv[1] ?? "cli") ? 0 : 1);
' -- "$METHOD"
RC=$?
if [ "$RC" -eq 0 ]; then
    echo "Check-in recorded (method: $METHOD)"
elif [ "$RC" -eq 2 ]; then
    echo "No check-in was recorded." >&2
    exit 1
else
    echo "ERROR: Check-in FAILED - state file was not updated" >&2
    exit 1
fi
