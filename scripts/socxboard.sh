#!/bin/sh
# SOCX: colorized SOC console dashboard.
IFLAN=ix0; IFWAN=ix1
TOPR="pfbtop --interval 1.5 --top 24"
ZK=/var/spool/zeek/zeek
SURI=$(ls -d /var/log/suricata/suricata_* 2>/dev/null | head -1)
ALERTS="$SURI/alerts.log"
DNSBL=/var/log/pfblockerng/dnsbl.log
[ -f "$DNSBL" ] || DNSBL=/var/log/pfblockerng/dns_reply.log

export TMUX_TMPDIR=/tmp
export SOCX_TICKER_STEP=18
/sbin/conscontrol mute on 2>/dev/null
mesg n 2>/dev/null || true
chmod go-w /dev/ttyv0 /dev/pts/* 2>/dev/null || true

tmux kill-session -t socx 2>/dev/null

# W1 NETX: pfSense cockpit top, color iftop/tcpdump clones underneath.
tmux new-session -d -s socx -n NETX "$TOPR"
tmux set-option -t socx -g mouse off
tmux set-option -t socx -g status on
tmux set-option -t socx -g status 2
tmux set-option -t socx -g status-interval 1
tmux set-option -t socx -g status-position bottom
tmux set-option -t socx -g status-justify centre
tmux set-option -t socx -g status-style 'fg=colour51,bg=black,bold'
tmux set-option -t socx -g status-format[0] '#[fg=colour51,bg=black,bold]#(/usr/local/sbin/socx-bottom-rail socx:NETX)'
tmux set-option -t socx -g status-format[1] '#[align=left]#[fg=colour16,bg=colour51,bold] %H:%M:%S #[fg=colour119,bg=black,bold] LIVE #[fg=colour245,bg=black]| #[fg=colour226,bg=black]#(SOCX_TICKER_STEP=18 /usr/local/sbin/socx-alert-ticker 48) #[align=right]#[fg=colour45,bg=black,bold]#{@socx_ups} '
tmux set-option -t socx -g pane-border-lines double
tmux set-option -t socx -g pane-border-style 'fg=colour51'
tmux set-option -t socx -g pane-active-border-style 'fg=colour51,bold'
tmux set-option -t socx -g message-style 'fg=colour16,bg=colour51,bold'
tmux set-option -t socx -g display-panes-colour colour201
tmux set-option -t socx -g display-panes-active-colour colour51
tmux set-option -t socx -g status-left-length 132
tmux set-option -t socx -g status-right-length 60
tmux set-option -t socx -g status-left '#[fg=colour16,bg=colour51,bold] %H:%M:%S #[fg=colour119,bg=black,bold] LIVE #[fg=colour245,bg=black]| #[fg=colour226,bg=black]#(SOCX_TICKER_STEP=18 /usr/local/sbin/socx-alert-ticker 48) '
tmux set-option -t socx -g status-right '#[fg=colour45,bold]#{@socx_ups} '
tmux set-option -t socx -g @socx_ups "$(SOCX_UPS_COMPACT=1 /usr/local/sbin/socx-ups-status 2>/dev/null || echo 'NUT UPS warming up')"
tmux run-shell -b 'while tmux has-session -t socx 2>/dev/null; do tmux set-option -q -t socx -g @socx_ups "$(SOCX_UPS_COMPACT=1 /usr/local/sbin/socx-ups-status 2>/dev/null || echo NUT UPS unavailable)"; tmux refresh-client -S -t socx 2>/dev/null || true; sleep 0.5; done'
tmux set-window-option -t socx -g window-status-format '#[fg=colour245,bg=black] #I:#W '
tmux set-window-option -t socx -g window-status-current-format '#[fg=colour16,bg=colour201,bold] #I:#W '
tmux set-window-option -t socx -g window-active-style 'fg=colour255,bg=black'
tmux set-window-option -t socx -g window-style 'fg=colour250,bg=black'
tmux split-window -v -p 42 -t socx:NETX "iftopx -i $IFLAN -n -N"
tmux split-window -h -p 44 -t socx:NETX.1 "tcpdumpx -i $IFWAN -nn -q"
tmux select-pane -t socx:NETX.0 -T 'PF TOP'
tmux select-pane -t socx:NETX.1 -T 'IFTOPX FLOW RADAR'
tmux select-pane -t socx:NETX.2 -T 'TCPDUMPX PACKET STORY'
tmux set-window-option -t socx:NETX pane-border-status off
tmux set-window-option -t socx:NETX pane-border-format '#[fg=colour51,bold]#{pane_title}'
tmux select-pane -t socx:NETX.0

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

tmux select-window -t socx:NETX
if [ -t 0 ]; then
    tmux attach -t socx
else
    tmux display-message -t socx 'SOCX started detached; attach with: tmux attach -t socx'
fi
