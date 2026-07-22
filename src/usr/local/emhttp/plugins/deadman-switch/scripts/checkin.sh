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

# The method reaches PHP via argv, never string interpolation
if php -r '
    require_once "/usr/local/emhttp/plugins/deadman-switch/include/helpers.php";
    exit(dms_do_checkin($argv[1] ?? "cli") ? 0 : 1);
' -- "$METHOD"; then
    echo "Check-in recorded (method: $METHOD)"
else
    echo "ERROR: Check-in FAILED - state file was not updated" >&2
    exit 1
fi
