#!/bin/bash
# Dead Man's Switch - Stop External API Server
# Stops the whole process group (master + workers), consumes the legacy flash
# PID file once, then sweeps any orphaned php -S still serving our router.

. "$(dirname "$0")/api-common.sh"
mkdir -p "$RUN_DIR" "$CONFIG_DIR/logs"

for f in "$PID_FILE" "$LEGACY_PID_FILE"; do
    [ -f "$f" ] || continue
    PID=$(cat "$f" 2>/dev/null)
    if api_pid_is_ours "$PID"; then
        if api_pid_is_group_leader "$PID"; then
            kill -TERM -- "-$PID" 2>/dev/null
        else
            kill -TERM "$PID" 2>/dev/null               # legacy master: sweep gets its workers
        fi
        api_log "External API server stopped (PID $PID)"
    fi
    rm -f "$f"                                          # stale or consumed either way
done

api_sweep TERM
for i in 1 2 3 4 5 6; do api_port_open || break; sleep 0.5; done
if api_port_open; then
    api_sweep KILL
    sleep 0.5
    api_log "WARN: port $API_PORT still bound after TERM; sent KILL"
fi
exit 0
