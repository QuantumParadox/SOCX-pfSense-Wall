#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

install -m 0755 "$ROOT/scripts/pfsense-btop.php" /usr/local/sbin/pfsense-btop
install -m 0755 "$ROOT/scripts/socxboard.sh" /root/socxboard.sh
install -m 0755 "$ROOT/scripts/socx-bottom-rail" /usr/local/sbin/socx-bottom-rail
install -m 0755 "$ROOT/scripts/socx-alert-ticker" /usr/local/sbin/socx-alert-ticker
install -m 0755 "$ROOT/scripts/socx-ups-status" /usr/local/sbin/socx-ups-status
install -m 0755 "$ROOT/scripts/socx-iftop-color" /usr/local/sbin/socx-iftop-color
install -m 0755 "$ROOT/scripts/socx-tcpdump-color" /usr/local/sbin/socx-tcpdump-color
install -m 0755 "$ROOT/scripts/socx_iftop_color.pl" /usr/local/sbin/socx_iftop_color.pl
install -m 0755 "$ROOT/scripts/socx_tcpdump_color.pl" /usr/local/sbin/socx_tcpdump_color.pl
install -m 0755 "$ROOT/scripts/iftopx" /usr/local/bin/iftopx
install -m 0755 "$ROOT/scripts/tcpdumpx" /usr/local/bin/tcpdumpx
install -m 0755 "$ROOT/scripts/socx" /usr/local/bin/socx
ln -sf /usr/local/bin/socx /usr/local/bin/SOCX
ln -sf /usr/local/bin/socx /usr/local/bin/soc
install -m 0755 "$ROOT/rc.d/socx" /usr/local/etc/rc.d/socx

echo "SOCX pfSense Wall installed."
echo "Run: socx"
echo "Autoboot script: /usr/local/etc/rc.d/socx"
