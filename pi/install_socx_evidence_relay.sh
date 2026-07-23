#!/bin/sh
# Installs the SOCX LAN-only evidence relay on a trusted Pi 4 host.
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
DEST="/opt/socx-pi-evidence-relay"

if [ "$(id -u)" -ne 0 ]; then
    exec sudo sh "$0" "$@"
fi

install -d -m 0755 "$DEST"
install -m 0755 "$ROOT/socx_pi4_evidence_relay.py" "$DEST/socx_pi4_evidence_relay.py"
install -m 0644 "$ROOT/socx-pi-evidence-relay.service" /etc/systemd/system/socx-pi-evidence-relay.service
systemctl daemon-reload
systemctl enable --now socx-pi-evidence-relay.service
systemctl --no-pager --full status socx-pi-evidence-relay.service
echo "SOCX evidence relay installed. It accepts TCP syslog on 5514 only from 192.168.1.1 and exposes health on 8097."
