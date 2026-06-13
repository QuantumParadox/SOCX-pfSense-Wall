# SOCX pfSense Wall

SOCX pfSense Wall is a terminal-based security operations display for pfSense. It is built for a large always-on monitor and combines a btop-style pfSense cockpit, readable IFTop/TCPDump clones, and a live SOC ticker inside tmux.

Suggested GitHub repo name: `SOCX-pfSense-Wall`

Suggested GitHub description:

`A tmux-based pfSense SOC wall dashboard with pfsense-btop, color iftop/tcpdump clones, threat ticker, and autoboot support.`

Suggested topics:

`pfsense`, `freebsd`, `tmux`, `soc`, `network-monitoring`, `terminal-dashboard`, `cybersecurity`, `tcpdump`, `iftop`

## What It Does

- Starts a full-screen tmux dashboard called `socx`.
- Defaults to `SOCX_MODE=wall`, a single-pane high-contrast wall display with internal panels and clipping.
- Shows a classic split-wall `NETX` layout: btop-style pfSense cockpit on top, IFTopX bottom-left, TCPDumpX bottom-right.
- Adds `socx-wall`, a readable wall-mode renderer with symmetric CPU/RAM, process, IFTopX, TCPDumpX, UPS, and event ticker panels.
- Runs wall mode inside a restart loop and logs renderer errors to `/tmp/socx-wall.err`.
- Shows CPU graph, CPU cores, RAM/ARC, pf state/search counters, live interface rates, and top processes in the top cockpit.
- Shows compact NUT/APC UPS watts/load/battery/runtime in the top-left `mem net pf live` box.
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
socx
```

The installer also creates `soc` and `SOCX` aliases.

Wall mode is the default. To launch the older split-pane layout:

```sh
SOCX_MODE=classic socx
```

Preview the wall renderer safely with fake data:

```sh
socx-wall --demo --mode wall --once --width 140 --height 36
```

Friendly hostnames are read from:

```sh
/usr/local/etc/socx_hosts.conf
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
