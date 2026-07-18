#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

install -d -m 0755 /usr/local/share/socx-web
install -d -m 0755 /usr/local/share/socx-web/static
install -m 0755 "$ROOT/scripts/pfsense-btop.php" /usr/local/sbin/pfsense-btop
install -m 0755 "$ROOT/scripts/socx-wall.php" /usr/local/sbin/socx-wall
install -m 0755 "$ROOT/scripts/socxboard.sh" /root/socxboard.sh
install -m 0755 "$ROOT/scripts/socx-bottom-rail" /usr/local/sbin/socx-bottom-rail
install -m 0755 "$ROOT/scripts/socx-alert-ticker" /usr/local/sbin/socx-alert-ticker
install -m 0755 "$ROOT/scripts/socx-ups-status" /usr/local/sbin/socx-ups-status
install -m 0755 "$ROOT/scripts/socx-ups-cache" /usr/local/sbin/socx-ups-cache
install -m 0755 "$ROOT/scripts/socx-speedtest-cache" /usr/local/sbin/socx-speedtest-cache
install -m 0755 "$ROOT/scripts/socx-speedtest-history" /usr/local/bin/socx-speedtest-history
install -m 0755 "$ROOT/scripts/socx-packet-radar-cache" /usr/local/sbin/socx-packet-radar-cache
install -m 0755 "$ROOT/scripts/socx-incident-capture" /usr/local/sbin/socx-incident-capture
install -m 0755 "$ROOT/scripts/socx-incident" /usr/local/bin/socx-incident
install -m 0755 "$ROOT/scripts/socx-report" /usr/local/bin/socx-report
install -m 0755 "$ROOT/scripts/socx-report-cron" /usr/local/bin/socx-report-cron
install -m 0755 "$ROOT/scripts/socx-hosts-audit" /usr/local/bin/socx-hosts-audit
install -m 0755 "$ROOT/scripts/socx-explain" /usr/local/bin/socx-explain
install -m 0755 "$ROOT/scripts/socx-menu" /usr/local/bin/socx-menu
install -m 0755 "$ROOT/scripts/socx-timeline" /usr/local/bin/socx-timeline
install -m 0755 "$ROOT/scripts/socx-incident-memory" /usr/local/bin/socx-incident-memory
install -m 0755 "$ROOT/scripts/socx-brief" /usr/local/bin/socx-brief
install -m 0755 "$ROOT/scripts/socx-story" /usr/local/bin/socx-story
install -m 0755 "$ROOT/scripts/socx-rule-assistant" /usr/local/bin/socx-rule-assistant
install -m 0755 "$ROOT/scripts/socx-repair" /usr/local/bin/socx-repair
install -m 0755 "$ROOT/scripts/socx-explain-screen" /usr/local/bin/socx-explain-screen
install -m 0755 "$ROOT/scripts/socx-history" /usr/local/bin/socx-history
install -m 0755 "$ROOT/scripts/socx-notify" /usr/local/bin/socx-notify
install -m 0755 "$ROOT/scripts/socx-notify-cron" /usr/local/bin/socx-notify-cron
install -m 0755 "$ROOT/scripts/socx-snapshot" /usr/local/bin/socx-snapshot
install -m 0755 "$ROOT/scripts/socx-snapshot-cron" /usr/local/bin/socx-snapshot-cron
install -m 0755 "$ROOT/scripts/socx-incident-mode" /usr/local/bin/socx-incident-mode
install -m 0755 "$ROOT/scripts/socx-why-now" /usr/local/bin/socx-why-now
install -m 0755 "$ROOT/scripts/socx-mode" /usr/local/bin/socx-mode
install -m 0755 "$ROOT/scripts/socx-host-labels" /usr/local/bin/socx-host-labels
install -m 0755 "$ROOT/scripts/socx-label-brain" /usr/local/bin/socx-label-brain
install -m 0755 "$ROOT/scripts/socx-doctor" /usr/local/bin/socx-doctor
install -m 0755 "$ROOT/scripts/socx-v1-check" /usr/local/bin/socx-v1-check
install -m 0755 "$ROOT/scripts/socx-ai-explain" /usr/local/bin/socx-ai-explain
install -m 0755 "$ROOT/scripts/socx-miranda-bridge" /usr/local/bin/socx-miranda-bridge
install -m 0755 "$ROOT/scripts/socx-miranda-cron" /usr/local/bin/socx-miranda-cron
install -m 0755 "$ROOT/scripts/socx-pi-llm-bridge" /usr/local/bin/socx-pi-llm-bridge
install -m 0755 "$ROOT/scripts/socx-pi-llm-cron" /usr/local/bin/socx-pi-llm-cron
install -m 0755 "$ROOT/scripts/socx-pi-lab" /usr/local/bin/socx-pi-lab
install -m 0755 "$ROOT/scripts/socx-pi-discover" /usr/local/bin/socx-pi-discover
install -m 0755 "$ROOT/scripts/socx-pi-nodes" /usr/local/bin/socx-pi-nodes
install -m 0755 "$ROOT/scripts/socx-iftop-color" /usr/local/sbin/socx-iftop-color
install -m 0755 "$ROOT/scripts/socx-tcpdump-color" /usr/local/sbin/socx-tcpdump-color
install -m 0755 "$ROOT/scripts/socx_iftop_color.pl" /usr/local/sbin/socx_iftop_color.pl
install -m 0755 "$ROOT/scripts/socx_tcpdump_color.pl" /usr/local/sbin/socx_tcpdump_color.pl
install -m 0755 "$ROOT/scripts/iftopx" /usr/local/bin/iftopx
install -m 0755 "$ROOT/scripts/tcpdumpx" /usr/local/bin/tcpdumpx
install -m 0755 "$ROOT/scripts/packet-radar" /usr/local/bin/packet-radar
install -m 0755 "$ROOT/scripts/socx" /usr/local/bin/socx
install -m 0755 "$ROOT/scripts/socweb" /usr/local/bin/socweb
install -m 0755 "$ROOT/web/socx-web.py" /usr/local/share/socx-web/socx-web.py
install -m 0644 "$ROOT/web/static/index.html" /usr/local/share/socx-web/static/index.html
install -m 0644 "$ROOT/web/static/detail.html" /usr/local/share/socx-web/static/detail.html
install -m 0644 "$ROOT/web/static/detail.js" /usr/local/share/socx-web/static/detail.js
install -m 0644 "$ROOT/web/static/styles.css" /usr/local/share/socx-web/static/styles.css
install -m 0644 "$ROOT/web/static/app.js" /usr/local/share/socx-web/static/app.js
ln -sf /usr/local/bin/socx /usr/local/bin/SOCX
ln -sf /usr/local/bin/socx /usr/local/bin/soc
install -m 0755 "$ROOT/rc.d/socx" /usr/local/etc/rc.d/socx
install -m 0755 "$ROOT/rc.d/socxweb" /usr/local/etc/rc.d/socxweb
if [ ! -f /usr/local/etc/socx_hosts.conf ]; then
    install -m 0644 "$ROOT/config/socx_hosts.conf.example" /usr/local/etc/socx_hosts.conf
fi
if [ ! -f /usr/local/etc/socx_ai_lab.conf ]; then
    install -m 0644 "$ROOT/config/socx_ai_lab.conf.example" /usr/local/etc/socx_ai_lab.conf
fi
if [ ! -f /usr/local/etc/socx_services.conf ]; then
    install -m 0644 "$ROOT/config/socx_services.conf.example" /usr/local/etc/socx_services.conf
fi
if [ ! -f /usr/local/etc/socx_watchlist.conf ]; then
    install -m 0644 "$ROOT/config/socx_watchlist.conf.example" /usr/local/etc/socx_watchlist.conf
fi
if [ ! -f /usr/local/etc/socx_speedtest_paths.conf ]; then
    install -m 0644 "$ROOT/config/socx_speedtest_paths.conf.example" /usr/local/etc/socx_speedtest_paths.conf
fi

echo "SOCX pfSense Wall installed."
echo "Run: soc"
echo "Web UI: socweb --host 0.0.0.0 --port 8094"
echo "Autoboot script: /usr/local/etc/rc.d/socx"
echo "Web boot script: /usr/local/etc/rc.d/socxweb"
