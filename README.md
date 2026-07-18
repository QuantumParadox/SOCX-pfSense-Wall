# SOCX pfSense Wall

SOCX pfSense Wall is a pfSense security operations display for a large always-on monitor. It includes the original terminal/tmux wall plus a browser-based `socweb` dashboard for the polished Option C look: dark btop-inspired cards, WebSocket updates, smooth ticker animation, real sparklines, live UPS wattage, firewall events, flows, packet story rows, and pfSense collectors.

It is built for the kind of wall monitor you glance at from across the room: WAN/VPN/DNS/UPS truth at the top, live pf state and flow context in the middle, packet stories and rotating SOC events at the bottom, and enough color logic to make trouble stand out without turning the screen into noise.

Suggested GitHub repo name: `SOCX-pfSense-Wall`

Suggested GitHub description:

`A pfSense SOC wall dashboard with a browser-based btop-style web UI, tmux fallback, live firewall ticker, UPS telemetry, and autoboot support.`

Suggested topics:

`pfsense`, `freebsd`, `tmux`, `websocket`, `soc`, `network-monitoring`, `terminal-dashboard`, `cybersecurity`, `tcpdump`, `iftop`

## Why This Exists

Most pfSense dashboards are either excellent admin pages or raw terminal tools. SOCX sits between those worlds: a read-only command wall for home labs, small offices, and network/security nerds who want live context on a dedicated display.

SOCX is not meant to replace pfSense, ntopng, Suricata, pfBlockerNG, NUT, or Speedtest. It pulls useful signals from those tools and presents them as a single high-contrast operations wall.

## Quick Start

```sh
git clone <your-fork-or-repo-url> SOCX-pfSense-Wall
cd SOCX-pfSense-Wall
sh install-pfsense.sh
soc
```

For a safe preview with fake data:

```sh
socx-wall --demo --once --mode wall --theme modern-btop --width 160 --height 42
```

## Ideas Wanted

This is a living wall-display project, and good ideas tend to come from real screens in real rooms. If you try SOCX, please open an issue with:

- a photo or description of your wall display size
- what pfSense packages you run
- what felt useful at a glance
- what was cramped, noisy, or missing
- sensors, services, or security signals you would like SOCX to support next

Useful idea areas include new UPS/environment sensors, better VPN provider detection, Suricata/pfBlockerNG summaries, WireGuard/OpenVPN details, ISP outage indicators, and better large-room readability presets.

## What It Does

- Live wall sample: [`docs/socx-live-wall-capture.md`](docs/socx-live-wall-capture.md)
- v1 release-candidate notes: [`docs/v1.0.0-rc3-release-notes.md`](docs/v1.0.0-rc3-release-notes.md)

- Starts a full-screen tmux dashboard called `socx`.
- Adds `socweb`, a local browser dashboard for the modern btop-style SOC wall when terminal rendering is too limiting.
- Serves WebSocket updates from pfSense collectors with no heavy Python framework dependency.
- Shows dark modern cards, real canvas sparklines, clipped tables, and a smooth CSS event ticker.
- Defaults to `SOCX_MODE=wall` and `SOCX_THEME=modern-btop`, a single-pane high-contrast wall display with internal panels and clipping.
- Shows a classic split-wall `NETX` layout: btop-style pfSense cockpit on top, IFTopX bottom-left, TCPDumpX bottom-right.
- Adds `socx-wall`, a modern btop-inspired wall renderer with metric cards, pftop-style live PF states, flow table, Packet Radar, UPS sparkline, and smooth event ticker.
- Runs wall mode inside a restart loop and logs renderer errors to `/tmp/socx-wall.err`.
- Keeps wall mode full-screen. Operator commands live in the `COMMANDX` tmux window or popup shortcuts, so adding command access does not cut off the SOCX wall.
- Adds an incident capture command that saves pf states, gateway/VPN state, recent logs, Packet Radar, checksums, and a short pcap into `/root/socx-incidents/`.
- Adds `socx-incident`, a short operator command that runs the capture and prints the newest bundle path plus a quick manifest. `socx incident quick` captures the same core evidence with a shorter packet sample.
- Adds `socx-report`, `socx-report-cron`, `socx-hosts-audit`, `socx-explain`, and `socx-miranda-bridge` for scheduled reports, device naming, label explanations, and MIRANDA/local-AI export.
- Adds `socx history`, a compact JSONL trend recorder for Autopilot, UPS, and DIRECT/VPN Speedtest path snapshots.
- Adds `socx notify`, an optional webhook hook for operator alerts when `SOCX_NOTIFY_WEBHOOK_URL` is configured.
- Adds a browser Command Center card and `/api/command-center` endpoint for Autopilot verdict, Speedtest path truth, history count, and recommended SOCX commands.
- Adds `socx why-now`, a plain-English explanation of the current wall state with evidence and recommended next actions.
- Adds four-part Autopilot scoring for network, security, AI, and sensor health.
- Adds `socx mode` presets for normal, incident, speedtest, AI, UPS, and quiet-night viewing.
- Adds `socx hosts suggest/apply` to reduce `LAN.x` and unknown labels using DHCP, ARP, and Pi discovery hints.
- Adds friendly app/domain labels across the browser wall and terminal wall when SOCX has DNS/log evidence, including streaming, Apple/iCloud, Hugging Face, Civitai, IBM Quantum, OpenAI/Claude/Grok, NVIDIA AI, Ollama, vLLM, and common cloud/CDN services.
- Keeps app/service enrichment local by default: raw IP-only `pftop`/`tcpdump` flows are labeled from local host maps, ports, DNS/DNSBL logs, and safe built-in patterns rather than automatic third-party lookups.
- Adds `socx label-brain`, a terminal-friendly passive identity summary that shows likely per-device apps and services from local pf states, DNS/DNSBL/resolver logs, and host labels.
- Adds rolling local Label Brain memory in `/var/db/socx_label_brain.json`, so SOCX can learn normal per-device apps/services and flag unusual app/service changes without external lookups.
- Adds device identity confidence labels (`confirmed`, `likely`, `unknown`) so friendly names are easier to trust at a glance.
- Adds grouped Now Watching categories such as Streaming, AI Lab, Quantum, and Cloud inside LAN Asset Watch when local DNS/log evidence supports them.
- Adds an Explain button in the browser Commander for a safe plain-English screen summary.
- Adds a browser Daily SOC Brief, structured Incident Timeline, and approval-only Rule Assistant for turning live telemetry into a readable investigation story.
- Adds focused browser detail pages for Speedtest, Devices, Incidents, AI, and Release Health so the main wall stays clean while deeper evidence is still available.
- Adds Data Truth freshness scoring, a plain-English Data Truth reason, and a What Changed timeline so collector age, inactive VPN tests, Pi discovery state, UPS freshness, and meaningful state changes are visible.
- Adds `/health` and `/api/health` for release readiness, wall error state, fixed service checks, and Pi AI role visibility.
- Adds `socx brief`, which writes a timestamped daily SOC summary to `/root/socx-briefs/`.
- Adds `socx rules`, an approval-only recommendation view for firewall, DNSBL, IDS, and device-profile review. It does not change firewall rules automatically.
- Adds `socx v1-check`, a readiness gate that verifies the terminal wall, browser API, renderer log, Speedtest paths, incident memory, Label Brain, host naming, Pi fleet, Pi 3-LLM roles, and pfSense service visibility.
- Adds `socx snapshot`, a read-only evidence bundle for status, why-now, history, timeline, Speedtest paths, topology, unknown services, and web API JSON.
- Adds `socx snapshot-cron`, a nightly read-only evidence snapshot schedule with retention cleanup.
- Adds `/api/history` plus a tiny Command Center score sparkline in the browser wall.
- Adds a browser Incident Cockpit and safe Commander buttons for status, Incident Mode, snapshots, Zeek health, and Pi AI checks.
- Adds a browser Mission Control strip with Threat Pulse, LAN Asset Watch, and AI Verdict Timeline.
- Adds local browser controls for ticker speed and big-text wall readability mode.
- Adds a compact Pi Fleet visualizer in the browser wall for pfSense, Pi 5 AI, Pi 4 telemetry, online count, temperature, memory, load, and service state.
- Adds browser voice Commander support for the same fixed allowlist as the buttons: status, incident, snapshot, speedtest, Zeek, and Pi AI.
- Adds browser Speedtest truth comparison for CLIENT, ROUTER, DIRECT, and VPN paths so router-side under-reporting is visible instead of confusing.
- Adds dedicated Speedtest history in `/var/db/socx_speedtest_history.jsonl`, `socx speedtest-history`, browser trend rows, and `/api/speedtest-history`.
- Refuses to label direct Frontier traffic as VPN throughput when `socx speedtest vpn` is run while the VPN path is inactive.
- Marks Speedtest detail rows as ready, stale, inactive, error, or waiting, so VPN readings are not presented as good data when the VPN path is off.
- Adds rolling incident memory in `/var/db/socx_incident_memory.jsonl`, `socx incident-memory`, browser memory rows, and `/api/incident-memory` so repeated scan/DNSBL patterns become recognizable.
- Adds `socx pi-lab`, a safe bridge for starting bounded, read-only Pi 5 AI experiments such as model inventory, LLM pulse, and thermal watch.
- Adds `/api/commander`, a fixed allowlist endpoint for safe operator actions without arbitrary shell access.
- Adds `socx notify-cron` to install/remove/status a five-minute notification-rule cron job.
- Adds `socx-doctor php-services` to catch malformed pfSense service entries that can cause PHP service-status crash reports.
- Adds `socx incident-mode`, a read-only incident summary of top blocked sources, ports, affected LAN hosts, DNSBL domains, IDS signal, and next actions.
- Adds `/api/incident` and `/api/top-talkers` for browser/detail integrations.
- Adds `socx-doctor zeek` to check Zeek process/log health and crash diagnostic folders.
- Adds `socx-doctor zeek --archive-reviewed` to archive reviewed Zeek crash diagnostics with checksums before live cleanup.
- Can surface LLDP and Service Watchdog health in the rotating Event Feed when those pfSense packages are configured.
- Adds WAN quality events from gateway/dpinger latency, loss, and status, separate from bandwidth-only Speedtest results.
- Adds compact `CHANGE` events when top talker, WAN quality, Speedtest source/status, Pi AI-node state, or unknown-device count changes.
- Adds `BACKUP` safety events for config age, backup count, and ZFS/boot-environment visibility when available.
- Shows LLDP topology summaries such as interface-to-switch neighbor hints when the connected switch advertises LLDP.
- Adds a rotating SOCX health score based on WAN, VPN, UPS, RAM, CPU, Speedtest freshness, IDS, DNSBL, and firewall scan pressure.
- Shows truthful VPN gateway/interface health in the top status strip, including `VPN UP 3/3`, `VPN PARTIAL 1/3`, `VPN DOWN 0/3`, `VPN N/A`, or `VPN UNKNOWN` plus `DATA LIVE`/`DATA STALE`.
- Adds a background Speedtest cache for scheduled Frontier/VPN path checks without blocking the 500 ms wall renderer.
- Shows scheduled Speedtest results with latency, stable/stale state, and countdown to the next test.
- Adds live WAN/LAN download/upload bars and a top-talker line in the NETWORK card.
- Rotates top upload/download and top-device summaries through the Event Feed so `LAN.148` style traffic becomes easier to understand.
- Learns friendly host names from `/usr/local/etc/socx_hosts.conf` and cached DHCP leases when available.
- Tracks known vs unknown LAN devices and logs unknown service ports to `/var/db/socx_unknown_services.log` so the wall gets smarter over time.
- Supports `/usr/local/etc/socx_watchlist.conf` for watched hosts, domains, services, ports, and event text.
- Adds WAN health and Speedtest 24-hour average/trend events to the rotating feed.
- Adds AI/MIRANDA lab awareness for Ollama, vLLM, xAI/Grok, NVIDIA Build, OpenAI, Anthropic, Gemini, Hugging Face, Jupyter, Ray, MLflow, and related local lab services.
- Shows CPU graph, CPU cores, RAM/ARC, pf state/search counters, live interface rates, and top processes in the top cockpit.
- Shows prominent NUT/APC UPS watts/load/battery/runtime plus 60-second peak, average, and sparkline.
- Adds `iftopx`, a readable color flow radar for live LAN/WAN traffic.
- Adds `tcpdumpx`, a readable color packet story view that explains traffic direction, service, size, and flow.
- `iftopx` and `tcpdumpx` use `/usr/local/etc/socx_hosts.conf` plus richer built-in service labels so terminal popups can show friendly local hosts and lab services instead of only raw `LAN.x` and unknown ports.
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
- `scripts/socx-speedtest-cache` - scheduled Speedtest collector/importer that keeps active, client, and router Speedtest cache files.
- `scripts/socx-speedtest-history` - summarizes Speedtest JSONL history by DIRECT/VPN path.
- `scripts/socx-iftop-color` and `scripts/socx_iftop_color.pl` - color flow radar wrapper and renderer.
- `scripts/socx-tcpdump-color` and `scripts/socx_tcpdump_color.pl` - color packet story wrapper and renderer.
- `scripts/socx-incident` - short incident command that runs capture and prints the latest bundle manifest.
- `scripts/socx-report` - daily/weekly text report generator for firewall, DNSBL, IDS, VPN, UPS, Speedtest, hosts, and unknown ports.
- `scripts/socx-report-cron` - installs/removes a daily SOCX report cron entry with report retention cleanup.
- `scripts/socx-v1-check` - v1 readiness gate for wall, browser API, Speedtest truth, incident memory, Label Brain, host naming, Pi fleet, Pi roles, and clean renderer logs.
- `scripts/socx-hosts-audit` - builds a known/unknown LAN device list from host config, ARP, DHCP leases, and PF states.
- `scripts/socx-explain` - explains short labels such as `tls`, `dnsbl`, `nut`, `sysl`, `game`, and `unk`.
- `scripts/socx-menu` - interactive SOCX Command Center for common operator workflows.
- `scripts/socx-timeline` - compact incident timeline from recent VPN, Speedtest, firewall, DNSBL, IDS, and AI signals.
- `scripts/socx-incident-memory` - rolling repeated-event memory for top scanner, top port, DNSBL domain, and IDS pressure.
- `scripts/socx-brief` - daily SOC brief generator with Autopilot, Label Brain, timeline, Speedtest, and Pi AI context.
- `scripts/socx-rule-assistant` - approval-only rule recommendation assistant for evidence-backed policy review.
- `scripts/socx-repair` - safe collector repair for SOCX wall helpers, vnstatd, LLDP, and Pi discovery without changing firewall policy.
- `scripts/socx-explain-screen` - plain-English explanation of the current wall state and Autopilot mode.
- `scripts/socx-history` - appends compact SOCX trend samples to `/var/db/socx_history.jsonl` and tails recent history.
- `scripts/socx-notify` - optional webhook notification hook for manual or future automated alerts.
- `scripts/socx-why-now` - plain-English current-state explanation from Autopilot, Speedtest, and AI caches.
- `scripts/socx-mode` - wall mode preset helper for normal, incident, speedtest, AI, UPS, and quiet-night operation.
- `scripts/socx-host-labels` - DHCP/ARP/Pi-discovery friendly-name suggestions for `/usr/local/etc/socx_hosts.conf`.
- `scripts/socx-snapshot` - read-only SOCX troubleshooting bundle with checksums and optional web API captures.
- `scripts/socx-snapshot-cron` - installs/removes/status a nightly read-only evidence snapshot cron entry.
- `scripts/socx-incident-mode` - read-only incident-mode summary for firewall, DNSBL, IDS, and next actions.
- `scripts/socx-notify-cron` - scheduled notification-rule cron installer/remover/status helper.
- `scripts/socx-doctor` - live health check for wall, logs, VPN, Speedtest, UPS, reports, MIRANDA ingest, vnstatd, unknown-service noise, DNSBL review, IDS tuning, and slow-network triage.
- `scripts/socx-miranda-bridge` - exports a compact JSON summary for MIRANDA or another local-AI/SOC collector.
- `scripts/socx-pi-llm-bridge` - posts the compact SOCX summary to a Raspberry Pi 5 AI HAT+ 3-LLM orchestration endpoint and caches the verdict.
- `scripts/socx-pi-llm-cron` - installs/removes the scheduled Pi 3-LLM export.
- `scripts/socx-pi-lab` - starts/statuses bounded read-only experiments on the Pi 5 SOCX AI dashboard.
- `scripts/socx-pi-nodes` - discovers Pi-class LAN nodes such as the Pi 5 AI endpoint and Pi 4 node-exporter telemetry node.
- `scripts/socx-ai-explain` - operator command that refreshes Pi/MIRANDA analysis and prints a plain-English summary.
- `pi/socx_pi_llm_orchestrator.py` - optional Raspberry Pi FastAPI receiver that fans SOCX evidence to three local model roles.
- `pi/socx_pi_sidecar.py` - lightweight standard-library Pi telemetry endpoint for Pi 4/Pi fleet health on port `8096`.
- Browser wall Command Center - includes safe click/voice actions, Speedtest truth comparison, and the compact Pi Fleet link visualizer.
- Browser detail pages - `/speedtest`, `/devices`, `/incidents`, and `/ai` expand the wall's main signals into readable evidence views.
- `scripts/iftopx`, `scripts/tcpdumpx`, `scripts/socx` - convenience launchers.
- `scripts/socweb` - browser dashboard launcher.
- `web/socx-web.py` - lightweight Python WebSocket/HTTP backend for the browser wall.
- `web/static/` - HTML, CSS, and JavaScript frontend for the modern card dashboard.
- `config/socx_hosts.conf.example` - optional friendly-name map for local LAN hosts.
- `config/socx_ai_lab.conf.example` - optional AI/MIRANDA endpoint map for local LLMs and external model APIs.
- `config/socx_watchlist.conf.example` - optional watchlist for important devices, services, domains, ports, and event text.
- `config/socx_speedtest_paths.conf.example` - optional DIRECT/VPN Speedtest path labels for Frontier, NYC, RCN-DE, RCN-VA, or other policy-routed test paths.
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

Focused detail views:

```text
http://192.168.1.1:8094/speedtest
http://192.168.1.1:8094/devices
http://192.168.1.1:8094/incidents
http://192.168.1.1:8094/ai
http://192.168.1.1:8094/health
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

Modern Wall density presets:

```sh
socx-wall --density compact
socx-wall --density normal
socx-wall --density large
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

Local service labels are read from:

```sh
/usr/local/etc/socx_services.conf
```

Use this for local lab ports such as MIRANDA, Ollama, vLLM, ntopng, InfluxDB, Grafana, Jupyter, Ray, and MLflow.

AI/MIRANDA lab endpoints are read from:

```sh
/usr/local/etc/socx_ai_lab.conf
```

The format is one lightweight TCP check per line. SOCX does not send API keys or call model APIs:

```text
MIRANDA=192.168.1.50:8090
Ollama=192.168.1.50:11434
vLLM=192.168.1.51:8000
NVIDIA-Build=build.nvidia.com:443
xAI-Grok=api.x.ai:443
OpenAI=api.openai.com:443
Anthropic=api.anthropic.com:443
Gemini=generativelanguage.googleapis.com:443
HuggingFace=huggingface.co:443
```

When enabled, SOCX rotates Event Feed messages such as:

```text
[AI][INFO] AI lab endpoints 8/10 online | offline vLLM
[AI][INFO] NVIDIA Build traffic LAN.148 -> EXT.72.38 4M/120K | HTTPS/TLS
[AI][INFO] Local AI signals online: Ollama, vLLM, MIRANDA
```

The NETWORK card also rotates its bottom line between the current top flow and a compact AI LAB summary, for example `AI LAB 7/8 down MIRANDA`, so lab health is visible even when the Event Feed is busy.

It also rotates a compact `HEALTH 94 ...` line based on WAN/VPN/DNS/UPS, RAM/CPU pressure, Speedtest freshness, IDS alerts, DNSBL hits, and firewall scan bursts. This gives you one fast room-distance read before you inspect the detailed panels.

AI/lab service labels are also shortened in PFTOP/IFTOPX where possible: `olma`, `vllm`, `llm`, `jupy`, `ray`, `mlfl`, `trtn`, `oai`, `xai`, `ngc`, `anth`, `gemi`, and `hf`.

Packet Radar replaces the old raw packet story panel with a tcpdump-backed, human-readable rolling cache. It is enabled by default in wall mode:

```sh
SOCX_PACKET_RADAR_ENABLED=true
SOCX_PACKET_RADAR_IFACE=ix1
SOCX_PACKET_RADAR_FILTER='not arp and not port 22'
packet-radar
```

Inside tmux, use `Prefix + c` for a command popup, `Prefix + r` for live Packet Radar, `Prefix + f` for `pftop`, `Prefix + v` for VPN details, or switch to the `COMMANDX` window for a full command deck.

Incident capture:

```sh
socx-incident
socx incident quick
socx-incident-capture
```

Use `socx-incident` during a suspicious event. It calls `socx-incident-capture`, then prints the newest `/root/socx-incidents/incident-*.tgz` bundle and a short list of included evidence. Use `socx incident quick` when you want the same bundle with a shorter packet sample. The tmux shortcut is `Prefix + i`.

Friendly device names:

```sh
socx-hosts-audit
socx-hosts-audit --apply-suggestions
vi /usr/local/etc/socx_hosts.conf
```

Example:

```text
192.168.1.1=pfSense-JupiterLXI
192.168.1.116=MIRANDA-Workstation
192.168.1.148=Lenovo-M910t
192.168.1.170=Metrics-Grafana
```

When a mapping exists, SOCX renders flows as names such as `MIRANDA-Workstation/LAN.116`; when there is no mapping, it falls back to DHCP lease hostnames and then compact `LAN.x` labels. SOCX also overlays fresh discovery data for the Pi AI node, UPS, and configured AI endpoints so stale labels do not win over live discovery. `--apply-suggestions` adds readable aliases such as `Pi5-AI-Node`, `Synology-DS1823xs`, `WirelessAP-117`, or `Device-105`; use it when you want immediate readability, then rename those entries later.

Watchlist:

```sh
vi /usr/local/etc/socx_watchlist.conf
```

Example:

```text
host=MIRANDA-Workstation|label:MIRANDA workstation|severity:LOW
domain=api.x.ai|label:xAI Grok API|severity:LOW
service=vpn|label:VPN tunnel traffic|severity:LOW
text=VPN DOWN|label:VPN outage|severity:HIGH
text=UPS on battery|label:Power event|severity:HIGH
```

Watchlist matches rotate into the Event Feed as `[WATCH]` events without adding another panel.

Unknown ports and labels:

```sh
socx-explain tls
socx-explain dnsbl
socx-explain unknowns
socx services
socx services --apply
socx services --archive
tail -40 /var/db/socx_unknown_services.log
vi /usr/local/etc/socx_services.conf
```

When SOCX repeatedly sees an unlabeled port, it appends a compact observation to `/var/db/socx_unknown_services.log`. `socx services` summarizes the top unknown ports and writes safe label suggestions to `/tmp/socx-service-suggestions.conf`. `socx services --apply` appends known-safe labels to `/usr/local/etc/socx_services.conf` after making a timestamped backup. `socx services --archive` saves the learner log and keeps only the newest observations. Add stable local labels manually using `port=name`, for example `540=custom-app`.

Daily/weekly report:

```sh
socx-report daily
socx-report weekly
socx-report-cron install
socx-report-cron status
socx menu
socx v1-check
socx status
socx autopilot
socx why-now
socx timeline
socx history trend
socx repair
socx notify rules
socx notify-cron status
socx hosts suggest
socx mode show
socx snapshot
socx incident-mode
socx explain-screen
socx-doctor
socx-doctor gateways
socx-doctor dnsbl
socx-doctor dnsbl-review
socx-doctor ids
socx tune ids
socx-doctor vnstat
socx-doctor why-slow
socx-doctor php-services
socx-doctor zeek
socx-doctor logs
socx-doctor why-blocked samsungcloudsolution.net
```

Reports are written to `/root/socx-reports/` and include firewall blocks, DNSBL samples, IDS samples, VPN/gateway status, Speedtest cache, UPS cache, top PF states, Service Watchdog data, unknown ports, and a host audit. `socx-report weekly` also adds an executive rollup with Speedtest path trends, repeated incident memory, learned device identities, and `socx v1-check` readiness output.

`socx status` is the fast operator overview. It checks the wall session, renderer errors, Speedtest cache, UPS cache, WAN/VPN gateway truth, DNS, vnstatd, LLDP tools, report/MIRANDA/Pi cron jobs, AI caches, and unknown-service learner noise. It prints `OK`, `WARN`, and `FAIL` lines with the next command to run when something needs attention.

`socx v1-check` is the release/readiness gate. It should end with `READY FOR v1.0` when there are no failures and only expected warnings, such as VPN Speedtest being inactive while the VPN path is intentionally off.

`socx menu` opens the SOCX Command Center. It gives quick access to status, Autopilot, Speedtest paths, VPN details, why-slow, DNSBL/IDS review, unknown services, incident capture, wall restart, collector repair, latest report, timeline, and screen explanation.

`socx timeline` builds a compact last-hour timeline from Autopilot, Speedtest paths, gateway/VPN logs, firewall blocks, DNSBL, IDS/Suricata, and AI verdict caches. Use it before or after `socx incident quick` to understand what happened.

`socx repair` safely restarts SOCX helper collectors such as UPS cache, Speedtest cache, Packet Radar, vnstatd, LLDP, and Pi discovery. It does not change firewall policy or pfSense rules.

`socx explain-screen` turns the current wall state into plain English, including Autopilot mode, key health signals, direct/VPN Speedtest meaning, and suggested safe next actions.

`socx autopilot` is read-only SOCX autonomy. It scores current wall, WAN/VPN, Speedtest, firewall, DNSBL, IDS, and Pi AI signals, writes `/tmp/socx-autopilot.env`, and selects an operator mode: `NORMAL`, `WATCH`, `INVESTIGATE`, or `INCIDENT`. It now also writes network, security, AI, and sensor sub-scores so the verdict is easier to understand. It never changes firewall rules. The Modern Wall rotates the cached Autopilot mode, score, summary, and suggested read-only actions through the Event Feed.

`socx why-now` explains the current verdict in plain English. It includes the four-part score, Speedtest evidence, Pi AI role status, and the next safe commands to run.

`socx history trend` summarizes recent JSONL samples from `/var/db/socx_history.jsonl`, including score trend, DIRECT/VPN Speedtest averages, event pressure, and UPS wattage.

`socx notify rules` evaluates Autopilot against notification rules. It only sends when `SOCX_NOTIFY_WEBHOOK_URL` is configured; otherwise it prints a safe skipped/quiet message.

`socx notify-cron install` schedules `socx notify rules` every five minutes. Leave it uninstalled unless you have configured `SOCX_NOTIFY_WEBHOOK_URL`.

`socx snapshot` builds a read-only troubleshooting bundle under `/root/socx-snapshots/` with text outputs, env caches, API captures, and checksums. This is the easiest artifact to attach when opening a GitHub issue.

`socx incident-mode` is the fast “what matters right now?” view for `SECURITY WATCH` or `INCIDENT` moments. It ranks blocked sources, blocked ports, affected LAN hosts, DNSBL domains, IDS signal, and next actions.

The browser backend also exposes `/api/incident` and `/api/top-talkers` for richer detail pages or future wall panels.

`socx mode show` lists wall presets. To apply one for a shell session:

```sh
eval "$(socx mode incident)"
soc
```

`socx-doctor` is the operator troubleshooting command. It checks the wall, VPN/dpinger gateway health, DNSBL false-positive candidates, Suricata routine-vs-high-signal noise, Traffic Totals/vnStat, log pressure, topology, and targeted “why was this blocked?” lookups. `socx-doctor wan-quality` shows gateway status, loss, recent gateway warnings, and Speedtest context. `socx-doctor topology` shows LLDP interface/neighbor state, host-naming audit output, and Pi AI-node discovery. `socx-doctor why-slow` pulls together load, gateway loss, interface counters, Speedtest cache, and PF state samples. `socx tune ids` prints the repeated Suricata signatures that are safest to threshold or suppress after review.

MIRANDA/local-AI bridge:

```sh
socx-miranda-bridge
SOCX_MIRANDA_POST_URL=http://192.168.1.116:8093/api/socx/ingest socx-miranda-bridge
socx-miranda-cron install
socx-miranda-cron status
socx pi-llm
socx pi-discover
socx explain-now
SOCX_PI_LLM_POST_URL=http://192.168.1.180:8095/api/socx/triage socx pi-llm
socx-pi-llm-cron install
socx-doctor pi-llm
```

By default, this writes `/tmp/socx-miranda-export.json`. It only posts when `SOCX_MIRANDA_POST_URL` is set, so it is safe to use as a local export even before MIRANDA has an ingest endpoint. If MIRANDA is running on your LAN workstation, `socx-miranda-cron install` posts a compact SOCX summary to `/api/socx/ingest` every five minutes. Successful posts also write `/tmp/socx-miranda-analysis.env`, which the wall rotates into the NETWORK card as an `AI SOC` insight line.

For a Raspberry Pi 5 AI HAT+ lab node, run the Pi receiver on the Pi and keep pfSense as the lightweight sender. SOCX can autoscan for the Pi if its DHCP address changes. `socx pi-discover` checks host hints, common `.local` names, DHCP leases, ARP entries with Raspberry Pi MAC OUIs, SSH, Ollama `11434`, and the SOCX Pi 3-LLM service on `8095`. It writes `/tmp/socx-pi-discovery.env`; `socx pi-llm` uses that cache automatically when `SOCX_PI_LLM_POST_URL` is not set. Override with `SOCX_PI_LLM_POST_URL` only when you want a fixed target.

Pi discovery knobs:

```sh
SOCX_PI_HOST_HINTS='raspberrypi.local pi.local 192.168.1.180'
SOCX_PI_FALLBACK_IPS='192.168.1.180 192.168.1.121'
SOCX_PI_LLM_PORT=8095
SOCX_PI_OLLAMA_PORT=11434
SOCX_PI_DISCOVERY_CACHE=/tmp/socx-pi-discovery.env
```

On the Pi:

```sh
git clone https://github.com/QuantumParadox/SOCX-pfSense-Wall.git
cd SOCX-pfSense-Wall
sudo sh pi/install_socx_pi_llm.sh
```

Manual Pi install:

```sh
sudo mkdir -p /opt/socx-pi-llm
sudo cp pi/socx_pi_llm_orchestrator.py /opt/socx-pi-llm/
sudo cp pi/socx-pi-llm.service /etc/systemd/system/
python3 -m pip install fastapi uvicorn
sudo systemctl daemon-reload
sudo systemctl enable --now socx-pi-llm
curl http://127.0.0.1:8095/health
```

The Pi receiver uses three roles: `triage`, `evidence`, and `action`. On a Raspberry Pi 5 AI HAT+ 2 / Hailo-10H node, SOCX tries the local Hailo chat-stream endpoint first, then falls back to CPU Ollama if a model is missing, slow, or offline. The default routing is:

```text
triage   -> Hailo llama3.2:3b
evidence -> Hailo qwen2.5-instruct:1.5b
action   -> Hailo qwen2.5-coder:1.5b
fallback -> CPU Ollama llama3.2:3b / qwen2.5:3b
```

Hailo model swaps can be slow, especially on the first run after boot, so SOCX gives Hailo a longer role timeout and keeps the CPU fallback visible in the dashboard. Override models or endpoints with:

```sh
SOCX_PI_LLM_TRIAGE_MODEL=llama3.2:3b
SOCX_PI_LLM_EVIDENCE_MODEL=qwen2.5-instruct:1.5b
SOCX_PI_LLM_ACTION_MODEL=qwen2.5-coder:1.5b
SOCX_PI_HAILO_CHAT_URL=http://127.0.0.1:8000/api/chat
SOCX_PI_HAILO_TIMEOUT=150
SOCX_PI_CPU_TRIAGE_MODEL=llama3.2:3b
SOCX_PI_CPU_EVIDENCE_MODEL=qwen2.5:3b
SOCX_PI_CPU_ACTION_MODEL=llama3.2:3b
SOCX_PI_LLM_TRIAGE_URL=http://127.0.0.1:11434/api/generate
SOCX_PI_LLM_EVIDENCE_URL=http://127.0.0.1:11434/api/generate
SOCX_PI_LLM_ACTION_URL=http://127.0.0.1:11434/api/generate
```

When the Pi answers, SOCX writes `/tmp/socx-pi-llm-analysis.env` and rotates wall events such as `PI 3LLM analysis roles 3/3 | routine IDS watch lines`. If the Pi is offline, pfSense keeps running normally and shows the Pi layer as waiting or unreachable instead of blocking the wall.

The Pi service also includes a real-time browser dashboard:

```text
http://<pi-ip>:8095/dashboard
```

It shows the latest SOCX verdict, visible role summaries for `triage`, `evidence`, and `action`, Hailo/Ollama health, model names, backend route names, live events, pfSense payload summary, and an animated SOCX AI/network visualization. The dashboard intentionally shows visible role summaries and model status, not hidden chain-of-thought. If both model backends are offline, it will show `HAILO OFF`, `OLLAMA OFF`, and `roles 0/3`.

The dashboard includes a read-only `Autopilot` layer for experimental local-AI SOC testing. Autopilot does not change firewall rules. After each SOCX analysis it records an in-memory trend point, scores the cycle, chooses an operator mode such as `OBSERVE`, `WATCH`, `INVESTIGATE`, or `COOLDOWN`, and shows safe experiment notes:

```text
LLM latency watch
signal drift
Pi thermal headroom
```

Autopilot and Pi telemetry are available as JSON too:

```sh
curl http://<pi-ip>:8095/api/socx/autonomy
curl http://<pi-ip>:8095/api/socx/latest
```

The Pi dashboard also includes an `Experiment Lab` for safe AI HAT testing. `THERMAL` samples Pi temperature/load, `LLM PULSE` records a bounded latency/telemetry window, and `MODELS` inventories the local Ollama roster. Tests are limited to 5-120 seconds, are read-only, and never change pfSense policy. The control endpoint is:

```text
POST /api/socx/experiment
{"kind":"thermal_watch","action":"start","duration":30}
```

The dashboard style is intentionally a restrained LCARS/cyberpunk hybrid: warm LCARS orange marks the lab controls, cyan identifies neutral system data, green identifies healthy services, yellow marks watch conditions, red marks failure, and magenta marks AI/threat context. The Pi remains an analysis and experiment plane; pfSense remains the authoritative enforcement plane.

The Pi dashboard also exposes a lightweight Network Digital Twin. The pfSense bridge samples current `pfctl` state rows and posts them to `/api/socx/network` separately from the slower LLM analysis, so the graph can refresh without waiting for all three roles. The read-only Command Center accepts `network` or `show network`, and the visual core shows the current node/link count. If no flow rows are available it explicitly shows `waiting for pfSense flow telemetry` instead of inventing paths.

Flow snapshots are retained in memory at `/api/socx/network/history` for replay and comparison. Additional read-only commands include `explain host <host>`, `trace flow`, `show anomalies`, and `compare normal`. Response proposals such as `draft block <host>` or `draft quarantine <host>` return `DRAFT ONLY` objects with `approval_required: true`; they never call the pfSense configuration API.

If SOCX shows the Pi endpoint as reachable but `roles 0/3`, the Pi receiver is running but the local model backend is not. On the Pi, check:

```sh
sudo systemctl status socx-pi-llm ollama
curl http://127.0.0.1:8095/health
curl http://127.0.0.1:8000/hailo/v1/list
curl http://127.0.0.1:11434/api/tags
```

Repair the usual CPU Ollama fallback case with:

```sh
curl -fsSL https://ollama.com/install.sh | sh
sudo systemctl enable --now ollama
ollama pull llama3.2:3b
ollama pull qwen2.5:3b
sudo systemctl restart socx-pi-llm
```

The pfSense bridge waits up to 300 seconds by default because the Pi may need time to run three small local model roles. The Pi service runs roles sequentially by default because Hailo generation context swaps and CPU model loads are not instant. Override only if your Pi is much faster or slower:

```sh
SOCX_PI_LLM_TIMEOUT=180 socx pi-llm
```

Ask SOCX for the current AI explanation:

```sh
socx explain-now
socx-ai-explain cache
```

This refreshes Pi discovery and the Pi bridge, then prints the latest Pi 3-LLM and MIRANDA summaries. If the Pi service is not listening yet, it explains exactly which IP was found and which endpoint is missing.

SOCX separates routine Suricata stream chatter from high-signal IDS/IPS alerts. Routine TCP stream notices become `IDS INFO`/watch signals; malware, C2, exploit, IPS block/drop, priority-1, and scan-style alerts remain high-severity.

Alert mode:

```sh
SOCX_ALERT_MODE=auto
```

`auto` keeps high/critical events visible longer in rotate mode while preserving the current wall layout. Use `SOCX_ALERT_MODE=off` if you want the feed to rotate at the raw configured timing.

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
SOCX_EVENT_ROTATE_SECONDS=1
SOCX_EVENT_HIGH_SECONDS=2
SOCX_EVENT_CRIT_SECONDS=3
```

Set `SOCX_EVENT_FEED_MODE=scroll` to use the older horizontal ticker, or `SOCX_EVENT_FEED_MODE=stack` to show a small vertical group when the panel has room.

DNSBL Event Feed entries describe DNS query-level hits. A line such as `DNSBL hit: discord.com` means the hostname matched DNSBL/sinkhole logic; it does not claim the entire app or service is unreachable. Actual pf firewall blocks are shown separately as `DROP`/`REJECT` style events.

The Modern Wall Event Feed acts as a SOC command ticker. It rotates and prioritizes events such as `[IDS][HIGH]`, `[WAN][WARN]`, `[VPN][INFO]`, `[UPS][INFO]`, `[DHCP][WARN]`, `[ARP][MED]`, `[FLOW][WARN]`, `[DNS][HIGH]`, `[FW][MED]`, and `[DNSBL][LOW]`. Repeated events are deduplicated with `xN`, and routine low-severity DNSBL hits are capped/summarized so outage and security alerts stay visible.

AI-SOC enrichment rotates through the same Event Feed without adding permanent columns or making the wall cramped. It adds compact context for CVE, CPE, CWE, CAPEC, CVSS, EPSS, KEV, ATT&CK, D3FEND, Sigma, YARA, Suricata, Windows Event IDs, Linux/macOS artifacts, memory artifacts, cloud logs, containment steps, evidence preservation steps, confidence score, and source references. SOCX leaves CVE/CVSS/EPSS/KEV as `n/a` unless a CVE is visible in the event text or you provide explicit values, so routine firewall scans are not assigned fake vulnerabilities.

AI-SOC enrichment settings:

```sh
SOCX_AI_SOC_ENRICHMENT=true
SOCX_AI_SOC_CVE=CVE-YYYY-NNNN
SOCX_AI_SOC_CPE='cpe:2.3:a:netgate:pfsense:*'
SOCX_AI_SOC_CWE=CWE-000
SOCX_AI_SOC_CAPEC='CAPEC-300 port scan'
SOCX_AI_SOC_CVSS='9.8'
SOCX_AI_SOC_EPSS='0.92'
SOCX_AI_SOC_KEV=yes
SOCX_AI_SOC_ATTACK='T1046 service discovery'
SOCX_AI_SOC_D3FEND='D3-NTA/D3-NTF'
SOCX_AI_SOC_SIGMA=socx_pfsense_scan_burst
SOCX_AI_SOC_YARA=socx_log_ioc_context
SOCX_AI_SOC_SURICATA='sid:9001046 scan-burst'
SOCX_AI_SOC_WINDOWS_EIDS='5152/5157/4688'
SOCX_AI_SOC_POSIX_ARTIFACTS='filter.log,dnsbl.log,suricata/*,auth.log'
SOCX_AI_SOC_MEMORY_ARTIFACTS='pf states,sockets,proc map'
SOCX_AI_SOC_CLOUD_LOGS='VPC Flow,WAF,DNS,CloudTrail/AzureActivity'
SOCX_AI_SOC_CONTAINMENT='block src,quarantine host,tighten rule'
SOCX_AI_SOC_EVIDENCE='logs,pcap,pfctl -ss,config.xml'
SOCX_AI_SOC_CONFIDENCE='88%'
SOCX_AI_SOC_SOURCES='NVD/CPE/CVSS, CISA KEV, FIRST EPSS, MITRE ATT&CK/CAPEC/D3FEND, SigmaHQ, YARA, Suricata'
```

Reference sources used by the enrichment labels: [NVD CPE](https://nvd.nist.gov/products/cpe), [NVD CVSS](https://nvd.nist.gov/vuln-metrics/cvss), [CISA KEV](https://www.cisa.gov/known-exploited-vulnerabilities-catalog), [FIRST EPSS](https://www.first.org/epss/), [MITRE ATT&CK T1046](https://attack.mitre.org/techniques/T1046/), [MITRE CAPEC-300](https://capec.mitre.org/data/definitions/300.html), [MITRE D3FEND D3-NTA](https://d3fend.mitre.org/technique/d3f:NetworkTrafficAnalysis/), [SigmaHQ](https://sigmahq.io/), [YARA](https://virustotal.github.io/yara/), and [Suricata rules](https://docs.suricata.io/en/latest/rules/intro.html).

UPS cache settings:

```sh
SOCX_UPS_ENABLED=true
SOCX_UPS_SOURCE=auto
SOCX_UPS_REFRESH_MS=1000
SOCX_UPS_HISTORY_SECONDS=60
SOCX_UPS_SHOW_SPARKLINE=true
SOCX_UPS_NOMINAL_WATTS=1950
SOCX_UPS_SNMP_HOST=192.168.1.114
SOCX_UPS_SNMP_COMMUNITY=public
SOCX_UPS_SNMP_VERSION=v2c
```

`SOCX_UPS_SOURCE=auto` tries NUT/`upsc` first and falls back to direct Schneider/APC UPS-MIB SNMP polling, which keeps wattage, load, battery, runtime, voltage, amps, battery voltage, and battery temperature fresh even if `upsd` is not listening. When APC environmental probes are present, SOCX also caches two probe temperatures and humidity, then rotates the UPS card footer through temperature, humidity, and load/headroom.

Speedtest cache settings:

```sh
SOCX_SPEEDTEST_ENABLED=true
SOCX_SPEEDTEST_INTERVAL=6h      # 30m, 1h, 6h, or seconds
SOCX_SPEEDTEST_SERVER_MODE=auto # frontier for fixed server, auto for nearest
SOCX_SPEEDTEST_ROTATE_SECONDS=4 # rotate DIRECT/VPN display profiles
SOCX_SPEEDTEST_DISPLAY_PROFILE=auto # auto, direct, or vpn
SOCX_SPEEDTEST_FRONTIER_SERVER_ID=56485
SOCX_SPEEDTEST_FRONTIER_SERVER_NAME=Frontier
SOCX_SPEEDTEST_FRONTIER_SERVER_LOCATION='Secaucus, NJ'
SOCX_OOKLA_SPEEDTEST=/usr/local/sbin/ookla-speedtest
SOCX_SPEEDTEST_BASELINE_DOWN_MBPS=2000
SOCX_SPEEDTEST_BASELINE_UP_MBPS=2000
```

`SOCX_SPEEDTEST_SERVER_MODE=auto` uses the Frontier Secaucus server while VPN status is down/direct, and lets Speedtest auto-select when a VPN path appears active. The wall reads the cache only; scheduled bandwidth tests run in `socx-speedtest-cache`, so the 500 ms wall renderer never blocks on a bandwidth test. SOCX does not change pfSense routing to force tests through a VPN; for the safest VPN truth, run/import a test from a client or policy-routed path that already uses that VPN.

SOCX keeps Speedtest data in small env caches:

```text
/tmp/socx-speedtest-cache.env         active result shown on the wall
/tmp/socx-speedtest-client.env        browser/app Speedtest.net truth result
/tmp/socx-speedtest-router.env        pfSense router-side CLI diagnostic result
/tmp/socx-speedtest-direct.env        direct/Frontier non-VPN profile
/tmp/socx-speedtest-vpn.env           VPN path profile
/tmp/socx-speedtest-vpn-nyc.env       named VPN NYC profile
/tmp/socx-speedtest-vpn-rcn-de.env    named VPN RCN Delaware profile
/tmp/socx-speedtest-vpn-rcn-va.env    named VPN RCN Virginia profile
```

Why separate client, router, direct, and VPN results? Some pfSense CLI Speedtest tools can under-report multi-gig fiber, choose a poor server, or fail with a zero/negative download while a browser Speedtest.net result is correct. SOCX treats a fresh imported browser/app result as `CLIENT` truth, keeps pfSense CLI results as `ROUTER` diagnostics, and keeps DIRECT/VPN profile caches separate so one path does not overwrite the other. The wall rotates profile labels such as `SPD DIRECT:3223↓/2372↑ 9ms` and `SPD VPN:840↓/620↑ 41ms`, then compares VPN throughput and latency against the direct baseline.

Named path labels live in `/usr/local/etc/socx_speedtest_paths.conf`; copy the example from `config/socx_speedtest_paths.conf.example`. The default example includes `DIRECT Frontier`, `VPN NYC`, `VPN RCN-DE`, and `VPN RCN-VA`.

Import a known-good Speedtest.net result from a browser or app:

```sh
socx speedtest import 3223.30 2371.66 9 "" 56485 Frontier "Secaucus, NJ" "Frontier Communications" 203.0.113.10 "https://www.speedtest.net/result/0000000000"
socx speedtest import direct 3223.30 2371.66 9 "" 56485 Frontier "Secaucus, NJ" "Frontier Communications" 203.0.113.10 "https://www.speedtest.net/result/0000000000"
socx speedtest import vpn 840 620 41 "" "" "TorGuard NYC" "VPN path" "" "" ""
socx speedtest import vpn:nyc 840 620 41 "" "" "TorGuard NYC" "VPN path" "" "" ""
socx speedtest import vpn:rcn-de 740 510 52 "" "" "TorGuard RCN Delaware" "VPN path" "" "" ""
socx speedtest import vpn:rcn-va 790 540 49 "" "" "TorGuard RCN Virginia" "VPN path" "" "" ""
```

Run a router-side diagnostic test without overwriting a fresh client result:

```sh
socx speedtest once
socx speedtest direct
socx speedtest vpn
socx-doctor speedtest-profiles
```

If the router result is much lower than the client result, SOCX rotates a warning in the Event Feed instead of replacing the wall with the bad number. If VPN throughput falls far below DIRECT or latency jumps, SOCX rotates a VPN Speedtest warning and Autopilot can move into `WATCH` or `INVESTIGATE`. Use the router result as a pfSense/tool/path clue; use `CLIENT` or a policy-routed profile import as the real user-experience speed.

Autopilot v2 uses this path data to choose more specific read-only modes:

```text
NORMAL
WATCH
VPN DEGRADED
WAN DEGRADED
SECURITY WATCH
INCIDENT
```

The distinction matters: `VPN DEGRADED` means the direct path can be healthy while the VPN path is slow/down; `WAN DEGRADED` points at Frontier/gateway quality; `SECURITY WATCH` points at IDS, DNSBL, or firewall bursts. SOCX still does not change firewall rules automatically.

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
