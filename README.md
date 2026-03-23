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
- **Dry run mode** — test your entire configuration safely before going live; run a dry test from the Actions tab to see exactly what would happen without actually deleting anything
- **Arm / Disarm / Pause controls** — full lifecycle management
- **Double-miss protection** — optionally require two consecutive missed deadlines before triggering
- **Dashboard tile** — real-time countdown on the Unraid dashboard

### Notifications

- **Discord webhooks** — rich embeds with severity-matched colors and one-click check-in links
- **Custom webhooks** — send notifications to any HTTP endpoint with configurable method and body template
- **Uptime Kuma** — push heartbeat monitoring so you can track check-in health externally
- **Warning thresholds** — configurable alerts at 50%, 75%, 90%, and 95% of elapsed time

### External API

- Runs on **port 3801**, separate from Unraid's authenticated nginx
- API key protected endpoints for remote check-ins and status queries
- Token-based quick check-in URLs (included in Discord notifications)
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

## API Reference

The plugin runs a lightweight API server on **port 3801** for remote access without Unraid authentication.

| Endpoint | Auth | Description |
|----------|------|-------------|
| `?action=health` | None | Health check, returns version |
| `?action=status&key=KEY` | API key | Current switch status and countdown |
| `?action=checkin&key=KEY` | API key | Perform a check-in |
| `?action=get_state&key=KEY` | API key | Full state JSON |
| `?action=quickcheckin&token=TOKEN` | Token | One-click check-in (for notification links) |

```bash
# Check status
curl "http://YOUR_UNRAID_IP:3801/?action=status&key=YOUR_API_KEY"

# Perform a check-in
curl "http://YOUR_UNRAID_IP:3801/?action=checkin&key=YOUR_API_KEY"
```

## Home Assistant Integration

The external API makes it easy to integrate with Home Assistant using REST sensors, automations, and actionable notifications.

<p float="left">
  <img src="screenshots/home-assistant-card.png" width="350" />
  <img src="screenshots/ha-phone-notification.png" width="350" />
</p>

<details>
<summary>Example REST sensor configuration</summary>

```yaml
rest:
  - resource: "http://YOUR_UNRAID_IP:3801/?action=status&key=YOUR_API_KEY"
    scan_interval: 300
    sensor:
      - name: "Dead Man's Switch Status"
        value_template: "{{ value_json.status }}"
        json_attributes:
          - time_remaining
          - last_checkin
          - deadline
          - interval_days
          - dry_run
```

</details>

## How It Works

1. **Arm** the switch and perform your first check-in
2. A cron job runs periodically (default: every 60 minutes) to evaluate the countdown
3. As the deadline approaches, notifications fire at configurable warning thresholds
4. If you check in before the deadline, the timer resets
5. If the deadline passes (plus grace period), configured trigger actions execute
6. The API server self-heals — if the process dies, the cron job restarts it

## License

This project is provided as-is with no warranty. Use at your own risk.
