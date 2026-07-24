# pfSense and SOCX Recovery Card

## Before Any Maintenance

1. Capture a named SOCX config backup: `socx drift backup`.
2. Confirm the web wall and terminal wall: `socx v1-check`.
3. Record the current release and package state from **System > Update**.
4. Do not apply an update while a repository check is failing.

## First Checks After a Problem

1. Connect locally or by SSH and run `socx resilience`.
2. Verify the browser wall at `http://192.168.1.1:8094/`.
3. Check managed services: `service socx status`, `service socxweb status`, and `service socxprobes status`.
4. Check UPS telemetry: `upsc APCSmartUPS2200SMT@localhost`.
5. Check flow export: `socx flow-export status`.

## SOCX Service Recovery

Use only when a status check shows a service is down:

```sh
service socx restart
service socxweb restart
service socxprobes restart
socx v1-check
```

## Configuration Recovery

1. Use **Diagnostics > Backup & Restore** from the pfSense GUI.
2. Prefer the newest known-good file in `/root/socx-config-vault/` or `/cf/conf/backup/`.
3. Restore on spare hardware or a disposable copy first when practical.
4. Reboot only after the restore is selected and reviewed.
5. After recovery, run `socx resilience` and `socx detection-validate`.

## Package Repository Failure

1. Preserve the current configuration first.
2. Use only the pfSense GUI or Netgate-documented repair commands.
3. Never add FreeBSD or third-party package repositories to pfSense.
4. If the official pfSense Plus package catalog returns HTTP 400, stop package updates and open a Netgate support case with the release, repository URL, and error timestamp.

## Safety Boundary

SOCX may observe, explain, make drafts, preserve evidence, and run read-only validation. Firewall rules, DNSBL policy, VLANs, routing, and UPS shutdown behavior remain operator-approved changes.
