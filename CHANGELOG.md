# Changelog

## Unreleased

- Added SOCX Replay, a read-only browser flight recorder at `/replay` backed by `/api/replay`.
- Replay summarizes recent SOCX history into calm score buckets, security playback, Speedtest playback, Flow Truth context, thermal/UPS spikes, and safe drilldown links.
- Added Replay links to the browser wall and detail navigation so operators can jump from live monitoring to after-action context without crowding the main wall.
- Added SOCX Answer Dock and Ask SOCX buttons across Replay, Speedtest, Devices, Flows, and Incidents drilldowns so rows can be explained in plain English without leaving the page.
- Added richer Device Cards with profile, identity confidence, likely apps, flow count, learned-normal warnings, and one-click device explanation.
- Added Replay time-window controls for 15m, 1h, 6h, and 24h after-action review without changing the live wall layout.
- Added Replay incident bookmarks for the biggest security, speed, flow, thermal, and UPS moments, each with a safe Ask SOCX prompt.
- Added Threat Map Lite at `/map` and `/api/threat-map`, a local-context blocked-source radar with severity, port, geo-hint, and reputation-style summaries.
- Added automatic browser Night Mode from 8 PM to 8 AM every day, with a manual `auto/on/off` button for temporary overrides.
- Added `docs/socx-project-backlog.md`, a long-form project roadmap for future SOCX, pfSense, Pi, AI, and research-lab enhancements.

## v1.5.0

- Added a dedicated browser `/chat` page for full-size SOCX Operator Chat with readable answers, suggestions, safety modes, current signals, and action cards.
- Added chat action cards from `/api/chat` for current evidence, intel context, DNSBL/IDS review, draft-only plans, snapshots, and rule-assistant next steps.
- Made Live Flows and Packet Story rows clickable on the wall; clicking a row asks Operator Chat to explain that exact flow or packet.
- Added wall chat action-card rendering so answers include compact next-step cards instead of only text.
- Kept all chat-driven configuration requests read-only and approval-required; no pfSense policy is applied from chat.

## v1.4.2

- Moved Operator Chat to the top of the browser Command Center so it is visible on the wall display.
- Slowed and smoothed the bottom ticker, batching text swaps and removing animation resets that caused visible jumps.
- Added a browser `/guide` page that explains the wall cards, Operator Chat, command buttons, ticker modes, drilldown pages, and safety model.
- Added tooltips to the main wall cards, navigation links, and Command Center buttons for quick in-interface descriptions.
- Trimmed the wall Command Center button deck so the chatbox has room without crowding the current layout.

## v1.4.0

- Added SOCX Operator Chat in the browser Command Center, backed by `/api/chat` and the terminal `socx chat` command.
- Added safe request classification for operator questions: `ANSWER`, `PLAN`, and `DENIED`.
- Added Pi 5 `/api/socx/chat` support with a dedicated operator prompt, visible `CHAT` events, route/model metadata, and read-only draft-only configuration guidance.
- Added local pfSense fallback explanations for firewall blocks, DNSBL, IDS/Suricata, VPN truth, Speedtest, and current threat/intel context when the Pi LLM is unavailable.
- Kept all chat-driven configuration requests approval-only; chat does not apply pfSense rules, aliases, IDS changes, DNSBL allowlists, or service changes.

## v1.3.0

- Added a read-only ATT&CK / D3FEND / CISA KEV intelligence layer for SOCX Mission and `/api/intel`.
- Mapped routine WAN scans, DNSBL hits, IDS watch/high-signal rows, and learned-normal drift to conservative ATT&CK context and D3FEND countermeasure names.
- Added CISA Known Exploited Vulnerabilities feed/cache support with local backoff so failed refreshes do not slow the wall.
- Added `socx intel` for terminal operators to print current priority, KEV status, ATT&CK/D3FEND mappings, and the kill-chain story.
- Enriched the Pi/MIRANDA export with the SOCX intel object and updated the Pi prompt to include ATT&CK/D3FEND/KEV context.
- Added Mission page cards for ATT&CK/D3FEND, KEV status, and the current kill-chain story.

## v1.2.0

- Added SOCX Mission / Operator Intelligence: a read-only mission summary for what changed, what matters, what to check, and what is probably noise.
- Added `/mission` and `/api/mission` for browser Mission Control detail views.
- Added `socx mission` for terminal/SSH operators, with a safe fallback when the browser API is unavailable.
- Added rotating terminal-wall Mission, VPN detail, Pi AI role, thermal confidence, and probable-noise events without adding another permanent panel.
- Added VPN drilldown summaries that can rotate gateway/path status such as `NYCVPN up | RCNVPN up | RCNVPN2 up`.
- Added hardware/load confidence to the operator layer, including CPU thermal headroom, RAM pressure, and UPS wattage.
- Added a GitHub README showcase using real photographed SOCX wall output.
- Fixed Release Health readiness parsing to look for the current `READY FOR SOCX` gate text.

## v1.1.3

- Polished the photographed terminal wall readability without changing the successful layout.
- Changed the compact Speedtest card from duplicated source/action labels such as `D D` to readable `D↓` and `D↑` rows.
- Made the Speedtest footer prioritize `stable`/`stale` plus countdown text instead of clipped `vs DIRECT...` comparisons.
- Improved the NETWORK top-talker line so friendly host names such as `MIRANDA` or `JupiterLXI` fit before falling back to compact LAN labels.
- Aggregated repeated PF/IFTopX flow rows into one row with an `xN` count to reduce repeated `MIRANDA -> EXT...` noise.
- Shortened Packet Radar VPN tunnel burst stories so packet detail rows stay inside the right edge.
- Widened the Flow Type field enough for labels such as `dnsblk` without ellipses.

## v1.1.2

- Added `socx hardware`, a live hardware-headroom view for pfSense appliances with CPU model, cores/threads, hottest-core temperature, thermal headroom, AES-NI, powerd, recent temperature trend, and UPS watt trend.
- Added `socx stability-watch`, a bounded JSONL stability sampler for short or overnight watches. It captures Data Truth, CPU temperature/headroom, UPS watt/load, active/DIRECT/VPN Speedtest state, and wall error size, then writes a plain summary.
- Added `socx baseline`, a one-command performance baseline report for system profile, CPU/sysctl evidence, BIOS, AES-NI/coretemp/cpuctl, Speedtest history, readiness, Daily Story, and hardware headroom.
- Extended `socx history append` and `socx history trend` with CPU temperature, CPU frequency, thermal headroom, and UPS watt trend fields.
- Extended Autopilot with hardware health scoring for warm/critical CPU state, AES-NI visibility, and powerd state; summaries now include CPU temperature and thermal headroom.
- Added browser Hardware Health trend/headroom rows on `/health` and `/story`, plus VPN crypto headroom on `/speedtest` using current hardware telemetry.
- Hardened `socx v1-check` Pi fleet parsing so the readiness gate no longer depends on `jq` for Pi online/count JSON.

## v1.1.0

- Added `/story`, a browser Daily Story page that turns SOCX health, firewall pressure, DNSBL, IDS, Speedtest path truth, Pi fleet, AI verdicts, repeated-event memory, and What Changed signals into a human-readable daily narrative.
- Added `/api/story` and `/api/story-archive` for integrations and one-click preservation of the current Daily Story.
- Added `socx story` and `socx story archive`, saving timestamped JSON and text reports under `/root/socx-stories/`.
- Added a browser Commander Story action and fixed voice routing so “story” opens the Story workflow instead of the incident timeline.
- Added hardware-health visibility for the Lenovo ThinkCentre M910t i7-7700 upgrade, including CPU profile, 4C/8T display, hottest-core Celsius/Fahrenheit, AES-NI, powerd, and thermal thresholds on `/health` and `/story`.
- Added an example `/usr/local/etc/socx_system_profile.env` profile and M910t/i7-7700 post-upgrade notes.

## v1.0.0

- Added `/why`, a browser operator page for explaining recent firewall/DNSBL block context by IP, domain, or port.
- Added `/api/why`, a fixed allowlist explanation endpoint backed by recent SOCX evidence and `socx why-blocked`.
- Added `/api/incident-bundle` and a browser Commander `Bundle` action for one-click, evidence-preserving incident capture.
- Added quick target buttons on the `/why` page for current top blocked sources, ports, and DNSBL domains.
- Cut the first final v1 release after rc3 readiness, clean wall logs, fresh Pi fleet, and live browser health checks.

## v1.0.0-rc3

- Cleaned repo noise by ignoring generated Python bytecode and local `work/` scratch output.
- Added `/health`, a browser Release Health page for SOCX readiness, Data Truth reason, wall error state, service checks, and Pi role detail.
- Added `/api/health`, a fixed allowlist health endpoint that runs the safe readiness/service checks on demand.
- Added human-readable Data Truth reasons such as why the wall is in `WATCH` instead of just showing a score.
- Expanded Pi AI visibility with per-role rows for triage, evidence, and action when the Pi health endpoint exposes them, plus safe synthesized rows when only role count is available.

## v1.0.0-rc2

- Added Data Truth freshness scoring to the browser wall and detail pages so stale collectors, inactive VPN tests, Pi discovery age, UPS freshness, Label Brain, and incident memory are visible.
- Added a What Changed timeline that tracks meaningful shifts such as top flow, Direct/VPN Speedtest state, Pi fleet state, incident verdict, top blocked source, learned-normal anomalies, and UPS freshness.
- Hardened Pi fleet discovery by refreshing the safe `socx-pi-nodes` cache from the browser collector when the Pi cache is missing or stale.
- Fixed `socx-pi-nodes` JSON output so `socx v1-check` and the browser API agree on Pi fleet `online`, `count`, and freshness state.
- Polished the detail pages into richer Mission Control views with compact freshness, change timeline, Pi node load/temp/memory, and human-readable AI role health.

## v1.0.0-rc1

- Added `socx v1-check`, a one-command readiness gate for the wall, browser API, Speedtest truth, incident memory, Label Brain, host naming, Pi fleet, Pi 3-LLM roles, and clean renderer logs.
- Added browser detail pages for `/speedtest`, `/devices`, `/incidents`, and `/ai` so the main wall can stay readable while deeper SOCX evidence remains one click away.
- Added Speedtest path state labels for browser detail views: `ready`, `stale`, `inactive`, `error`, and `waiting`, including message and external-IP context when available.
- Added a weekly executive rollup section to `socx-report weekly` with Speedtest path trends, repeated incident memory, learned device identities, and v1 readiness output.
- Added top navigation links in the browser wall for Speed, Devices, Incidents, and AI without changing the working main layout.

## v0.1.46

- Added dedicated Speedtest history in `/var/db/socx_speedtest_history.jsonl`, `socx speedtest-history`, browser trend rows, and `/api/speedtest-history`.
- Added VPN speedtest truth guarding so `socx speedtest vpn` does not overwrite the active DIRECT cache or pretend direct Frontier traffic is a VPN path when VPN is off.
- Added rolling incident memory in `/var/db/socx_incident_memory.jsonl`, `socx incident-memory`, browser memory rows, and `/api/incident-memory` for repeated source/port/DNSBL awareness.
- Added `socx pi-lab` and a browser Commander Pi Lab action for bounded, read-only Pi 5 experiments against the SOCX 3-LLM dashboard.
- Added a structured browser Incident Timeline that links firewall pressure, DNSBL hits, IDS buckets, learned-normal device anomalies, and Pi/MIRANDA AI verdicts into one readable story.
- Added a Daily SOC Brief card and `socx brief`, which saves a timestamped report under `/root/socx-briefs/` with Autopilot, Label Brain, timeline, Speedtest, and Pi AI context.
- Added approval-only `socx rules` / Rule Assistant recommendations for firewall, DNSBL, IDS, and device-profile review without automatically changing pfSense policy.
- Added browser Commander buttons and safe endpoints for Brief, Timeline, Rules, and Doctor.
- Added device identity confidence labels (`confirmed`, `likely`, `unknown`) on top of Label Brain app/service confidence.
- Expanded snapshots and reports with Daily Brief, Rule Assistant, and structured timeline artifacts.
- Added Label Brain v2 rolling local memory with per-device profiles, normal apps/services, unusual behavior hints, grouped Now Watching categories, and browser/terminal visibility.
- Added an Explain button to the browser Commander and a `/api/label-brain` endpoint for integrations and snapshots.
- Enriched Pi/MIRANDA exports with Label Brain context so local LLMs can reason about device identity and learned-normal behavior.
- Added safer IDS high-signal logic so routine Suricata decoder/checksum noise is counted as watch/routine instead of inflating critical IDS counts.
- Added friendly app/domain labels for SOCX browser and terminal wall events, including Netflix, Prime Video, YouTube, Disney+, Apple/iCloud, Hugging Face, Civitai, IBM Quantum, OpenAI, Anthropic, xAI/Grok, NVIDIA AI, Ollama, vLLM, and common cloud/CDN services when DNS/log evidence is available.
- Expanded terminal `tcpdumpx` and `iftopx` local host labeling from `/usr/local/etc/socx_hosts.conf`, plus richer lab/service port names for MIRANDA, SOCX web, Pi LLM, Ollama, vLLM, Jupyter, Ray, Gradio, Plex, metrics, and UPS/NUT.
- Added the local-passive SOCX Label Brain for browser LAN Asset Watch, Live Flow app hints, and the `socx label-brain` terminal command.
- Added SOCX Browser Mission Control v0.8 strip with Threat Pulse, LAN Asset Watch, and AI Verdict Timeline panels.
- Added browser wall controls for local ticker speed cycling and big-text mode.
- Added backend `/api/state` objects for `threat_pulse`, `asset_watch`, and `ai_timeline` using pfSense/SOCX logs and read-only cache files.
- Expanded LAN Asset Watch with Pi 4/Pi 5 health, unknown-service learner size, top LAN asset, top service, and observed known/unknown LAN device counts.
- Added v0.6 Incident Cockpit for the browser wall with top blocked sources, ports, DNSBL domains, affected LAN hosts, IDS signal, and a visible incident verdict.
- Added multi-Pi discovery with `socx pi-nodes`, including Pi 5 SOCX AI and Pi 4 node-exporter telemetry detection.
- Added a standard-library Pi telemetry sidecar on port `8096` for richer Pi Fleet health, plus SOCX status/autopilot awareness of the Pi fleet.
- Added a compact browser Pi Fleet mini-visualizer showing pfSense-to-Pi links, online count, score, temperature, memory, load, and active SOCX/sidecar services.
- Added safe browser voice Commander support for allowlisted SOCX actions: status, Incident Mode, snapshot, Zeek, Speedtest, and Pi AI.
- Added Speedtest truth comparison in the browser Command Center so client, router, direct, and VPN path readings can be compared without hiding under-reporting router-side tests.
- Fixed Zeek health checks to honor the configured `zeekctl` log directory instead of assuming the legacy `/usr/local/zeek/logs/current` path.
- Slowed the browser ticker for wall-display readability and expanded Pi Fleet cards with AI roles, Hailo/CPU model counts, experiment health, autonomy mode/score, uptime, load, memory, and Celsius/Fahrenheit temperature.
- Added safe browser Commander buttons backed by `/api/commander` for status, Incident Mode, evidence snapshot, Zeek health, Speedtest profiles, and Pi AI analysis.
- Added `socx snapshot-cron` for nightly read-only evidence snapshots with retention cleanup, and expanded snapshots with `incident.json` and `top-talkers.json`.
- Added `socx-doctor zeek --archive-reviewed` to checksum/archive reviewed Zeek crash folders before removing them from the live Zeek tmp directory.
- Enriched pfSense-to-Pi 3-LLM exports with Incident Mode and top-talker context, and added terminal-wall Incident Mode rotation through Threat Pulse/Event Feed.
- Polished the Modern Wall without changing the successful btop-style layout.
- Added v0.4 evidence and web-history helpers: `socx snapshot`, `socx notify-cron`, `/api/history`, and a browser Command Center score sparkline.
- Added `socx-doctor php-services` and a status check for malformed pfSense service entries that can trigger PHP crash reports such as `is_process_running(null)`.
- Added v0.5 Incident Mode, Zeek health checks, `/api/incident`, and `/api/top-talkers` after reviewing pfSense package tuning patterns for DNSBL, IDS/Suricata, and Zeek health evidence.
- Added v0.3 autonomy helpers: `socx why-now`, `socx history trend`, `socx notify rules`, `socx mode`, and `socx hosts suggest/apply`.
- Split Autopilot into four operator scores: network, security, AI, and sensors.
- Added before/after repair snapshots in `/tmp/socx-repair-report.txt`.
- Expanded the browser Command Center with score breakdown and history trend fields.
- Added SOCX history samples with `socx history`; `socx autopilot` now appends compact Autopilot, UPS, and DIRECT/VPN Speedtest trend data to `/var/db/socx_history.jsonl`.
- Added optional `socx notify` webhook support for operator alerts when `SOCX_NOTIFY_WEBHOOK_URL` is configured.
- Added a browser Command Center card plus `/api/command-center` for Autopilot verdict, Speedtest path truth, history count, and recommended SOCX commands.
- Added SOCX Command Center v1 with `socx menu`, `socx timeline`, `socx repair`, and `socx explain-screen` for one-place operation, timeline review, safe collector restart, and plain-English wall interpretation.
- Added `socx status`, a one-command operator health view for wall, Speedtest, UPS, WAN/VPN, DNS, vnstatd, LLDP, reports, MIRANDA, Pi AI, and unknown-service noise.
- Added `socx autopilot`, a read-only SOCX autonomy mode that scores WAN/VPN, Speedtest, firewall, DNSBL, IDS, Pi AI, and wall health into `NORMAL`, `WATCH`, `INVESTIGATE`, or `INCIDENT` without changing firewall rules.
- Rotates Autopilot mode, score, summary, and suggested read-only actions through the Modern Wall Event Feed from `/tmp/socx-autopilot.env`.
- Added DIRECT/VPN Speedtest profiles with separate caches, profile-aware imports, `socx speedtest direct`, `socx speedtest vpn`, `socx-doctor speedtest-profiles`, wall rotation, and VPN-vs-direct throughput/latency warnings.
- Added named VPN path truth caches for NYC, RCN-DE, and RCN-VA style profiles, Autopilot v2 modes such as `VPN DEGRADED`, `WAN DEGRADED`, and `SECURITY WATCH`, and incident-bundle capture of Speedtest path evidence.
- Added `socx services` / `socx-doctor unknown-services` to summarize unknown ports, generate safe service-label suggestions, apply known labels, and archive/trim the learner log.
- Expanded built-in and example service labels for MIRANDA, SOCX Web, Pi 3-LLM, Ollama/vLLM, Ray, metrics, Splunk, router APIs, Cassandra, Transmission, and common local legacy ports.
- Reworded Pi analysis events as `SOCX AI says...` so the rotating feed reads more like an operator verdict.
- Added `CHANGE` intelligence events for top-flow, WAN-quality, Speedtest-source, Pi AI-node, and unknown-device changes.
- Added `BACKUP` safety events for pfSense config age, backup count, and boot-environment visibility.
- Filtered stale gateway log warnings so old dpinger noise does not keep `socx-doctor wan-quality` in WARN.
- Improved dynamic host naming with Pi discovery overlays and smarter host-audit suggestions for Pi, Synology, AP, Lenovo, and UPS devices.
- Added WAN quality scoring from live gateway/dpinger status, including latency/loss/status events separate from Speedtest.
- Expanded LLDP topology events to show neighbor/interface summaries when switch advertisements are visible.
- Enriched Pi 3-LLM events with Pi discovery IP/service/age so SOCX can show whether the AI node is reachable and fresh.
- Added `socx-doctor wan-quality` and `socx-doctor topology` for gateway quality, LLDP, host naming, and Pi AI-node checks.
- Split Speedtest into `CLIENT` truth and `ROUTER` diagnostic caches so bad pfSense CLI tests no longer overwrite known-good browser Speedtest.net results.
- Added `socx speedtest import ...`, Speedtest source labels, 2G/2G baseline comparison, and Event Feed warnings when router-side CLI tests under-report versus the client result.
- Set scheduled Speedtest runs to a six-hour default cadence.
- Changed the Speedtest card to show a countdown to the next scheduled test instead of elapsed cache age.
- Filled the spare Memory card row with live SWAP usage so memory pressure is visible at a glance.
- Moved the top-strip `LIVE`/`STALE` indicator beside the clock so it no longer hangs off the far-right edge.
- Added UPS load headroom display using the APC Smart-UPS 2200 1950W rating and set NUT/APC polling to a one-second default.
- Added APC environmental probe caching and rotated the UPS card footer through PSU temperature, humidity, and load/headroom.
- Added severity-aware SOC coloring for healthy, neutral, warning, drop/critical, and threat-intel contexts.
- Expanded the top status strip with compact WAN/VPN/DNS/UPS metrics when available.
- Replaced compact PF abbreviations with readable `STATES`, `SEARCH`, and `TRAFFIC` labels.
- Added trend arrows for CPU, memory, packet drops, PF states/search, DNSBL hits, and WAN traffic.
- Enriched Live Packets with DNSBL/known-bad, Suricata/threat-intel, scanner, abuse, and optional country context.
- Collapsed repeated WAN scan bursts into `[FW][DROP]` summaries with top ports.
- Tightened Network Flows service labels and added `--density compact|normal|large`.
- Rotates Threat Pulse and appliance identity through the Event Feed, with an optional wide-screen Threat Pulse card.
- Hardened Modern Wall color rendering so a null row or metric cannot crash the PHP colorizer.
- Added rotating AI-SOC enrichment for CVE/CPE/CWE/CAPEC/CVSS/EPSS/KEV, ATT&CK, D3FEND, Sigma, YARA, Suricata, host artifacts, cloud logs, containment, evidence preservation, confidence, and source references.
- Replaced the Modern Wall Process Tree slot with `PFTOP LIVE STATES`, a color-coded, human-readable PF state view with direction, protocol, service, state, live rate, age/expiry, and compact LAN/EXT flow labels.
- Fixed the top status strip so VPN health is computed from live pfSense gateway/interface/WireGuard signals instead of assuming `VPN UP`; it now shows counts, `DATA LIVE`/`DATA STALE`, VPN feed details, and debug status logs.
- Added scheduled Speedtest caching with Frontier Secaucus server defaults, VPN-aware auto-server mode, Network card display, and Event Feed status without blocking wall refreshes; SOCX now prefers the official Ookla native CLI with `speedtest-go` and Python fallbacks.

## v0.1.45

- Made the Modern Wall Live Packets panel use raw packet-like firewall/DNSBL/IDS events instead of the balanced command-feed queue.
- Replaced cramped packet columns with human-readable packet story rows such as `FW DROP WAN scan blocked...`, `ALLOW Allowed...`, and `DNSBL HIT ... DNSBL hit`.
- Gave short wall displays an extra Live Packets row while preserving the main Process/Network panels.
- Suppressed exact duplicate packet rows so the Live Packets panel shows more variety.
- Kept DNSBL packet wording as query-level `DNSBL hit` / `DNS sinkhole`, separate from actual firewall drops.

## v0.1.44

- Rebalanced the Modern Wall Event Feed so firewall drops no longer crowd out DNSBL, UPS, WAN/DNS/VPN, DHCP/ARP, IDS/IPS, PF, and flow-status events.
- Increased the default event queue from 25 to 40 entries and added per-category caps for the rotating feed.
- Changed firewall feed wording from terse `drop` lines to human-readable messages like `WAN scan blocked`, `Firewall blocked`, and `Firewall allowed`.
- Added periodic SOC activity summaries for recent firewall blocks, DNSBL hit counts, top live flow, WAN/LAN traffic, UPS load, and PF state health.
- Debounced WAN counter warnings so first-sample network counters do not pin the Event Feed above real traffic events.
- Interleaved rotate-mode feed categories so firewall scans, DNSBL, flow, UPS, PF, WAN/DNS/VPN, IDS, DHCP, and ARP items appear as a mixed command feed instead of long runs of firewall events.
- Prevented routine LOW/MED events from resetting the rotate index, so frequent firewall/DNSBL updates no longer keep the feed pinned near the front of the queue.
- Lowered the routine firewall cap and moved FW behind flow/UPS/PF/DNSBL in rotate order so scan noise cannot dominate the wall.
- Reworded pfSense WAN DHCP client renewals as WAN lease events instead of misleading LAN DHCP device renewals.
- Kept DNSBL wording accurate as `DNSBL hit` / query-level filtering instead of implying the whole app/site is blocked.

## v0.1.43

- Removed the wide-mode IFTOPX `MTR` column that formed a large vertical white block on wider wall displays.
- Changed wide Network Flows to a cleaner `FLOW / RATE / SVC / KIND` table so rows stay readable without dot-heavy clipping.
- Use shorter `E15.230`/`L161` style labels in medium-width flow rows and compact UPS card details when the card is narrow.
- Label common VPN/WireGuard-style ports as `vpn` instead of clipped generic `p...` service text.
- Fixed IPv6 `filter.log` parsing by using IPv6-specific field offsets, preventing IPv6 addresses and TCP flags from landing in the wrong packet columns.
- Compact IPv6 packet/event labels as `LANv6`, `EXTv6`, `LLv6`, and `MCAST6`, and suppress routine link-local/multicast filter noise from the wall feed.
- Use short endpoint labels in firewall ticker events so long `EXT.x.y` text does not ellipsize.

## v0.1.42

- Replaced stale event-derived IFTOPX rows with live `pfctl -ss -v` state byte deltas, so Network Flows update from active pf traffic.
- Tightened narrow Network Flows columns to show compact `LAN -> EXT`, rate, and service text instead of clipped dot-heavy rows.
- Filtered loopback, multicast/link-local, and broadcast pf states out of IFTOPX so the wall focuses on useful LAN/WAN/VPN conversations.
- Compact PF stats with readable state/search/block/pass values plus insert/remove rates where space allows.
- Compacted tight NETWORK and MEMORY card values to reduce clipped `...` text on 80-column wall displays.
- Compact Live Packets endpoint and service labels so IPv6/filterlog rows do not stretch packet columns.
- Lowered the UPS cache refresh default from 500 ms to 250 ms so NUT/APC wattage updates feel more responsive on the wall.

## v0.1.41

- Fixed stale UPS telemetry by making `socx-ups-cache` fall back to direct Schneider/APC SNMP polling when NUT `upsc` cannot reach `upsd`.
- Changed the SOC launcher UPS source default to `auto` and added Schneider NMC SNMP defaults for `192.168.1.114`.
- Added sub-second cache timestamps so 500 ms UPS refreshes are visible to the wall renderer.
- Expanded cached UPS fields with model/source, input/output voltage and frequency, output current, battery voltage, battery temperature, and nominal watt rating.
- Updated the Modern Wall UPS card and UPS command-feed event to show richer live power details.

## v0.1.40

- Expanded the Modern Wall Event Feed into a prioritized SOC command ticker.
- Added command-center event categories for DNSBL, FW, IDS, IPS, VPN, WAN, DHCP, ARP, FLOW, UPS, SYS, DNS, IFACE, and DEVICE style alerts.
- Prioritized CRIT/HIGH security, outage, VPN, WAN, DNS, and UPS events above routine low-severity DNSBL hits.
- Added DNSBL spam control so low-priority repeats get `xN` counts and overflow summary lines instead of taking over the feed.
- Added two-line rotate behavior on taller walls: the highest-priority event stays visible while a secondary event rotates below it.
- Kept DNSBL wording accurate as `DNSBL hit`/`SINKHOLE`; actual firewall/IPS drops continue to use `DROP`, `REJECT`, or `blocked` wording.

## v0.1.39

- Tightened the modern-btop wall layout to reclaim rows from the top metric cards and inner padding.
- Reweighted top cards so UPS no longer steals excessive width from PF/CPU.
- Shortened Process, Flow, and Packet table headers to show more useful rows and cleaner clipped columns.
- Added a slim two-line Event Feed strip on short tmux panes so the current 80x24 wall can show 7 Process/Flow rows.
- Changed DNSBL wording from misleading `blocked` language to `DNSBL hit` / `SINKHOLE` semantics.
- Changed actual pf block display wording to `DROP`, keeping firewall drops visually distinct from DNSBL query hits.

## v0.1.38

- Changed the modern-btop Event Feed default from horizontal scrolling to rotating whole-event display.
- Added `SOCX_EVENT_FEED_MODE=scroll|rotate|stack` with `rotate` as the modern-btop default.
- Added rotate timing controls for normal, HIGH, and CRIT events while keeping repeated-event `xN` counts.
- Preserved the old scrolling ticker as an explicit fallback with `SOCX_EVENT_FEED_MODE=scroll`.
- Updated partial Event Feed redraws so rotate mode clears and rewrites only the event row without corrupting Unicode borders.

## v0.1.37

- Polished the successful Unicode `modern-btop` wall without changing the overall layout.
- Replaced the Network Flows BAR column with per-row horizontal Unicode mini meters.
- Made UPS wattage cleaner and more prominent, with readable load, battery, runtime, peak/average, and sparkline rows.
- Simplified the live header so detailed WAN/LAN/PF/UPS values only appear when there is enough width.
- Added ticker padding/separators and tightened modern card content padding.
- Improved process table clipping with shorter USER/MEM columns and Unicode ellipsis for long commands.

## v0.1.36

- Fixed the active `/usr/local/sbin/socx-wall` renderer path so `modern-btop` no longer reaches the unconditional ASCII `render_box()` wrapper.
- Added Unicode startup defaults with `SOCX_FORCE_UNICODE=true` and `SOCX_DISABLE_ASCII_FALLBACK=true`.
- Added an ASCII fallback guard that logs `BUG: render_box_ascii called in modern-btop` to `/tmp/socx-wall.err` and skips ASCII drawing unless fallback is explicitly allowed.
- Added `--force-unicode`, `--unicode-test`, and `--capture FILE` support to `socx-wall`.
- Added capture validation for modern-btop to fail on ASCII panel-border markers such as `+==`, `====`, and `| NETWORK`.

## v0.1.35

- Added `socweb`, a browser-first Option C dashboard for the modern btop-style SOC wall when terminal/tmux rendering is too limiting.
- Added a lightweight Python 3.11 standard-library HTTP/WebSocket backend under `/usr/local/share/socx-web`.
- Added dark card-based HTML/CSS/JS frontend with canvas sparklines, clipped process/flow/packet tables, prominent UPS wattage, and smooth CSS event ticker.
- Added live pfSense collectors for `top -P`, `pfctl -si`, `netstat`, `pfctl -ss`, filterlog, DNSBL logs, and the existing NUT/APC UPS cache.
- Added `socxweb` rc.d service for optional browser dashboard autoboot.

## v0.1.34

- Made `modern-btop` render Unicode by default with box-drawing card borders, block meters, Unicode sparklines, and ANSI coloring.
- Added `SOCX_BORDER_STYLE=unicode` and `SOCX_GRAPH_STYLE=unicode` defaults alongside `SOCX_UNICODE=true`.
- Reworked the wall canvas writer to handle UTF-8 cells so Unicode borders and graphs stay aligned in tmux.
- Added explicit demo-mode render diagnostics for Unicode status, border style, graph style, TERM, and TMUX.
- Kept ASCII fallback available with `SOCX_UNICODE=false`.
- Fixed PHP 8.4 `str_getcsv()` deprecation noise that could cause the wall renderer loop to exit.

## v0.1.33

- Hardened the pfSense autoboot script so SOCX starts correctly when boot calls rc scripts with `faststart`, `onestart`, or `forcestart`.
- Added boot defaults for wall mode, modern-btop theme, ASCII-safe rendering, and smooth ticker behavior.
- Moved stale SOCX rc.d backup scripts out of the pfSense boot-scan directory so only one startup hook runs.

## v0.1.32

- Changed modern-btop WALL mode from boxed metric cells to softer btop-style cards with lighter borders.
- Weighted the top cards so UPS gets more room and shows wattage as the primary readout.
- Expanded CPU, memory, network, pf, and UPS meters/sparklines while keeping table rows single-line clipped.
- Fixed the modern layout height calculation so packet panels and the event ticker do not overlap on 80x24 tmux panes.

## v0.1.31

- Redesigned the modern-btop wall renderer with thin ASCII borders and spaced cards instead of shared `====` grid borders.
- Removed the duplicate header title and changed the bottom ticker panel title to `EVENT FEED [FAST]`.
- Tightened compact 80-column flow rows to stay single-line and avoid wrapping.
- Made UPS wattage more prominent in the UPS card and reduced visual color noise.
- Added `SOCX_UNICODE=false` as the default pfSense/tmux-safe graph mode.

## v0.1.30

- Documented `soc` as the primary launch command while keeping `socx` and `SOCX` aliases.
- Updated the pfSense installer completion message to say `Run: soc`.

## v0.1.29

- Added `SOCX_THEME=modern-btop` as the default wall theme with a btop-inspired metric-card layout.
- Added top metric cards for network, pf states, CPU cores/load, memory/ARC, and UPS telemetry.
- Added a background `socx-ups-cache` collector so UPS wattage, load, battery, runtime, line voltage, 60-second peak/average, and sparkline render from cached data without blocking the ticker.
- Changed smooth ticker defaults to one-column movement every `75ms` in wall mode, with tmux clamping to avoid over-aggressive repainting.
- Added `SOCX_DEBUG_TIMING=true` logging to `/tmp/socx-wall-timing.log` for terminal size, tmux mode, render timing, ticker timing, and UPS cache age.
- Added modern demo support with `socx-wall --demo --mode wall --theme modern-btop --ticker-smooth`.

## v0.1.28

- Changed wall-mode ticker rendering to update only the bottom ticker row between full 500ms dashboard refreshes, reducing whole-screen redraw jitter.
- Switched the default ticker feel to one-character smooth scrolling with `SOCX_TICKER_STEP=1` and `SOCX_TICKER_INTERVAL_MS=25`.
- Prevented the diamond event separator from being split mid-scroll during fast ticker movement.
- Forced unbuffered wall renderer output so ticker-only frames are pushed immediately instead of bunching together.
- Removed repeated full-screen clears after startup; full dashboard refreshes now repaint in place to avoid ticker hiccups.

## v0.1.27

- Changed wall-mode ticker defaults to turbo speed with `SOCX_TICKER_STEP=6` and `SOCX_TICKER_INTERVAL_MS=50`.
- Made ticker advancement elapsed-time based so it catches up smoothly if a frame is delayed.
- Cached tmux pane dimensions for one second to reduce redraw overhead during high-speed ticker updates.

## v0.1.26

- Added wall-mode ticker speed controls: `SOCX_TICKER_SPEED`, `SOCX_TICKER_STEP`, `SOCX_TICKER_INTERVAL_MS`, `SOCX_TICKER_MAX_EVENTS`, and `SOCX_TICKER_DEDUPE_SECONDS`.
- Made wall-mode ticker movement independent from the 500ms metrics refresh; default wall ticker repaints at 100ms with fast step 4.
- Added rolling ticker queue behavior with diamond separators, 25-event default history, 10-second dedupe, and repeat counts like `x7`.
- Added brief HIGH/CRIT pin behavior so important alerts hold before scrolling away.

## v0.1.25

- Wrapped wall mode in a tmux restart loop so a renderer exit no longer drops the console back to a shell prompt.
- Added wall-renderer exception recovery with `/tmp/socx-wall.err` logging.
- Added animated CPU core pulse bars that repaint on the 0.5-second wall refresh.
- Kept UPS wattage sampled on every wall refresh and labeled it as `0.5s` in the live panel.

## v0.1.24

- Added dedicated `SOCX_MODE=wall` with a new single-pane `socx-wall` renderer for large, high-contrast wall displays.
- Added a clipped panel layout system with `truncate_text`, `pad_or_clip`, `render_box`, `render_row`, `draw_hline`, `draw_vline`, and `safe_write` helpers.
- Added demo mode via `socx-wall --demo --mode wall --once` for safe layout previews without live pfSense data.
- Added friendly host mapping through `/usr/local/etc/socx_hosts.conf`.
- Made the wall-mode IFTopX/TCPDumpX areas symmetrical and kept UPS, CPU cores, process rows, and the event ticker inside fixed panels.

## v0.1.23

- Added narrow two-line IFTopX formatting for the bottom-left pane so flow, rates, and class remain readable without right-edge clipping.
- Added narrow two-line TCPDumpX formatting for the bottom-right pane so each packet keeps its flow line paired with its service/size detail line.

## v0.1.22

- Changed the classic top cockpit refresh from `1500ms` to `500ms` so CPU core bars update twice per second.

## v0.1.21

- Added compact NUT/APC UPS telemetry into the top-left `mem net pf live` cockpit box.
- Kept the bottom rail dedicated entirely to firewall/security ticker text.
- Gave the top cockpit slightly more height so the UPS line fits on the 80-column console.

## v0.1.20

- Removed the `LIVE:` label from the classic split-wall bottom rail.
- Let the firewall/security ticker use the full tmux window width with no status prefix.

## v0.1.19

- Simplified the classic split-wall bottom rail to only `LIVE:` plus firewall/security ticker text.
- Removed the bottom-rail clock and NUT/UPS telemetry so firewall data has the full line.
- Added a ticker prefix override so this wall can show firewall text without the old `SOCX:` prefix.

## v0.1.18

- Refined the classic split-wall bottom rail: removed `SOCX WALL` and `JupiterLXI` labels from the status line.
- Moved the clock, `LIVE`, and firewall ticker to the left side of the bottom rail.
- Added compact NUT/APC UPS telemetry on the right side with a 0.5s tmux updater for wattage.

## v0.1.17

- Restored the classic split-wall `NETX` layout shown in the reference photo.
- Brought back the `SOCX WALL cpu preset NEON` top cockpit with IFTopX asset/peer map and TCPDumpX packet story panes underneath.
- Restored the bottom `SOCX WALL` / `JupiterLXI` / `LIVE` status styling and removed the NUT-first bar from the primary wall.
- Set the primary cockpit refresh back to `1500ms` to match the reference wall.

## v0.1.16

- Rebuilt the primary `NETX` wall as a single-pane LCARS SOC center instead of a split top/IFTop/TCPDump mosaic.
- Added the three-quadrant layout from the new brief: Q1 CPU/RAM/process, Q2 network/state traffic, and Q3 human-readable firewall/pflog events.
- Added filterlog parsing for readable PASS/BLOCK rows and active pf state peer sampling for the network quadrant.
- Expanded the bottom NUT widget to include APC model, wattage with `(0.5s)`, load, battery, runtime, and temperature when available.

## v0.1.15

- Rebuilt `NETX` as a SOC-specific btop wall: compute/process, live network traffic, firewall/power, and bottom IFTop/TCPDump panes.
- Added RAM history tracking so the compute panel shows both CPU and RAM traces at the 0.5s cockpit refresh.
- Replaced the static bottom `SOCX WALL` / `JupiterLXI` labels with a live `NUT APC UPS` widget plus the firewall ticker.

## v0.1.14

- Converted the primary `NETX` wall into a full-screen btop-style SOC cockpit.
- Moved IFTop/TCPDump into a separate `TRAFFICX` window so the main AOC view can match the btop layout.
- Expanded the top CPU panel height on full-screen walls and kept CPU cores on the right.
- Made the bottom rail draw a clean full-width line when the active wall has one pane.

## v0.1.13

- Reworked the SOC cockpit frame style toward a btop/LCARS look with rounded Unicode panel borders.
- Improved Unicode-aware padding so the new frame characters stay aligned inside tmux.
- Scaled the CPU history graph dynamically so normal CPU activity remains visually alive.

## v0.1.12

- Restored the wide btop-style SOC cockpit layout for 24-row wall panes.
- Kept CPU core rows dedicated to CPU/load while preserving the live NUT/APC UPS line.
- Removed the clipped duplicate UPS text from the small `mem net pf` panel.

## v0.1.11

- Added a `NUT UPS` tmux status-bar widget so UPS watts, load, battery, and runtime are visible below the traffic panes.
- Updated SOCX startup to clear stale legacy `soc` tmux sessions before launching the current `socx` wall.

## v0.1.10

- Added NUT/APC Smart-UPS telemetry to the SOCX top cockpit.
- Shows UPS state, live wattage, load percent, battery percent, runtime remaining, and input voltage.
- Changed the SOCX cockpit refresh to `0.5s` so UPS wattage and system health repaint faster.

## v0.1.9

- Reworked IFTop wall rows into `asset` and `peer` columns.
- Added `10s` and SOC `tag` fields to use horizontal space more effectively.
- Removed relationship symbols from IFTop wall rows.

## v0.1.8

- Replaced IFTop `talks with` labels with a compact SOC-style `⇄` relationship marker.
- Removed IFTop `>` truncation markers and replaced them with ellipses.

## v0.1.7

- Reworded IFTop as a human-readable live-conversations panel.
- Removed `>`/arrow-style flow text from the IFTop wall view.
- Renamed IFTop columns to `up`, `down`, `trend`, and `heat`.

## v0.1.6

- Widened the IFTop lower pane by giving TCPDump a narrower 44% split.
- Added a compact `40s` trend column to IFTop so the wider pane uses space more evenly.

## v0.1.5

- Flattened the lower SOCX rail so the far left/right edges no longer curl upward.
- Let IFTop fill the available pane height before hiding lower-priority traffic rows.
- Increased IFTop sampling depth so the lower-left pane shows more rows and better matches TCPDump density.

## v0.1.4

- Reworked the lower SOCX wall into two matched panels with one-line data rows.
- Restored the bottom frame as a dedicated rail above the ticker and changed it to `╚════╩════╝`.
- Removed the unused pane-border floor helper after standardizing on the unified lower frame rail.

## v0.1.3

- Removed the two internal IFTop separator rules so the lower pane reads cleaner with the tmux pane-border floor.

## v0.1.2

- Moved the lower IFTop/TCPDump floor from a separate tmux status rail into the tmux pane-border layer.
- Added `socx-pane-floor` so the lower horizontal line is drawn on the same row/layer as the vertical pane divider.
- Reduced SOCX back to one status row for the ticker while keeping the lower panes boxed in.

## v0.1.1

- Replaced ASCII dash/equal divider lines with solid box-drawing rules.
- Changed the SOCX bottom rail to a continuous `═` line with a centered `╩` join under the IFTop/TCPDump split.
- Refreshed the lower-pane renderers so IFTop and TCPDump read as one squared-in console frame.

## v0.1.0

- Initial SOCX pfSense Wall release.
- Added pfsense-btop, iftopx, tcpdumpx, SOC ticker, bottom rail, install script, and pfSense autoboot service.
