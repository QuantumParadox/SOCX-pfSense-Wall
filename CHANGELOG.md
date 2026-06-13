# Changelog

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
