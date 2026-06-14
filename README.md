# SOCX pfSense Wall

SOCX pfSense Wall is a pfSense security operations display for a large always-on monitor. It includes the original terminal/tmux wall plus a browser-based `socweb` dashboard for the polished Option C look: dark btop-inspired cards, WebSocket updates, smooth ticker animation, real sparklines, live UPS wattage, firewall events, flows, packet story rows, and pfSense collectors.

Suggested GitHub repo name: `SOCX-pfSense-Wall`

Suggested GitHub description:

`A pfSense SOC wall dashboard with a browser-based btop-style web UI, tmux fallback, live firewall ticker, UPS telemetry, and autoboot support.`

Suggested topics:

`pfsense`, `freebsd`, `tmux`, `websocket`, `soc`, `network-monitoring`, `terminal-dashboard`, `cybersecurity`, `tcpdump`, `iftop`

## What It Does

- Starts a full-screen tmux dashboard called `socx`.
- Adds `socweb`, a local browser dashboard for the modern btop-style SOC wall when terminal rendering is too limiting.
- Serves WebSocket updates from pfSense collectors with no heavy Python framework dependency.
- Shows dark modern cards, real canvas sparklines, clipped tables, and a smooth CSS event ticker.
- Defaults to `SOCX_MODE=wall` and `SOCX_THEME=modern-btop`, a single-pane high-contrast wall display with internal panels and clipping.
- Shows a classic split-wall `NETX` layout: btop-style pfSense cockpit on top, IFTopX bottom-left, TCPDumpX bottom-right.
- Adds `socx-wall`, a modern btop-inspired wall renderer with metric cards, process table, flow table, live packet table, UPS sparkline, and smooth event ticker.
- Runs wall mode inside a restart loop and logs renderer errors to `/tmp/socx-wall.err`.
- Shows CPU graph, CPU cores, RAM/ARC, pf state/search counters, live interface rates, and top processes in the top cockpit.
- Shows prominent NUT/APC UPS watts/load/battery/runtime plus 60-second peak, average, and sparkline.
- Adds `iftopx`, a readable color flow radar for live LAN/WAN traffic.
- Adds `tcpdumpx`, a readable color packet story view that explains traffic direction, service, size, and flow.
- Uses narrow two-line formatting in the bottom IFTopX/TCPDumpX panes so flows and packet details stay readable on the wall display.
- Keeps IFTopX/TCPDumpX visible in the bottom of the primary `NETX` wall.
- Adds a SOC ticker for firewall blocks, DNS blocks, IDS-style alerts, and other useful events.
- Shows a bottom rail with only firewall/security ticker text for maximum readable space.
- Labels DNSBL query hits as `DNSBL hit`/`SINKHOLE` and reserves `DROP`/`REJECT` wording for actual firewall block events.
- Leaves the original `htop`, `tcpdump`, and `iftop` tools untouched.
- Includes a pfSense boot script so SOCX can start automatically after reboot.

## Files

- `scripts/pfsense-btop.php` - btop-style read-only pfSense monitor.
- `scripts/socx-wall.php` - single-pane wall-mode renderer with panel clipping and demo mode.
- `scripts/socxboard.sh` - tmux layout and SOCX session launcher.
- `scripts/socx-bottom-rail` - dynamically draws the lower frame rail and center join.
- `scripts/socx-alert-ticker` - bottom status ticker for readable firewall/security events.
- `scripts/socx-ups-status` - compact NUT/APC UPS status widget for the tmux status bar.
- `scripts/socx-ups-cache` - background UPS collector that keeps `/tmp/socx-ups-cache.env` fresh without blocking wall rendering.
- `scripts/socx-iftop-color` and `scripts/socx_iftop_color.pl` - color flow radar wrapper and renderer.
- `scripts/socx-tcpdump-color` and `scripts/socx_tcpdump_color.pl` - color packet story wrapper and renderer.
- `scripts/iftopx`, `scripts/tcpdumpx`, `scripts/socx` - convenience launchers.
- `scripts/socweb` - browser dashboard launcher.
- `web/socx-web.py` - lightweight Python WebSocket/HTTP backend for the browser wall.
- `web/static/` - HTML, CSS, and JavaScript frontend for the modern card dashboard.
- `config/socx_hosts.conf.example` - optional friendly-name map for local LAN hosts.
- `rc.d/socx` - pfSense/FreeBSD boot script for automatic detached startup.
- `rc.d/socxweb` - optional pfSense/FreeBSD boot script for the browser dashboard service.

## Install On pfSense

From this repo on pfSense:

```sh
sh install-pfsense.sh
```

Then launch manually:

```sh
soc
```

The installer also keeps `socx` and `SOCX` as aliases, but `soc` is the short command to use.

Launch the browser dashboard:

```sh
socweb --host 0.0.0.0 --port 8094
```

Open it from the wall display:

```text
http://192.168.1.1:8094/
```

Kiosk examples:

```sh
chrome --kiosk http://192.168.1.1:8094/
firefox --kiosk http://192.168.1.1:8094/
```

Web dashboard settings:

```sh
SOCX_WEB_HOST=0.0.0.0
SOCX_WEB_PORT=8094
SOCX_WEB_REFRESH_MS=500
SOCX_IFWAN=ix1
SOCX_IFLAN=ix0
SOCX_WEB_MAX_EVENTS=40
```

Wall mode is the default. To launch the older split-pane layout:

```sh
SOCX_MODE=classic socx
```

Preview the wall renderer safely with fake data:

```sh
socx-wall --demo --mode wall --theme modern-btop --ticker-smooth --once --width 160 --height 42
```

Force and test Unicode rendering:

```sh
socx-wall --unicode-test
SOCX_FORCE_UNICODE=true SOCX_DISABLE_ASCII_FALLBACK=true SOCX_BORDER_STYLE=unicode SOCX_GRAPH_STYLE=unicode \
  socx-wall --demo --once --mode wall --theme modern-btop --force-unicode --capture /tmp/socx-frame.txt --width 160 --height 42
```

Use the older single-pane wall if needed:

```sh
SOCX_THEME=classic socx
```

Friendly hostnames are read from:

```sh
/usr/local/etc/socx_hosts.conf
```

Wall ticker speed can be tuned with:

```sh
SOCX_TICKER_SPEED=fast
SOCX_TICKER_STEP=1
SOCX_TICKER_INTERVAL_MS=75
SOCX_TICKER_MAX_EVENTS=25
SOCX_TICKER_DEDUPE_SECONDS=10
```

Modern-btop defaults to a non-scrolling rotating Event Feed so tmux does not constantly crawl text across the wall:

```sh
SOCX_EVENT_FEED_MODE=rotate
SOCX_EVENT_ROTATE_SECONDS=3
SOCX_EVENT_HIGH_SECONDS=6
SOCX_EVENT_CRIT_SECONDS=8
```

Set `SOCX_EVENT_FEED_MODE=scroll` to use the older horizontal ticker, or `SOCX_EVENT_FEED_MODE=stack` to show a small vertical group when the panel has room.

DNSBL Event Feed entries describe DNS query-level hits. A line such as `DNSBL hit: discord.com` means the hostname matched DNSBL/sinkhole logic; it does not claim the entire app or service is unreachable. Actual pf firewall blocks are shown separately as `DROP`/`REJECT` style events.

UPS cache settings:

```sh
SOCX_UPS_ENABLED=true
SOCX_UPS_SOURCE=nut
SOCX_UPS_REFRESH_MS=500
SOCX_UPS_HISTORY_SECONDS=60
SOCX_UPS_SHOW_SPARKLINE=true
```

Modern btop rendering defaults to Unicode/ANSI cards, block meters, and sparklines inside pfSense/tmux:

```sh
SOCX_UNICODE=true
SOCX_BORDER_STYLE=unicode
SOCX_GRAPH_STYLE=unicode
```

Set `SOCX_UNICODE=false` for the ASCII fallback.

Preview speeds in demo mode:

```sh
socx-wall --demo --mode wall --theme modern-btop --event-feed-mode rotate
socx-wall --demo --mode wall --theme modern-btop --event-feed-mode scroll --ticker-speed turbo
```

## Autoboot

The included boot script installs to:

```sh
/usr/local/etc/rc.d/socx
```

It waits briefly after boot, then starts `/root/socxboard.sh` detached. Stop or restart it with:

```sh
service socx stop
service socx restart
```

The boot script also accepts the FreeBSD/pfSense boot verbs `faststart`, `onestart`, and `forcestart`, so it works when called directly by the boot sequence.

Attach to the live wall with:

```sh
tmux attach -t socx
```

Start or stop the browser dashboard service with:

```sh
service socxweb start
service socxweb stop
service socxweb status
```

## Notes

This project is read-only monitoring glue. It does not change firewall rules, capture credentials, or modify packet handling. The browser dashboard should be exposed only on a trusted admin LAN or run locally in kiosk mode.
