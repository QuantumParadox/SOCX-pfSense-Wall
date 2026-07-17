#!/usr/bin/env python3.11
"""SOCX browser wall dashboard.

This is intentionally dependency-light for pfSense.  It uses the Python
standard library for HTTP and WebSocket transport, then serves a static
HTML/CSS/JS dashboard that does the polished rendering in the browser.
"""

from __future__ import annotations

import argparse
import base64
import csv
import hashlib
import html
import json
import mimetypes
import os
import random
import re
import socket
import struct
import subprocess
import sys
import threading
import time
from collections import OrderedDict, deque
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any
from urllib.parse import parse_qs, urlparse


APP_DIR = Path(__file__).resolve().parent
STATIC_DIR = APP_DIR / "static"
WS_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"


def env_bool(name: str, default: bool) -> bool:
    value = os.environ.get(name)
    if value is None:
        return default
    return value.strip().lower() not in {"0", "false", "no", "off"}


def env_int(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, str(default)))
    except ValueError:
        return default


def clamp(value: float, low: float, high: float) -> float:
    return max(low, min(high, value))


def now_ms() -> int:
    return int(time.time() * 1000)


def run_cmd(command: str, timeout: float = 1.2) -> str:
    try:
        proc = subprocess.run(
            command,
            shell=True,
            check=False,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except Exception:
        return ""
    return proc.stdout or ""


def parse_env_file(path: Path) -> dict[str, str]:
    data: dict[str, str] = {}
    try:
        for raw in path.read_text(errors="ignore").splitlines():
            if "=" not in raw:
                continue
            key, value = raw.split("=", 1)
            data[key.strip()] = value.strip().strip("'\"")
    except Exception:
        pass
    return data


def parse_size(value: str) -> int:
    match = re.match(r"\s*([0-9.]+)\s*([KMGTPE]?)(?:i?B)?\s*$", value, re.I)
    if not match:
        try:
            return int(float(value))
        except ValueError:
            return 0
    number = float(match.group(1))
    suffix = match.group(2).upper()
    scale = {"": 1, "K": 1024, "M": 1024**2, "G": 1024**3, "T": 1024**4}.get(suffix, 1)
    return int(number * scale)


def human_bytes(value: float, suffix: str = "B") -> str:
    value = float(max(0, value))
    units = ["", "K", "M", "G", "T"]
    idx = 0
    while value >= 1024 and idx < len(units) - 1:
        value /= 1024.0
        idx += 1
    if idx == 0:
        return f"{value:.0f}{suffix}"
    if value >= 100:
        return f"{value:.0f}{units[idx]}{suffix}"
    if value >= 10:
        return f"{value:.1f}{units[idx]}{suffix}"
    return f"{value:.2f}{units[idx]}{suffix}"


def human_rate(value: float) -> str:
    return human_bytes(value, "/s")


def service_name(port: str) -> str:
    services = {
        "22": "ssh",
        "53": "dns",
        "67": "dhcp",
        "68": "dhcp",
        "80": "http",
        "123": "ntp",
        "137": "netbios",
        "138": "netbios",
        "139": "smb",
        "443": "https",
        "445": "smb",
        "500": "ike",
        "993": "imaps",
        "1443": "alt-https",
        "1701": "l2tp",
        "4500": "ipsec-nat",
        "5223": "apple-push",
        "5228": "gcm",
        "5938": "teamviewer",
        "8886": "iot-cloud",
    }
    return services.get(str(port), f"port {port}" if port else "other")


class SocxCollector:
    def __init__(self, interval_ms: int = 500, demo: bool = False) -> None:
        self.interval = max(0.2, interval_ms / 1000.0)
        self.demo = demo
        self.wan_if = os.environ.get("SOCX_IFWAN", "ix1")
        self.lan_if = os.environ.get("SOCX_IFLAN", "ix0")
        self.ups_cache = Path(os.environ.get("SOCX_UPS_CACHE", "/tmp/socx-ups-cache.env"))
        self.host_map_path = Path(os.environ.get("SOCX_HOSTS_CONFIG", "/usr/local/etc/socx_hosts.conf"))
        self.event_max = env_int("SOCX_WEB_MAX_EVENTS", 40)
        self.hosts = self.load_hosts()
        self.lock = threading.Lock()
        self.stop_event = threading.Event()
        self.net_prev: dict[str, tuple[float, int, int]] = {}
        self.histories: dict[str, deque[float]] = {
            "cpu": deque(maxlen=180),
            "mem": deque(maxlen=180),
            "wan_rx": deque(maxlen=180),
            "wan_tx": deque(maxlen=180),
            "lan_rx": deque(maxlen=180),
            "lan_tx": deque(maxlen=180),
            "pf_search": deque(maxlen=180),
            "ups_watts": deque(maxlen=180),
        }
        self.events: OrderedDict[str, dict[str, Any]] = OrderedDict()
        self.packets: deque[dict[str, Any]] = deque(maxlen=16)
        self.seen_log_lines: OrderedDict[str, float] = OrderedDict()
        self.last_event_read = 0.0
        self.last_state_sample = 0.0
        self.ups_samples: deque[float] = deque(maxlen=120)
        self.state: dict[str, Any] = self.demo_state()

    def load_hosts(self) -> dict[str, str]:
        hosts: dict[str, str] = {}
        try:
            for raw in self.host_map_path.read_text(errors="ignore").splitlines():
                line = raw.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                ip, name = line.split("=", 1)
                hosts[ip.strip()] = name.strip()
        except Exception:
            pass
        return hosts

    def start(self) -> None:
        thread = threading.Thread(target=self.loop, daemon=True)
        thread.start()

    def loop(self) -> None:
        while not self.stop_event.is_set():
            started = time.time()
            try:
                new_state = self.sample()
                with self.lock:
                    self.state = new_state
            except Exception as exc:
                with self.lock:
                    self.state["status"] = {"health": "collector degraded", "error": str(exc), "ts": now_ms()}
            elapsed = time.time() - started
            self.stop_event.wait(max(0.05, self.interval - elapsed))

    def snapshot(self) -> dict[str, Any]:
        with self.lock:
            return json.loads(json.dumps(self.state))

    def sample(self) -> dict[str, Any]:
        if self.demo:
            return self.demo_state()

        top = run_cmd("top -b -n 1 -P 2>/dev/null | head -80", timeout=1.5)
        cpu = self.collect_cpu(top)
        mem = self.collect_memory(top)
        pf = self.collect_pf()
        net = self.collect_network()
        ups = self.collect_ups()
        processes = self.collect_processes()
        command_center = self.collect_command_center()

        packets: list[dict[str, Any]] = []
        if time.time() - self.last_event_read > 0.8:
            packets = self.collect_events()
            self.last_event_read = time.time()
        else:
            packets = self.state.get("packets", [])

        flows = self.collect_flows()
        for key, value in {
            "cpu": cpu["overall"],
            "mem": mem["used_pct"],
            "wan_rx": net["wan"]["rx_bps"],
            "wan_tx": net["wan"]["tx_bps"],
            "lan_rx": net["lan"]["rx_bps"],
            "lan_tx": net["lan"]["tx_bps"],
            "pf_search": pf["searches_per_s"],
            "ups_watts": ups["watts"],
        }.items():
            self.histories[key].append(float(value or 0))

        return {
            "ts": now_ms(),
            "hostname": socket.gethostname(),
            "mode": "live",
            "status": {"health": "online", "refresh_ms": int(self.interval * 1000)},
            "cpu": cpu | {"history": list(self.histories["cpu"])},
            "memory": mem | {"history": list(self.histories["mem"])},
            "pf": pf | {"history": list(self.histories["pf_search"])},
            "net": {
                "wan": net["wan"] | {
                    "history_rx": list(self.histories["wan_rx"]),
                    "history_tx": list(self.histories["wan_tx"]),
                },
                "lan": net["lan"] | {
                    "history_rx": list(self.histories["lan_rx"]),
                    "history_tx": list(self.histories["lan_tx"]),
                },
            },
            "ups": ups | {"history": list(self.histories["ups_watts"])},
            "processes": processes,
            "command_center": command_center,
            "flows": flows,
            "packets": packets,
            "events": list(self.events.values())[-self.event_max :],
        }

    def collect_cpu(self, top: str) -> dict[str, Any]:
        cores: list[dict[str, Any]] = []
        for line in top.splitlines():
            match = re.match(r"CPU\s+(\d+):.*?([0-9.]+)%\s+idle", line)
            if not match:
                continue
            pct = 100.0 - float(match.group(2))
            cores.append({"id": int(match.group(1)), "pct": round(clamp(pct, 0, 100), 1)})
        if not cores:
            match = re.search(r"CPU:.*?([0-9.]+)%\s+idle", top)
            pct = 100.0 - float(match.group(1)) if match else 0.0
            cores.append({"id": 0, "pct": round(clamp(pct, 0, 100), 1)})
        overall = round(sum(item["pct"] for item in cores) / max(1, len(cores)), 1)
        load = [0.0, 0.0, 0.0]
        match = re.search(r"load averages?:\s*([0-9.]+),\s*([0-9.]+),\s*([0-9.]+)", top)
        if match:
            load = [float(match.group(1)), float(match.group(2)), float(match.group(3))]
        proc_match = re.search(r"(\d+) processes:\s*(.*)", top)
        proc_text = proc_match.group(0) if proc_match else ""
        freq = run_cmd("sysctl -n dev.cpu.0.freq 2>/dev/null", timeout=0.4).strip()
        temp_raw = run_cmd("sysctl -n dev.cpu.0.temperature 2>/dev/null", timeout=0.4).strip()
        temp_match = re.search(r"([0-9.]+)", temp_raw)
        return {
            "overall": overall,
            "cores": cores,
            "load": load,
            "freq_mhz": int(float(freq)) if freq.replace(".", "", 1).isdigit() else None,
            "temp_c": float(temp_match.group(1)) if temp_match else None,
            "process_text": proc_text,
        }

    def collect_memory(self, top: str) -> dict[str, Any]:
        total = parse_size(run_cmd("sysctl -n hw.physmem 2>/dev/null", timeout=0.4).strip())
        free = 0
        match = re.search(r"Mem:.*?([0-9.]+\s*[KMGT])\s+Free", top, re.I)
        if match:
            free = parse_size(match.group(1))
        used = max(0, total - free) if total else 0
        arc = 0
        match = re.search(r"ARC:\s*([0-9.]+\s*[KMGT])\s+Total", top, re.I)
        if match:
            arc = parse_size(match.group(1))
        return {
            "total": total,
            "used": used,
            "free": free,
            "arc": arc,
            "used_pct": round((used / total) * 100, 1) if total else 0,
            "total_h": human_bytes(total),
            "used_h": human_bytes(used),
            "free_h": human_bytes(free),
            "arc_h": human_bytes(arc),
        }

    def collect_pf(self) -> dict[str, Any]:
        out = run_cmd("pfctl -si 2>/dev/null", timeout=1.0)
        states = int(re.search(r"current entries\s+(\d+)", out).group(1)) if re.search(r"current entries\s+(\d+)", out) else 0
        searches_per_s = 0.0
        match = re.search(r"searches\s+\d+\s+([0-9.]+)/s", out)
        if match:
            searches_per_s = float(match.group(1))

        passed = 0
        blocked = 0
        for line in out.splitlines():
            stripped = line.strip()
            if stripped.startswith("Passed") or stripped.startswith("Blocked"):
                nums = [int(x) for x in re.findall(r"\d+", stripped)]
                if stripped.startswith("Passed"):
                    passed += sum(nums)
                else:
                    blocked += sum(nums)
        return {
            "states": states,
            "searches_per_s": searches_per_s,
            "passed": passed,
            "blocked": blocked,
            "states_h": f"{states:,}",
            "searches_h": f"{searches_per_s:,.1f}/s",
            "passed_h": human_bytes(passed, ""),
            "blocked_h": human_bytes(blocked, ""),
        }

    def collect_network(self) -> dict[str, Any]:
        return {
            "wan": self.collect_interface(self.wan_if),
            "lan": self.collect_interface(self.lan_if),
        }

    def collect_interface(self, name: str) -> dict[str, Any]:
        out = run_cmd(f"netstat -ibdn -I {name} 2>/dev/null | head -4", timeout=0.7)
        ibytes = obytes = ipkts = opkts = 0
        for line in out.splitlines():
            parts = line.split()
            if len(parts) >= 11 and parts[0] == name and parts[2].startswith("<Link#"):
                try:
                    ipkts = int(parts[4])
                    ibytes = int(parts[7])
                    opkts = int(parts[8])
                    obytes = int(parts[10])
                except ValueError:
                    pass
                break
        now = time.time()
        prev = self.net_prev.get(name)
        rx_bps = tx_bps = 0.0
        if prev:
            elapsed = max(0.1, now - prev[0])
            rx_bps = max(0.0, (ibytes - prev[1]) / elapsed)
            tx_bps = max(0.0, (obytes - prev[2]) / elapsed)
        self.net_prev[name] = (now, ibytes, obytes)
        return {
            "name": name,
            "rx_bps": rx_bps,
            "tx_bps": tx_bps,
            "rx_h": human_rate(rx_bps),
            "tx_h": human_rate(tx_bps),
            "rx_total": human_bytes(ibytes),
            "tx_total": human_bytes(obytes),
            "packets_in": ipkts,
            "packets_out": opkts,
        }

    def collect_ups(self) -> dict[str, Any]:
        data = parse_env_file(self.ups_cache)
        updated = float(data.get("updated", "0") or 0)
        watts = float(data.get("watts", "0") or 0)
        load = float(data.get("load", "0") or 0)
        battery = float(data.get("battery", "0") or 0)
        runtime = float(data.get("runtime", "0") or 0)
        linev = float(data.get("linev", "0") or 0)
        if watts:
            self.ups_samples.append(watts)
        peak = max(self.ups_samples) if self.ups_samples else watts
        avg = sum(self.ups_samples) / len(self.ups_samples) if self.ups_samples else watts
        stale = updated > 0 and (time.time() - updated) > 20
        return {
            "online": data.get("status", "").upper() in {"OL", "ONLINE"},
            "status": data.get("status", "unknown") or "unknown",
            "watts": watts,
            "watts_h": f"{watts:.0f} W" if watts else "-- W",
            "load": load,
            "battery": battery,
            "runtime_sec": runtime,
            "runtime_h": f"{runtime / 60:.0f}m" if runtime else "--",
            "linev": linev,
            "linev_h": f"{linev:.1f} V" if linev else "--",
            "peak_watts": peak,
            "avg_watts": avg,
            "age_sec": max(0, time.time() - updated) if updated else None,
            "stale": stale,
        }

    def collect_command_center(self) -> dict[str, Any]:
        auto = parse_env_file(Path("/tmp/socx-autopilot.env"))
        direct = parse_env_file(Path("/tmp/socx-speedtest-direct.env"))
        vpn = parse_env_file(Path("/tmp/socx-speedtest-vpn.env"))
        named_paths: list[dict[str, Any]] = []
        for path in sorted(Path("/tmp").glob("socx-speedtest-vpn-*.env")):
            data = parse_env_file(path)
            if not data:
                continue
            label = data.get("profile_label") or path.stem.replace("socx-speedtest-vpn-", "").upper()
            named_paths.append(self.speedtest_summary(label, data))
        history_path = Path(os.environ.get("SOCX_HISTORY_FILE", "/var/db/socx_history.jsonl"))
        history_rows: list[dict[str, Any]] = []
        try:
            for raw in history_path.read_text(errors="ignore").splitlines()[-8:]:
                try:
                    history_rows.append(json.loads(raw))
                except Exception:
                    continue
        except Exception:
            pass

        mode = auto.get("mode", "UNKNOWN")
        score = auto.get("score", "0")
        summary = auto.get("summary") or auto.get("reason") or "Run socx autopilot for a fresh verdict"
        actions = [
            "socx status",
            "socx timeline 60",
            "socx repair",
            "socx explain-screen",
        ]
        if mode.upper() in {"INVESTIGATE", "INCIDENT", "SECURITY WATCH"}:
            actions.insert(1, "socx incident quick")
        return {
            "mode": mode,
            "score": score,
            "summary": summary,
            "updated": auto.get("updated") or auto.get("ts") or "",
            "direct": self.speedtest_summary("DIRECT", direct),
            "vpn": self.speedtest_summary("VPN", vpn),
            "vpn_paths": named_paths,
            "history_count": len(history_rows),
            "history": history_rows,
            "actions": actions[:5],
        }

    def speedtest_summary(self, label: str, data: dict[str, str]) -> dict[str, Any]:
        status = data.get("status") or data.get("result_status") or ("OK" if data.get("download_mbps") else "WAIT")
        age = 0
        try:
            updated = float(data.get("updated", "0") or 0)
            age = int(max(0, time.time() - updated)) if updated else 0
        except ValueError:
            age = 0
        return {
            "label": label,
            "status": status,
            "down": data.get("download_mbps", ""),
            "up": data.get("upload_mbps", ""),
            "ping": data.get("ping_ms", ""),
            "server": data.get("server_name") or data.get("server") or "",
            "age_sec": age,
        }

    def collect_processes(self) -> list[dict[str, Any]]:
        out = run_cmd("ps axo pid,user,pcpu,pmem,rss,command 2>/dev/null | sort -k3 -nr | head -16", timeout=0.8)
        rows: list[dict[str, Any]] = []
        for line in out.splitlines():
            if line.lower().strip().startswith("pid"):
                continue
            parts = line.split(None, 5)
            if len(parts) < 6:
                continue
            try:
                rows.append(
                    {
                        "pid": int(parts[0]),
                        "user": parts[1],
                        "cpu": float(parts[2]),
                        "mem_pct": float(parts[3]),
                        "rss_h": human_bytes(float(parts[4]) * 1024),
                        "command": parts[5],
                    }
                )
            except ValueError:
                continue
        return rows[:12]

    def collect_flows(self) -> list[dict[str, Any]]:
        out = run_cmd("pfctl -ss 2>/dev/null | head -180", timeout=1.1)
        seen: OrderedDict[str, dict[str, Any]] = OrderedDict()
        for line in out.splitlines():
            match = re.search(
                r"\b(?P<proto>tcp|udp|icmp)\b.*?\((?P<src>192\.168\.1\.\d+):(?P<sport>\d+)\)\s+->\s+(?P<dst>[0-9a-fA-F:.]+):(?P<dport>\d+)",
                line,
                re.I,
            )
            if not match:
                match = re.search(
                    r"\b(?P<proto>tcp|udp|icmp)\b\s+(?P<src>192\.168\.1\.\d+):(?P<sport>\d+)\s+->\s+(?P<dst>[0-9a-fA-F:.]+):(?P<dport>\d+)",
                    line,
                    re.I,
                )
            if not match:
                continue
            src = match.group("src")
            dst = match.group("dst")
            proto = match.group("proto").upper()
            port = match.group("dport")
            key = f"{src}>{dst}:{port}:{proto}"
            if key in seen:
                seen[key]["count"] += 1
                continue
            seen[key] = {
                "asset": self.pretty_host(src),
                "peer": self.pretty_host(dst),
                "proto": proto,
                "service": service_name(port),
                "port": port,
                "state": "ESTABLISHED" if "ESTABLISHED" in line else "ACTIVE",
                "count": 1,
                "tag": self.flow_tag(dst, port),
            }
        return list(seen.values())[:14]

    def collect_events(self) -> list[dict[str, Any]]:
        filter_log = run_cmd("tail -n 80 /var/log/filter.log 2>/dev/null", timeout=0.8)
        for line in filter_log.splitlines():
            if not self.should_process_log_line(line):
                continue
            packet = self.parse_filterlog(line)
            if not packet:
                continue
            self.packets.appendleft(packet)
            if packet["action"].lower() == "block":
                src = packet["src_label"]
                dst = packet["dst_label"]
                text = f"[FW][MED] {src} -> {dst}:{packet.get('dport', '')} blocked"
                self.push_event("FW", "MED", text, packet["fingerprint"])

        dnsbl = run_cmd("tail -n 60 /var/log/pfblockerng/dnsbl.log 2>/dev/null", timeout=0.6)
        for line in dnsbl.splitlines():
            if not self.should_process_log_line(line):
                continue
            event = self.parse_dnsbl(line)
            if event:
                self.push_event("DNSBL", "LOW", event["text"], event["fingerprint"])

        return list(self.packets)

    def should_process_log_line(self, line: str) -> bool:
        key = hashlib.sha1(line.encode("utf-8", "ignore")).hexdigest()
        if key in self.seen_log_lines:
            return False
        self.seen_log_lines[key] = time.time()
        while len(self.seen_log_lines) > 800:
            self.seen_log_lines.popitem(last=False)
        return True

    def parse_filterlog(self, line: str) -> dict[str, Any] | None:
        if "filterlog" not in line or ": " not in line:
            return None
        prefix, payload = line.split(": ", 1)
        try:
            fields = next(csv.reader([payload]))
        except Exception:
            return None
        if len(fields) < 19:
            return None
        action = fields[6]
        direction = fields[7]
        ipver = fields[8]
        if ipver == "4":
            if len(fields) < 20:
                return None
            proto = fields[16].upper()
            size = fields[17]
            src = fields[18]
            dst = fields[19]
            sport = fields[20] if len(fields) > 20 and fields[20].isdigit() else ""
            dport = fields[21] if len(fields) > 21 and fields[21].isdigit() else ""
        else:
            proto = fields[12].upper()
            size = fields[14]
            src = fields[15]
            dst = fields[16]
            sport = fields[17] if len(fields) > 17 and fields[17].isdigit() else ""
            dport = fields[18] if len(fields) > 18 and fields[18].isdigit() else ""
        time_match = re.search(r"(\d\d:\d\d:\d\d)", prefix)
        service = service_name(dport)
        severity = "MED" if action == "block" else "LOW"
        if dport in {"22", "500", "4500", "3389"} and action == "block":
            severity = "HIGH"
        return {
            "time": time_match.group(1) if time_match else time.strftime("%H:%M:%S"),
            "action": action.upper(),
            "direction": direction.upper(),
            "proto": proto,
            "src": src,
            "dst": dst,
            "src_label": self.pretty_host(src),
            "dst_label": self.pretty_host(dst),
            "sport": sport,
            "dport": dport,
            "service": service,
            "size": size,
            "info": f"{service} {size}B",
            "severity": severity,
            "fingerprint": f"fw:{action}:{proto}:{src}:{dst}:{dport}",
        }

    def parse_dnsbl(self, line: str) -> dict[str, str] | None:
        try:
            fields = next(csv.reader([line]))
        except Exception:
            return None
        if len(fields) < 4:
            return None
        domain = fields[2].strip()
        src = fields[3].strip()
        if not domain or not src:
            return None
        text = f"[DNSBL][LOW] {self.pretty_host(src)} -> {domain} blocked"
        return {"text": text, "fingerprint": f"dnsbl:{src}:{domain}"}

    def push_event(self, kind: str, severity: str, text: str, fingerprint: str) -> None:
        now = time.time()
        existing = self.events.get(fingerprint)
        if existing and now - existing.get("updated_at", 0) < 10:
            existing["count"] = int(existing.get("count", 1)) + 1
            existing["updated_at"] = now
            existing["text"] = text
            self.events.move_to_end(fingerprint)
            return
        self.events[fingerprint] = {
            "kind": kind,
            "severity": severity,
            "text": text,
            "count": 1,
            "created_at": now,
            "updated_at": now,
        }
        while len(self.events) > self.event_max:
            self.events.popitem(last=False)

    def pretty_host(self, ip: str) -> str:
        if ip in self.hosts:
            return f"{self.hosts[ip]}/{ip}"
        if ip.startswith("192.168.1."):
            return f"LAN.{ip.rsplit('.', 1)[-1]}"
        if ip.startswith("74.46.") or ip == "WAN":
            return "WAN"
        if ":" in ip:
            return "IPv6"
        if re.match(r"^\d+\.\d+\.\d+\.\d+$", ip):
            parts = ip.split(".")
            return f"EXT.{parts[2]}.{parts[3]}"
        return ip

    def flow_tag(self, dst: str, port: str) -> str:
        if port in {"443", "80"}:
            return "web"
        if port in {"5223", "5228"}:
            return "push"
        if port in {"500", "4500"}:
            return "vpn"
        if port in {"53"}:
            return "dns"
        if port in {"993"}:
            return "mail"
        return "state"

    def demo_state(self) -> dict[str, Any]:
        t = time.time()
        def wave(offset: float, scale: float = 1.0) -> float:
            return (50 + 40 * random.random() + 20 * random.random() * (0.5 + 0.5 * random.random())) * scale

        for key in self.histories:
            self.histories[key].append(wave(t, 1.0))
        events = [
            {"kind": "DNSBL", "severity": "LOW", "text": "[DNSBL][LOW] JupiterLXI/LAN.161 -> beacons.gvt2.com blocked", "count": 7},
            {"kind": "FW", "severity": "MED", "text": "[FW][MED] iPhone/LAN.127 -> 147.185.133.70:137 blocked", "count": 1},
            {"kind": "IDS", "severity": "HIGH", "text": "[IDS][HIGH] Enceladus/LAN.102 -> suspicious outbound beacon", "count": 1},
        ]
        return {
            "ts": now_ms(),
            "hostname": "JupiterLXI",
            "mode": "demo",
            "status": {"health": "demo", "refresh_ms": int(self.interval * 1000)},
            "cpu": {
                "overall": round(24 + random.random() * 12, 1),
                "cores": [{"id": i, "pct": round(18 + random.random() * 24, 1)} for i in range(4)],
                "load": [1.4, 1.7, 1.6],
                "freq_mhz": 3600,
                "temp_c": 58,
                "process_text": "152 processes: 2 running, 150 sleeping",
                "history": list(self.histories["cpu"]),
            },
            "memory": {
                "total": 32 * 1024**3,
                "used": 24 * 1024**3,
                "free": 8 * 1024**3,
                "arc": 17 * 1024**3,
                "used_pct": 76.1,
                "total_h": "31.7GB",
                "used_h": "24.1GB",
                "free_h": "7.6GB",
                "arc_h": "17.0GB",
                "history": list(self.histories["mem"]),
            },
            "pf": {
                "states": 1880,
                "searches_per_s": 6538.3,
                "passed": 406_000_000,
                "blocked": 138_700,
                "states_h": "1,880",
                "searches_h": "6,538/s",
                "passed_h": "406M",
                "blocked_h": "139K",
                "history": list(self.histories["pf_search"]),
            },
            "net": {
                "wan": {"name": "ix1", "rx_bps": 42000, "tx_bps": 19300, "rx_h": "42K/s", "tx_h": "19K/s", "history_rx": list(self.histories["wan_rx"]), "history_tx": list(self.histories["wan_tx"])},
                "lan": {"name": "ix0", "rx_bps": 51000, "tx_bps": 23800, "rx_h": "51K/s", "tx_h": "24K/s", "history_rx": list(self.histories["lan_rx"]), "history_tx": list(self.histories["lan_tx"])},
            },
            "ups": {
                "online": True,
                "status": "OL",
                "watts": 540 + random.random() * 120,
                "watts_h": f"{540 + random.random() * 120:.0f} W",
                "load": 31,
                "battery": 100,
                "runtime_sec": 2280,
                "runtime_h": "38m",
                "linev": 120.1,
                "linev_h": "120.1 V",
                "peak_watts": 812,
                "avg_watts": 603,
                "stale": False,
                "history": list(self.histories["ups_watts"]),
            },
            "processes": [
                {"pid": 8809, "user": "ntopng", "cpu": 4.1, "mem_pct": 2.2, "rss_h": "540MB", "command": "ntopng"},
                {"pid": 60684, "user": "root", "cpu": 2.9, "mem_pct": 1.8, "rss_h": "472MB", "command": "tmux: server"},
            ],
            "command_center": {
                "mode": "WATCH",
                "score": "82",
                "summary": "WAN healthy, VPN paths mixed, DNSBL routine.",
                "direct": {"label": "DIRECT", "status": "OK", "down": "3223", "up": "2372", "ping": "9", "age_sec": 900},
                "vpn": {"label": "VPN", "status": "OK", "down": "740", "up": "510", "ping": "52", "age_sec": 980},
                "vpn_paths": [
                    {"label": "NYC", "status": "OK", "down": "586", "up": "753", "ping": "19", "age_sec": 980},
                    {"label": "RCN-DE", "status": "OK", "down": "740", "up": "510", "ping": "52", "age_sec": 980},
                ],
                "history_count": 8,
                "history": [],
                "actions": ["socx status", "socx timeline 60", "socx repair", "socx explain-screen"],
            },
            "flows": [
                {"asset": "JupiterLXI/192.168.1.161", "peer": "EXT.155.209", "proto": "TCP", "service": "https", "port": "443", "state": "ESTABLISHED", "count": 3, "tag": "web"},
                {"asset": "NAS-Core/192.168.1.127", "peer": "EXT.57.155", "proto": "TCP", "service": "imaps", "port": "993", "state": "ESTABLISHED", "count": 1, "tag": "mail"},
            ],
            "packets": [
                {"time": "19:43:11", "action": "BLOCK", "direction": "IN", "proto": "TCP", "src_label": "EXT.217.142", "dst_label": "WAN", "service": "https", "size": "287", "info": "https 287B", "severity": "MED"},
                {"time": "19:43:12", "action": "PASS", "direction": "OUT", "proto": "UDP", "src_label": "LAN.161", "dst_label": "EXT.163.208", "service": "alt-https", "size": "936", "info": "alt-https 936B", "severity": "LOW"},
            ],
            "events": events,
        }


class SocxHandler(BaseHTTPRequestHandler):
    server_version = "SOCXWeb/0.1"

    def log_message(self, fmt: str, *args: Any) -> None:
        if env_bool("SOCX_WEB_ACCESS_LOG", False):
            super().log_message(fmt, *args)

    @property
    def collector(self) -> SocxCollector:
        return self.server.collector  # type: ignore[attr-defined]

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path == "/ws":
            self.handle_websocket()
            return
        if parsed.path == "/api/state":
            self.send_json(self.collector.snapshot())
            return
        if parsed.path == "/api/command-center":
            self.send_json(self.collector.snapshot().get("command_center", {}))
            return
        if parsed.path == "/api/config":
            self.send_json({"refresh_ms": int(self.collector.interval * 1000), "demo": self.collector.demo})
            return
        self.serve_static(parsed.path)

    def serve_static(self, path: str) -> None:
        if path in {"", "/"}:
            target = STATIC_DIR / "index.html"
        else:
            clean = Path(path.lstrip("/"))
            target = STATIC_DIR / clean
        try:
            target = target.resolve()
            if not str(target).startswith(str(STATIC_DIR.resolve())) or not target.is_file():
                self.send_error(404)
                return
            body = target.read_bytes()
            ctype = mimetypes.guess_type(str(target))[0] or "application/octet-stream"
            self.send_response(200)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store" if target.name.endswith((".html", ".js", ".css")) else "public, max-age=3600")
            self.end_headers()
            self.wfile.write(body)
        except Exception:
            self.send_error(500)

    def send_json(self, payload: dict[str, Any]) -> None:
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def handle_websocket(self) -> None:
        key = self.headers.get("Sec-WebSocket-Key")
        if not key:
            self.send_error(400, "Missing Sec-WebSocket-Key")
            return
        accept = base64.b64encode(hashlib.sha1((key + WS_GUID).encode("ascii")).digest()).decode("ascii")
        self.send_response(101, "Switching Protocols")
        self.send_header("Upgrade", "websocket")
        self.send_header("Connection", "Upgrade")
        self.send_header("Sec-WebSocket-Accept", accept)
        self.end_headers()
        self.close_connection = True

        while True:
            payload = json.dumps(self.collector.snapshot(), separators=(",", ":")).encode("utf-8")
            try:
                self.wfile.write(ws_frame(payload))
                self.wfile.flush()
            except Exception:
                break
            time.sleep(self.collector.interval)


def ws_frame(payload: bytes) -> bytes:
    length = len(payload)
    if length < 126:
        return bytes([0x81, length]) + payload
    if length < 65536:
        return bytes([0x81, 126]) + struct.pack("!H", length) + payload
    return bytes([0x81, 127]) + struct.pack("!Q", length) + payload


class SocxServer(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, address: tuple[str, int], handler: type[BaseHTTPRequestHandler], collector: SocxCollector) -> None:
        super().__init__(address, handler)
        self.collector = collector


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="SOCX browser wall dashboard")
    parser.add_argument("--host", default=os.environ.get("SOCX_WEB_HOST", "0.0.0.0"))
    parser.add_argument("--port", type=int, default=env_int("SOCX_WEB_PORT", 8094))
    parser.add_argument("--interval-ms", type=int, default=env_int("SOCX_WEB_REFRESH_MS", 500))
    parser.add_argument("--demo", action="store_true", default=env_bool("SOCX_WEB_DEMO", False))
    args = parser.parse_args(argv)

    if not STATIC_DIR.exists():
        print(f"Missing static directory: {STATIC_DIR}", file=sys.stderr)
        return 2

    collector = SocxCollector(interval_ms=args.interval_ms, demo=args.demo)
    collector.start()
    server = SocxServer((args.host, args.port), SocxHandler, collector)
    print(
        f"SOCX web dashboard listening on http://{args.host}:{args.port}/ "
        f"refresh={args.interval_ms}ms demo={args.demo}",
        flush=True,
    )
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        collector.stop_event.set()
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
