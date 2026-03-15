#!/bin/bash
# Dead Man's Switch - Start External API Server
# Runs PHP's built-in server on port 3801 for external API access (bypasses nginx auth)

PLUGIN_DIR="/usr/local/emhttp/plugins/deadman-switch"
CONFIG_DIR="/boot/config/plugins/deadman-switch"
PID_FILE="$CONFIG_DIR/api-server.pid"
LOG_FILE="$CONFIG_DIR/logs/api-server.log"
PORT=3801
ROUTER="$PLUGIN_DIR/include/external-api.php"

# Stop existing server if running
"$PLUGIN_DIR/scripts/stop-api.sh" 2>/dev/null

# Verify router file exists
if [ ! -f "$ROUTER" ]; then
    echo "ERROR: Router file not found: $ROUTER" >> "$LOG_FILE"
    exit 1
fi

# Start PHP built-in server in the background
nohup php -S 0.0.0.0:$PORT "$ROUTER" >> "$LOG_FILE" 2>&1 &
echo $! > "$PID_FILE"

sleep 1

# Verify it started
if kill -0 "$(cat "$PID_FILE" 2>/dev/null)" 2>/dev/null; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] External API server started on port $PORT (PID $(cat "$PID_FILE"))" >> "$LOG_FILE"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: Failed to start external API server on port $PORT" >> "$LOG_FILE"
    rm -f "$PID_FILE"
    exit 1
fi
