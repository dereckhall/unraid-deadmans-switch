# Dead Man's Switch for Unraid

A dead man's switch plugin for Unraid that requires periodic check-ins. If you stop checking in, it triggers configurable actions — like deleting files or running scripts — after a deadline passes.

Useful for privacy-conscious users who want automated cleanup if they become unable to manage their server.

![Dashboard Tile](screenshots/dashboard-tile.png)

## Features

- **Configurable check-in interval** — set how many days between required check-ins (default: 30)
- **Grace period** — extra hours after the deadline before actions trigger
- **Multiple trigger actions** — delete files/folders (with glob support) or run custom scripts
- **Discord notifications** — rich embeds with severity-matched colors at configurable warning thresholds (50%, 75%, 90%, 95%)
- **Custom webhook support** — send notifications to any HTTP endpoint
- **Uptime Kuma integration** — push heartbeat monitoring
- **External API** — runs on port 3801 for remote check-ins and status queries (API key protected)
- **Quick check-in links** — one-click token-based check-in URLs (included in Discord notifications)
- **Dashboard tile** — real-time countdown on the Unraid dashboard
- **Dry run mode** — test your configuration safely before going live
- **Arm / Disarm / Pause controls** — full lifecycle management
- **Double-miss protection** — optionally require two consecutive missed deadlines before triggering

![Settings Dashboard](screenshots/settings-dashboard.png)

## Installation

### Via Plugin URL

In the Unraid web UI, go to **Plugins > Install Plugin** and paste:

```
https://raw.githubusercontent.com/dereckhall/unraid-deadmans-switch/main/plugin/deadman-switch.plg
```

### Requirements

- Unraid 6.12.0 or later

## Configuration

After installation, go to **Settings > Dead Man's Switch**. The UI has five tabs:

| Tab | Description |
|-----|-------------|
| **Dashboard** | Live countdown, check-in button, arm/disarm/pause controls |
| **Notifications** | Discord webhooks, custom webhooks, Uptime Kuma push URL, warning thresholds |
| **Actions** | File/folder deletions (glob patterns supported) and script executions |
| **Settings** | Check-in interval, grace period, cron frequency, dry run toggle, API key management |
| **Logs** | Recent activity and check-in history |

## External API

The plugin runs a lightweight API server on **port 3801**, separate from Unraid's authenticated nginx. This allows remote check-ins from phones, Home Assistant, scripts, etc.

### Endpoints

| Endpoint | Auth | Description |
|----------|------|-------------|
| `?action=health` | None | Health check, returns version |
| `?action=status&key=YOUR_KEY` | API key | Current switch status and countdown |
| `?action=checkin&key=YOUR_KEY` | API key | Perform a check-in |
| `?action=get_state&key=YOUR_KEY` | API key | Full state JSON |
| `?action=quickcheckin&token=TOKEN` | Token | One-click check-in (for notification links) |

### Example

```bash
# Check status
curl "http://YOUR_UNRAID_IP:3801/?action=status&key=YOUR_API_KEY"

# Perform a check-in
curl "http://YOUR_UNRAID_IP:3801/?action=checkin&key=YOUR_API_KEY"
```

## Home Assistant Integration

The external API has CORS enabled, making it straightforward to integrate with Home Assistant using REST sensors and automations.

![Home Assistant Card](screenshots/home-assistant-card.png)

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
3. As the deadline approaches, notifications fire at configurable thresholds
4. If you check in before the deadline, the timer resets
5. If the deadline passes (plus grace period), configured actions execute
6. The API server self-heals — if the process dies, the cron job restarts it

## License

This project is provided as-is with no warranty. Use at your own risk.
