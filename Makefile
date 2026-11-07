PLUGIN_NAME = deadman-switch
PLUGIN_DIR = src/usr/local/emhttp/plugins/$(PLUGIN_NAME)

.PHONY: dev-install clean

# For local development: copy files directly to Unraid plugin directory
# Run this on the Unraid server itself
dev-install:
	mkdir -p /usr/local/emhttp/plugins/$(PLUGIN_NAME)
	mkdir -p /boot/config/plugins/$(PLUGIN_NAME)/logs

	cp -R $(PLUGIN_DIR)/* /usr/local/emhttp/plugins/$(PLUGIN_NAME)/
	chmod +x /usr/local/emhttp/plugins/$(PLUGIN_NAME)/scripts/*.sh

	# Run install script (also writes the cron schedule)
	/usr/local/emhttp/plugins/$(PLUGIN_NAME)/scripts/install.sh

	@echo "Dev install complete. Refresh Unraid web UI."
