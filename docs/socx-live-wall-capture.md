# SOCX live wall terminal capture

Captured from a running pfSense SOCX tmux wall. ANSI color is stripped by tmux capture-pane; the live wall uses neon ANSI colors.

```text
SOCX | [20:22] | UP 32d 1h 30m | WAN:UP | VPN:UP 3/3 | DNS:OK | UPS:100% 36m | SPD:872↓/905↑ 19ms next 5h02
────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
┌ NETWORK ───────────────────┐  ┌ SPEEDTEST ─────────────┐  ┌ CPU ─────────────────────────────┐  ┌ MEMORY ────────────────────┐  ┌ UPS ───────────────────────┐
│WAN ↓ 5.83K  ↑ 4.54K ↑      │  │DOWN 872M ↓             │  │ 38% →  3.6GHz   66°C             │  │RAM 27.1G/31.7G             │  │           578 W            │
│LAN ↓ 4.74K  ↑ 7.00K        │  │UP   905M ↑             │  │C0 ▇███▁▁▁▁ 46%  C1 █▇█▁▁▁▁▁ 36%  │  │85% ███████████████████▁▁▁ →│  │load 29% batt 100% run 36m  │
│W D █████████ U ███████░░   │  │PING 19ms J0ms stable   │  │C2 ██▇▁▁▁▁▁ 37%  C3 ███▃▁▁▁▁ 34%  │  │ARC 19.0G  free 4.62G       │  │out 120.1V 5.2A  in 120.1V  │
│AI LAB 7/7 NVIDIA-Build,x...│  │University of Rochest...│  │LOAD 1/5/15m 57/73/78%            │  │SWAP 0B/1.00G 0%            │  │LOAD 578/1950W              │
└────────────────────────────┘  └────────────────────────┘  └──────────────────────────────────┘  └────────────────────────────┘  └────────────────────────────┘
┌ PFTOP LIVE STATES ───────────────────────────────────────────────────────────┐ ┌ NETWORK FLOWS / IFTOPX ─────────────────────────────────────────────────────┐
│DIR PRO APP  STAT TRAFFIC  AGE     LEFT    PATH                               │ │#  PATH                                              TRAFFIC    APP   TYPE   │
│OUT UDP vpn  MULT 45.1K/s  4d23h   1m00    EXT.15.230 -> EXT.163.208          │ │01 EXT.15.230 -> EXT.163.208                         12K/33K    vpn   state  │
│IN  TCP tls  EST  20.9K/s  34m09   1d0h    MIRANDA-W... -> EXT.32.47          │ │02 MIRANDA-W... -> EXT.32.47                         181/20K    tls   net    │
│VPN TCP tls  EST  20.9K/s  34m09   1d0h    MIRANDA-W... -> EXT.32.47          │ │03 MIRANDA-W... -> EXT.32.47                         181/20K    tls   vpn    │
│IN  UDP tls  MULT 14.4K/s  0m01    1m00    MIRANDA-W... -> EXT.217.142        │ │04 MIRANDA-W... -> EXT.217.142                       7K/7K      tls   net    │
│VPN UDP tls  MULT 14.4K/s  0m01    1m00    MIRANDA-W... -> EXT.217.142        │ │05 MIRANDA-W... -> EXT.217.142                       7K/7K      tls   vpn    │
│IN  TCP nut  EST  5.56K/s  5h50    1d0h    Device-146 -> pfSense-J...         │ │06 Device-146 -> pfSense-J...                        2K/3K      nut   state  │
│OUT ICM ping 0/0  1.92K/s  0m20    0m10    EXT.15.230 -> EXT.1.1              │ │07 EXT.15.230 -> EXT.1.1                             1K/917     ping  state  │
│IN  UDP tls  MULT 1.22K/s  0m17    1m00    MIRANDA-W... -> EXT.151.2          │ │08 MIRANDA-W... -> EXT.151.2                         489/759    tls   net    │
│VPN UDP tls  MULT 1.22K/s  0m17    1m00    MIRANDA-W... -> EXT.151.2          │ │09 MIRANDA-W... -> EXT.151.2                         489/759    tls   vpn    │
│OUT UDP sysl SING 457B/s   31d9h   0m29    pfSense-J... -> Device-180         │ │10 pfSense-J... -> Device-180                        457/0      sysl  state  │
│OUT UDP sysl SING 457B/s   21h49   0m29    pfSense-J... -> MIRANDA-W...       │ │11 pfSense-J... -> MIRANDA-W...                      457/0      sysl  state  │
│OUT UDP snmp SING 414B/s   0m01    0m29    pfSense-J... -> APC-SmartUPS       │ │12 pfSense-J... -> APC-SmartUPS                      192/222    snmp  state  │
│OUT UDP snmp SING 414B/s   0m00    0m30    pfSense-J... -> APC-SmartUPS       │ │13 pfSense-J... -> APC-SmartUPS                      192/222    snmp  state  │
│IN  UDP dns  MULT 238B/s   0m17    0m59    MIRANDA-W... -> pfSense-J...       │ │14 MIRANDA-W... -> pfSense-J...                      75/163     dns   net    │
│OUT UDP dns  SING 232B/s   0m01    0m29    EXT.15.230 -> EXT.1.1              │ │15 EXT.15.230 -> EXT.1.1                             77/155     dns   net    │
│IN  TCP tls  EST  229B/s   2h11    23h59   MIRANDA-W... -> EXT.221.104        │ │16 MIRANDA-W... -> EXT.221.104                       94/135     tls   net    │
└──────────────────────────────────────────────────────────────────────────────┘ └─────────────────────────────────────────────────────────────────────────────┘
┌ PACKET RADAR ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┐
│TIME     EVENT          WHAT HAPPENED                                                                                                                         │
│20:22:06 RADAR          VPN tunnel burst 67.103.163.208 -> WAN 18 packets 19.2K                                                                               │
│20:22:06 RADAR          VPN tunnel burst WAN -> 67.103.163.208 6 packets 544B                                                                                 │
│20:22:06 FIREWALL BLOCK 18 WAN scans stopped in 60s | ports: 8080, 8443, 443                                                                                  │
│20:22:03 FIREWALL BLOCK WAN scan blocked EXT.47.20 p8080 [abuse high]                                                                                         │
│20:21:31 FIREWALL BLOCK WAN scan blocked EXT.43.243 p8443 [abuse high]                                                                                        │
│20:20:59 FIREWALL BLOCK WAN scan blocked EXT.43.243 p443 [abuse high]                                                                                         │
│20:22:05 FIREWALL BLOCK LAN policy blocked Device-146 -> EXT.123.150 port 540                                                                                 │
│20:22:05 DNS BLOCK      MIRANDA-W... DNS blocked cdn.attn.tv [known bad]                                                                                      │
└──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┘
┌ EVENT FEED [ROTATE] ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┐
│[FW][HIGH] WAN scan blocked: E47.20 -> WAN HTTP                                                                                                               │
│next ◆ [FW][HIGH] 18 WAN scans stopped in 60s | ports: 8080, 8443, 443                                                                                        │
└──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────┘
```
