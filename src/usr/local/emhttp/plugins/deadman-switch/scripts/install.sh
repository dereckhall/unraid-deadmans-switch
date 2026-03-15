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
# Unraid uses update_cron which reads *.cron files from each plugin's config dir
CRON_FILE="$CONFIG_DIR/deadman-switch.cron"
if [ -f "$CONFIG_DIR/config.json" ]; then
    CRON_MINUTES=$(php -r "
        \$config = json_decode(file_get_contents('$CONFIG_DIR/config.json'), true);
        echo \$config['cron_interval_minutes'] ?? 60;
    " 2>/dev/null)
    [ -z "$CRON_MINUTES" ] && CRON_MINUTES=60

    if [ "$CRON_MINUTES" = "60" ]; then
        echo "0 * * * * $PLUGIN_DIR/scripts/check.sh >> $CONFIG_DIR/logs/cron.log 2>&1" > "$CRON_FILE"
    else
        echo "*/$CRON_MINUTES * * * * $PLUGIN_DIR/scripts/check.sh >> $CONFIG_DIR/logs/cron.log 2>&1" > "$CRON_FILE"
    fi

    # Remove legacy cron file if present
    rm -f /etc/cron.d/deadman-switch

    /usr/local/sbin/update_cron
fi

# Log the install
php -r "
    require_once '$PLUGIN_DIR/include/helpers.php';
    dms_log('Plugin installed/updated - version ' . DMS_VERSION);
"

echo "Dead Man's Switch plugin installed successfully."
