<p align="center">
  <img src="assets/images/deadman-switch-icon.png" width="200" />
</p>

# Dead Man's Switch for Unraid

A dead man's switch plugin for Unraid that requires periodic check-ins. If you stop checking in, it triggers configurable actions — like deleting files or running scripts — after a deadline passes.

Useful for privacy-conscious users who want automated cleanup if they become unable to manage their server.

## Screenshots

### Unraid Dashboard Tile
![Dashboard Tile](screenshots/dashboard-tile.png)

### Plugin Dashboard
![Settings Dashboard](screenshots/settings-dashboard.png)

### Actions Configuration
![Actions Tab](screenshots/actions-tab.png)

### Discord Notifications
![Discord Notification](screenshots/discord-notification.png)

## Features

- **Configurable check-in interval** — set how many days between required check-ins (default: 30)
- **Grace period** — extra hours after the deadline before actions trigger
- **Trigger actions** — delete files/folders (with glob pattern support) or run custom bash scripts
- **System-root protection** — deletion paths such as `/`, `/boot` or `/mnt` are refused when you save and again at trigger time; whole-array or pool paths ask for confirmation
- **Settings validation** — inconsistent settings are rejected on save, and shortening the deadline on an armed switch asks for confirmation first (see [Settings validation](#settings-validation))
- **Dry run mode** — test your entire configuration safely before going live; run a dry test from the Actions tab to see exactly what would happen (including every file a glob pattern matches) without actually deleting anything
- **Arm / Disarm / Pause controls** — full lifecycle management; the remaining time is frozen while paused, and pausing an already-paused switch extends the pause
- **Double-miss protection** — optionally require two consecutive missed deadlines before triggering
- **Corruption protection** — a backup (`.bak`) of the last good state and config is kept; a corrupt state or config file is restored from it (the corrupt bytes are kept in a `.corrupt` file) and the switch is disarmed until you verify and re-arm
- **Dashboard tile** — real-time countdown on the Unraid dashboard

### Notifications

- **Discord webhooks** — rich embeds with severity-matched colors and quick check-in links
- **Custom webhooks** — send notifications to any HTTP endpoint with configurable method and a body template whose placeholders are filled in (see [Custom webhook body template](#custom-webhook-body-template))
- **Uptime Kuma** — push heartbeat monitoring so you can track check-in health externally
- **Warning thresholds** — configurable alerts at 50%, 75%, 90%, and 95% of elapsed time

### External API

- Runs on **port 3801**, separate from Unraid's authenticated nginx
- API key protected endpoints (`X-Api-Key` header) for remote check-ins and status queries
- Token-based quick check-in URLs (included in Discord notifications) that open a confirmation page
- CORS enabled for Home Assistant and browser clients

## Installation

In the Unraid web UI, go to **Plugins > Install Plugin** and paste:

```
https://raw.githubusercontent.com/dereckhall/unraid-deadmans-switch/main/plugin/deadman-switch.plg
```

Requires **Unraid 6.12.0** or later.

## Configuration

After installation, go to **Settings > Dead Man's Switch**. The UI has five tabs:

| Tab | Description |
|-----|-------------|
| **Dashboard** | Live countdown, check-in button, arm/disarm/pause controls |
| **Notifications** | Discord webhooks, custom webhooks, Uptime Kuma push URL, warning thresholds |
| **Actions** | File/folder deletions (glob patterns supported) and custom script executions, with dry run testing |
| **Settings** | Check-in interval, grace period, cron frequency, dry run toggle, API key management |
| **Logs** | Recent activity and check-in history |

### Message and custom webhook body templates

The **Message Template** on the Notifications tab is the plain-text notification. It supports these placeholders:

| Placeholder | Value |
|-------------|-------|
| `{{status}}` | Switch status in capitals, e.g. `ARMED OK`, `GRACE PERIOD` |
| `{{days_remaining}}` | Days left until the deadline, one decimal (a number) |
| `{{hours_remaining}}` | Hours left until the deadline, rounded (a number) |
| `{{checkin_link}}` | Quick check-in link, or `N/A` when there is none (switch disarmed or triggered) |
| `{{last_checkin}}` | Time of the last check-in, or `Never` |

#### Custom webhook body template

The custom webhook's **Body Template** supports the same placeholders plus one more:

| Placeholder | Value |
|-------------|-------|
| `{{message}}` | The notification text: the rendered Message Template for status notifications, or the alert text for alerts (missed first deadline with double-miss, clock problems, failure alerts) and the **Test** button |

- With method **POST**, the placeholders in the body template are filled in and the result is sent as the request body (`Content-Type: application/json`). With an empty body template, the rendered message text is sent instead.
- Text values (`{{status}}`, `{{checkin_link}}`, `{{last_checkin}}`, `{{message}}`) are JSON-escaped, so quotes and line breaks in them cannot break your JSON. Put them inside quotes in the template.
- Numbers (`{{days_remaining}}`, `{{hours_remaining}}`) are substituted bare, without quotes, so they can be used as JSON numbers.
- If a template that starts with `{` or `[` is no longer valid JSON after the placeholders are filled in, the notification is still sent and a WARN line is logged.
- With method **GET**, the URL is requested as a bare ping: no body is sent and the body template is ignored.

Example body template:

```json
{"title": "Dead Man's Switch: {{status}}", "text": "{{message}}", "days_left": {{days_remaining}}, "link": "{{checkin_link}}"}
```

Each notification creates one quick check-in link, shared by every channel and every retry of that notification.

### External API URL

Quick check-in links in notifications point at your server's LAN address on port 3801 by default. To use them away from home, set **External API URL** on the Settings tab to a public URL (reverse proxy or port forward) that routes to the external API on **port 3801**, not to the Unraid web UI.

- When you save a new External API URL, the server tries to reach it. The settings are always saved, but you get a warning message if the URL answers and is not this plugin's API (for example, the Unraid web UI), or if it cannot be reached from the server at all. The second case is expected when your router does not support NAT loopback (hairpin NAT); check from outside your network in that case.
- Once per boot, the cron check tests the saved URL again. If it answers but is not this plugin's API, an ERROR is logged and a webhook alert is sent (once per boot). A URL that simply cannot be reached from the server is only noted in the log.
- **If you set External API URL before 2026.07.22a:** older versions used this setting for a different purpose, and some setups hold the Unraid web UI address there. Since 2026.07.22a it is used to build check-in links, so a web UI address there makes every check-in link in notifications fail. Change it to a URL that routes to port 3801, or clear it to use the LAN address.

### Settings validation

Saving settings is refused, with a message saying why, when:

- the warning thresholds do not strictly increase (reminder < warning < critical < last chance)
- the grace period is shorter than one cron check interval (otherwise the grace-period notification may never be sent)
- a deletion path is a system root: `/`, `/boot`, `/mnt`, `/usr`, `/etc`, `/bin`, `/sbin`, `/lib`, `/lib64`, `/var`, `/proc`, `/sys`, `/dev`, `/run`, `/root`, `/home`, `/tmp` or `/usr/local/emhttp` (symlinks are resolved first). Such a path is also refused when the switch triggers, even if it was saved by an older version: it is skipped with a `REFUSED (system root)` ERROR in the log, and the Actions tab marks it with a red **REFUSED at trigger** tag.

Saving asks for confirmation first when:

- a newly added deletion path removes an entire array or pool (`/mnt/user`, `/mnt/user0`, `/mnt/cache`, `/mnt/diskN` or any other `/mnt/<name>`). These paths still run at trigger time; the Actions tab marks them with an orange **whole array/pool** tag. Paths you already confirmed do not ask again.
- the switch is armed and you shorten the check-in interval or grace period so that the deadline lands before the next cron check. The prompt says what the next check will do (count a missed check-in, enter the grace period, or trigger). Check in first, or confirm to save anyway.

The **Dry Test** button on the Actions tab lists every file and folder each glob pattern currently matches (the first 20, plus a count of the rest) and tags system-root paths as refused and whole-array or pool paths as dangerous.

## API Reference

The plugin runs a lightweight API server on **port 3801** for remote access without Unraid authentication.

| Endpoint | Auth | Description |
|----------|------|-------------|
| `?action=health` | None | Health check, returns version |
| `?action=status` | API key (`X-Api-Key` header; `&key=` still accepted) | Current switch status and countdown |
| `?action=checkin` | API key (`X-Api-Key` header; `&key=` still accepted) | Perform a check-in |
| `?action=get_state` | API key (`X-Api-Key` header; `&key=` still accepted) | Full state JSON |
| `?action=quickcheckin&token=TOKEN` | Token | Quick check-in link: opens a confirmation page whose button records the check-in |

```bash
# Check status
curl -H "X-Api-Key: YOUR_API_KEY" "http://YOUR_UNRAID_IP:3801/?action=status"

# Perform a check-in
curl -H "X-Api-Key: YOUR_API_KEY" "http://YOUR_UNRAID_IP:3801/?action=checkin"
```

- Prefer the header: a key passed as `&key=` in the URL ends up in browser history and reverse-proxy access logs. The query-string form still works for existing setups.
- API, web UI, quick-link and `checkin.sh` check-ins are refused while the switch is disarmed or has triggered — after a trigger, disarm and re-arm first.
- Opening a quick check-in link only shows the confirmation page; the check-in happens when you press its button (a POST). A plain GET never records a check-in and other methods (e.g. HEAD) get `405`, so link previews and URL scanners can no longer record a check-in (or use up the link).
- From a shell on the server: `/usr/local/emhttp/plugins/deadman-switch/scripts/checkin.sh [method]` (the optional method is recorded in the check-in history; default `cli`). It prints the reason and exits nonzero when the switch is disarmed or has triggered.
- The authenticated Unraid-side endpoint `include/api.php?action=status` returns the same field names as the port-3801 `status` (only relevant if you call it directly).

## Home Assistant Integration

The external API makes it easy to integrate with Home Assistant using REST sensors, automations, and actionable notifications.

<p float="left">
  <img src="screenshots/home-assistant-card.png" width="350" />
  <img src="screenshots/ha-phone-notification.png" width="350" />
</p>

<details>
<summary>REST sensor + check-in command</summary>

Add to `configuration.yaml`, then restart Home Assistant (or reload YAML configuration):

```yaml
rest:
  - scan_interval: 60
    resource: "http://YOUR_UNRAID_IP:3801/?action=status"
    method: GET
    headers:
      X-Api-Key: YOUR_API_KEY
    sensor:
      - name: "DMS Status"
        unique_id: "dms_status"
        value_template: "{{ value_json.status }}"
        json_attributes:
          - armed
          - paused
          - dry_run
          - warning_level
          - days_remaining
          - time_remaining_display
          - last_checkin
          - next_deadline
          - elapsed_pct
          - checkin_interval_days

rest_command:
  dms_checkin:
    url: "http://YOUR_UNRAID_IP:3801/?action=checkin"
    method: GET
    headers:
      X-Api-Key: YOUR_API_KEY
```

</details>

### Status card and check-in button

Add these cards to your dashboard via **Dashboard → Edit → Add Card → Manual**. Stacking a few small cards gives a clean status readout plus a one-tap check-in.

A markdown card turns the raw status (e.g. `armed_ok`) into a colored alert with the time remaining:

```yaml
type: markdown
content: >
  {% set s = states('sensor.dms_status') %}
  {% set remaining = state_attr('sensor.dms_status', 'time_remaining_display') %}
  {% set info = {
    'armed_ok': {'label': 'OK - ' ~ remaining ~ ' remaining', 'type': 'success'},
    'armed_reminder': {'label': 'Reminder - ' ~ remaining ~ ' remaining', 'type': 'warning'},
    'armed_warning': {'label': 'Warning - ' ~ remaining ~ ' remaining', 'type': 'warning'},
    'armed_critical': {'label': 'Critical - ' ~ remaining ~ ' remaining', 'type': 'error'},
    'grace_period': {'label': 'Grace Period - ' ~ remaining ~ ' remaining', 'type': 'error'},
    'triggered': {'label': 'TRIGGERED - deleted files', 'type': 'error'},
    'paused': {'label': 'Paused - ' ~ remaining ~ ' remaining', 'type': 'success'},
    'disarmed': {'label': 'Disarmed', 'type': 'success'}
  } %}
  {% set i = info.get(s, {'label': s, 'type': 'info'}) %}
  <ha-alert alert-type="{{ i.type }}">
  {{ i.label }}
  </ha-alert>
```

An entities card shows the details:

```yaml
type: entities
entities:
  - entity: sensor.dms_status
    type: attribute
    attribute: time_remaining_display
    name: Time Remaining
    icon: mdi:timer-sand
  - entity: sensor.dms_status
    type: attribute
    attribute: last_checkin
    name: Last Check-in
    icon: mdi:clock-check-outline
  - entity: sensor.dms_status
    type: attribute
    attribute: next_deadline
    name: Next Deadline
    icon: mdi:clock-alert-outline
```

And a button card for the check-in. It uses a confirmation prompt so an accidental tap won't reset the timer:

```yaml
type: button
name: Check In
icon: mdi:check-circle
tap_action:
  action: perform-action
  perform_action: rest_command.dms_checkin
  confirmation:
    text: Are you sure you want to check in to the Dead Man's Switch?
```

Tap the button, confirm, and it hits the check-in endpoint and resets your timer. The sensor refreshes on its next poll (every 60 seconds above). You can fire the same `rest_command.dms_checkin` from an automation or an actionable mobile notification to get a check-in button right on your phone's lock screen when the timer runs low.

## How It Works

1. **Arm** the switch and perform your first check-in
2. A cron job runs periodically (default: every 60 minutes) to evaluate the countdown
3. As the deadline approaches, notifications fire at configurable warning thresholds
4. If you check in before the deadline, the timer resets
5. You can pause the switch: while paused the remaining time is frozen, and the deadline timestamp moves out by the length of the pause
6. If the deadline passes (plus grace period), configured trigger actions execute — at most once per boot until you re-arm, even if the flash drive cannot record that the switch triggered
7. With double-miss enabled, the first miss sends a warning and resets the timer. If the warning cannot be delivered, it is retried until the grace period ends; then the timer resets anyway and an ERROR is logged, so a broken webhook cannot stall the switch. If the flash drive cannot be written, the first miss cannot be recorded: the switch stays parked and will **not** trigger until the flash drive is writable, and it says so (ERROR in the log and syslog, a webhook alert once per boot)
8. The API server self-heals — if the process dies, the cron job restarts it
9. Failures are loud:
   - a failing cron evaluator, an unreadable state or config file, or a restore from backup alerts once per boot by webhook when PHP still runs, and always logs to syslog
   - a missing `config.json` or `state.json` (e.g. an unmounted flash drive) is logged to syslog on every cron check
   - Uptime Kuma reports **down** when the state or config file is unreadable and after a restore from backup, and its message says since when notifications have been failing
   - every failed action in the web UI shows an error toast

### Files

Settings and state live on the flash drive in `/boot/config/plugins/deadman-switch/`:

- `config.json`, `state.json` — settings and switch state (deleting `config.json` resets all settings to defaults)
- `config.json.bak`, `state.json.bak` — copy of the last good version of each, refreshed when a meaningful setting or state value changes. If `state.json` or `config.json` is found corrupt it is restored from its `.bak` and the switch is **DISARMED** as a precaution (see Troubleshooting)
- `config.json.corrupt`, `state.json.corrupt` — the corrupt bytes, kept when a file was restored from its `.bak`

- `deadman-switch.cron` — the cron schedule (see [Uninstall and reinstall](#uninstall-and-reinstall))
- `.removed` — left behind when the plugin is uninstalled, and removed by the next install (see below)

Per-boot runtime files live in RAM in `/var/run/deadman-switch/`: the API server PID file (`api-server.pid`, moved off the flash drive), `alert.*` markers that limit failure alerts to once per boot, and the `triggered` marker that stops trigger actions running twice in one boot.

### Uninstall and reinstall

- **Cron schedule:** every install and every boot runs the plugin's install script, which writes `deadman-switch.cron` from the saved cron check interval using the same code that the Settings tab uses when you change the interval. The file is only rewritten when the schedule actually changed.
- **Uninstall:** removing the plugin stops the API server, removes the cron schedule and keeps your settings and state in `/boot/config/plugins/deadman-switch/`. If the switch is still armed, the removal output says so with a NOTE: on reinstall it resumes, unless its deadline has passed in the meantime. Delete that directory for a clean removal.
- **Reinstall:** if the switch was armed and its deadline plus grace period passed while the plugin was uninstalled, the reinstall **DISARMS** it instead of letting the first cron check trigger the actions. The install output shows a NOTICE and an ERROR is logged; review Settings and the Actions tab, then re-arm. If the deadline has not passed yet, the switch stays armed (a WARN is logged).
- A normal reboot never disarms the switch, even if the deadline passed while the server was off: that only happens after an explicit uninstall and reinstall.

## Troubleshooting

### API server not running

The external API server on port 3801 should auto-start on install and self-heal via the cron job. If it's not running:

```bash
# Check if the process is alive (PID file is in RAM)
cat /var/run/deadman-switch/api-server.pid
ps aux | grep "external-api.php" | grep -v grep

# Manually restart it (the server runs in its own process group, so stop
# removes every worker and frees the port)
/usr/local/emhttp/plugins/deadman-switch/scripts/stop-api.sh
/usr/local/emhttp/plugins/deadman-switch/scripts/start-api.sh

# Check for port conflicts
lsof -i :3801
```

### Cron job not firing

The plugin writes a cron file at `/boot/config/plugins/deadman-switch/deadman-switch.cron` on every install and boot, and again when you change the cron check interval. If notifications or countdown checks aren't happening:

```bash
# Verify the cron file exists and has content
cat /boot/config/plugins/deadman-switch/deadman-switch.cron

# Rebuild cron from the plugin's cron file
/usr/local/sbin/update_cron
```

If the flash drive cannot be written, the log shows `Failed to write cron schedule` as an ERROR.

### Discord notifications not sending

1. Verify your webhook URL is correct in the Notifications tab
2. Use the **Test** button in the UI to send a test notification (it saves your settings first and only sends once the save succeeded)
3. Check the Logs tab for any error messages; a notification that failed on every channel also shows a banner on the Dashboard tab
4. Ensure your Unraid server has outbound internet access

### "Notifications have been failing since ..." banner

This orange banner on the Settings page appears when a notification could not be delivered on any enabled channel, and shows when the failures started. The Uptime Kuma message mentions it too. It disappears by itself as soon as a notification is delivered on at least one channel again. Check the webhook URLs on the Notifications tab and use the **Test** buttons.

### Switch disarmed after a restore, or "state.json is unreadable"

If `state.json` on the flash drive is found corrupt, it is restored from `state.json.bak` and the switch is **DISARMED** as a precaution — the backup may be older than your last check-in. An orange "State or settings were restored from a backup ... and the switch was DISARMED" banner on the Settings page and a webhook alert (once per boot) tell you when this happens. Check the **Last Check-in** time on the Dashboard tab, then re-arm the switch. The banner disappears when you arm, disarm or check in.

A corrupt `config.json` with a usable `config.json.bak` is handled the same way: the settings are restored and the switch is **DISARMED**. The restored actions list may predate your last save, so review the Settings and Actions tabs, then re-arm. (If the switch cannot be disarmed because the flash drive cannot be written, the restore is not applied and the switch is not evaluated until the flash drive is writable.)

In both cases the corrupt original is kept next to it as `state.json.corrupt` or `config.json.corrupt`.

If there is no usable `.bak`, the switch refuses to write anything (so the evidence is not overwritten with defaults), stops evaluating the countdown, and shows a red banner on the Settings page. Repair or delete the corrupt file in `/boot/config/plugins/deadman-switch/` and reload the page (deleting `config.json` resets all settings to defaults).

### Actions not triggering (or triggering unexpectedly)

- Make sure **Dry Run** mode is off in the Settings tab if you want real execution
- Use the **Dry Test** button on the Actions tab to preview what would happen
- Glob patterns (`*`) do not match dotfiles/dotfolders — this is intentional for safety
- Check the Logs tab for trigger execution details
- A log line `REFUSED (system root): <path>` (ERROR) means a deletion path, or one of its glob matches, is a system root such as `/boot` or `/mnt`. It was skipped and nothing under it was deleted. The Actions tab marks such a path with a red **REFUSED at trigger** tag; change it to the folder you actually want deleted (see [Settings validation](#settings-validation))
- If the switch was disarmed right after a reinstall, see [Uninstall and reinstall](#uninstall-and-reinstall)

### Failure alerts arrive only once

Failure alerts (unreadable state or config, restore from backup, cron check failing, flash drive not writable for double-miss, wrong External API URL) are sent by webhook once per boot, so a lasting problem does not flood you. Each sent alert leaves a marker file `/var/run/deadman-switch/alert.<name>` in RAM; the markers are cleared on reboot. To get an alert again without rebooting, delete its marker (for the External API URL check, also delete `/var/run/deadman-switch/probe.external-url`, which makes the next cron check test the URL again). Check the Logs tab for the details of each problem.

### Quick check-in link not working

Opening the link shows a confirmation page; the check-in is only recorded when you press its button. The link is single-use, and it shows an "inactive" page (without being used up) while the switch is disarmed or triggered.

By default the link points at your server's LAN address on port 3801, so it only works on your network. To use it away from home, set **External API URL** on the Settings tab to a public URL (reverse proxy or port forward) that routes to **port 3801, not the Unraid web UI**. A URL that reaches the normal Unraid web interface will not work.

If you get the warning "External API URL answered but is not the Dead Man's Switch API" when saving, or an alert that the External API URL "answers but does not reach the Dead Man's Switch API", the URL reaches some other service (often the Unraid web UI, a common setting before 2026.07.22a). Point it at port 3801 or clear it. A "could not be reached from this server" warning is expected if your router lacks NAT loopback; test the link from outside your network. See [External API URL](#external-api-url).

### Plugin not updating

GitHub CDN can cache the PLG file. To force a fresh install:

```bash
curl -H 'Cache-Control: no-cache' -o /tmp/deadman-switch.plg \
  https://raw.githubusercontent.com/dereckhall/unraid-deadmans-switch/main/plugin/deadman-switch.plg
plugin install /tmp/deadman-switch.plg
```

## License

MIT License — see [LICENSE](LICENSE) for details.
