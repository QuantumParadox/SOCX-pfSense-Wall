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
install -m 0755 "$ROOT/scripts/socx-iftop-color" /usr/local/sbin/socx-iftop-color
install -m 0755 "$ROOT/scripts/socx-tcpdump-color" /usr/local/sbin/socx-tcpdump-color
install -m 0755 "$ROOT/scripts/socx_iftop_color.pl" /usr/local/sbin/socx_iftop_color.pl
install -m 0755 "$ROOT/scripts/socx_tcpdump_color.pl" /usr/local/sbin/socx_tcpdump_color.pl
install -m 0755 "$ROOT/scripts/iftopx" /usr/local/bin/iftopx
install -m 0755 "$ROOT/scripts/tcpdumpx" /usr/local/bin/tcpdumpx
install -m 0755 "$ROOT/scripts/socx" /usr/local/bin/socx
install -m 0755 "$ROOT/scripts/socweb" /usr/local/bin/socweb
install -m 0755 "$ROOT/web/socx-web.py" /usr/local/share/socx-web/socx-web.py
install -m 0644 "$ROOT/web/static/index.html" /usr/local/share/socx-web/static/index.html
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

echo "SOCX pfSense Wall installed."
echo "Run: soc"
echo "Web UI: socweb --host 0.0.0.0 --port 8094"
echo "Autoboot script: /usr/local/etc/rc.d/socx"
echo "Web boot script: /usr/local/etc/rc.d/socxweb"
