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


def run_cmd_capture(args: list[str], timeout: float = 20.0) -> dict[str, Any]:
    started = time.time()
    try:
        proc = subprocess.run(
            args,
            check=False,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
        out = (proc.stdout or "") + (proc.stderr or "")
        return {
            "ok": proc.returncode == 0,
            "returncode": proc.returncode,
            "elapsed_ms": int((time.time() - started) * 1000),
            "output": out[-6000:],
        }
    except subprocess.TimeoutExpired as exc:
        out = ((exc.stdout or "") if isinstance(exc.stdout, str) else "") + ((exc.stderr or "") if isinstance(exc.stderr, str) else "")
        return {"ok": False, "returncode": 124, "elapsed_ms": int((time.time() - started) * 1000), "output": (out + "\ncommand timed out")[-6000:]}
    except Exception as exc:
        return {"ok": False, "returncode": 1, "elapsed_ms": int((time.time() - started) * 1000), "output": str(exc)[:1000]}


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


def human_duration(seconds: Any) -> str:
    try:
        total = int(float(seconds))
    except (TypeError, ValueError):
        return "--"
    if total <= 0:
        return "--"
    days, rem = divmod(total, 86400)
    hours, rem = divmod(rem, 3600)
    minutes, _ = divmod(rem, 60)
    if days:
        return f"{days}d {hours}h"
    if hours:
        return f"{hours}h {minutes}m"
    return f"{minutes}m"


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
        "5230": "gcm",
        "5938": "teamviewer",
        "6379": "redis",
        "7860": "gradio",
        "8000": "vllm",
        "8001": "vllm",
        "8002": "vllm",
        "8086": "influx",
        "8089": "splunk",
        "8093": "miranda",
        "8094": "socx-web",
        "8095": "pi-llm",
        "8265": "ray",
        "8886": "iot-cloud",
        "8888": "jupyter",
        "8889": "jupyter",
        "9090": "metrics",
        "9100": "node-exporter",
        "10001": "ray",
        "11434": "ollama",
        "11435": "llm",
        "19090": "triton",
    }
    return services.get(str(port), f"port {port}" if port else "other")


def app_label(text: str) -> str:
    value = text.lower()
    patterns = [
        (r"netflix|nflxvideo", "Netflix"),
        (r"primevideo|amazonvideo|aiv-cdn|atv-ps|media-amazon", "Prime Video"),
        (r"youtube|googlevideo|ytimg", "YouTube"),
        (r"disney|disneyplus|dssott", "Disney+"),
        (r"hulu", "Hulu"),
        (r"max\.com|hbomax|hbo", "Max"),
        (r"peacocktv|peacock", "Peacock"),
        (r"paramountplus|cbsivideo|cbsaavideo", "Paramount+"),
        (r"roku", "Roku"),
        (r"plex", "Plex"),
        (r"apple|icloud|mzstatic|itunes|aaplimg|appldnld|tv\.apple", "Apple/iCloud"),
        (r"apns", "Apple Push"),
        (r"huggingface|hf\.co", "Hugging Face"),
        (r"civitai", "Civitai"),
        (r"quantum-computing\.ibm|cloud\.ibm|ibm\.com", "IBM Quantum"),
        (r"openai|chatgpt", "OpenAI"),
        (r"anthropic|claude", "Anthropic"),
        (r"x\.ai|grok", "xAI/Grok"),
        (r"nvidia|build\.nvidia", "NVIDIA AI"),
        (r"ollama", "Ollama"),
        (r"vllm", "vLLM"),
        (r"googleapis|gstatic|googleusercontent", "Google APIs"),
        (r"doubleclick|googlesyndication|googleadservices", "Google Ads"),
        (r"microsoft|windowsupdate|office365|live\.com|msn\.com|azure", "Microsoft"),
        (r"amazonaws|cloudfront", "AWS/CloudFront"),
        (r"facebook|fbcdn|instagram|whatsapp", "Meta"),
        (r"discord", "Discord"),
        (r"spotify", "Spotify"),
        (r"steam|steampowered", "Steam"),
    ]
    for pattern, label in patterns:
        if re.search(pattern, value):
            return label
    return ""


def is_routine_ids(text: str) -> bool:
    return bool(re.search(r"SURICATA (Stream|Ethertype unknown|TCPv[46] invalid checksum|UDPv[46] invalid checksum|ICMPv[46] invalid checksum|QUIC failed decrypt)|Generic Protocol Command Decode|invalid ack|invalid timestamp|bad window|wrong seq|retransmission|decoder event|HTTP unable to match response to request|Raw pkt:", text, re.I))


def is_high_signal_ids(text: str) -> bool:
    if is_routine_ids(text):
        return False
    return bool(re.search(r"malware|trojan|ransom|command.?and.?control|c2 beacon|cnc|callback|exploit|shellcode|botnet|coinminer|credential|phish|blacklist|known.?bad", text, re.I))


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
        self.pi_fleet_checked = 0.0
        self.pi_fleet_cache: dict[str, Any] = {"updated": 0, "count": 0, "online": 0, "score": 0, "nodes": []}
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
        incident = self.collect_incident_light(command_center)
        pi_nodes = self.collect_pi_nodes()
        threat_pulse = self.collect_threat_pulse(incident)
        flows = self.collect_flows()
        label_brain = self.collect_label_brain(flows, incident)
        flows = self.enrich_flows_with_labels(flows, label_brain)
        asset_watch = self.collect_asset_watch(flows, pi_nodes, label_brain)
        ai_timeline = self.collect_ai_timeline(pi_nodes, command_center)

        packets: list[dict[str, Any]] = []
        if time.time() - self.last_event_read > 0.8:
            packets = self.collect_events()
            self.last_event_read = time.time()
        else:
            packets = self.state.get("packets", [])

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
            "incident": incident,
            "pi_nodes": pi_nodes,
            "threat_pulse": threat_pulse,
            "asset_watch": asset_watch,
            "label_brain": label_brain,
            "ai_timeline": ai_timeline,
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
        client = parse_env_file(Path("/tmp/socx-speedtest-client.env"))
        router = parse_env_file(Path("/tmp/socx-speedtest-router.env"))
        direct = parse_env_file(Path("/tmp/socx-speedtest-direct.env"))
        vpn = parse_env_file(Path("/tmp/socx-speedtest-vpn.env"))
        named_paths: list[dict[str, Any]] = []
        for path in sorted(Path("/tmp").glob("socx-speedtest-vpn-*.env")):
            data = parse_env_file(path)
            if not data:
                continue
            label = data.get("profile_label") or path.stem.replace("socx-speedtest-vpn-", "").upper()
            named_paths.append(self.speedtest_summary(label, data))
        history_rows = self.collect_history(48)

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
            "scores": {
                "network": auto.get("network_score", ""),
                "security": auto.get("security_score", ""),
                "ai": auto.get("ai_score", ""),
                "sensors": auto.get("sensor_score", ""),
            },
            "summary": summary,
            "updated": auto.get("updated") or auto.get("ts") or "",
            "client": self.speedtest_summary("CLIENT", client),
            "router": self.speedtest_summary("ROUTER", router),
            "direct": self.speedtest_summary("DIRECT", direct),
            "vpn": self.speedtest_summary("VPN", vpn),
            "speed_truth": self.speedtest_truth(client, router, direct, vpn),
            "vpn_paths": named_paths,
            "history_count": len(history_rows),
            "history_trend": self.history_trend(history_rows),
            "history": history_rows,
            "actions": actions[:5],
        }

    def speedtest_truth(
        self,
        client: dict[str, str],
        router: dict[str, str],
        direct: dict[str, str],
        vpn: dict[str, str],
    ) -> dict[str, Any]:
        def num(data: dict[str, str], key: str) -> float:
            try:
                return float(data.get(key, "") or 0)
            except ValueError:
                return 0.0
        client_down = num(client, "download_mbps")
        router_down = num(router, "download_mbps")
        direct_down = num(direct, "download_mbps")
        vpn_down = num(vpn, "download_mbps")
        router_low = bool(client_down and router_down and router_down < client_down * 0.65)
        vpn_low = bool(direct_down and vpn_down and vpn_down < direct_down * 0.45)
        if router_low:
            label = "router lower than client"
        elif vpn_low:
            label = "vpn path slower"
        elif client_down or router_down or direct_down or vpn_down:
            label = "speed truth normal"
        else:
            label = "speed truth waiting"
        return {
            "label": label,
            "router_under_client": router_low,
            "vpn_under_direct": vpn_low,
            "client_down": round(client_down) if client_down else "",
            "router_down": round(router_down) if router_down else "",
            "direct_down": round(direct_down) if direct_down else "",
            "vpn_down": round(vpn_down) if vpn_down else "",
        }

    def collect_pi_nodes(self) -> dict[str, Any]:
        now = time.time()
        if self.pi_fleet_cache.get("nodes") and now - self.pi_fleet_checked < env_int("SOCX_PI_FLEET_POLL_SECONDS", 5):
            cached = dict(self.pi_fleet_cache)
            cached["age_sec"] = int(max(0, now - float(cached.get("updated") or now)))
            return cached
        path = Path(os.environ.get("SOCX_PI_NODES_JSON", "/tmp/socx-pi-nodes.json"))
        data: dict[str, Any] = {"updated": 0, "count": 0, "nodes": []}
        try:
            parsed = json.loads(path.read_text(errors="ignore"))
            if isinstance(parsed, dict):
                data.update(parsed)
        except Exception:
            discovery = parse_env_file(Path("/tmp/socx-pi-discovery.env"))
            if discovery.get("ip"):
                data["count"] = 1
                data["nodes"] = [{
                    "ip": discovery.get("ip", ""),
                    "name": "RaspberryPi5",
                    "model": "Raspberry Pi",
                    "role": "pi-ai",
                    "service": discovery.get("service", ""),
                    "ports": "8095",
                    "detail": discovery.get("note", ""),
                }]
        enriched = []
        for node in data.get("nodes", []):
            if isinstance(node, dict):
                enriched.append(self.collect_pi_node_health(node))
        data["nodes"] = enriched
        data["count"] = len(enriched)
        data["online"] = sum(1 for node in enriched if node.get("status") == "online")
        temps = [float(node.get("temperature_c")) for node in enriched if node.get("temperature_c") is not None]
        hot = any(temp >= 75 for temp in temps)
        data["score"] = max(0, 100 - ((len(enriched) - int(data["online"])) * 35) - (20 if hot else 0))
        data["summary"] = f"{data['online']}/{data['count']} Pi nodes online"
        updated = float(data.get("updated") or 0)
        data["age_sec"] = int(max(0, time.time() - updated)) if updated else None
        self.pi_fleet_cache = data
        self.pi_fleet_checked = now
        return data

    def collect_pi_node_health(self, node: dict[str, Any]) -> dict[str, Any]:
        ip = str(node.get("ip") or "")
        result = dict(node)
        result.setdefault("status", "online" if ip else "unknown")
        if not ip:
            return self.decorate_pi_node(result)
        pi_ai = run_cmd(f"curl -fsS --max-time 1 http://{ip}:8095/health 2>/dev/null", timeout=1.4)
        if pi_ai:
            result["status"] = "online"
            try:
                data = json.loads(pi_ai)
                system = data.get("system") if isinstance(data.get("system"), dict) else {}
                runtime = data.get("runtime") if isinstance(data.get("runtime"), dict) else {}
                hailo = runtime.get("hailo") if isinstance(runtime.get("hailo"), dict) else {}
                cpu_ollama = runtime.get("cpu_ollama") if isinstance(runtime.get("cpu_ollama"), dict) else {}
                autonomy = data.get("autonomy") if isinstance(data.get("autonomy"), dict) else {}
                result["service"] = result.get("service") or "socx-3llm"
                result["temperature_c"] = system.get("temp_c")
                result["load_one"] = (system.get("load") or {}).get("one") if isinstance(system.get("load"), dict) else None
                result["memory_used_pct"] = (system.get("memory") or {}).get("used_pct") if isinstance(system.get("memory"), dict) else None
                result["roles_online"] = data.get("roles_online")
                result["latest_status"] = data.get("latest_status")
                result["hailo_models"] = hailo.get("model_count")
                result["cpu_models"] = cpu_ollama.get("model_count") or (data.get("ollama") or {}).get("model_count")
                result["autonomy_mode"] = autonomy.get("mode")
                result["autonomy_score"] = autonomy.get("score")
                result["autonomy_summary"] = autonomy.get("summary")
                result["last_cycle_iso"] = autonomy.get("last_cycle_iso") or data.get("updated_iso")
                experiments = autonomy.get("experiments")
                if isinstance(experiments, list):
                    result["experiments_ok"] = sum(1 for item in experiments if isinstance(item, dict) and item.get("status") in {"pass", "stable", "ok"})
                    result["experiments_total"] = len(experiments)
            except Exception:
                pass
        sidecar = run_cmd(f"curl -fsS --max-time 1 http://{ip}:8096/health 2>/dev/null", timeout=1.4)
        if sidecar:
            try:
                data = json.loads(sidecar)
                result["status"] = "online"
                result["service"] = "socx-sidecar" if result.get("service") == "node-exporter" else result.get("service", "socx-sidecar")
                result["hostname"] = data.get("hostname") or result.get("name")
                result["name"] = data.get("hostname") or result.get("name")
                result["model"] = data.get("model") or result.get("model")
                result["temperature_c"] = data.get("temperature_c")
                result["load_one"] = (data.get("load") or {}).get("one")
                result["memory_used_pct"] = (data.get("memory") or {}).get("used_pct")
                result["uptime_seconds"] = data.get("uptime_seconds")
                return self.decorate_pi_node(result)
            except Exception:
                pass
        metrics = run_cmd(f"curl -fsS --max-time 1 http://{ip}:9100/metrics 2>/dev/null | head -900", timeout=1.4)
        if metrics:
            result["status"] = "online"
            uname = re.search(r'^node_uname_info\{([^}]*)\}', metrics, re.M)
            if uname:
                name = re.search(r'nodename="([^"]+)"', uname.group(1))
                if name:
                    result["name"] = name.group(1)
            boot = re.search(r"^node_boot_time_seconds\s+([0-9.eE+-]+)", metrics, re.M)
            if boot:
                try:
                    result["uptime_seconds"] = int(max(0, time.time() - float(boot.group(1))))
                except ValueError:
                    pass
            mem_total = re.search(r"^node_memory_MemTotal_bytes\s+([0-9.eE+-]+)", metrics, re.M)
            mem_avail = re.search(r"^node_memory_MemAvailable_bytes\s+([0-9.eE+-]+)", metrics, re.M)
            if mem_total and mem_avail:
                try:
                    total = float(mem_total.group(1))
                    avail = float(mem_avail.group(1))
                    result["memory_used_pct"] = round((1 - avail / total) * 100, 1) if total else None
                except ValueError:
                    pass
            temp = re.search(r"^node_(?:thermal_zone_temp|hwmon_temp)_celsius(?:\{[^}]*\})?\s+([0-9.eE+-]+)", metrics, re.M)
            if temp:
                try:
                    result["temperature_c"] = round(float(temp.group(1)), 1)
                except ValueError:
                    pass
            return self.decorate_pi_node(result)
        if result.get("status") != "online":
            result["status"] = "offline"
        return self.decorate_pi_node(result)

    def decorate_pi_node(self, node: dict[str, Any]) -> dict[str, Any]:
        result = dict(node)
        temp = result.get("temperature_c")
        try:
            temp_c = float(temp)
            result["temperature_f"] = round((temp_c * 9 / 5) + 32, 1)
            result["temperature_h"] = f"{temp_c:.0f}C/{result['temperature_f']:.0f}F"
        except (TypeError, ValueError):
            result["temperature_h"] = "--"
        try:
            result["memory_h"] = f"{float(result.get('memory_used_pct')):.0f}%"
        except (TypeError, ValueError):
            result["memory_h"] = "--"
        try:
            result["load_h"] = f"{float(result.get('load_one')):.2f}"
        except (TypeError, ValueError):
            result["load_h"] = "--"
        result["uptime_h"] = human_duration(result.get("uptime_seconds"))
        role = str(result.get("role") or "pi").replace("pi-", "").replace("-", " ").upper()
        if result.get("roles_online"):
            role = f"AI {result.get('roles_online')}"
        result["role_h"] = role
        service = str(result.get("service") or "")
        extras = []
        if result.get("hailo_models") not in {None, ""}:
            extras.append(f"Hailo {result.get('hailo_models')}")
        if result.get("cpu_models") not in {None, ""}:
            extras.append(f"CPU {result.get('cpu_models')}")
        if result.get("experiments_total") not in {None, ""}:
            extras.append(f"EXP {result.get('experiments_ok', 0)}/{result.get('experiments_total')}")
        result["service_h"] = " ".join([service, *extras]).strip() or "--"
        return result

    def collect_incident_light(self, center: dict[str, Any] | None = None) -> dict[str, Any]:
        incident = self.collect_incident(center=center, sample_limit=280)
        counts = {
            "sources": sum(int(item.get("count", 0) or 0) for item in incident.get("blocked_sources", [])),
            "dnsbl": sum(int(item.get("count", 0) or 0) for item in incident.get("dnsbl_domains", [])),
            "lan": len(incident.get("lan_hosts", [])),
            "ids_high": int((incident.get("ids") or {}).get("high_signal", 0) or 0),
        }
        mode = str(incident.get("mode") or "WATCH").upper()
        if counts["ids_high"] > 0 or counts["sources"] > 80:
            verdict = "INVESTIGATE"
        elif counts["sources"] > 0 or counts["dnsbl"] > 0:
            verdict = "WATCH"
        elif mode in {"INCIDENT", "SECURITY WATCH", "INVESTIGATE"}:
            verdict = mode
        else:
            verdict = "QUIET"
        top_src = incident.get("blocked_sources", [{}])[0].get("name", "none") if incident.get("blocked_sources") else "none"
        top_port = incident.get("blocked_ports", [{}])[0].get("name", "none") if incident.get("blocked_ports") else "none"
        return {
            **incident,
            "verdict": verdict,
            "counts": counts,
            "headline": f"{verdict}: top source {top_src}, top port {top_port}",
            "updated_ms": now_ms(),
        }

    def collect_threat_pulse(self, incident: dict[str, Any]) -> dict[str, Any]:
        counts = incident.get("counts") if isinstance(incident.get("counts"), dict) else {}
        fw = int(counts.get("sources", 0) or 0)
        dnsbl = int(counts.get("dnsbl", 0) or 0)
        ids_high = int(counts.get("ids_high", 0) or 0)
        ids = incident.get("ids") if isinstance(incident.get("ids"), dict) else {}
        ids_routine = int(ids.get("routine", 0) or 0)
        ports = incident.get("blocked_ports") if isinstance(incident.get("blocked_ports"), list) else []
        domains = incident.get("dnsbl_domains") if isinstance(incident.get("dnsbl_domains"), list) else []
        sources = incident.get("blocked_sources") if isinstance(incident.get("blocked_sources"), list) else []
        top_port = ports[0] if ports else {}
        top_domain = domains[0] if domains else {}
        top_source = sources[0] if sources else {}
        score = 100
        score -= min(35, fw // 8)
        score -= min(25, dnsbl // 50)
        score -= min(30, ids_high * 12)
        score -= min(12, ids_routine // 60)
        score = max(0, score)
        if ids_high > 0 or fw >= 180:
            label = "INVESTIGATE"
            severity = "red"
        elif fw > 0 or dnsbl > 0 or ids_routine > 0:
            label = "WATCH"
            severity = "yellow"
        else:
            label = "QUIET"
            severity = "green"
        return {
            "label": label,
            "severity": severity,
            "score": score,
            "fw_blocks": fw,
            "dnsbl_hits": dnsbl,
            "ids_high": ids_high,
            "ids_watch": ids_routine,
            "top_port": top_port.get("name", "--"),
            "top_port_count": top_port.get("count", 0),
            "top_domain": top_domain.get("name", "--"),
            "top_domain_count": top_domain.get("count", 0),
            "top_source": top_source.get("name", "--"),
            "top_source_count": top_source.get("count", 0),
        }

    def collect_asset_watch(self, flows: list[dict[str, Any]], pi_nodes: dict[str, Any], label_brain: dict[str, Any] | None = None) -> dict[str, Any]:
        label_brain = label_brain or {"devices": [], "top_apps": []}
        audit_path = Path(os.environ.get("SOCX_HOSTS_AUDIT_OUT", "/tmp/socx-hosts-audit.txt"))
        unknown_log = Path(os.environ.get("SOCX_UNKNOWN_SERVICES_LOG", "/var/db/socx_unknown_services.log"))
        audit = ""
        try:
            audit = audit_path.read_text(errors="ignore")
        except Exception:
            pass
        known_ips: set[str] = set()
        unknown_ips: set[str] = set()
        in_observed = False
        for line in audit.splitlines():
            if line.strip().lower().startswith("observed lan devices"):
                in_observed = True
                continue
            if not in_observed:
                continue
            if line.strip().lower().startswith(("suggested additions", "suggested corrections")):
                break
            if not line.strip() or line.lstrip().startswith("#"):
                continue
            parts = line.split()
            if len(parts) < 4 or not parts[0].startswith("192.168.1."):
                continue
            state = parts[3].upper()
            if state == "KNOWN":
                known_ips.add(parts[0])
                unknown_ips.discard(parts[0])
            elif state == "UNKNOWN" and parts[0] not in known_ips:
                unknown_ips.add(parts[0])
        known = len(known_ips)
        unknown = len(unknown_ips)
        unknown_bytes = 0
        try:
            unknown_bytes = unknown_log.stat().st_size
        except Exception:
            pass
        top_assets: dict[str, int] = {}
        top_services: dict[str, int] = {}
        for flow in flows:
            count = int(flow.get("count", 1) or 1)
            asset = str(flow.get("asset", "unknown"))
            svc = str(flow.get("service", "other"))
            top_assets[asset] = top_assets.get(asset, 0) + count
            top_services[svc] = top_services.get(svc, 0) + count
        rank = lambda data: [{"name": k, "count": v} for k, v in sorted(data.items(), key=lambda kv: kv[1], reverse=True)[:4]]
        pi_count = int(pi_nodes.get("count", 0) or 0)
        pi_online = int(pi_nodes.get("online", 0) or 0)
        top_asset = rank(top_assets)
        top_service = rank(top_services)
        top_app = (label_brain.get("top_apps") if isinstance(label_brain.get("top_apps"), list) else [])[:4]
        devices = (label_brain.get("devices") if isinstance(label_brain.get("devices"), list) else [])[:5]
        status = "green" if unknown_bytes < 50000 and pi_online == pi_count else "yellow"
        if pi_count and pi_online < pi_count:
            status = "red"
        headline = f"Pi {pi_online}/{pi_count} | unknown learner {human_bytes(unknown_bytes)}"
        if top_app:
            headline = f"Now: {top_app[0].get('name', '--')} | {headline}"
        return {
            "status": status,
            "known": known,
            "unknown": unknown,
            "unknown_bytes": unknown_bytes,
            "unknown_h": human_bytes(unknown_bytes),
            "pi_online": pi_online,
            "pi_count": pi_count,
            "top_assets": top_asset,
            "top_services": top_service,
            "top_apps": top_app,
            "devices": devices,
            "headline": headline,
        }

    def collect_label_brain(self, flows: list[dict[str, Any]], incident: dict[str, Any]) -> dict[str, Any]:
        """Build a local, passive label model from pf states and DNS/DNSBL evidence."""
        devices: dict[str, dict[str, Any]] = {}
        apps: dict[str, int] = {}

        def asset_row(name: str) -> dict[str, Any]:
            if name not in devices:
                devices[name] = {"asset": name, "flows": 0, "services": {}, "apps": {}, "domains": {}, "confidence": "low"}
            return devices[name]

        for flow in flows:
            asset = str(flow.get("asset") or "unknown")
            row = asset_row(asset)
            count = int(flow.get("count", 1) or 1)
            row["flows"] += count
            svc = str(flow.get("service") or "other")
            row["services"][svc] = row["services"].get(svc, 0) + count
            app = app_label(str(flow.get("peer") or "")) or app_label(svc)
            if app:
                row["apps"][app] = row["apps"].get(app, 0) + count
                apps[app] = apps.get(app, 0) + count

        dns_sources = self.collect_recent_dns_labels()
        for src, entries in dns_sources.items():
            asset = self.pretty_host(src)
            row = asset_row(asset)
            for entry in entries:
                app = str(entry.get("app") or "")
                domain = str(entry.get("domain") or "")
                count = int(entry.get("count", 1) or 1)
                if app:
                    row["apps"][app] = row["apps"].get(app, 0) + count
                    apps[app] = apps.get(app, 0) + count
                if domain:
                    row["domains"][domain] = row["domains"].get(domain, 0) + count

        for item in incident.get("dnsbl_domains", []) if isinstance(incident.get("dnsbl_domains"), list) else []:
            name = str(item.get("name") or "")
            raw = str(item.get("raw") or name)
            count = int(item.get("count", 1) or 1)
            app = app_label(name) or app_label(raw)
            if app:
                apps[app] = apps.get(app, 0) + count

        def rank_map(data: dict[str, int], limit: int = 4) -> list[dict[str, Any]]:
            return [{"name": k, "count": v} for k, v in sorted(data.items(), key=lambda kv: kv[1], reverse=True)[:limit]]

        rows: list[dict[str, Any]] = []
        for row in devices.values():
            app_rank = rank_map(row["apps"], 4)
            service_rank = rank_map(row["services"], 3)
            domain_rank = rank_map(row["domains"], 3)
            confidence = "high" if app_rank and row["flows"] else ("medium" if app_rank or row["flows"] >= 3 else "low")
            rows.append({
                "asset": row["asset"],
                "flows": row["flows"],
                "apps": app_rank,
                "services": service_rank,
                "domains": domain_rank,
                "confidence": confidence,
                "summary": self.device_identity_summary(row["asset"], app_rank, service_rank, confidence),
            })
        rows.sort(key=lambda item: (len(item.get("apps", [])), int(item.get("flows", 0))), reverse=True)
        return {
            "mode": "local-passive",
            "privacy": "local DNS, pf states, DNSBL and host labels only",
            "devices": rows[:10],
            "top_apps": rank_map(apps, 8),
            "updated_ms": now_ms(),
        }

    def collect_recent_dns_labels(self) -> dict[str, list[dict[str, Any]]]:
        logs = "/var/log/pfblockerng/dnsbl.log /var/log/pfblockerng/dns_reply.log /var/log/resolver.log"
        out = run_cmd(f"tail -n 2200 {logs} 2>/dev/null | tail -900", timeout=1.0)
        seen: dict[str, dict[str, int]] = {}
        for line in out.splitlines():
            ips = re.findall(r"\b(192\.168\.1\.\d+)\b", line)
            if not ips:
                continue
            domains = [token.strip(".").lower() for token in re.findall(r"([a-z0-9][a-z0-9._-]+\.[a-z][a-z0-9.-]+)", line.lower())]
            for domain in domains:
                if not self.useful_domain(domain):
                    continue
                app = app_label(domain)
                if not app:
                    continue
                for ip in ips[:2]:
                    key = f"{app}|{domain}"
                    seen.setdefault(ip, {})[key] = seen.setdefault(ip, {}).get(key, 0) + 1
        result: dict[str, list[dict[str, Any]]] = {}
        for ip, counts in seen.items():
            result[ip] = [
                {"app": key.split("|", 1)[0], "domain": key.split("|", 1)[1], "count": count}
                for key, count in sorted(counts.items(), key=lambda kv: kv[1], reverse=True)[:8]
            ]
        return result

    def useful_domain(self, domain: str) -> bool:
        if len(domain) < 6 or domain.endswith((".arpa", ".local", ".lan")):
            return False
        noisy = {"log", "local", "home", "localhost"}
        return domain not in noisy

    def device_identity_summary(self, asset: str, apps: list[dict[str, Any]], services: list[dict[str, Any]], confidence: str) -> str:
        app_text = ", ".join(str(item.get("name")) for item in apps[:3]) if apps else ""
        svc_text = ", ".join(str(item.get("name")) for item in services[:2]) if services else "quiet"
        if app_text:
            return f"{asset}: {app_text} via {svc_text} ({confidence})"
        return f"{asset}: {svc_text} traffic ({confidence})"

    def enrich_flows_with_labels(self, flows: list[dict[str, Any]], label_brain: dict[str, Any]) -> list[dict[str, Any]]:
        by_asset: dict[str, dict[str, Any]] = {}
        for device in label_brain.get("devices", []) if isinstance(label_brain.get("devices"), list) else []:
            by_asset[str(device.get("asset") or "")] = device
        enriched: list[dict[str, Any]] = []
        for flow in flows:
            item = dict(flow)
            device = by_asset.get(str(item.get("asset") or ""))
            apps = device.get("apps", []) if isinstance(device, dict) and isinstance(device.get("apps"), list) else []
            if apps and str(item.get("service") or "").lower() in {"https", "tls", "http", "web", "port 443", "port443"}:
                item["app_hint"] = apps[0].get("name")
                item["confidence"] = device.get("confidence", "medium")
            enriched.append(item)
        return enriched

    def collect_ai_timeline(self, pi_nodes: dict[str, Any], center: dict[str, Any]) -> dict[str, Any]:
        rows: list[dict[str, Any]] = []
        pi_cache = parse_env_file(Path(os.environ.get("SOCX_PI_LLM_ANALYSIS_CACHE", "/tmp/socx-pi-llm-analysis.env")))
        miranda_cache = parse_env_file(Path(os.environ.get("SOCX_MIRANDA_ANALYSIS_CACHE", "/tmp/socx-miranda-analysis.env")))

        def env_row(source: str, data: dict[str, str]) -> None:
            if not data:
                return
            severity = (data.get("severity") or data.get("status") or "UNKNOWN").upper()
            confidence = data.get("confidence") or data.get("score") or ""
            reason = data.get("reason") or data.get("summary") or data.get("message") or "analysis cache present"
            age = 0
            try:
                updated = float(data.get("updated", "0") or data.get("ts", "0") or 0)
                age = int(max(0, time.time() - updated)) if updated else 0
            except ValueError:
                age = 0
            rows.append({
                "source": source,
                "severity": severity,
                "confidence": confidence,
                "reason": reason[:180],
                "age_sec": age,
                "age_h": human_duration(age) if age else "fresh",
            })

        env_row("Pi AI", pi_cache)
        env_row("MIRANDA", miranda_cache)
        for node in (pi_nodes.get("nodes") if isinstance(pi_nodes.get("nodes"), list) else []):
            if not isinstance(node, dict) or not node.get("autonomy_mode"):
                continue
            rows.append({
                "source": node.get("name") or "Pi",
                "severity": str(node.get("autonomy_mode") or "watch").upper(),
                "confidence": node.get("autonomy_score", ""),
                "reason": str(node.get("autonomy_summary") or node.get("service_h") or "AI node online")[:180],
                "age_sec": 0,
                "age_h": "live",
            })
        rows.append({
            "source": "Autopilot",
            "severity": str(center.get("mode") or "UNKNOWN").upper(),
            "confidence": center.get("score", ""),
            "reason": str(center.get("summary") or "SOCX command center ready")[:180],
            "age_sec": 0,
            "age_h": "live",
        })
        return {"rows": rows[:6], "count": len(rows)}

    def history_trend(self, rows: list[dict[str, Any]]) -> dict[str, Any]:
        if not rows:
            return {"label": "no history", "score_avg": "", "direct_avg": "", "vpn_avg": "", "network_avg": "", "security_avg": "", "ai_avg": "", "sensor_avg": ""}
        def avg(key: str, nonzero: bool = False) -> float:
            vals = [float(row.get(key, 0) or 0) for row in rows]
            if nonzero:
                vals = [v for v in vals if v > 0]
            return sum(vals) / max(1, len(vals))
        first = float(rows[0].get("score", 0) or 0)
        last = float(rows[-1].get("score", 0) or 0)
        if last - first > 2:
            label = "rising"
        elif first - last > 2:
            label = "falling"
        else:
            label = "stable"
        return {
            "label": label,
            "score_avg": round(avg("score")),
            "direct_avg": round(avg("direct_down")),
            "vpn_avg": round(avg("vpn_down")),
            "network_avg": round(avg("network_score", True)),
            "security_avg": round(avg("security_score", True)),
            "ai_avg": round(avg("ai_score", True)),
            "sensor_avg": round(avg("sensor_score", True)),
        }

    def collect_history(self, limit: int = 120) -> list[dict[str, Any]]:
        history_path = Path(os.environ.get("SOCX_HISTORY_FILE", "/var/db/socx_history.jsonl"))
        rows: list[dict[str, Any]] = []
        try:
            for raw in history_path.read_text(errors="ignore").splitlines()[-limit:]:
                try:
                    rows.append(json.loads(raw))
                except Exception:
                    continue
        except Exception:
            pass
        return rows

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
                "service": app_label(dst) or service_name(port),
                "port": port,
                "state": "ESTABLISHED" if "ESTABLISHED" in line else "ACTIVE",
                "count": 1,
                "tag": self.flow_tag(dst, port),
            }
        return list(seen.values())[:14]

    def collect_top_talkers(self) -> dict[str, Any]:
        flows = self.collect_flows()
        assets: dict[str, int] = {}
        peers: dict[str, int] = {}
        services: dict[str, int] = {}
        for item in flows:
            count = int(item.get("count", 1) or 1)
            assets[str(item.get("asset", "unknown"))] = assets.get(str(item.get("asset", "unknown")), 0) + count
            peers[str(item.get("peer", "unknown"))] = peers.get(str(item.get("peer", "unknown")), 0) + count
            services[str(item.get("service", "other"))] = services.get(str(item.get("service", "other")), 0) + count
        rank = lambda data: [{"name": k, "count": v} for k, v in sorted(data.items(), key=lambda kv: kv[1], reverse=True)[:10]]
        return {"assets": rank(assets), "peers": rank(peers), "services": rank(services), "flows": flows[:20]}

    def collect_incident(self, center: dict[str, Any] | None = None, sample_limit: int = 300) -> dict[str, Any]:
        center = center or self.collect_command_center()
        limit = max(80, min(1200, int(sample_limit)))
        filter_log = run_cmd(f"tail -n 1600 /var/log/filter.log 2>/dev/null | grep -Ei 'block|drop|reject' | tail -{limit}", timeout=1.0)
        dns_log = run_cmd(f"tail -n 1600 /var/log/pfblockerng/dnsbl.log /var/log/pfblockerng/dns_reply.log 2>/dev/null | tail -{limit}", timeout=1.0)
        ids_log = run_cmd("find /var/log/suricata -maxdepth 2 -type f \\( -name alerts.log -o -name fast.log \\) 2>/dev/null | xargs tail -500 2>/dev/null", timeout=1.0)

        src_counts: dict[str, int] = {}
        port_counts: dict[str, int] = {}
        lan_counts: dict[str, int] = {}
        for line in filter_log.splitlines():
            payload = line.split(": ", 1)[1] if ": " in line else line
            try:
                fields = next(csv.reader([payload]))
            except Exception:
                continue
            if len(fields) < 22 or fields[8] != "4":
                continue
            src = fields[18]
            dst = fields[19]
            dport = fields[21] if fields[21].isdigit() else ""
            if re.match(r"^\d+\.\d+\.\d+\.\d+$", src):
                src_counts[src] = src_counts.get(src, 0) + 1
            if dport:
                port_counts[dport] = port_counts.get(dport, 0) + 1
            for host in (src, dst):
                if host.startswith("192.168.1."):
                    lan_counts[host] = lan_counts.get(host, 0) + 1

        def rank(counts: dict[str, int], limit: int = 8) -> list[dict[str, Any]]:
            return [{"name": k, "count": v} for k, v in sorted(counts.items(), key=lambda kv: kv[1], reverse=True)[:limit]]

        domains: dict[str, int] = {}
        for line in dns_log.splitlines():
            for token in re.findall(r"([a-z0-9][a-z0-9._-]+\.[a-z][a-z0-9.-]+)", line.lower()):
                if len(token) > 5:
                    domains[token.strip(".")] = domains.get(token.strip("."), 0) + 1
        ids_lines = [line for line in ids_log.splitlines() if line.strip()]
        ids_high = sum(1 for line in ids_lines if is_high_signal_ids(line))
        ids_routine = sum(1 for line in ids_lines if is_routine_ids(line))
        return {
            "mode": center.get("mode"),
            "score": center.get("score"),
            "summary": center.get("summary"),
            "generated_ms": now_ms(),
            "blocked_sources": rank(src_counts),
            "blocked_ports": rank(port_counts),
            "lan_hosts": rank(lan_counts),
            "dnsbl_domains": [{"name": app_label(k) or k, "raw": k, "count": v} for k, v in sorted(domains.items(), key=lambda kv: kv[1], reverse=True)[:8]],
            "ids": {"high_signal": ids_high, "routine": ids_routine},
            "actions": [
                "socx snapshot",
                "socx incident-mode 120",
                "socx-doctor dnsbl-review",
                "socx tune ids",
                "socx pi-llm",
            ],
        }

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
        service = app_label(dst) or service
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
        label = app_label(domain)
        if label:
            text = f"[DNSBL][LOW] {self.pretty_host(src)} -> {label} blocked ({domain})"
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
                "scores": {"network": "94", "security": "76", "ai": "100", "sensors": "96"},
                "summary": "WAN healthy, VPN paths mixed, DNSBL routine.",
                "direct": {"label": "DIRECT", "status": "OK", "down": "3223", "up": "2372", "ping": "9", "age_sec": 900},
                "vpn": {"label": "VPN", "status": "OK", "down": "740", "up": "510", "ping": "52", "age_sec": 980},
                "vpn_paths": [
                    {"label": "NYC", "status": "OK", "down": "586", "up": "753", "ping": "19", "age_sec": 980},
                    {"label": "RCN-DE", "status": "OK", "down": "740", "up": "510", "ping": "52", "age_sec": 980},
                ],
                "history_count": 8,
                "history_trend": {"label": "stable", "score_avg": 82, "direct_avg": 3223, "vpn_avg": 740},
                "history": [],
                "actions": ["socx status", "socx timeline 60", "socx repair", "socx explain-screen"],
            },
            "pi_nodes": {
                "updated": int(time.time()),
                "count": 2,
                "online": 2,
                "score": 100,
                "summary": "2/2 Pi nodes online",
                "age_sec": 0,
                "nodes": [
                    {"ip": "192.168.1.121", "name": "RaspberryPi5", "model": "Raspberry Pi 5", "role": "pi-5-ai", "role_h": "AI 3/3", "status": "online", "service": "socx-3llm", "service_h": "socx-3llm Hailo 4 CPU 3 EXP 3/3", "temperature_h": "41C/106F", "memory_h": "9%", "load_h": "0.04", "uptime_h": "--", "autonomy_mode": "watch", "autonomy_score": 53, "autonomy_summary": "WATCH score 53 | FW 2000 DNSBL 2000 IDS watch 300", "ports": "22,8095"},
                    {"ip": "192.168.1.180", "name": "ColumbiaPi4", "model": "Raspberry Pi 4", "role": "pi-4-telemetry", "role_h": "TELEMETRY", "status": "online", "service": "socx-sidecar", "service_h": "socx-sidecar", "temperature_h": "36C/96F", "memory_h": "12%", "load_h": "0.03", "uptime_h": "121d 12h", "ports": "22,9100"},
                ],
            },
            "incident": {
                "verdict": "WATCH",
                "headline": "WATCH: top source EXT.217.142, top port 443",
                "counts": {"sources": 22, "dnsbl": 7, "lan": 2, "ids_high": 1},
                "blocked_sources": [{"name": "217.142.11.40", "count": 9}, {"name": "147.185.133.70", "count": 6}],
                "blocked_ports": [{"name": "443", "count": 11}, {"name": "23", "count": 4}],
                "lan_hosts": [{"name": "192.168.1.161", "count": 8}],
                "dnsbl_domains": [{"name": "beacons.gvt2.com", "count": 7}],
                "ids": {"high_signal": 1, "routine": 12},
                "actions": ["socx snapshot", "socx incident-mode 120", "socx pi-llm"],
                "updated_ms": now_ms(),
            },
            "threat_pulse": {
                "label": "WATCH",
                "severity": "yellow",
                "score": 82,
                "fw_blocks": 22,
                "dnsbl_hits": 7,
                "ids_high": 1,
                "ids_watch": 12,
                "top_port": "443",
                "top_port_count": 11,
                "top_source": "217.142.11.40",
                "top_source_count": 9,
                "top_domain": "beacons.gvt2.com",
                "top_domain_count": 7,
            },
            "asset_watch": {
                "status": "green",
                "known": 18,
                "unknown": 2,
                "unknown_bytes": 2048,
                "unknown_h": "2.00KB",
                "pi_online": 2,
                "pi_count": 2,
                "headline": "Pi 2/2 | unknown learner 2.00KB",
                "top_assets": [{"name": "JupiterLXI/192.168.1.161", "count": 3}],
                "top_services": [{"name": "https", "count": 3}],
            },
            "ai_timeline": {
                "count": 3,
                "rows": [
                    {"source": "Pi AI", "severity": "WARN", "confidence": "91", "reason": "Routine watch, preserve incident bundle before changes", "age_h": "2m"},
                    {"source": "MIRANDA", "severity": "WARN", "confidence": "88", "reason": "DNSBL/FW watch signals elevated", "age_h": "live"},
                    {"source": "Autopilot", "severity": "WATCH", "confidence": "82", "reason": "WAN healthy, VPN paths mixed, DNSBL routine", "age_h": "live"},
                ],
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
        if parsed.path == "/api/history":
            rows = self.collector.collect_history(240)
            self.send_json({"count": len(rows), "trend": self.collector.history_trend(rows), "rows": rows})
            return
        if parsed.path == "/api/top-talkers":
            self.send_json(self.collector.collect_top_talkers())
            return
        if parsed.path == "/api/incident":
            self.send_json(self.collector.collect_incident())
            return
        if parsed.path == "/api/config":
            self.send_json({"refresh_ms": int(self.collector.interval * 1000), "demo": self.collector.demo})
            return
        self.serve_static(parsed.path)

    def do_POST(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path != "/api/commander":
            self.send_error(404)
            return
        try:
            length = int(self.headers.get("Content-Length", "0") or "0")
        except ValueError:
            length = 0
        raw = self.rfile.read(min(length, 4096)) if length else b"{}"
        try:
            data = json.loads(raw.decode("utf-8", "replace"))
        except Exception:
            data = {}
        action = str(data.get("action", "")).strip().lower()
        self.send_json(self.run_commander_action(action))

    def run_commander_action(self, action: str) -> dict[str, Any]:
        commands: dict[str, tuple[list[str], float, str]] = {
            "snapshot": (["/usr/local/bin/socx", "snapshot"], 90.0, "Evidence snapshot"),
            "incident": (["/usr/local/bin/socx", "incident-mode", "120"], 25.0, "Incident Mode"),
            "zeek": (["/usr/local/bin/socx-doctor", "zeek"], 25.0, "Zeek health"),
            "speedtest": (["/usr/local/bin/socx-doctor", "speedtest-profiles"], 25.0, "Speedtest profiles"),
            "pi": (["/usr/local/bin/socx", "pi-llm"], 330.0, "Pi 3-LLM analysis"),
            "status": (["/usr/local/bin/socx", "status"], 25.0, "SOCX status"),
        }
        if action not in commands:
            return {"ok": False, "action": action, "title": "Unknown action", "output": "Allowed: snapshot, incident, zeek, speedtest, pi, status"}
        args, timeout, title = commands[action]
        result = run_cmd_capture(args, timeout=timeout)
        return {"action": action, "title": title, **result}

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
