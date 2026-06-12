# Changelog

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
