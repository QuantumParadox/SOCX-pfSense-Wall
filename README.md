# SOCX pfSense Wall

SOCX pfSense Wall is a terminal-based security operations display for pfSense. It is built for a large always-on monitor and combines a btop-style pfSense cockpit, readable IFTop/TCPDump clones, and a live SOC ticker inside tmux.

Suggested GitHub repo name: `SOCX-pfSense-Wall`

Suggested GitHub description:

`A tmux-based pfSense SOC wall dashboard with pfsense-btop, color iftop/tcpdump clones, threat ticker, and autoboot support.`

Suggested topics:

`pfsense`, `freebsd`, `tmux`, `soc`, `network-monitoring`, `terminal-dashboard`, `cybersecurity`, `tcpdump`, `iftop`

## What It Does

- Starts a full-screen tmux dashboard called `socx`.
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
- `config/socx_hosts.conf.example` - optional friendly-name map for local LAN hosts.
- `rc.d/socx` - pfSense/FreeBSD boot script for automatic detached startup.

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

Wall mode is the default. To launch the older split-pane layout:

```sh
SOCX_MODE=classic socx
```

Preview the wall renderer safely with fake data:

```sh
socx-wall --demo --mode wall --theme modern-btop --ticker-smooth --once --width 160 --height 42
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

UPS cache settings:

```sh
SOCX_UPS_ENABLED=true
SOCX_UPS_SOURCE=nut
SOCX_UPS_REFRESH_MS=500
SOCX_UPS_HISTORY_SECONDS=60
SOCX_UPS_SHOW_SPARKLINE=true
```

Preview speeds in demo mode:

```sh
socx-wall --demo --mode wall --ticker-speed fast
socx-wall --demo --mode wall --ticker-speed turbo
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

Attach to the live wall with:

```sh
tmux attach -t socx
```

## Notes

This project is read-only monitoring glue. It does not change firewall rules, capture credentials, or modify packet handling. It is designed for a trusted admin console on your own pfSense box.
