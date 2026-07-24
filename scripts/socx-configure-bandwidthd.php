<?php
/* Configure pfSense BandwidthD as a low-overhead LAN host-usage view. */
require_once("globals.inc");
require_once("config.inc");
require_once("service-utils.inc");
require_once("/usr/local/pkg/bandwidthd.inc");

if (!function_exists("write_rcfile") || !function_exists("bandwidthd_install_config")) {
    fwrite(STDERR, "BandwidthD service API is unavailable; no configuration changes were made.\n");
    exit(1);
}

$backup = "/cf/conf/backup/socx-config-before-bandwidthd-" . date("Ymd-His") . ".xml";
if (!@copy("/cf/conf/config.xml", $backup)) {
    fwrite(STDERR, "Unable to create configuration backup: {$backup}\n");
    exit(1);
}

$settings = [
    "enable" => "on",
    "active_interface" => "lan",
    "interface_array" => ["lan"],
    "sensorid" => "JupiterLXI-pfSense",
    "drawgraphs" => "on",
    "meta_refresh" => "300",
    "skipintervals" => "1",
    "graphcutoff" => "2048",
];

config_set_path("installedpackages/bandwidthd/config/0", $settings);
write_config("[SOCX] Configured BandwidthD for passive LAN host-usage monitoring.");
bandwidthd_install_config();

echo "backup={$backup}\n";
echo "active_interface=lan\n";
echo "subnets=LAN only\n";
echo "graphs=enabled, interval=400 seconds, refresh=300 seconds\n";
echo "cdf_archive=disabled\n";
