<?php
declare(strict_types=1);

require_once("config.inc");
require_once("/usr/local/pkg/servicewatchdog.inc");

$items = config_get_path("installedpackages/servicewatchdog/item", []);
$remaining = [];
$removed = 0;

foreach ($items as $item) {
    if (($item["name"] ?? "") === "socx") {
        $removed++;
        continue;
    }
    $remaining[] = $item;
}

if ($removed === 0) {
    echo "SOCX Service Watchdog entry not present; no change made.\n";
    exit(0);
}

$backup = "/cf/conf/backup/socx-before-watchdog-fix-" . date("Ymd-His") . ".xml";
if (!@copy("/cf/conf/config.xml", $backup)) {
    fwrite(STDERR, "Could not create pfSense configuration backup: {$backup}\n");
    exit(1);
}

config_set_path("installedpackages/servicewatchdog/item", $remaining);
write_config("[SOCX] Removed false Service Watchdog entry for SOCX. SOCX retains its own service and endpoint health checks.");
servicewatchdog_cron_job();

echo "Removed {$removed} false SOCX Service Watchdog entry.\n";
echo "Configuration backup: {$backup}\n";
echo "Remaining watched services: " . count($remaining) . "\n";
