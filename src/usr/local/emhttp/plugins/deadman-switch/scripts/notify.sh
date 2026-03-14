#!/bin/bash
# Dead Man's Switch - Notification Script
# Sends webhook notifications for configured services
# Usage: notify.sh [level]
# Level is optional context for logging (e.g., "reminder", "warning", "critical", "grace")

CONFIG_DIR="/boot/config/plugins/deadman-switch"
LOG_FILE="$CONFIG_DIR/logs/deadman.log"
LEVEL="${1:-INFO}"

log() {
    local level="${2:-INFO}"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $1" >> "$LOG_FILE"
}

log "Sending notifications (level: $LEVEL)"

# Delegate all webhook sending to PHP which handles JSON, HTTP, and retries properly
php << 'PHPEOF'
<?php
require_once '/usr/local/emhttp/plugins/deadman-switch/include/helpers.php';

$config = dms_load_config();
$state = dms_load_state();

$message = dms_render_template($config['message_template'], $config, $state);
$max_retries = 3;
$base_delay = 5;

function send_webhook_with_retry($type, $webhook_config, $message, $max_retries, $base_delay) {
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $result = dms_send_webhook($type, $webhook_config, $message);
        if ($result['success']) {
            dms_log("Notification sent via $type");
            return true;
        }
        if ($attempt < $max_retries) {
            dms_log("Retry $attempt/$max_retries for $type: " . $result['message'], 'WARN');
            sleep($base_delay * $attempt);
        }
    }
    dms_log("Failed to send notification via $type after $max_retries attempts", 'ERROR');
    return false;
}

foreach ($config['webhooks'] as $type => $wh) {
    if (!$wh['enabled']) continue;
    send_webhook_with_retry($type, $wh, $message, $max_retries, $base_delay);
}
PHPEOF

RESULT=$?
if [ $RESULT -eq 0 ]; then
    log "Notification dispatch complete (level: $LEVEL)"
else
    log "Notification dispatch encountered errors (level: $LEVEL)" "ERROR"
fi
