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

# Update cron interval from config if user has customized it
# (PLG installs default hourly cron, this adjusts if config says otherwise)
CRON_FILE="/etc/cron.d/deadman-switch"
if [ -f "$CONFIG_DIR/config.json" ]; then
    CRON_MINUTES=$(php -r "
        \$config = json_decode(file_get_contents('$CONFIG_DIR/config.json'), true);
        echo \$config['cron_interval_minutes'] ?? 60;
    " 2>/dev/null)
    [ -z "$CRON_MINUTES" ] && CRON_MINUTES=60

    if [ "$CRON_MINUTES" != "60" ]; then
        echo "*/$CRON_MINUTES * * * * root $PLUGIN_DIR/scripts/check.sh >/dev/null 2>&1" > "$CRON_FILE"
    fi
fi

echo "Dead Man's Switch plugin installed successfully."
