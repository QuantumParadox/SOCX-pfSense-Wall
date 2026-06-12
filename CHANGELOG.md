# Changelog

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
