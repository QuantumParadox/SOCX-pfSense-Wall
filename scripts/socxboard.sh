#!/bin/sh
# SOCX: colorized SOC console dashboard.
IFLAN=ix0; IFWAN=ix1
MODE="${SOCX_MODE:-wall}"
THEME="${SOCX_THEME:-modern-btop}"
TOPR="pfbtop --interval 0.5 --top 24"
if [ "$MODE" = "wall" ]; then
    TOPR="/bin/sh -c 'while :; do /usr/local/sbin/socx-wall --mode wall --theme \"$THEME\" --ticker-smooth --interval 0.5 2>>/tmp/socx-wall.err; printf \"\\033[0m\\nSOCX wall renderer exited; restarting in 3s\\n\"; sleep 3; done'"
fi
ZK=/var/spool/zeek/zeek
SURI=$(ls -d /var/log/suricata/suricata_* 2>/dev/null | head -1)
ALERTS="$SURI/alerts.log"
DNSBL=/var/log/pfblockerng/dnsbl.log
[ -f "$DNSBL" ] || DNSBL=/var/log/pfblockerng/dns_reply.log

export TMUX_TMPDIR=/tmp
export SOCX_THEME="${SOCX_THEME:-modern-btop}"
export SOCX_UNICODE="${SOCX_UNICODE:-true}"
export SOCX_BORDER_STYLE="${SOCX_BORDER_STYLE:-unicode}"
export SOCX_GRAPH_STYLE="${SOCX_GRAPH_STYLE:-unicode}"
export SOCX_TMUX_MODE="${SOCX_TMUX_MODE:-auto}"
export SOCX_FORCE_256COLOR="${SOCX_FORCE_256COLOR:-true}"
export SOCX_UPS_ENABLED="${SOCX_UPS_ENABLED:-true}"
export SOCX_UPS_SOURCE="${SOCX_UPS_SOURCE:-auto}"
export SOCX_UPS_REFRESH_MS="${SOCX_UPS_REFRESH_MS:-1000}"
export SOCX_UPS_HISTORY_SECONDS="${SOCX_UPS_HISTORY_SECONDS:-60}"
export SOCX_UPS_SHOW_SPARKLINE="${SOCX_UPS_SHOW_SPARKLINE:-true}"
export SOCX_UPS_NOMINAL_WATTS="${SOCX_UPS_NOMINAL_WATTS:-1950}"
export SOCX_UPS_SNMP_HOST="${SOCX_UPS_SNMP_HOST:-192.168.1.114}"
export SOCX_UPS_SNMP_COMMUNITY="${SOCX_UPS_SNMP_COMMUNITY:-public}"
export SOCX_UPS_SNMP_VERSION="${SOCX_UPS_SNMP_VERSION:-v2c}"
export SOCX_SPEEDTEST_ENABLED="${SOCX_SPEEDTEST_ENABLED:-true}"
export SOCX_SPEEDTEST_INTERVAL="${SOCX_SPEEDTEST_INTERVAL:-6h}"
export SOCX_SPEEDTEST_FRONTIER_SERVER_ID="${SOCX_SPEEDTEST_FRONTIER_SERVER_ID:-56485}"
export SOCX_SPEEDTEST_FRONTIER_SERVER_NAME="${SOCX_SPEEDTEST_FRONTIER_SERVER_NAME:-Frontier}"
export SOCX_SPEEDTEST_FRONTIER_SERVER_LOCATION="${SOCX_SPEEDTEST_FRONTIER_SERVER_LOCATION:-Secaucus, NJ}"
export SOCX_SPEEDTEST_SERVER_MODE="${SOCX_SPEEDTEST_SERVER_MODE:-auto}"
export SOCX_PACKET_RADAR_ENABLED="${SOCX_PACKET_RADAR_ENABLED:-true}"
export SOCX_PACKET_RADAR_IFACE="${SOCX_PACKET_RADAR_IFACE:-$IFWAN}"
export SOCX_PACKET_RADAR_FILTER="${SOCX_PACKET_RADAR_FILTER:-not arp and not port 22}"
export SOCX_TMUX_WIDTH="${SOCX_TMUX_WIDTH:-160}"
export SOCX_TMUX_HEIGHT="${SOCX_TMUX_HEIGHT:-42}"

# WALL MODE ticker controls:
#   SOCX_TICKER_SPEED=slow|normal|fast|turbo
#   SOCX_TICKER_STEP=1|2|3|4|6
#   SOCX_TICKER_INTERVAL_MS=75
#   SOCX_TICKER_MAX_EVENTS=25
#   SOCX_TICKER_DEDUPE_SECONDS=10
export SOCX_TICKER_SPEED="${SOCX_TICKER_SPEED:-fast}"
export SOCX_TICKER_STEP="${SOCX_TICKER_STEP:-1}"
export SOCX_TICKER_INTERVAL_MS="${SOCX_TICKER_INTERVAL_MS:-75}"
export SOCX_TICKER_MAX_EVENTS="${SOCX_TICKER_MAX_EVENTS:-25}"
export SOCX_TICKER_DEDUPE_SECONDS="${SOCX_TICKER_DEDUPE_SECONDS:-10}"
/sbin/conscontrol mute on 2>/dev/null
mesg n 2>/dev/null || true
chmod go-w /dev/ttyv0 /dev/pts/* 2>/dev/null || true
if [ "$SOCX_FORCE_256COLOR" = "true" ] && command -v tput >/dev/null 2>&1; then
    export TERM="${TERM:-tmux-256color}"
fi

stop_socx_helper() {
    pattern=$1
    pkill -f "$pattern" 2>/dev/null || true
    ps ax -o pid= -o command= 2>/dev/null | awk -v pattern="$pattern" '
        index($0, pattern) { print $1 }
    ' | while IFS= read -r pid; do
        [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
    done
}

tmux kill-session -t socx 2>/dev/null
if [ "$MODE" = "wall" ] && [ "$SOCX_UPS_ENABLED" = "true" ] && command -v /usr/local/sbin/socx-ups-cache >/dev/null 2>&1; then
    if [ -f /tmp/socx-ups-cache.pid ]; then
        kill "$(cat /tmp/socx-ups-cache.pid)" 2>/dev/null || true
    fi
    stop_socx_helper 'socx-ups-cache'
    /usr/local/sbin/socx-ups-cache loop >/tmp/socx-ups-cache.log 2>&1 &
    echo $! >/tmp/socx-ups-cache.pid
fi
if [ "$MODE" = "wall" ] && [ "$SOCX_SPEEDTEST_ENABLED" = "true" ] && command -v /usr/local/sbin/socx-speedtest-cache >/dev/null 2>&1; then
    if [ -f /tmp/socx-speedtest-cache.pid ]; then
        kill "$(cat /tmp/socx-speedtest-cache.pid)" 2>/dev/null || true
    fi
    stop_socx_helper 'socx-speedtest-cache'
    /usr/local/sbin/socx-speedtest-cache loop >/tmp/socx-speedtest-cache.log 2>&1 &
    echo $! >/tmp/socx-speedtest-cache.pid
fi
if [ "$MODE" = "wall" ] && [ "$SOCX_PACKET_RADAR_ENABLED" = "true" ] && command -v /usr/local/sbin/socx-packet-radar-cache >/dev/null 2>&1; then
    /usr/local/sbin/socx-packet-radar-cache stop >/dev/null 2>&1 || true
    stop_socx_helper 'socx-packet-radar-cache'
    SOCX_PACKET_RADAR_IFACE="$SOCX_PACKET_RADAR_IFACE" SOCX_PACKET_RADAR_FILTER="$SOCX_PACKET_RADAR_FILTER" \
        /usr/local/sbin/socx-packet-radar-cache loop >/tmp/socx-packet-radar-cache.log 2>&1 &
    echo $! >/tmp/socx-packet-radar.pid
fi

# W1 NETX: full-screen SOCX wall. Command tools live in popups/COMMANDX so
# the wall never loses height to a bottom split.
tmux new-session -d -x "$SOCX_TMUX_WIDTH" -y "$SOCX_TMUX_HEIGHT" -s socx -n NETX "$TOPR"
tmux set-option -t socx -g mouse off
tmux set-option -t socx -g default-size "${SOCX_TMUX_WIDTH}x${SOCX_TMUX_HEIGHT}" 2>/dev/null || true
tmux set-option -t socx -g window-size latest 2>/dev/null || true
tmux set-window-option -t socx -g aggressive-resize on 2>/dev/null || true
tmux set-option -t socx -g activity-action none 2>/dev/null || true
tmux set-option -t socx -g bell-action none 2>/dev/null || true
tmux set-option -t socx -g visual-activity off 2>/dev/null || true
tmux set-option -t socx -g visual-bell off 2>/dev/null || true
tmux set-window-option -t socx -g monitor-activity off 2>/dev/null || true
tmux set-window-option -t socx -g monitor-bell off 2>/dev/null || true
tmux set-window-option -t socx -g remain-on-exit off 2>/dev/null || true
tmux set-option -t socx -g pane-border-lines double
tmux set-option -t socx -g pane-border-style 'fg=colour51'
tmux set-option -t socx -g pane-active-border-style 'fg=colour51,bold'
tmux set-option -t socx -g message-style 'fg=colour16,bg=colour51,bold'
tmux set-option -t socx -g display-panes-colour colour201
tmux set-option -t socx -g display-panes-active-colour colour51
tmux set-option -t socx -g status-left-length 200
tmux set-option -t socx -g status-right-length 0
tmux set-option -t socx -g status-left '#[fg=colour226,bg=black]#(SOCX_TICKER_PREFIX= SOCX_TICKER_STEP=18 /usr/local/sbin/socx-alert-ticker #{window_width})'
tmux set-option -t socx -g status-right ''
tmux set-window-option -t socx -g window-status-format '#[fg=colour245,bg=black] #I:#W '
tmux set-window-option -t socx -g window-status-current-format '#[fg=colour16,bg=colour201,bold] #I:#W '
tmux set-window-option -t socx -g window-active-style 'fg=colour255,bg=black'
tmux set-window-option -t socx -g window-style 'fg=colour250,bg=black'
tmux bind-key c display-popup -E -w 92% -h 76% -T 'SOCX COMMAND DECK' "sh -lc 'clear; echo \"SOCX COMMAND DECK\"; echo \"packet-radar | pftop | vnstat | vpn | logs | shell\"; echo; exec sh'"
tmux bind-key r display-popup -E -w 96% -h 82% -T 'PACKET RADAR' "sh -lc 'SOCX_WIDTH=132 SOCX_COMPACT=1 tcpdumpx -i $SOCX_PACKET_RADAR_IFACE -nn -q $SOCX_PACKET_RADAR_FILTER'"
tmux bind-key f display-popup -E -w 96% -h 82% -T 'PFTOP LIVE STATES' "pftop"
tmux bind-key v display-popup -E -w 88% -h 74% -T 'VPN GATEWAY STATUS' "sh -lc '/usr/local/sbin/pfSsh.php playback gatewaystatus; echo; wg show 2>/dev/null; echo; read -r _'"
tmux bind-key t display-popup -E -w 88% -h 74% -T 'TRAFFIC TOTALS' "sh -lc 'vnstat; echo; vnstat -i $IFWAN; echo; read -r _'"
tmux bind-key i display-popup -E -w 88% -h 74% -T 'SOCX INCIDENT CAPTURE' "sh -lc '/usr/local/sbin/socx-incident-capture; echo; echo Incident capture complete.; read -r _'"
if [ "$MODE" = "wall" ]; then
    tmux set-option -t socx -g status off
    tmux set-option -t socx -g history-limit 100 2>/dev/null || true
    tmux set-window-option -t socx:NETX synchronize-panes on 2>/dev/null || true
    tmux set-window-option -t socx:NETX pane-border-status off
    tmux select-pane -t socx:NETX.0 -T 'SOCX WALL MODE'
else
    tmux set-option -t socx -g status on
    tmux set-option -t socx -g status 2
    tmux set-option -t socx -g status-interval 1
    tmux set-option -t socx -g status-position bottom
    tmux set-option -t socx -g status-justify centre
    tmux set-option -t socx -g status-style 'fg=colour51,bg=black,bold'
    tmux set-option -t socx -g status-format[0] '#[fg=colour51,bg=black,bold]#(/usr/local/sbin/socx-bottom-rail socx:NETX)'
    tmux set-option -t socx -g status-format[1] '#[align=left]#[fg=colour226,bg=black]#(SOCX_TICKER_PREFIX= SOCX_TICKER_STEP=18 /usr/local/sbin/socx-alert-ticker #{window_width})'
    tmux split-window -v -p 38 -t socx:NETX "iftopx -i $IFLAN -n -N"
    tmux split-window -h -p 44 -t socx:NETX.1 "tcpdumpx -i $IFWAN -nn -q"
    tmux select-pane -t socx:NETX.0 -T 'PF TOP'
    tmux select-pane -t socx:NETX.1 -T 'IFTOPX FLOW RADAR'
    tmux select-pane -t socx:NETX.2 -T 'TCPDUMPX PACKET STORY'
    tmux set-window-option -t socx:NETX pane-border-status off
    tmux set-window-option -t socx:NETX pane-border-format '#[fg=colour51,bold]#{pane_title}'
fi
tmux select-pane -t socx:NETX.0
tmux clear-history -t socx:NETX.0 2>/dev/null || true

# W2 THREATX: retain the existing alert views, but with brighter grep color.
tmux new-window -t socx -n THREATX "sh -c 'export GREP_COLOR=\"01;31\"; echo == SURICATA IDS ALERTS ==; tail -n 40 -F \"$ALERTS\" 2>/dev/null | grep --line-buffered --color=always -Ei \"alert|drop|blocked|malware|scan|trojan|cnc|c2|$\"'"
tmux split-window -h -t socx:THREATX "sh -c 'export GREP_COLOR=\"01;35\"; echo == pfBlockerNG DNSBL ==; tail -n 40 -F \"$DNSBL\" 2>/dev/null | grep --line-buffered --color=always -Ei \"block|deny|dnsbl|$\"'"
tmux split-window -v -p 50 -t socx:THREATX.0 "pftop"

# W3 FLOWX
tmux new-window -t socx -n FLOWX "vnstat -l -i $IFWAN"
tmux split-window -h -t socx:FLOWX "systat -ifstat 1"
tmux split-window -v -t socx:FLOWX.0 "mtr -o LSRNABWV 1.1.1.1"

# W4 LIVEX
tmux new-window -t socx -n LIVEX "sh -c 'echo == FIREWALL filter.log ==; tail -n 50 -F /var/log/filter.log 2>/dev/null | grep --line-buffered --color=always -Ei \"block|pass|tcp|udp|icmp|$\"'"
tmux split-window -h -t socx:LIVEX "sh -c 'echo == ZEEK conn.log ==; tail -n 50 -F $ZK/conn.log 2>/dev/null'"
tmux split-window -v -t socx:LIVEX.0 "sh -c 'echo == ZEEK dns.log ==; tail -n 50 -F $ZK/dns.log 2>/dev/null'"
tmux split-window -v -t socx:LIVEX.1 "sh /root/eve_alerts.sh"

# W5 SYSX
tmux new-window -t socx -n SYSX "systat -vmstat 1"
tmux split-window -h -t socx:SYSX "systat -iostat 1"
tmux split-window -v -t socx:SYSX.0 "top -m io"

# W6 COMMANDX: command deck lives in its own window so NETX never gets clipped.
tmux new-window -t socx -n COMMANDX "sh -lc 'clear; cat <<EOF
SOCX COMMANDX

Prefix + c  popup shell
Prefix + r  Packet Radar tcpdump view
Prefix + f  pftop live states
Prefix + v  VPN gateway and WireGuard detail
Prefix + t  vnStat traffic totals
Prefix + i  capture SOCX incident evidence bundle

Useful commands:
  packet-radar
  tcpdumpx -i $IFWAN -nn -q $SOCX_PACKET_RADAR_FILTER
  pftop
  vnstat -i $IFWAN
  socx-incident-capture
  tail -f /var/log/filter.log

EOF
exec sh'"

tmux select-window -t socx:NETX
if [ -t 0 ]; then
    tmux attach -t socx
else
    tmux display-message -t socx 'SOCX started detached; attach with: tmux attach -t socx'
fi
