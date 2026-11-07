#!/bin/bash
# Dead Man's Switch - External API server shared helpers
# Sourced by start-api.sh, stop-api.sh and check.sh; not executed directly.

PLUGIN_DIR="/usr/local/emhttp/plugins/deadman-switch"
CONFIG_DIR="/boot/config/plugins/deadman-switch"
RUN_DIR="/var/run/deadman-switch"
PID_FILE="$RUN_DIR/api-server.pid"
LEGACY_PID_FILE="$CONFIG_DIR/api-server.pid"            # pre-Release-1 location on flash
API_LOG="$CONFIG_DIR/logs/api-server.log"
API_PORT=3801
API_ROUTER="$PLUGIN_DIR/include/external-api.php"

api_log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$API_LOG"; }

# Live process AND its cmdline is our server (defeats PID reuse). php execs in
# place under both `setsid php -S ...` and the legacy `nohup env ... php -S ...`,
# so the cmdline starts with `php -S` in both cases.
api_pid_is_ours() {
    [ -n "$1" ] && [ -r "/proc/$1/cmdline" ] &&
        tr '\0' ' ' < "/proc/$1/cmdline" 2>/dev/null | grep -q "php -S 0\.0\.0\.0:$API_PORT .*external-api\.php"
}

# PGID == PID, i.e. `kill -- -$1` is safe
api_pid_is_group_leader() {
    local stat rest
    stat=$(cat "/proc/$1/stat" 2>/dev/null) || return 1
    rest=${stat##*) }
    set -- $rest
    [ "$3" = "${stat%% *}" ]
}

# Signal every php -S serving our router, whatever started it
# (/proc loop: procps not guaranteed, testable anywhere)
api_sweep() {
    local sig="${1:-TERM}" d pid
    for d in /proc/[0-9]*; do
        pid="${d#/proc/}"
        [ "$pid" = "$$" ] && continue
        api_pid_is_ours "$pid" && kill "-$sig" "$pid" 2>/dev/null
    done
}

api_port_open() { php -r 'exit(@fsockopen("127.0.0.1", (int)$argv[1], $e, $s, 1) ? 0 : 1);' -- "$API_PORT"; }
