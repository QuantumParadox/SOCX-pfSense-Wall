#!/bin/sh
set -eu

BASE="${SOCX_OBSERVABILITY_DIR:-/opt/socx-observability}"
INFLUX_DB="${SOCX_INFLUX_DB:-pfsense}"
GRAFANA_ADMIN_USER="${SOCX_GRAFANA_ADMIN_USER:-admin}"
GRAFANA_ADMIN_PASSWORD="${SOCX_GRAFANA_ADMIN_PASSWORD:-}"

if [ "$(id -u)" -ne 0 ]; then
    exec sudo env \
        SOCX_OBSERVABILITY_DIR="$BASE" \
        SOCX_INFLUX_DB="$INFLUX_DB" \
        SOCX_GRAFANA_ADMIN_USER="$GRAFANA_ADMIN_USER" \
        SOCX_GRAFANA_ADMIN_PASSWORD="$GRAFANA_ADMIN_PASSWORD" \
        sh "$0" "$@"
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required before installing SOCX observability." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose plugin is required before installing SOCX observability." >&2
    exit 1
fi

if [ -z "$GRAFANA_ADMIN_PASSWORD" ]; then
    GRAFANA_ADMIN_PASSWORD="$(openssl rand -base64 18 | tr -d '=+/ ' | cut -c1-20)"
fi

install -d -m 0755 "$BASE"
install -d -m 0755 "$BASE/grafana/provisioning/datasources"
install -d -m 0755 "$BASE/grafana/provisioning/dashboards"
install -d -m 0755 "$BASE/grafana/provisioning/alerting"
install -d -m 0755 "$BASE/grafana/dashboards"
install -d -m 0755 "$BASE/influxdb"
install -d -m 0750 "$BASE/backups"

cat > "$BASE/.env" <<EOF
INFLUX_DB=$INFLUX_DB
GRAFANA_ADMIN_USER=$GRAFANA_ADMIN_USER
GRAFANA_ADMIN_PASSWORD=$GRAFANA_ADMIN_PASSWORD
EOF
chmod 0600 "$BASE/.env"

cat > "$BASE/docker-compose.yml" <<'EOF'
services:
  influxdb:
    image: influxdb:1.8
    container_name: socx-influxdb
    restart: unless-stopped
    ports:
      - "8086:8086"
    environment:
      INFLUXDB_DB: ${INFLUX_DB}
      INFLUXDB_HTTP_FLUX_ENABLED: "false"
      INFLUXDB_REPORTING_DISABLED: "true"
    volumes:
      - ./influxdb:/var/lib/influxdb

  grafana:
    image: grafana/grafana-oss:latest
    container_name: socx-grafana
    restart: unless-stopped
    ports:
      - "3000:3000"
    environment:
      GF_SECURITY_ADMIN_USER: ${GRAFANA_ADMIN_USER}
      GF_SECURITY_ADMIN_PASSWORD: ${GRAFANA_ADMIN_PASSWORD}
      GF_AUTH_ANONYMOUS_ENABLED: "false"
      GF_USERS_ALLOW_SIGN_UP: "false"
      GF_SERVER_ROOT_URL: "http://%(domain)s:3000/"
    volumes:
      - grafana-data:/var/lib/grafana
      - ./grafana/provisioning:/etc/grafana/provisioning:ro
      - ./grafana/dashboards:/var/lib/grafana/dashboards:ro
    depends_on:
      - influxdb

volumes:
  grafana-data:
EOF

cat > "$BASE/grafana/provisioning/datasources/socx-influxdb.yml" <<EOF
apiVersion: 1

datasources:
  - name: SOCX pfSense InfluxDB
    uid: socx-pfsense-influxdb
    type: influxdb
    access: proxy
    url: http://influxdb:8086
    database: $INFLUX_DB
    isDefault: true
    editable: true
    jsonData:
      httpMode: GET
EOF

cat > "$BASE/grafana/provisioning/dashboards/socx-dashboards.yml" <<'EOF'
apiVersion: 1

providers:
  - name: SOCX
    orgId: 1
    folder: SOCX
    type: file
    disableDeletion: false
    editable: true
    options:
      path: /var/lib/grafana/dashboards
EOF

cat > "$BASE/grafana/provisioning/alerting/socx-alerts.yml" <<'EOF'
apiVersion: 1

groups:
  - orgId: 1
    name: SOCX pfSense watch
    folder: SOCX
    interval: 1m
    rules:
      - uid: socx_wan_ping_watch
        title: SOCX WAN ping watch
        condition: C
        data:
          - refId: A
            relativeTimeRange:
              from: 600
              to: 0
            datasourceUid: socx-pfsense-influxdb
            model:
              query: SELECT mean("average_response_ms") FROM "ping" WHERE $timeFilter
              rawQuery: true
              resultFormat: time_series
          - refId: B
            datasourceUid: __expr__
            model:
              type: reduce
              expression: A
              reducer: mean
              settings:
                mode: dropNN
          - refId: C
            datasourceUid: __expr__
            model:
              type: threshold
              expression: B
              conditions:
                - evaluator:
                    type: gt
                    params: [80]
                  operator:
                    type: and
                  query:
                    params: [C]
                  reducer:
                    type: last
                  type: query
        noDataState: NoData
        execErrState: Error
        for: 5m
        annotations:
          summary: WAN ping has averaged above 80 ms for five minutes.
          description: SOCX sees gateway or internet latency pressure. Compare WAN quality, VPN path, and Speedtest truth before changing rules.
        labels:
          socx: metrics
          severity: warn
      - uid: socx_memory_watch
        title: SOCX memory pressure watch
        condition: C
        data:
          - refId: A
            relativeTimeRange:
              from: 600
              to: 0
            datasourceUid: socx-pfsense-influxdb
            model:
              query: SELECT mean("used_percent") FROM "mem" WHERE $timeFilter
              rawQuery: true
              resultFormat: time_series
          - refId: B
            datasourceUid: __expr__
            model:
              type: reduce
              expression: A
              reducer: mean
              settings:
                mode: dropNN
          - refId: C
            datasourceUid: __expr__
            model:
              type: threshold
              expression: B
              conditions:
                - evaluator:
                    type: gt
                    params: [90]
                  operator:
                    type: and
                  query:
                    params: [C]
                  reducer:
                    type: last
                  type: query
        noDataState: NoData
        execErrState: Error
        for: 10m
        annotations:
          summary: pfSense memory has averaged above 90 percent for ten minutes.
          description: Check ARC, package load, swap use, and sustained process growth before upgrading hardware.
        labels:
          socx: metrics
          severity: warn
      - uid: socx_pf_state_watch
        title: SOCX PF state pressure watch
        condition: C
        data:
          - refId: A
            relativeTimeRange:
              from: 600
              to: 0
            datasourceUid: socx-pfsense-influxdb
            model:
              query: SELECT mean("entries") FROM "pf" WHERE $timeFilter
              rawQuery: true
              resultFormat: time_series
          - refId: B
            datasourceUid: __expr__
            model:
              type: reduce
              expression: A
              reducer: mean
              settings:
                mode: dropNN
          - refId: C
            datasourceUid: __expr__
            model:
              type: threshold
              expression: B
              conditions:
                - evaluator:
                    type: gt
                    params: [50000]
                  operator:
                    type: and
                  query:
                    params: [C]
                  reducer:
                    type: last
                  type: query
        noDataState: NoData
        execErrState: Error
        for: 5m
        annotations:
          summary: PF state table pressure is elevated.
          description: Review SOCX Live Flows, Packet Story, and recent scan aggregation for sustained state growth.
        labels:
          socx: metrics
          severity: warn
EOF

cat > "$BASE/backup_socx_observability.sh" <<'EOF'
#!/bin/sh
set -eu

BASE="${SOCX_OBSERVABILITY_DIR:-/opt/socx-observability}"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$BASE/backups/socx-observability-$STAMP"
ARCHIVE="$OUT.tgz"
RETENTION_DAYS="${SOCX_OBSERVABILITY_BACKUP_RETENTION_DAYS:-14}"

install -d -m 0750 "$BASE/backups" "$OUT"

cp -p "$BASE/docker-compose.yml" "$OUT/docker-compose.yml" 2>/dev/null || true
cp -p "$BASE/.env" "$OUT/env.redacted" 2>/dev/null || true
sed -i 's/^\(GRAFANA_ADMIN_PASSWORD=\).*/\1REDACTED/' "$OUT/env.redacted" 2>/dev/null || true

if [ -d "$BASE/grafana/provisioning" ]; then
    tar -C "$BASE" -cf "$OUT/grafana-provisioning.tar" grafana/provisioning 2>/dev/null || true
fi
if [ -d "$BASE/grafana/dashboards" ]; then
    tar -C "$BASE" -cf "$OUT/grafana-dashboards.tar" grafana/dashboards 2>/dev/null || true
fi

if docker ps --format '{{.Names}}' | grep -qx socx-influxdb; then
    docker exec socx-influxdb influxd backup -portable /tmp/socx-influx-backup >/dev/null 2>&1 || true
    docker cp socx-influxdb:/tmp/socx-influx-backup "$OUT/influxdb-backup" >/dev/null 2>&1 || true
    docker exec socx-influxdb rm -rf /tmp/socx-influx-backup >/dev/null 2>&1 || true
fi

tar -C "$BASE/backups" -czf "$ARCHIVE" "$(basename "$OUT")"
rm -rf "$OUT"
find "$BASE/backups" -type f -name 'socx-observability-*.tgz' -mtime +"$RETENTION_DAYS" -delete

echo "$ARCHIVE"
EOF
chmod 0755 "$BASE/backup_socx_observability.sh"

cat > /etc/cron.d/socx-observability-backup <<EOF
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
35 3 * * * root SOCX_OBSERVABILITY_DIR="$BASE" "$BASE/backup_socx_observability.sh" >/var/log/socx-observability-backup.log 2>&1
EOF

cat > "$BASE/grafana/dashboards/socx-pfsense-overview.json" <<'EOF'
{
  "annotations": {"list": []},
  "editable": true,
  "fiscalYearStartMonth": 0,
  "graphTooltip": 0,
  "id": null,
  "links": [],
  "liveNow": false,
  "panels": [
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "thresholds"}, "thresholds": {"mode": "absolute", "steps": [{"color": "green", "value": null}, {"color": "yellow", "value": 70}, {"color": "red", "value": 90}]}}, "overrides": []},
      "gridPos": {"h": 8, "w": 6, "x": 0, "y": 0},
      "id": 1,
      "options": {"colorMode": "value", "graphMode": "area", "justifyMode": "auto", "orientation": "auto", "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": false}, "textMode": "auto", "wideLayout": true},
      "pluginVersion": "11.0.0",
      "targets": [{"query": "SELECT mean(\"usage_system\") + mean(\"usage_user\") FROM \"cpu\" WHERE $timeFilter GROUP BY time($__interval) fill(null)", "refId": "A"}],
      "title": "CPU Busy %",
      "type": "stat"
    },
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "palette-classic"}, "unit": "bytes"}, "overrides": []},
      "gridPos": {"h": 8, "w": 9, "x": 6, "y": 0},
      "id": 2,
      "options": {"legend": {"displayMode": "list", "placement": "bottom", "showLegend": true}, "tooltip": {"mode": "single", "sort": "none"}},
      "targets": [
        {"query": "SELECT non_negative_derivative(mean(\"bytes_recv\"), 1s) FROM \"net\" WHERE $timeFilter GROUP BY time($__interval), \"interface\" fill(null)", "refId": "A"},
        {"query": "SELECT non_negative_derivative(mean(\"bytes_sent\"), 1s) FROM \"net\" WHERE $timeFilter GROUP BY time($__interval), \"interface\" fill(null)", "refId": "B"}
      ],
      "title": "Interface Traffic",
      "type": "timeseries"
    },
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "thresholds"}, "thresholds": {"mode": "absolute", "steps": [{"color": "green", "value": null}, {"color": "yellow", "value": 70}, {"color": "red", "value": 90}]}, "unit": "percent"}, "overrides": []},
      "gridPos": {"h": 8, "w": 5, "x": 15, "y": 0},
      "id": 3,
      "options": {"colorMode": "value", "graphMode": "area", "justifyMode": "auto", "orientation": "auto", "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": false}, "textMode": "auto", "wideLayout": true},
      "targets": [{"query": "SELECT mean(\"used_percent\") FROM \"mem\" WHERE $timeFilter GROUP BY time($__interval) fill(null)", "refId": "A"}],
      "title": "Memory Used %",
      "type": "stat"
    },
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "palette-classic"}}, "overrides": []},
      "gridPos": {"h": 8, "w": 4, "x": 20, "y": 0},
      "id": 4,
      "options": {"colorMode": "value", "graphMode": "area", "justifyMode": "auto", "orientation": "auto", "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": false}, "textMode": "auto", "wideLayout": true},
      "targets": [{"query": "SELECT mean(\"load1\") FROM \"system\" WHERE $timeFilter GROUP BY time($__interval) fill(null)", "refId": "A"}],
      "title": "Load 1m",
      "type": "stat"
    },
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "palette-classic"}}, "overrides": []},
      "gridPos": {"h": 9, "w": 24, "x": 0, "y": 8},
      "id": 5,
      "options": {"legend": {"displayMode": "list", "placement": "bottom", "showLegend": true}, "tooltip": {"mode": "multi", "sort": "none"}},
      "targets": [
        {"query": "SELECT mean(\"entries\") FROM \"pf\" WHERE $timeFilter GROUP BY time($__interval) fill(null)", "refId": "A"},
        {"query": "SELECT mean(\"searches\") FROM \"pf\" WHERE $timeFilter GROUP BY time($__interval) fill(null)", "refId": "B"}
      ],
      "title": "PF State Table",
      "type": "timeseries"
    },
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "palette-classic"}, "unit": "ms"}, "overrides": []},
      "gridPos": {"h": 8, "w": 8, "x": 0, "y": 17},
      "id": 6,
      "options": {"legend": {"displayMode": "list", "placement": "bottom", "showLegend": true}, "tooltip": {"mode": "multi", "sort": "none"}},
      "targets": [{"query": "SELECT mean(\"average_response_ms\") FROM \"ping\" WHERE $timeFilter GROUP BY time($__interval), \"url\" fill(null)", "refId": "A"}],
      "title": "Gateway / Internet Ping",
      "type": "timeseries"
    },
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "thresholds"}, "thresholds": {"mode": "absolute", "steps": [{"color": "green", "value": null}, {"color": "yellow", "value": 70}, {"color": "red", "value": 90}]}, "unit": "percent"}, "overrides": []},
      "gridPos": {"h": 8, "w": 4, "x": 8, "y": 17},
      "id": 7,
      "options": {"colorMode": "value", "graphMode": "area", "justifyMode": "auto", "orientation": "auto", "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": false}, "textMode": "auto", "wideLayout": true},
      "targets": [{"query": "SELECT max(\"used_percent\") FROM \"disk\" WHERE $timeFilter GROUP BY time($__interval) fill(null)", "refId": "A"}],
      "title": "Disk Used %",
      "type": "stat"
    },
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "thresholds"}, "thresholds": {"mode": "absolute", "steps": [{"color": "green", "value": null}, {"color": "yellow", "value": 1}, {"color": "red", "value": 25}]}, "unit": "percent"}, "overrides": []},
      "gridPos": {"h": 8, "w": 4, "x": 12, "y": 17},
      "id": 8,
      "options": {"colorMode": "value", "graphMode": "area", "justifyMode": "auto", "orientation": "auto", "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": false}, "textMode": "auto", "wideLayout": true},
      "targets": [{"query": "SELECT mean(\"used_percent\") FROM \"swap\" WHERE $timeFilter GROUP BY time($__interval) fill(null)", "refId": "A"}],
      "title": "Swap Used %",
      "type": "stat"
    },
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "palette-classic"}}, "overrides": []},
      "gridPos": {"h": 8, "w": 4, "x": 16, "y": 17},
      "id": 9,
      "options": {"colorMode": "value", "graphMode": "area", "justifyMode": "auto", "orientation": "auto", "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": false}, "textMode": "auto", "wideLayout": true},
      "targets": [{"query": "SELECT mean(\"total\") FROM \"processes\" WHERE $timeFilter GROUP BY time($__interval) fill(null)", "refId": "A"}],
      "title": "Processes",
      "type": "stat"
    },
    {
      "datasource": {"type": "influxdb", "uid": "socx-pfsense-influxdb"},
      "fieldConfig": {"defaults": {"color": {"mode": "palette-classic"}}, "overrides": []},
      "gridPos": {"h": 8, "w": 4, "x": 20, "y": 17},
      "id": 10,
      "options": {"colorMode": "value", "graphMode": "area", "justifyMode": "auto", "orientation": "auto", "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": false}, "textMode": "auto", "wideLayout": true},
      "targets": [{"query": "SELECT non_negative_derivative(mean(\"packets_recv\"), 1s) + non_negative_derivative(mean(\"packets_sent\"), 1s) FROM \"net\" WHERE $timeFilter GROUP BY time($__interval) fill(null)", "refId": "A"}],
      "title": "Packets / sec",
      "type": "stat"
    }
  ],
  "refresh": "10s",
  "schemaVersion": 39,
  "tags": ["socx", "pfsense"],
  "templating": {"list": []},
  "time": {"from": "now-6h", "to": "now"},
  "timepicker": {},
  "timezone": "browser",
  "title": "SOCX pfSense Overview",
  "uid": "socx-pfsense-overview",
  "version": 1,
  "weekStart": ""
}
EOF

cat > "$BASE/README.txt" <<EOF
SOCX observability stack

Grafana:  http://$(hostname -I | awk '{print $1}'):3000/
InfluxDB: http://$(hostname -I | awk '{print $1}'):8086/
Database: $INFLUX_DB
Login:    $GRAFANA_ADMIN_USER
Password: $GRAFANA_ADMIN_PASSWORD

pfSense Telegraf target:
  http://$(hostname -I | awk '{print $1}'):8086
EOF
chmod 0600 "$BASE/README.txt"

cd "$BASE"
docker compose up -d
sleep 5

echo "SOCX observability installed."
cat "$BASE/README.txt"
