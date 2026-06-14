# Changelog

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
