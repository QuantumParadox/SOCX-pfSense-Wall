# SOCX live wall terminal capture

Captured from a running pfSense SOCX tmux wall. ANSI color is stripped by tmux capture-pane; the live wall uses neon ANSI colors.

```text
SOCX | [20:08] | UP 32d 1h 17m | WAN:UP | VPN:UP 3/3 | DNS:OK | UPS:100% 36m | SPD:872↓/905↑ 19ms next 5h15
────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
┌ NETWORK ───────────────────┐  ┌ SPEEDTEST ─────────────┐  ┌ CPU ─────────────────────────────┐  ┌ MEMORY ────────────────────┐  ┌ UPS ───────────────────────┐
│WAN ↓ 14.2K  ↑ 8.69K ↑      │  │DOWN 872M ↓             │  │ 38% →  3.6GHz   66°C             │  │RAM 27.2G/31.7G             │  │           603 W            │
│LAN ↓ 8.02K  ↑ 12.9K        │  │UP   905M ↑             │  │C0 ██▇█▁▁▁▁ 46%  C1 ███▃▁▁▁▁ 36%  │  │86% ███████████████████▁▁▁ →│  │load 31% batt 100% run 36m  │
│W D █████████ U ██████░░░   │  │PING 19ms J0ms stable   │  │C2 ███▁▃▁▁▁ 37%  C3 ███▁▁▃▁▁ 34%  │  │ARC 19.0G  free 4.47G       │  │out 120.1V 5.2A  in 120.1V  │
│TOP E15.230>E163.208 vpn    │  │University of Rochest...│  │LOAD 1/5/15m 85/84/81%            │  │SWAP 0B/1.00G 0%            │  │T 79F/26C                   │
└────────────────────────────┘  └────────────────────────┘  └──────────────────────────────────┘  └────────────────────────────┘  └────────────────────────────┘
┌ PFTOP LIVE STATES ───────────────────────────────────────────────────────────┐ ┌ NETWORK FLOWS / IFTOPX ─────────────────────────────────────────────────────┐
│DIR PRO APP  STAT TRAFFIC  AGE     LEFT    PATH                               │ │#  PATH                                              TRAFFIC    APP   TYPE   │
│OUT UDP vpn  MULT 6.26K/s  4d23h   1m00    EXT.15.230 -> EXT.163.208          │ │01 EXT.15.230 -> EXT.163.208                         2K/4K      vpn   state  │
│IN  TCP tls  EST  2.31K/s  20m52   1d0h    LAN.116 -> EXT.32.47               │ │02 LAN.116 -> EXT.32.47                              436/1K     tls   net    │
│VPN TCP tls  EST  2.31K/s  20m52   1d0h    LAN.116 -> EXT.32.47               │ │03 LAN.116 -> EXT.32.47                              436/1K     tls   vpn    │
│IN  TCP nut  EST  1.79K/s  5h37    1d0h    LAN.146 -> LAN.1                   │ │04 LAN.146 -> LAN.1                                  797/1K     nut   state  │
│OUT ICM ping 0/0  856B/s   1m00    0m10    EXT.15.230 -> EXT.1.1              │ │05 EXT.15.230 -> EXT.1.1                             428/428    ping  state  │
│IN  TCP tls  EST  607B/s   4m11    1d0h    LAN.116 -> EXT.134.234             │ │06 LAN.116 -> EXT.134.234                            87/520     tls   net    │
│VPN TCP tls  EST  607B/s   4m11    1d0h    LAN.116 -> EXT.134.234             │ │07 LAN.116 -> EXT.134.234                            87/520     tls   vpn    │
│OUT UDP snmp SING 414B/s   0m00    0m30    LAN.1 -> LAN.114                   │ │08 LAN.1 -> LAN.114                                  192/222    snmp  state  │
│OUT UDP ssdp SING 286B/s   26d8h   0m30    LAN.1 -> LAN.170                   │ │09 LAN.1 -> LAN.170                                  286/0      ssdp  state  │
│IN  TCP app  EST  245B/s   4d5h    1d0h    LAN.103 -> EXT.232.245             │ │10 LAN.103 -> EXT.232.245                            137/108    app   state  │
│VPN TCP app  EST  245B/s   4d5h    1d0h    LAN.103 -> EXT.232.245             │ │11 LAN.103 -> EXT.232.245                            137/108    app   vpn    │
│IN  UDP ssdp SING 94B/s    26d8h   0m30    LAN.170 -> EXT.255.250             │ │12 LAN.170 -> EXT.255.250                            94/0       ssdp  state  │
│OUT ICM ping 0/0  84B/s    5h37    0m10    EXT.15.230 -> EXT.12.1             │ │13 EXT.15.230 -> EXT.12.1                            42/42      ping  state  │
│IN  TCP p540 CLOS 44B/s    0m02    0m30    LAN.146 -> EXT.0.1                 │ │14 LAN.146 -> EXT.0.1                                44/0       p540  state  │
│VPN TCP p540 CLOS 44B/s    0m02    0m30    LAN.146 -> EXT.0.1                 │ │15 LAN.146 -> EXT.0.1                                44/0       p540  vpn    │
│IN  TCP p540 CLOS 44B/s    0m02    0m30    LAN.146 -> EXT.0.1                 │ │16 LAN.146 -> EXT.0.1                                44/0       p540  state  │
└──────────────────────────────────────────────────────────────────────────────┘ └─────────────────────────────────────────────────────────────────────────────┘
┌ PACKET RADAR ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┐
│TIME     EVENT          WHAT HAPPENED                                                                                                                         │
│20:08:49 RADAR          VPN tunnel burst WAN -> 67.103.163.208 11 packets 944B                                                                                │
│20:08:49 RADAR          VPN tunnel burst 67.103.163.208 -> WAN 13 packets 1.80K                                                                               │
│20:08:49 FIREWALL BLOCK 22 WAN scans stopped in 60s | ports: 443, 23, 8080                                                                                    │
│20:08:38 FIREWALL BLOCK WAN scan blocked EXT.43.243 p443 [abuse high]                                                                                         │
│20:08:33 FIREWALL BLOCK WAN scan blocked EXT.61.64 p23 [abuse high]                                                                                           │
│20:07:51 FIREWALL BLOCK WAN scan blocked EXT.43.243 p8080 [abuse high]                                                                                        │
│20:08:48 DNS BLOCK      LAN.127 DNS blocked z.moatads.com [known bad]                                                                                         │
│20:08:48 DNS BLOCK      LAN.116 DNS blocked www.clarity.ms [known bad]                                                                                        │
└──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┘
┌ EVENT FEED [ROTATE] ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┐
│[FW][HIGH] 22 WAN scans stopped in 60s | ports: 443, 23, 8080 x2                                                                                              │
│next ◆ [FW][HIGH] 23 WAN scans stopped in 60s | ports: 443, 23, 8080 x8                                                                                       │
└──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┘
```
