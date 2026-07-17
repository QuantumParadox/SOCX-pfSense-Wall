# SOCX Architecture

```mermaid
flowchart LR
    pf["pfSense signals\npfctl, filter.log, DNSBL, Suricata, gateways"]
    ups["UPS / sensors\nNUT APC cache"]
    spd["Speedtest paths\nDIRECT + VPN profiles"]
    ai["AI layer\nPi 3-LLM + MIRANDA"]
    hist["History\n/var/db/socx_history.jsonl"]
    auto["SOCX Autopilot\nnetwork/security/AI/sensor scoring"]
    wall["Terminal Wall\nsoc / tmux / modern neon"]
    web["Web Wall\nsocweb /api/state /api/command-center"]
    ops["Operator commands\nwhy-now, timeline, repair, notify"]

    pf --> auto
    ups --> auto
    spd --> auto
    ai --> auto
    auto --> hist
    auto --> wall
    auto --> web
    hist --> web
    auto --> ops
```

SOCX is intentionally read-only by default. It watches pfSense and related lab services, explains the current state, preserves evidence on demand, and restarts its own collectors when asked. It does not create firewall rules or block traffic automatically.

## Autonomy Loop

1. Collect live pfSense, Speedtest, UPS, DNSBL, IDS, VPN, Pi AI, and MIRANDA signals.
2. Score the environment into network, security, AI, and sensor health.
3. Write `/tmp/socx-autopilot.env`.
4. Append a compact trend sample to `/var/db/socx_history.jsonl`.
5. Render the result in the terminal wall, browser Command Center, and `socx why-now`.
6. Optionally evaluate `socx notify rules` if a webhook is configured.
