# SOCX Long Project Backlog

This is the standing roadmap for SOCX, pfSense, the Pi 5 AI node, the Pi 4 observability node, and the browser/terminal wall. Keep production pfSense changes read-only or approval-gated until an operator confirms the exact action.

## 1. Near-Term Wall Polish

- Build a per-room readability calibration page for font size, ticker speed, glow, contrast, and card density.
- Add a wall photo checklist so screenshots and phone photos can be scored for clipping, blur, crowding, and color balance.
- Add a terminal/browser style parity pass so friendly names, severity colors, and Packet Radar wording match between both walls.
- Extend quiet-night scheduling with configurable operator hours, per-display profiles, and saved room presets.
- Add a manual "big incident" view that hides low-value cards and enlarges VPN, IDS, packet, flow, and AI verdict panels.

## 2. Packet And Flow Intelligence

- Build Packet Radar v2 with TCP flag summaries, direction, service, reason, and one-line human descriptions.
- Extend the Network top-talker strip into tiny directional download/upload bars once reliable per-flow byte deltas are available.
- Add long-lived connection detection for streaming, VPN, backup, cloud sync, and unusual idle sessions.
- Add protocol confidence labels: confirmed, inferred from port, inferred from DNS, or unknown.
- Add flow replay comparison: what changed in the last 15 minutes versus the previous 15 minutes.

## 3. IDS, DNSBL, And Threat Intel

- Add Suricata alert clustering by signature, source, destination, and affected internal host.
- Add DNSBL explainers that distinguish ads, telemetry, malware, phishing, CDN noise, and false-positive candidates.
- Add local reputation memory so repeated blocked sources get a stable reputation score without external lookups.
- Add MITRE ATT&CK/D3FEND drilldowns for each incident cluster with defensive next steps.
- Add CISA KEV watch cards for exposed services, package versions, and high-interest ports.

## 4. Safe AI Operator Console

- Add evidence tabs to the full SOCX Chat page: Summary, Evidence, Why, Plan, Commands, Risks, and Cockpit context.
- Stream LLM role reasoning as visible stages: triage, evidence, action, safety review, final answer.
- Expand "explain this row" everywhere: packets, flows, devices, IDS, DNSBL, Speedtest, VPN, UPS, replay bookmarks, and Threat Story evidence cards. Device Detail v1 and Incident Report v1 are now live as first focused drilldowns.
- Add approval cards for draft firewall aliases, DNSBL allowlist candidates, IDS threshold changes, and quarantine plans.
- Add a safety ledger showing which requests were answered, denied, or converted into draft-only plans.

## 5. Pi 5 AI HAT Lab

- Extend the Hailo role benchmark dashboard with exported history for latency, tokens/sec equivalent, thermal pressure, and model availability.
- Add model routing tests for firewall triage, DNSBL evidence, rule drafting, complex investigation, voice, and fallback CPU Ollama. Initial `bench`, `explain`, and `compare` launchers are now wired through pfSense and the browser Commander.
- Expand the current `COMPARE` lab into a full "model tournament" mode where the Pi compares small models on the same SOCX evidence bundle.
- Add a Pi thermal stress lab with fan/temperature/load timelines and safe abort limits.
- Add replay/export controls for the current slow visual "thinking radar" so role timelines can be preserved with incident bundles.

## 6. Pi 4 Observability Stack

- Finish Grafana/Influx dashboards for pfSense interface traffic, PF states, VPN gateways, UPS, thermal, and flow exporter status.
- Add retention and backup health cards for InfluxDB and Grafana.
- Add alert rules for WAN quality, VPN partial/down, memory pressure, high packet drops, IDS spikes, and UPS runtime.
- Add Grafana deep links from SOCX cards.
- Add Pi 4 versus Pi 5 role clarity: Pi 4 stores and graphs metrics; Pi 5 runs AI and SOC reasoning.

## 7. pfSense Reliability And Hygiene

- Add service health cards for vnstatd, lldpd, Suricata, pfBlockerNG, dpinger, Unbound, NUT, Telegraf, and softflowd.
- Add package crash parsing for PHP errors and recent package logs, with human-readable root-cause guesses.
- Add config-drift diff summaries with "expected", "new", and "needs review" groupings.
- Add backup health checks for config history, ZFS boot environments, and exported evidence bundles.
- Extend the read-only pfSense Doctor page with clickable repair-plan cards that remain approval-gated and never run automatically.

## 8. Network Asset Identity

- Add a friendly-name lab that learns from DHCP leases, ARP, DNS, mDNS, LLDP, NetBIOS, and local SOCX labels.
- Add per-device profiles: owner, role, common services, normal bandwidth, normal domains, and last-seen time. Device Detail v1 now exposes current identity, trust, apps/services, flow evidence, packet evidence, and safe questions per asset.
- Add "unknown reducer" mode that shows why a device/app is unknown and what evidence would fix it.
- Add Synology, Apple TV, consoles, phones, MIRANDA, Pi nodes, and lab machines as richer asset cards.
- Add device story pages with timeline, top peers, top domains, blocks, IDS hits, and learned-normal drift.

## 9. Speedtest And Path Truth

- Add DIRECT versus VPN historical comparison with automatic labels for Frontier, NYC VPN, Delaware RCN, Virginia RCN, and unknown path.
- Add scheduled tests with quiet-hour limits, bandwidth guardrails, and manual one-shot tests.
- Add confidence labels when router-side Speedtest differs from browser Speedtest.
- Add VPN crypto headroom estimates tied to CPU temperature, frequency, and gateway latency.
- Add outage timeline: WAN latency/loss, DNS, Speedtest result, VPN state, and user-visible symptoms.

## 10. Research-Lab Features

- Add mission-assurance scoring inspired by CISA, NIST CSF, MITRE ATT&CK, and D3FEND.
- Add evidence-preserving incident bundles that produce a readable mini-report and machine-readable JSON. Incident Report v1 now generates a browser-readable Markdown report from current SOCX threat-story evidence.
- Add Sigma/YARA/Suricata rule-draft assistants that generate draft rules from evidence, never live changes.
- Add CAPEC/CWE/CVE/CVSS/EPSS/KEV enrichment for relevant IDS or exposed-service findings.
- Add a patent/research notebook mode for SOCX ideas, experiment notes, validation results, and prior-art search terms.

## 11. Cyberpunk LCARS Experience

- Add a second-screen full-size version of the slow readable network twin animation using real nodes, links, packets, and AI role state.
- Add an LCARS-inspired mission page with segmented color bands, but keep the main wall btop-readable.
- Add ambient audio/voice readouts only as optional browser controls, muted by default.
- Add operator personas such as Security Watch, Network Engineer, Incident Commander, and Lab Scientist.
- Add a "bridge screen" mode for a second monitor with larger flow/twin animation and fewer text tables.

## 12. Good Stopping Points

- Stable wall milestone: no clipping, no ticker jumps, clean `socx v1-check`, and photographed monitor looks readable.
- Reliable observability milestone: Pi 4 Influx/Grafana stores pfSense metrics for at least 7 days with backups.
- AI lab milestone: Pi 5 roles return bounded, visible, explainable results without blocking SOCX refresh.
- Incident readiness milestone: one-click bundle, replay bookmarks, chat explanation, and draft-only recommendations all work.
- Public release milestone: README screenshots, install guide, safety model, backlog, and GitHub issues are ready for outside users.
