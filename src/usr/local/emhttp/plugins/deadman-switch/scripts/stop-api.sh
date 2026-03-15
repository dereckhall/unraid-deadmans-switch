#!/bin/bash
# Dead Man's Switch - Stop External API Server

CONFIG_DIR="/boot/config/plugins/deadman-switch"
PID_FILE="$CONFIG_DIR/api-server.pid"
LOG_FILE="$CONFIG_DIR/logs/api-server.log"

if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    if kill -0 "$PID" 2>/dev/null; then
        kill "$PID" 2>/dev/null
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] External API server stopped (PID $PID)" >> "$LOG_FILE"
    fi
    rm -f "$PID_FILE"
fi
