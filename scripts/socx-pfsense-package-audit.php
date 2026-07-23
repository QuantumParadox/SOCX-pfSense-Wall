<?php
/* Read-only audit for pfSense package configuration. */
require_once("config.inc");

echo "INTERFACES\n";
foreach (get_configured_interface_with_descr() as $interface => $description) {
    printf("%s | %s | %s\n", $interface, $description, get_interface_ip($interface));
}

echo "BANDWIDTHD_CONFIG\n";
var_export(config_get_path("installedpackages/bandwidthd/config/0", []));
echo "\n";
