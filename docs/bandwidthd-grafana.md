# BandwidthD Daily Host History

SOCX exports the generated BandwidthD daily table to the existing InfluxDB target every five minutes. The values are daily cumulative totals, not live line rate; use the SOCX Network card or NetFlow views for real-time traffic.

## Operator Commands

```sh
socx bandwidthd-metrics
socx bandwidthd-metrics-cron install
socx bandwidthd-metrics-cron status
```

## Grafana

Import `grafana/socx-bandwidthd-daily.json`, select the existing InfluxDB datasource, and keep the dashboard read-only for operators. The dashboard shows the latest daily total, sent, and received values grouped by LAN host.

The exporter sends only private LAN IP address totals to the local Pi-hosted InfluxDB instance. It does not export packet payloads, domains, credentials, or firewall policy.
