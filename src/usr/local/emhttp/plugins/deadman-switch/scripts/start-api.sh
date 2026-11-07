#!/bin/bash
# Dead Man's Switch - Start External API Server
# Runs PHP's built-in server on port 3801 for external API access (bypasses nginx auth)

. "$(dirname "$0")/api-common.sh"
mkdir -p "$RUN_DIR" "$CONFIG_DIR/logs"

# Stop existing server (and any orphaned workers) if running
"$PLUGIN_DIR/scripts/stop-api.sh" 2>/dev/null

[ -f "$API_ROUTER" ] || { api_log "ERROR: Router file not found: $API_ROUTER"; exit 1; }

# Multiple workers so one stalled connection can't block remote check-ins.
# setsid makes php a group leader; the 4 workers inherit the group, so one group kill stops all.
# In a non-interactive script the & child is not a leader, so setsid execs php in place and $! is php.
if command -v setsid >/dev/null 2>&1; then
    PHP_CLI_SERVER_WORKERS=4 setsid php -S 0.0.0.0:$API_PORT "$API_ROUTER" >> "$API_LOG" 2>&1 < /dev/null &
else
    api_log "WARN: setsid not found; starting without a dedicated process group (stop relies on the sweep)"
    PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:$API_PORT "$API_ROUTER" >> "$API_LOG" 2>&1 < /dev/null &
fi
PID=$!
echo "$PID" > "$PID_FILE"
sleep 1

if api_pid_is_ours "$PID"; then
    api_pid_is_group_leader "$PID" || api_log "WARN: PID $PID is not a group leader; stop relies on the sweep"
    api_log "External API server started on port $API_PORT (PID $PID)"
else
    rm -f "$PID_FILE"
    api_log "ERROR: Failed to start external API server on port $API_PORT"
    exit 1
fi
