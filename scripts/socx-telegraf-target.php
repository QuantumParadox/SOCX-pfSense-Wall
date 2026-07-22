<?php
/*
 * SOCX helper to point pfSense Telegraf at an external InfluxDB target.
 * Run on pfSense as root/admin:
 *   php socx-telegraf-target.php http://192.168.1.180:8086 pfsense
 */

require_once("config.inc");
require_once("util.inc");
require_once("/usr/local/pkg/telegraf.inc");

$target = $argv[1] ?? "http://192.168.1.180:8086";
$database = $argv[2] ?? "pfsense";

if (!preg_match('/^https?:\/\/[A-Za-z0-9._:-]+\/?$/', $target)) {
    fwrite(STDERR, "Invalid InfluxDB URL: {$target}\n");
    exit(2);
}

$target = rtrim($target, "/");
$backup = "/conf/config.xml.socx-telegraf-" . date("Ymd-His");
if (!copy("/conf/config.xml", $backup)) {
    fwrite(STDERR, "Could not write config backup: {$backup}\n");
    exit(1);
}

$path = "installedpackages/telegraf/config/0";
$cfg = config_get_path($path, []);
if (!is_array($cfg)) {
    $cfg = [];
}

$cfg["enable"] = "on";
$cfg["telegraf_output"] = "influxdb";
$cfg["influx_server"] = $target;
$cfg["influx_db"] = $database;
$cfg["insecure_skip_verify"] = "on";
if (empty($cfg["interval"])) {
    $cfg["interval"] = "10";
}
if (empty($cfg["shortname"])) {
    $cfg["shortname"] = "on";
}

config_set_path($path, $cfg);
write_config("SOCX: point Telegraf at external InfluxDB {$target}/{$database}");
telegraf_resync_config();

echo "SOCX Telegraf target updated\n";
echo "Backup: {$backup}\n";
echo "InfluxDB: {$target}\n";
echo "Database: {$database}\n";
?>
