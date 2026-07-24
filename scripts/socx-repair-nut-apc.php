<?php
declare(strict_types=1);

require_once("config.inc");
require_once("/usr/local/pkg/nut/nut.inc");

const SOCX_APC_NAME = "APCSmartUPS2200SMT";
const SOCX_APC_ADDRESS = "192.168.1.114";

$nut = config_get_path("installedpackages/nut/config/0", []);
if (!is_array($nut)) {
    fwrite(STDERR, "NUT configuration is invalid.\n");
    exit(1);
}

$backup = "/cf/conf/backup/socx-before-nut-apc-repair-" . date("Ymd-His") . ".xml";
if (!@copy("/cf/conf/config.xml", $backup)) {
    fwrite(STDERR, "Could not create pfSense configuration backup: {$backup}\n");
    exit(1);
}

$nut["type"] = "remote_snmp";
$nut["name"] = SOCX_APC_NAME;
$nut["remote_addr"] = SOCX_APC_ADDRESS;
$nut["ups_conf"] = "";
$nut["extra_args"] = base64_encode("mibs = apcc\ncommunity = public");

config_set_path("installedpackages/nut/config/0", $nut);
write_config("[SOCX] Repaired NUT APC SNMP configuration with one read-only Smart-UPS definition.");
nut_sync_config();

echo "NUT APC configuration repaired.\n";
echo "Configuration backup: {$backup}\n";
echo "UPS: " . SOCX_APC_NAME . " @ " . SOCX_APC_ADDRESS . "\n";
