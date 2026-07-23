<?php
/* Configure pfSense Darkstat as a LAN-only, read-only traffic summary. */
require_once("config.inc");
require_once("service-utils.inc");
require_once("/usr/local/pkg/darkstat.inc");

$backup = "/cf/conf/backup/socx-config-before-darkstat-" . date("Ymd-His") . ".xml";
if (!@copy("/cf/conf/config.xml", $backup)) {
    fwrite(STDERR, "Unable to create configuration backup: {$backup}\n");
    exit(1);
}

$settings = [
    "enable" => "on",
    "capture_interfaces" => "lan",
    "bind_interfaces" => "lan",
    "port" => "666",
    "host" => "192.168.1.1",
    "localnetworkenable" => "on",
    "localnetworkonly" => "on",
    "localnetwork" => "lan",
    "nodns" => "on",
    "hostsmax" => "256",
    "hostskeep" => "128",
    "portsmax" => "256",
    "portskeep" => "128",
];

config_set_path("installedpackages/darkstat/config/0", $settings);
write_config("[SOCX] Configured Darkstat for LAN-only traffic monitoring.");
sync_package_darkstat();

echo "backup={$backup}\n";
echo "capture=LAN only\n";
echo "dashboard=http://192.168.1.1:666\n";
echo "name_resolution=disabled\n";
echo "host_limit=256, port_limit=256\n";
