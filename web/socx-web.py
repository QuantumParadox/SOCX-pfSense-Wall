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
from urllib.parse import parse_qs, urlencode, urlparse
from urllib.request import Request, urlopen


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


def post_json(url: str, payload: dict[str, Any], timeout: float = 30.0) -> dict[str, Any]:
    body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    req = Request(url, data=body, headers={"Content-Type": "application/json"}, method="POST")
    with urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8", "replace")
    return json.loads(raw)


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
        (r"github|githubusercontent|githubassets", "GitHub"),
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
        self.change_last: dict[str, str] = {}
        self.change_events: deque[dict[str, Any]] = deque(maxlen=36)
        self.release_health_checked = 0.0
        self.release_health_cache: dict[str, Any] = {}
        self.observability_checked = 0.0
        self.observability_cache: dict[str, Any] = {}
        self.metrics_intel_checked = 0.0
        self.metrics_intel_cache: dict[str, Any] = {}
        self.autonomy_loop_checked = 0.0
        self.autonomy_loop_cache: dict[str, Any] = {}
        self.ups_samples: deque[float] = deque(maxlen=120)
        self.label_history_path = Path(os.environ.get("SOCX_LABEL_BRAIN_HISTORY", "/var/db/socx_label_brain.json"))
        self.label_history_last_write = 0.0
        self.label_history_cache: dict[str, Any] = self.load_label_history()
        self.last_incident_memory_write = 0.0
        self.kev_memory: dict[str, Any] = {"checked": 0.0, "data": {}, "error": ""}
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

    def load_label_history(self) -> dict[str, Any]:
        try:
            data = json.loads(self.label_history_path.read_text(errors="ignore"))
            if isinstance(data, dict):
                data.setdefault("devices", {})
                data.setdefault("updated", 0)
                return data
        except Exception:
            pass
        return {"updated": 0, "devices": {}}

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
        hardware = self.collect_hardware_health(cpu)
        processes = self.collect_processes()
        command_center = self.collect_command_center(hardware)
        observability = self.collect_observability()
        metrics_intel = self.collect_metrics_intel()
        power_mods = self.collect_power_mods()
        autonomy_loop = self.collect_autonomy_loop()
        incident = self.collect_incident_light(command_center)
        pi_nodes = self.collect_pi_nodes()
        threat_pulse = self.collect_threat_pulse(incident)
        flows = self.collect_flows()
        netflow_intel = self.collect_netflow_intel()
        label_brain = self.collect_label_brain(flows, incident)
        flows = self.enrich_flows_with_labels(flows, label_brain)
        device_trust = self.collect_device_trust(flows, label_brain, netflow_intel, incident, pi_nodes)
        asset_watch = self.collect_asset_watch(flows, pi_nodes, label_brain)
        ai_timeline = self.collect_ai_timeline(pi_nodes, command_center)
        incident_timeline = self.collect_incident_timeline(incident, label_brain, ai_timeline)
        daily_brief = self.collect_daily_brief(command_center, incident, label_brain, pi_nodes, ai_timeline, metrics_intel)
        rule_assistant = self.collect_rule_assistant(incident, label_brain)
        speedtest_history = self.collect_speedtest_history()
        incident_memory = self.collect_incident_memory()
        self.maybe_append_incident_memory()
        data_truth = self.collect_data_truth(command_center, pi_nodes, ups, incident_memory, label_brain, observability)
        what_changed = self.collect_what_changed(command_center, incident, label_brain, pi_nodes, net, ups, data_truth, flows)
        intel = self.collect_intel_layer(incident, label_brain, flows, hardware)
        mission = self.collect_mission(command_center, incident, label_brain, pi_nodes, ai_timeline, hardware, data_truth, what_changed, flows, intel)
        mission_assurance = self.collect_mission_assurance(command_center, data_truth, incident, power_mods, netflow_intel, device_trust, pi_nodes, hardware)

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
            "hardware": hardware,
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
            "observability": observability,
            "metrics_intel": metrics_intel,
            "power_mods": power_mods,
            "autonomy_loop": autonomy_loop,
            "incident": incident,
            "pi_nodes": pi_nodes,
            "threat_pulse": threat_pulse,
            "mission_assurance": mission_assurance,
            "asset_watch": asset_watch,
            "label_brain": label_brain,
            "device_trust": device_trust,
            "ai_timeline": ai_timeline,
            "incident_timeline": incident_timeline,
            "daily_brief": daily_brief,
            "rule_assistant": rule_assistant,
            "speedtest_history": speedtest_history,
            "incident_memory": incident_memory,
            "data_truth": data_truth,
            "what_changed": what_changed,
            "intel": intel,
            "mission": mission,
            "netflow_intel": netflow_intel,
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

    def collect_hardware_health(self, cpu: dict[str, Any]) -> dict[str, Any]:
        profile = parse_env_file(Path(os.environ.get("SOCX_SYSTEM_PROFILE", "/usr/local/etc/socx_system_profile.env")))
        model = run_cmd("sysctl -n hw.model 2>/dev/null", timeout=0.4).strip()
        ncpu = run_cmd("sysctl -n hw.ncpu 2>/dev/null", timeout=0.4).strip()
        bios_version = profile.get("SOCX_BIOS_VERSION") or run_cmd("kenv smbios.bios.version 2>/dev/null", timeout=0.4).strip().strip('"')
        bios_date = profile.get("SOCX_BIOS_RELEASE_DATE") or run_cmd("kenv smbios.bios.reldate 2>/dev/null", timeout=0.4).strip().strip('"')
        temp_out = run_cmd("sysctl -a 2>/dev/null | egrep '^dev.cpu\\.[0-9]+\\.temperature:'", timeout=0.7)
        temps: list[dict[str, Any]] = []
        for line in temp_out.splitlines():
            match = re.search(r"dev\.cpu\.(\d+)\.temperature:\s*([0-9.]+)C", line)
            if not match:
                continue
            celsius = float(match.group(2))
            temps.append({"id": int(match.group(1)), "c": celsius, "f": round((celsius * 9 / 5) + 32, 1)})
        temps.sort(key=lambda item: item["id"])
        max_temp = max((float(item["c"]) for item in temps), default=float(cpu.get("temp_c") or 0))
        avg_temp = round(sum(float(item["c"]) for item in temps) / len(temps), 1) if temps else None
        aesni_out = run_cmd("kldstat 2>/dev/null | grep aesni; dmesg 2>/dev/null | grep -i aesni | tail -5", timeout=0.6)
        aesni = "aesni" in aesni_out.lower()
        powerd_status = run_cmd("service powerd status 2>/dev/null", timeout=0.6).strip()
        cx = run_cmd("sysctl -n dev.cpu.0.cx_lowest 2>/dev/null", timeout=0.4).strip()
        warning_c = env_int("SOCX_CPU_WARN_C", 75)
        critical_c = env_int("SOCX_CPU_CRIT_C", 85)
        if max_temp >= critical_c:
            tone = "red"
            status = "critical"
        elif max_temp >= warning_c:
            tone = "yellow"
            status = "warm"
        else:
            tone = "green"
            status = "normal"
        cores = profile.get("SOCX_CPU_CORES") or ""
        threads = profile.get("SOCX_CPU_THREADS") or ncpu
        cpu_name = profile.get("SOCX_CPU_MODEL") or model
        freq = cpu.get("freq_mhz")
        max_temp_h = f"{max_temp:.0f}C/{((max_temp * 9 / 5) + 32):.0f}F" if max_temp else "--"
        return {
            "status": status,
            "tone": tone,
            "system_name": profile.get("SOCX_SYSTEM_NAME") or socket.gethostname(),
            "vendor": profile.get("SOCX_SYSTEM_VENDOR", ""),
            "model": profile.get("SOCX_SYSTEM_MODEL", ""),
            "type_model": profile.get("SOCX_SYSTEM_TYPE_MODEL", ""),
            "cpu_model": cpu_name,
            "cpu_sysctl": model,
            "cores": cores,
            "threads": threads,
            "base_ghz": profile.get("SOCX_CPU_BASE_GHZ", ""),
            "tdp_w": profile.get("SOCX_CPU_TDP_W", ""),
            "upgraded_from": profile.get("SOCX_CPU_UPGRADED_FROM", ""),
            "upgrade_date": profile.get("SOCX_CPU_UPGRADE_DATE", ""),
            "bios_version": bios_version,
            "bios_release_date": bios_date,
            "freq_mhz": freq,
            "freq_h": f"{freq} MHz" if freq else "--",
            "max_temp_c": round(max_temp, 1) if max_temp else None,
            "max_temp_f": round((max_temp * 9 / 5) + 32, 1) if max_temp else None,
            "max_temp_h": max_temp_h,
            "avg_temp_c": avg_temp,
            "temps": temps,
            "aesni": aesni,
            "powerd": "running" if "running" in powerd_status.lower() else (powerd_status or "unknown"),
            "cx_lowest": cx or "--",
            "warning_c": warning_c,
            "critical_c": critical_c,
            "summary": f"{cpu_name} {cores}C/{threads}T max {max_temp_h} AES-NI {'on' if aesni else 'unknown'}",
            "trend": self.hardware_trend(max_temp, warning_c),
        }

    def hardware_trend(self, current_temp: float, warning_c: int) -> dict[str, Any]:
        rows = self.collect_history(288)
        temps = [float(row.get("cpu_temp_max") or 0) for row in rows if float(row.get("cpu_temp_max") or 0) > 0]
        watts = [float(row.get("ups_watts") or 0) for row in rows if float(row.get("ups_watts") or 0) > 0]
        headroom = warning_c - float(current_temp or 0)
        trend = "stable"
        if len(temps) >= 4:
            delta = temps[-1] - temps[0]
            if delta >= 3:
                trend = "rising"
            elif delta <= -3:
                trend = "falling"
        return {
            "samples": len(temps),
            "current_c": round(float(current_temp or 0), 1) if current_temp else None,
            "avg_c": round(sum(temps) / len(temps), 1) if temps else None,
            "peak_c": round(max(temps), 1) if temps else None,
            "headroom_c": round(headroom, 1) if current_temp else None,
            "label": trend,
            "ups_avg_watts": round(sum(watts) / len(watts)) if watts else None,
            "ups_peak_watts": round(max(watts)) if watts else None,
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

    def collect_command_center(self, hardware: dict[str, Any] | None = None) -> dict[str, Any]:
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
            "vpn_crypto": self.vpn_crypto_headroom(direct, vpn, auto, hardware or {}),
            "vpn_paths": named_paths,
            "history_count": len(history_rows),
            "history_trend": self.history_trend(history_rows),
            "history": history_rows,
            "actions": actions[:5],
        }

    def collect_observability(self) -> dict[str, Any]:
        now = time.time()
        ttl = env_int("SOCX_OBSERVABILITY_POLL_SECONDS", 8)
        if self.observability_cache and now - self.observability_checked < ttl:
            cached = dict(self.observability_cache)
            cached["age_sec"] = int(max(0, now - float(cached.get("updated") or now)))
            return cached

        cfg = parse_env_file(Path(os.environ.get("SOCX_OBSERVABILITY_CONF", "/usr/local/etc/socx_observability.conf")))
        host = cfg.get("SOCX_OBSERVABILITY_HOST") or os.environ.get("SOCX_OBSERVABILITY_HOST", "ColumbiaPi4")
        grafana_url = (cfg.get("SOCX_GRAFANA_URL") or os.environ.get("SOCX_GRAFANA_URL", "http://192.168.1.180:3000")).rstrip("/")
        influx_url = (cfg.get("SOCX_INFLUX_URL") or os.environ.get("SOCX_INFLUX_URL", "http://192.168.1.180:8086")).rstrip("/")
        influx_db = cfg.get("SOCX_INFLUX_DB") or os.environ.get("SOCX_INFLUX_DB", "pfsense")

        def get_json(url: str, timeout: float = 2.5) -> tuple[bool, dict[str, Any], str]:
            try:
                req = Request(url, headers={"User-Agent": "SOCX/observability"})
                with urlopen(req, timeout=timeout) as resp:
                    raw = resp.read(20000).decode("utf-8", "replace")
                    return 200 <= resp.status < 300, json.loads(raw or "{}"), ""
            except Exception as exc:
                return False, {}, str(exc)[:140]

        def http_ok(url: str, expected: set[int] | None = None, timeout: float = 2.5) -> tuple[bool, str]:
            expected = expected or {200}
            try:
                req = Request(url, headers={"User-Agent": "SOCX/observability"})
                with urlopen(req, timeout=timeout) as resp:
                    return resp.status in expected, f"HTTP {resp.status}"
            except Exception as exc:
                return False, str(exc)[:140]

        grafana_ok, grafana_health, grafana_error = get_json(f"{grafana_url}/api/health")
        influx_ok, influx_error = http_ok(f"{influx_url}/ping", {204})

        measurements: list[str] = []
        latest: dict[str, Any] = {}
        query_url = f"{influx_url}/query?db={influx_db}&q=SHOW%20MEASUREMENTS"
        meas_ok, meas_json, meas_error = get_json(query_url)
        if meas_ok:
            try:
                values = meas_json["results"][0]["series"][0]["values"]
                measurements = [str(row[0]) for row in values if row]
            except Exception:
                measurements = []
        for name, query in {
            "cpu": 'SELECT last("usage_user"),last("usage_system") FROM "cpu"',
            "mem": 'SELECT last("used_percent") FROM "mem"',
            "pf": 'SELECT last("entries"),last("searches") FROM "pf"',
            "ping": 'SELECT last("average_response_ms") FROM "ping"',
        }.items():
            encoded = query.replace(" ", "%20").replace('"', "%22").replace(",", "%2C")
            ok, data, _ = get_json(f"{influx_url}/query?db={influx_db}&q={encoded}", timeout=2.5)
            if not ok:
                continue
            try:
                series = data["results"][0]["series"][0]
                cols = series.get("columns", [])
                vals = series.get("values", [[]])[0]
                latest[name] = {str(col): vals[idx] for idx, col in enumerate(cols) if idx < len(vals)}
            except Exception:
                continue

        core = {"cpu", "mem", "net", "pf", "ping", "system"}
        core_present = sorted(core.intersection(set(measurements)))
        telegraf_target = ""
        try:
            conf = Path("/usr/local/etc/telegraf.conf").read_text(errors="ignore")
            match = re.search(r'urls\s*=\s*\["([^"]+)"\]', conf)
            telegraf_target = match.group(1) if match else ""
        except Exception:
            pass
        telegraf_ok = bool(telegraf_target == influx_url)

        if grafana_ok and influx_ok and len(core_present) >= 4 and telegraf_ok:
            label = "LIVE"
            tone = "green"
        elif grafana_ok or influx_ok or core_present:
            label = "WATCH"
            tone = "yellow"
        else:
            label = "DOWN"
            tone = "red"

        errors = [item for item in [grafana_error, influx_error if not influx_ok else "", meas_error if not meas_ok else ""] if item]
        result = {
            "updated": now,
            "age_sec": 0,
            "host": host,
            "grafana_url": grafana_url,
            "influx_url": influx_url,
            "database": influx_db,
            "label": label,
            "tone": tone,
            "grafana_ok": grafana_ok,
            "grafana_version": grafana_health.get("version", ""),
            "influx_ok": influx_ok,
            "telegraf_ok": telegraf_ok,
            "telegraf_target": telegraf_target,
            "measurements": measurements[:40],
            "core_measurements": core_present,
            "latest": latest,
            "summary": f"{host} metrics {label.lower()} | {len(core_present)}/6 core series | Grafana {'ok' if grafana_ok else 'warn'} | Influx {'ok' if influx_ok else 'warn'}",
            "errors": errors[:3],
        }
        self.observability_cache = result
        self.observability_checked = now
        return result

    def collect_metrics_intel(self) -> dict[str, Any]:
        now = time.time()
        ttl = env_int("SOCX_METRICS_INTEL_POLL_SECONDS", 30)
        if self.metrics_intel_cache and now - self.metrics_intel_checked < ttl:
            cached = dict(self.metrics_intel_cache)
            cached["age_sec"] = int(max(0, now - float(cached.get("generated") or now)))
            return cached
        cache = Path(os.environ.get("SOCX_METRICS_INTEL_JSON", "/tmp/socx-metrics-intel.json"))
        age = self.file_age_seconds(cache)
        if age is None or age > ttl:
            run_cmd("if command -v socx-metrics-intel >/dev/null 2>&1; then socx-metrics-intel --json >/tmp/socx-metrics-intel-web.out 2>/tmp/socx-metrics-intel-web.err; fi", timeout=12.0)
            age = self.file_age_seconds(cache)
        data: dict[str, Any] = {"severity": "UNKNOWN", "summary": "Metrics intelligence waiting", "alerts": [], "next_steps": [], "generated": 0}
        try:
            parsed = json.loads(cache.read_text(errors="ignore"))
            if isinstance(parsed, dict):
                data.update(parsed)
        except Exception:
            pass
        data["age_sec"] = age
        self.metrics_intel_cache = data
        self.metrics_intel_checked = now
        return data

    def collect_power_mods(self) -> dict[str, Any]:
        items: dict[str, dict[str, Any]] = {}
        specs = {
            "flow_export": Path("/tmp/socx-flow-export.env"),
            "suricata_eve": Path("/tmp/socx-suricata-eve.env"),
            "lldp": Path("/tmp/socx-lldp-map.env"),
            "config_drift": Path("/tmp/socx-config-drift.env"),
            "evidence_vault": Path("/tmp/socx-evidence-vault.env"),
            "quarantine_draft": Path("/tmp/socx-quarantine-draft.env"),
        }
        for name, path in specs.items():
            data = parse_env_file(path)
            try:
                updated = float(data.get("updated") or 0)
            except (TypeError, ValueError):
                updated = 0.0
            items[name] = {
                "status": data.get("status", "WAITING"),
                "summary": data.get("summary", "waiting for first SOCX sample"),
                "age_sec": int(max(0, time.time() - updated)) if updated else None,
                "data": data,
            }
        stale = [name for name, item in items.items() if item["status"] in {"WAITING", "UNKNOWN"}]
        watch = [name for name, item in items.items() if item["status"] in {"WARN", "PLAN", "STOPPED", "DOWN"}]
        if watch:
            status = "WATCH"
            summary = f"{len(watch)} power-user checks need attention"
        elif stale:
            status = "WAITING"
            summary = "power-user checks waiting for first run"
        else:
            status = "OK"
            summary = "power-user checks are reporting"
        return {"status": status, "summary": summary, "items": items, "updated": int(time.time())}

    def collect_autonomy_loop(self) -> dict[str, Any]:
        now = time.time()
        ttl = env_int("SOCX_AUTONOMY_POLL_SECONDS", 2)
        if self.autonomy_loop_cache and now - self.autonomy_loop_checked < ttl:
            cached = dict(self.autonomy_loop_cache)
            cached["age_sec"] = int(max(0, now - float(cached.get("updated") or now)))
            return cached
        path = Path(os.environ.get("SOCX_AUTONOMY_CACHE", "/tmp/socx-autonomy-loop.env"))
        data = parse_env_file(path)
        updated = 0.0
        try:
            updated = float(data.get("updated") or 0)
        except Exception:
            updated = 0.0
        age = int(max(0, now - updated)) if updated else None
        status = str(data.get("status") or ("waiting" if not updated else "unknown"))
        tone = "green" if status == "ok" and (age is None or age < 600) else "yellow" if status in {"skipped", "waiting", "unknown"} else "red"
        if age is not None and age > 900:
            tone = "red"
            status = "stale"
        result = {
            "updated": updated,
            "age_sec": age,
            "status": status,
            "tone": tone,
            "summary": data.get("summary") or "autonomy loop waiting",
            "log": data.get("log") or "/tmp/socx-autonomy-loop.log",
            "read_only": True,
        }
        self.autonomy_loop_cache = result
        self.autonomy_loop_checked = now
        return result

    def vpn_crypto_headroom(
        self,
        direct: dict[str, str],
        vpn: dict[str, str],
        auto: dict[str, str],
        hardware: dict[str, Any],
    ) -> dict[str, Any]:
        def num(data: dict[str, str], key: str) -> float:
            try:
                return float(data.get(key, "") or 0)
            except ValueError:
                return 0.0
        dd = num(direct, "download_mbps")
        du = num(direct, "upload_mbps")
        vd = num(vpn, "download_mbps")
        vu = num(vpn, "upload_mbps")
        trend = hardware.get("trend") if isinstance(hardware.get("trend"), dict) else {}
        cpu_temp = float(hardware.get("max_temp_c") or auto.get("cpu_temp_max", "0") or 0)
        cpu_headroom = float(trend.get("headroom_c") or auto.get("cpu_headroom_c", "0") or 0)
        down_pct = round((vd / dd) * 100) if dd and vd else None
        up_pct = round((vu / du) * 100) if du and vu else None
        if down_pct is None and up_pct is None:
            label = "waiting"
            tone = "yellow"
        elif (down_pct or 0) >= 80 and (up_pct or 0) >= 70 and cpu_headroom >= 5:
            label = "high"
            tone = "green"
        elif (down_pct or 0) >= 45 and cpu_headroom >= 0:
            label = "normal"
            tone = "cyan"
        else:
            label = "watch"
            tone = "yellow"
        return {
            "label": label,
            "tone": tone,
            "down_pct": down_pct,
            "up_pct": up_pct,
            "cpu_temp_c": cpu_temp or None,
            "cpu_headroom_c": cpu_headroom,
            "summary": f"VPN {down_pct or '--'}/{up_pct or '--'}% of direct, CPU {cpu_temp or '--'}C, headroom {cpu_headroom}C",
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
        max_age = env_int("SOCX_PI_NODES_MAX_AGE_SECONDS", 45)
        cache_age = self.file_age_seconds(path)
        if cache_age is None or cache_age > max_age:
            refreshed = run_cmd("if command -v socx-pi-nodes >/dev/null 2>&1; then socx-pi-nodes >/tmp/socx-web-pi-nodes-refresh.log 2>&1; echo refreshed; fi", timeout=8.0)
            if refreshed:
                cache_age = self.file_age_seconds(path)
            cache_age = self.file_age_seconds(path)
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
        data["cache_age_sec"] = cache_age
        data["fresh"] = bool(data.get("age_sec") is not None and int(data.get("age_sec") or 0) <= max_age)
        data["state"] = "fresh" if data["fresh"] else ("empty" if not enriched else "stale")
        self.pi_fleet_cache = data
        self.pi_fleet_checked = now
        return data

    def file_age_seconds(self, path: Path) -> int | None:
        try:
            return int(max(0, time.time() - path.stat().st_mtime))
        except OSError:
            return None

    def truth_signal(self, name: str, state: str, age: Any = None, source: str = "", detail: str = "") -> dict[str, Any]:
        state_l = str(state or "unknown").lower()
        if state_l in {"ok", "ready", "fresh", "live", "online"}:
            tone = "green"
        elif state_l in {"stale", "waiting", "partial", "warn", "inactive"}:
            tone = "yellow"
        elif state_l in {"down", "fail", "failed", "error", "offline"}:
            tone = "red"
        else:
            tone = "cyan"
        return {
            "name": name,
            "state": state_l.upper(),
            "tone": tone,
            "age_sec": age,
            "age_h": human_duration(age),
            "source": source,
            "detail": detail,
        }

    def collect_data_truth(
        self,
        center: dict[str, Any],
        pi_nodes: dict[str, Any],
        ups: dict[str, Any],
        incident_memory: dict[str, Any],
        label_brain: dict[str, Any],
        observability: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        signals: list[dict[str, Any]] = []
        active = center.get("router") if isinstance(center.get("router"), dict) else {}
        direct = center.get("direct") if isinstance(center.get("direct"), dict) else {}
        vpn = center.get("vpn") if isinstance(center.get("vpn"), dict) else {}

        def add_speed(label: str, row: dict[str, Any]) -> None:
            state = row.get("path_state") or row.get("status") or "waiting"
            signals.append(self.truth_signal(label, state, row.get("age_sec"), "speedtest-cache", f"{row.get('down') or '--'}/{row.get('up') or '--'} Mbps {row.get('ping') or '--'}ms"))

        signals.append(self.truth_signal("Wall/API", "live", 0, "socxweb", "browser state is being generated"))
        signals.append(self.truth_signal("UPS", "stale" if ups.get("stale") else "fresh", ups.get("age_sec"), "NUT/APC", f"{ups.get('watts_h') or ups.get('watts') or '--'}"))
        add_speed("Speed Active", active)
        add_speed("Speed Direct", direct)
        add_speed("Speed VPN", vpn)
        pi_state = "fresh" if pi_nodes.get("fresh") else ("stale" if pi_nodes.get("count") else "waiting")
        signals.append(self.truth_signal("Pi Fleet", pi_state, pi_nodes.get("age_sec"), "socx-pi-nodes", f"{pi_nodes.get('online', 0)}/{pi_nodes.get('count', 0)} online"))
        signals.append(self.truth_signal("Pi AI", "ready" if any(str(n.get("roles_online") or "") == "3/3" for n in pi_nodes.get("nodes", []) if isinstance(n, dict)) else "waiting", pi_nodes.get("age_sec"), "Pi 3-LLM", "role health from Pi dashboard"))
        signals.append(self.truth_signal("Label Brain", "ready" if label_brain.get("devices") else "waiting", 0, "local memory", f"{len(label_brain.get('devices', []) if isinstance(label_brain.get('devices'), list) else [])} devices"))
        memory_count = incident_memory.get("count") or incident_memory.get("samples") or 0
        signals.append(self.truth_signal("Incident Memory", "ready" if memory_count else "waiting", 0, "jsonl memory", f"{memory_count} samples"))
        obs = observability or {}
        if obs:
            obs_state = "ready" if obs.get("label") == "LIVE" else ("warn" if obs.get("label") == "WATCH" else "down")
            signals.append(self.truth_signal("Grafana/Influx", obs_state, obs.get("age_sec"), obs.get("host", "Pi metrics"), obs.get("summary", "")))

        ok = sum(1 for item in signals if item["tone"] == "green")
        warn = sum(1 for item in signals if item["tone"] == "yellow")
        red = sum(1 for item in signals if item["tone"] == "red")
        score = max(0, min(100, int((ok / max(1, len(signals))) * 100) - red * 15 - warn * 4))
        if red:
            label = "DEGRADED"
            tone = "red"
        elif warn:
            label = "WATCH"
            tone = "yellow"
        else:
            label = "LIVE"
            tone = "green"
        reason_items = [f"{item['name']} {str(item['state']).lower()}" for item in signals if item["tone"] in {"yellow", "red"}]
        if reason_items:
            reason = f"{label} because " + "; ".join(reason_items[:3])
        else:
            reason = "LIVE because all primary SOCX collectors are fresh or ready"
        return {"label": label, "tone": tone, "score": score, "ok": ok, "warn": warn, "red": red, "reason": reason, "signals": signals, "updated_ms": now_ms()}

    def collect_release_health(self) -> dict[str, Any]:
        now = time.time()
        if self.release_health_cache and now - self.release_health_checked < env_int("SOCX_RELEASE_HEALTH_TTL_SECONDS", 20):
            cached = dict(self.release_health_cache)
            cached["cache_age_sec"] = int(now - self.release_health_checked)
            return cached
        snapshot = self.snapshot()
        data_truth = snapshot.get("data_truth", {}) if isinstance(snapshot, dict) else {}
        pi_nodes = snapshot.get("pi_nodes", {}) if isinstance(snapshot, dict) else {}
        status = snapshot.get("status", {}) if isinstance(snapshot, dict) else {}
        wall_err_age = self.file_age_seconds(Path("/tmp/socx-wall.err"))
        wall_err_size = 0
        try:
            wall_err_size = Path("/tmp/socx-wall.err").stat().st_size
        except OSError:
            pass
        v1 = run_cmd_capture(["/usr/local/bin/socx", "v1-check"], timeout=35.0)
        svc = run_cmd_capture(["/usr/sbin/service", "socxweb", "status"], timeout=8.0)
        health = {
            "generated_ms": now_ms(),
            "hostname": socket.gethostname(),
            "status": status,
            "data_truth": data_truth,
            "pi_nodes": pi_nodes,
            "wall_error": {"bytes": wall_err_size, "age_sec": wall_err_age, "clean": wall_err_size == 0},
            "checks": [
                {"name": "SOCX Web", "ok": bool(svc.get("ok")), "elapsed_ms": svc.get("elapsed_ms"), "output": svc.get("output", "").strip()},
                {"name": "Readiness", "ok": bool(v1.get("ok")) and "READY FOR SOCX" in str(v1.get("output", "")), "elapsed_ms": v1.get("elapsed_ms"), "output": v1.get("output", "").strip()},
            ],
            "versions": {
                "web": "SOCXWeb/0.1",
                "api": "state+health",
                "refresh_ms": int(self.interval * 1000),
            },
        }
        self.release_health_cache = health
        self.release_health_checked = now
        return health

    def collect_doctor(self) -> dict[str, Any]:
        now = time.time()
        ttl = env_int("SOCX_DOCTOR_TTL_SECONDS", 60)
        cached = getattr(self, "doctor_cache", None)
        checked = float(getattr(self, "doctor_checked", 0) or 0)
        if cached and now - checked < ttl:
            result = dict(cached)
            result["cache_age_sec"] = int(now - checked)
            return result
        checks = [
            ("SOCX Status", "Core wall, web, collectors, gateway, AI, and service overview.", ["socx", "status"], 24.0, "status"),
            ("pfSense Services", "Reads pfSense service status safely and catches malformed service entries.", ["socx-doctor", "php-services"], 24.0, "services"),
            ("WAN Quality", "Gateway/dpinger latency, loss, warnings, and Speedtest context.", ["socx-doctor", "wan-quality"], 24.0, "network"),
            ("VPN Gateways", "Actual VPN gateway/interface truth, separate from WAN health.", ["socx-doctor", "gateways"], 24.0, "network"),
            ("DNSBL Review", "pfBlockerNG/DNSBL pressure and false-positive review hints.", ["socx-doctor", "dnsbl-review"], 32.0, "security"),
            ("IDS Review", "Suricata high-signal versus routine IDS noise.", ["socx-doctor", "ids"], 32.0, "security"),
            ("vnStat", "Traffic Totals/vnstatd collection daemon health.", ["socx-doctor", "vnstat"], 24.0, "services"),
            ("LLDP Topology", "Neighbor discovery and switch topology hints.", ["socx-doctor", "topology"], 24.0, "topology"),
            ("Speedtest Profiles", "DIRECT/VPN/client/router Speedtest freshness and path truth.", ["socx-doctor", "speedtest-profiles"], 24.0, "speed"),
            ("Pi LLM", "Pi discovery, Pi 3-LLM role health, and model-route status.", ["socx-doctor", "pi-llm"], 40.0, "ai"),
        ]
        rows = []
        ok_count = warn_count = fail_count = 0
        for label, why, args, timeout, group in checks:
            exe = f"/usr/local/bin/{args[0]}"
            result = run_cmd_capture([exe, *args[1:]], timeout=timeout)
            output = result.get("output", "")
            upper = output.upper()
            summary_match = re.search(r"summary\s+OK=(\d+)\s+WARN=(\d+)\s+FAIL=(\d+)", output, re.I)
            if summary_match:
                row_warn = int(summary_match.group(2))
                row_fail = int(summary_match.group(3))
            else:
                row_warn = -1
                row_fail = -1
            if not result.get("ok"):
                status = "FAIL"
                fail_count += 1
            elif summary_match and row_fail > 0:
                status = "FAIL"
                fail_count += 1
            elif summary_match and row_warn > 0:
                status = "WARN"
                warn_count += 1
            elif summary_match:
                status = "OK"
                ok_count += 1
            elif "FAIL" in upper or "CRITICAL" in upper or "FATAL" in upper:
                status = "WARN"
                warn_count += 1
            elif "WARN" in upper or "ERROR" in upper or "STALE" in upper:
                status = "WARN"
                warn_count += 1
            else:
                status = "OK"
                ok_count += 1
            summary = ""
            for raw in output.splitlines():
                line = raw.strip()
                if line and not line.startswith("===") and not line.lower().startswith("socx doctor:") and not line.lower().startswith("generated "):
                    summary = line[:220]
                    break
            rows.append({
                "label": label,
                "group": group,
                "status": status,
                "why": why,
                "elapsed_ms": result.get("elapsed_ms"),
                "summary": summary or ("command completed" if result.get("ok") else "command failed"),
                "command": " ".join(args),
                "output": output[-2400:],
                "ok": bool(result.get("ok")),
            })
        status = "FAIL" if fail_count else "WATCH" if warn_count else "OK"
        doctor = {
            "updated_ms": now_ms(),
            "status": status,
            "summary": f"{ok_count} OK / {warn_count} WARN / {fail_count} FAIL across {len(rows)} read-only checks",
            "ok": ok_count,
            "warn": warn_count,
            "fail": fail_count,
            "rows": rows,
            "next": [
                "Open the row output before applying any repair.",
                "Use Bundle or Snapshot before changing pfSense services during an incident.",
                "Ask SOCX to explain a WARN row if the command output is noisy.",
            ],
            "read_only": True,
        }
        self.doctor_cache = doctor
        self.doctor_checked = now
        return doctor

    def collect_intel_layer(
        self,
        incident: dict[str, Any],
        label_brain: dict[str, Any],
        flows: list[dict[str, Any]],
        hardware: dict[str, Any],
    ) -> dict[str, Any]:
        rows: list[dict[str, Any]] = []
        counts = incident.get("counts") if isinstance(incident.get("counts"), dict) else {}
        ids = incident.get("ids") if isinstance(incident.get("ids"), dict) else {}
        sources = incident.get("blocked_sources") if isinstance(incident.get("blocked_sources"), list) else []
        ports = incident.get("blocked_ports") if isinstance(incident.get("blocked_ports"), list) else []
        dns = incident.get("dnsbl_domains") if isinstance(incident.get("dnsbl_domains"), list) else []
        anomalies = label_brain.get("anomalies") if isinstance(label_brain.get("anomalies"), list) else []

        if int(counts.get("sources", 0) or 0) or sources:
            top = sources[0] if sources else {}
            rows.append(self.intel_row(
                "WAN scan pressure",
                "P4",
                "BTP",
                "Network-facing reconnaissance is being blocked at the firewall.",
                "T1595",
                "Active Scanning",
                "Reconnaissance",
                "D3-NTA",
                "Network Traffic Analysis",
                f"top source {top.get('name', 'unknown')} x{top.get('count', 0)}; top port {(ports[0] if ports else {}).get('name', 'mixed')}",
            ))
        if dns:
            top = dns[0]
            rows.append(self.intel_row(
                "DNSBL known-bad/reputation hit",
                "P3",
                "BTP",
                "DNS/reputation filtering is active; review only if a trusted app breaks.",
                "T1568",
                "Dynamic Resolution",
                "Command and Control",
                "D3-DNSDL",
                "DNS Denylisting",
                f"{top.get('name', 'domain')} x{top.get('count', 0)}",
            ))
        if int(ids.get("high_signal", 0) or 0) > 0:
            rows.append(self.intel_row(
                "High-signal IDS alert",
                "P2",
                "TP?",
                "IDS matched suspicious content; correlate before containment.",
                "T1190",
                "Exploit Public-Facing Application",
                "Initial Access",
                "D3-IPS",
                "Intrusion Prevention",
                f"{ids.get('high_signal')} high-signal IDS rows",
            ))
        elif int(ids.get("watch", 0) or 0) > 0:
            rows.append(self.intel_row(
                "Routine IDS watch volume",
                "P4",
                "BTP",
                "Low-confidence IDS decoder/stream noise is present.",
                "N/A",
                "Routine IDS telemetry",
                "Monitoring",
                "D3-NTA",
                "Network Traffic Analysis",
                f"{ids.get('watch', 0)} routine watch rows",
            ))
        if anomalies:
            rows.append(self.intel_row(
                "Learned-normal asset drift",
                "P3",
                "Needs Review",
                "A host is using a new app/service compared with the learned baseline.",
                "T1046",
                "Network Service Discovery",
                "Discovery",
                "D3-BA",
                "Behavioral Analytics",
                f"{len(anomalies)} asset behavior change(s)",
            ))

        kev = self.collect_kev_watch(incident, flows)
        priority = self.intel_priority(rows, kev, hardware)
        story = self.kill_chain_story(rows)
        return {
            "title": "ATT&CK / D3FEND / KEV Intelligence",
            "generated": time.strftime("%Y-%m-%d %H:%M:%S"),
            "status": priority.get("status"),
            "priority": priority,
            "rows": rows[:8],
            "story": story,
            "kev": kev,
            "sources": [
                {"name": "MITRE ATT&CK", "url": "https://attack.mitre.org/"},
                {"name": "MITRE D3FEND", "url": "https://d3fend.mitre.org/"},
                {"name": "CISA KEV", "url": "https://www.cisa.gov/known-exploited-vulnerabilities-catalog"},
            ],
        }

    def intel_row(
        self,
        signal: str,
        priority: str,
        disposition: str,
        summary: str,
        attack_id: str,
        attack_name: str,
        tactic: str,
        d3fend_id: str,
        d3fend_name: str,
        evidence: str,
    ) -> dict[str, Any]:
        return {
            "signal": signal,
            "priority": priority,
            "disposition": disposition,
            "summary": summary,
            "attack": {"id": attack_id, "name": attack_name, "tactic": tactic, "url": f"https://attack.mitre.org/techniques/{attack_id}/" if attack_id.startswith("T") else ""},
            "d3fend": {"id": d3fend_id, "name": d3fend_name, "url": "https://d3fend.mitre.org/"},
            "evidence": evidence,
            "confidence": "medium" if disposition in {"BTP", "Needs Review"} else "low",
            "safe_actions": ["preserve evidence", "correlate with timeline", "human approval required for policy changes"],
        }

    def collect_kev_watch(self, incident: dict[str, Any], flows: list[dict[str, Any]]) -> dict[str, Any]:
        cache_path = Path(os.environ.get("SOCX_KEV_CACHE", "/var/db/socx_kev_cache.json"))
        url = os.environ.get("SOCX_KEV_URL", "https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json")
        ttl = env_int("SOCX_KEV_TTL_SECONDS", 86400)
        data: dict[str, Any] = {}
        fetched = False
        now = time.time()
        try:
            if cache_path.exists() and now - cache_path.stat().st_mtime < ttl:
                data = json.loads(cache_path.read_text(encoding="utf-8"))
            elif self.kev_memory.get("data") and now - float(self.kev_memory.get("checked") or 0) < env_int("SOCX_KEV_RETRY_SECONDS", 900):
                data = self.kev_memory.get("data") if isinstance(self.kev_memory.get("data"), dict) else {}
            elif self.kev_memory.get("error") and now - float(self.kev_memory.get("checked") or 0) < env_int("SOCX_KEV_RETRY_SECONDS", 900):
                raise RuntimeError(str(self.kev_memory.get("error") or "KEV refresh backoff active"))
            else:
                req = Request(url, headers={"User-Agent": "SOCX-pfSense-Wall/1.3"})
                with urlopen(req, timeout=4.0) as resp:
                    data = json.loads(resp.read().decode("utf-8", "replace"))
                self.kev_memory = {"checked": now, "data": data, "error": ""}
                try:
                    cache_path.parent.mkdir(parents=True, exist_ok=True)
                    cache_path.write_text(json.dumps(data, separators=(",", ":")), encoding="utf-8")
                except OSError:
                    pass
                fetched = True
        except Exception as exc:
            self.kev_memory = {"checked": now, "data": self.kev_memory.get("data") or {}, "error": str(exc)[:160]}
            try:
                if cache_path.exists():
                    data = json.loads(cache_path.read_text(encoding="utf-8"))
            except Exception:
                data = {}
            if not data:
                return {"status": "unknown", "matches": [], "count": 0, "reason": f"KEV feed unavailable: {str(exc)[:120]}", "source": url}

        cves = sorted(set(re.findall(r"CVE-\d{4}-\d{4,7}", json.dumps({"incident": incident, "flows": flows})[:12000], re.I)))
        catalog = data.get("vulnerabilities") if isinstance(data.get("vulnerabilities"), list) else []
        by_cve = {str(item.get("cveID", "")).upper(): item for item in catalog if isinstance(item, dict)}
        matches = []
        for cve in cves:
            item = by_cve.get(cve.upper())
            if item:
                matches.append({
                    "cve": cve.upper(),
                    "vendor": item.get("vendorProject"),
                    "product": item.get("product"),
                    "name": item.get("vulnerabilityName"),
                    "due": item.get("dueDate"),
                    "known_ransomware": item.get("knownRansomwareCampaignUse"),
                })
        age = None
        try:
            age = int(now - cache_path.stat().st_mtime)
        except OSError:
            pass
        status = "hit" if matches else ("fresh" if age is not None and age < ttl * 2 else "stale")
        return {
            "status": status,
            "matches": matches[:5],
            "count": len(matches),
            "observed_cves": cves[:12],
            "catalog_count": len(catalog),
            "fetched": fetched,
            "age_sec": age,
            "source": url,
            "reason": "No observed CVEs matched CISA KEV in current SOCX evidence." if not matches else "Observed CVE is listed in CISA KEV.",
        }

    def intel_priority(self, rows: list[dict[str, Any]], kev: dict[str, Any], hardware: dict[str, Any]) -> dict[str, Any]:
        order = {"P1": 4, "P2": 3, "P3": 2, "P4": 1}
        highest = "P4"
        if kev.get("matches"):
            highest = "P1"
        for row in rows:
            pri = str(row.get("priority") or "P4")
            if order.get(pri, 0) > order.get(highest, 0):
                highest = pri
        if highest == "P1":
            status = "critical"
            action = "preserve evidence and escalate before changing policy"
        elif highest == "P2":
            status = "investigate"
            action = "build incident bundle and correlate IDS evidence"
        elif highest == "P3":
            status = "watch"
            action = "review DNSBL/assets if repeated or user impact appears"
        else:
            status = "routine"
            action = "batch routine scan noise; keep monitoring"
        return {"level": highest, "status": status, "action": action, "read_only": True, "hardware": hardware.get("summary")}

    def kill_chain_story(self, rows: list[dict[str, Any]]) -> list[dict[str, str]]:
        tactics = OrderedDict()
        for row in rows:
            attack = row.get("attack") if isinstance(row.get("attack"), dict) else {}
            tactic = str(attack.get("tactic") or "Unmapped")
            tactics.setdefault(tactic, []).append(str(row.get("signal") or "signal"))
        if not tactics:
            return [{"stage": "Observe", "summary": "No mapped ATT&CK activity in the current sample."}]
        return [{"stage": tactic, "summary": ", ".join(signals[:3])} for tactic, signals in tactics.items()]

    def collect_mission(
        self,
        center: dict[str, Any],
        incident: dict[str, Any],
        label_brain: dict[str, Any],
        pi_nodes: dict[str, Any],
        ai_timeline: dict[str, Any],
        hardware: dict[str, Any],
        data_truth: dict[str, Any],
        what_changed: dict[str, Any],
        flows: list[dict[str, Any]],
        intel: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        intel = intel if isinstance(intel, dict) else {}
        ids = incident.get("ids") if isinstance(incident.get("ids"), dict) else {}
        counts = incident.get("counts") if isinstance(incident.get("counts"), dict) else {}
        top_source = (incident.get("blocked_sources") or [{}])[0] if isinstance(incident.get("blocked_sources"), list) else {}
        top_port = (incident.get("blocked_ports") or [{}])[0] if isinstance(incident.get("blocked_ports"), list) else {}
        top_dns = (incident.get("dnsbl_domains") or [{}])[0] if isinstance(incident.get("dnsbl_domains"), list) else {}
        anomalies = label_brain.get("anomalies") if isinstance(label_brain.get("anomalies"), list) else []
        ai_rows = ai_timeline.get("rows") if isinstance(ai_timeline.get("rows"), list) else []
        changed_rows = what_changed.get("rows") if isinstance(what_changed.get("rows"), list) else []
        top_flow = flows[0] if flows else {}
        ht = hardware.get("trend") if isinstance(hardware.get("trend"), dict) else {}
        headroom = ht.get("headroom_c")
        mode = str(center.get("mode") or "UNKNOWN").upper()
        score = center.get("score", "--")
        matters: list[str] = []
        checks: list[str] = []
        noise: list[str] = []

        if top_source.get("name") and int(top_source.get("count", 0) or 0) >= 20:
            matters.append(f"WAN scan pressure from {top_source.get('name')} x{top_source.get('count')} on port {top_port.get('name', 'mixed')}")
        else:
            noise.append("Firewall blocks look like normal internet background scan noise")
        if top_dns.get("name"):
            matters.append(f"DNSBL is filtering {top_dns.get('name')} x{top_dns.get('count', 0)}")
            checks.append("Use /why before allowlisting any DNSBL hit that breaks a trusted app")
        else:
            noise.append("No dominant DNSBL domain in the current sample")
        if int(ids.get("high_signal", 0) or 0) > 0:
            matters.append(f"IDS has {ids.get('high_signal')} high-signal alert(s)")
            checks.append("Build an incident bundle before changing IDS policy")
        elif int(ids.get("watch", 0) or 0) > 0:
            noise.append(f"IDS watch/routine volume is present but low confidence: {ids.get('watch', 0)} watch")
        if anomalies:
            matters.append(f"{len(anomalies)} learned-normal device change(s) need review")
            checks.append("Open /devices and verify whether the new app/service belongs to that host")
        if str(data_truth.get("label", "")).upper() != "LIVE":
            matters.append(f"Data Truth is {data_truth.get('label', 'UNKNOWN')}: {data_truth.get('reason', 'collector freshness issue')}")
            checks.append("Open /health and refresh stale collectors before trusting automation")
        if headroom is not None and float(headroom) < 5:
            matters.append(f"CPU thermal headroom is tight at {headroom}C")
            checks.append("Watch cooling/airflow if CPU stays warm across several history samples")
        elif hardware:
            noise.append(f"Hardware headroom normal: {hardware.get('max_temp_h', '--')} max, {headroom if headroom is not None else '--'}C headroom")
        if int(pi_nodes.get("count", 0) or 0):
            matters.append(f"Pi fleet {pi_nodes.get('online', 0)}/{pi_nodes.get('count', 0)} online; AI roles visible")
        if ai_rows:
            checks.append(f"Review AI verdict: {ai_rows[0].get('source', 'AI')} {ai_rows[0].get('severity', '--')} - {ai_rows[0].get('reason', '--')}")
        for row in (intel.get("rows") if isinstance(intel.get("rows"), list) else [])[:2]:
            attack = row.get("attack", {}) if isinstance(row.get("attack"), dict) else {}
            ddef = row.get("d3fend", {}) if isinstance(row.get("d3fend"), dict) else {}
            matters.append(f"{row.get('signal', 'Signal')} maps to ATT&CK {attack.get('id', 'N/A')} {attack.get('name', '')}".strip())
            checks.append(f"D3FEND suggests {ddef.get('name', 'preserve evidence and monitor')} for {row.get('signal', 'signal')}")
        kev = intel.get("kev") if isinstance(intel.get("kev"), dict) else {}
        if kev.get("matches"):
            matters.append(f"CISA KEV match requires priority review: {kev.get('matches')[0].get('cve')}")
            checks.append("Preserve evidence and review exposed package/version before any upgrade or rule change")
        elif kev.get("status") == "stale":
            checks.append("KEV feed cache is stale; refresh internet access before relying on CVE coverage")
        if top_flow:
            app = top_flow.get("app_hint") or top_flow.get("service") or "traffic"
            noise.append(f"Top flow is {top_flow.get('asset', top_flow.get('src', '--'))} -> {top_flow.get('peer', top_flow.get('dst', '--'))} via {app}")
        if not matters:
            matters.append(f"SOCX is in {mode} mode with score {score}; no critical evidence in the current sample")
        if not checks:
            checks.append("Keep watching; run socx snapshot before any risky configuration change")

        headline = f"{mode} {score}/100 | {matters[0]}"
        return {
            "title": "SOCX Mission",
            "generated": time.strftime("%Y-%m-%d %H:%M:%S"),
            "headline": headline[:180],
            "mode": mode,
            "score": score,
            "data_truth": {"label": data_truth.get("label"), "score": data_truth.get("score"), "reason": data_truth.get("reason"), "tone": data_truth.get("tone")},
            "what_changed": changed_rows[:5],
            "what_matters": matters[:6],
            "what_to_check": checks[:6],
            "probably_noise": noise[:6],
            "intel": intel,
            "vpn": {
                "summary": self.vpn_mission_summary(center),
                "paths": center.get("vpn_paths", []) if isinstance(center.get("vpn_paths"), list) else [],
                "crypto": center.get("vpn_crypto", {}),
            },
            "pi_ai": {
                "summary": f"{pi_nodes.get('online', 0)}/{pi_nodes.get('count', 0)} nodes online",
                "rows": ai_rows[:5],
            },
            "hardware": {
                "summary": hardware.get("summary"),
                "headroom_c": headroom,
                "trend": ht.get("label"),
                "tone": hardware.get("tone"),
            },
            "next_actions": (center.get("actions") if isinstance(center.get("actions"), list) else ["socx status"])[:5],
            "updated_ms": now_ms(),
        }

    def vpn_mission_summary(self, center: dict[str, Any]) -> str:
        paths = center.get("vpn_paths") if isinstance(center.get("vpn_paths"), list) else []
        if paths:
            parts = []
            for path in paths[:3]:
                label = str(path.get("label") or "VPN")
                state = str(path.get("path_state") or path.get("status") or "waiting").upper()
                ping = path.get("ping")
                parts.append(f"{label} {state.lower()}{(' ' + str(ping) + 'ms') if ping not in ('', None) else ''}")
            return " | ".join(parts)
        vpn = center.get("vpn") if isinstance(center.get("vpn"), dict) else {}
        return f"VPN {vpn.get('path_state') or vpn.get('status') or 'waiting'} {vpn.get('down') or '--'}/{vpn.get('up') or '--'} Mbps"

    def safe_why_target(self, target: str) -> str:
        value = str(target or "").strip()
        value = re.sub(r"[^A-Za-z0-9_.:/*-]", "", value)
        return value[:120]

    def collect_why(self, target: str = "") -> dict[str, Any]:
        snapshot = self.snapshot()
        incident = snapshot.get("incident", {}) if isinstance(snapshot, dict) else {}
        packets = snapshot.get("packets", []) if isinstance(snapshot, dict) else []
        target = self.safe_why_target(target)
        candidates: list[dict[str, Any]] = []
        for item in incident.get("blocked_sources", []) if isinstance(incident.get("blocked_sources"), list) else []:
            name = str(item.get("name") or "")
            if name:
                candidates.append({"type": "source", "target": name, "count": item.get("count", 0)})
        for item in incident.get("blocked_ports", []) if isinstance(incident.get("blocked_ports"), list) else []:
            name = str(item.get("name") or "")
            if name:
                candidates.append({"type": "port", "target": name, "count": item.get("count", 0)})
        for item in incident.get("dnsbl_domains", []) if isinstance(incident.get("dnsbl_domains"), list) else []:
            raw = str(item.get("raw") or item.get("name") or "")
            if raw:
                candidates.append({"type": "domain", "target": raw, "count": item.get("count", 0)})
        if not target and candidates:
            target = str(candidates[0].get("target") or "")
        matches: list[dict[str, Any]] = []
        target_l = target.lower()
        for packet in packets if isinstance(packets, list) else []:
            if not isinstance(packet, dict):
                continue
            hay = " ".join(str(packet.get(k, "")) for k in ("src", "dst", "src_label", "dst_label", "dport", "service", "action")).lower()
            if target_l and target_l not in hay:
                continue
            matches.append({
                "time": packet.get("time", ""),
                "action": packet.get("action", ""),
                "direction": packet.get("direction", ""),
                "source": packet.get("src_label") or packet.get("src") or "",
                "destination": packet.get("dst_label") or packet.get("dst") or "",
                "port": packet.get("dport", ""),
                "service": packet.get("service", ""),
                "severity": packet.get("severity", ""),
            })
        command = {"ok": False, "output": "No target selected.", "elapsed_ms": 0}
        if target:
            command = run_cmd_capture(["/usr/local/bin/socx", "why-blocked", target], timeout=25.0)
        source_count = next((item.get("count") for item in candidates if item.get("target") == target and item.get("type") == "source"), 0)
        port_count = next((item.get("count") for item in candidates if item.get("target") == target and item.get("type") == "port"), 0)
        domain_count = next((item.get("count") for item in candidates if item.get("target") == target and item.get("type") == "domain"), 0)
        if domain_count:
            verdict = "DNSBL or reputation block context"
            next_step = "Review DNSBL evidence before allowlisting; preserve a bundle if this affects a trusted app."
        elif source_count or port_count or matches:
            verdict = "Firewall block context"
            next_step = "Treat repeated WAN scans as expected internet noise unless a LAN host is affected; preserve a bundle before changing policy."
        else:
            verdict = "No direct match in recent SOCX evidence"
            next_step = "Try a different IP, domain, port, or run an incident bundle for wider evidence."
        return {
            "target": target,
            "verdict": verdict,
            "next_step": next_step,
            "counts": {"source": source_count, "port": port_count, "domain": domain_count, "packet_matches": len(matches)},
            "candidates": candidates[:16],
            "matches": matches[:12],
            "command": command,
            "updated_ms": now_ms(),
        }

    def collect_daily_story(self) -> dict[str, Any]:
        snapshot = self.snapshot()
        truth = snapshot.get("data_truth", {}) if isinstance(snapshot, dict) else {}
        changed = snapshot.get("what_changed", {}) if isinstance(snapshot, dict) else {}
        incident = snapshot.get("incident", {}) if isinstance(snapshot, dict) else {}
        memory = snapshot.get("incident_memory", {}) if isinstance(snapshot, dict) else {}
        speed = snapshot.get("speedtest_history", {}) if isinstance(snapshot, dict) else {}
        center = snapshot.get("command_center", {}) if isinstance(snapshot, dict) else {}
        pi = snapshot.get("pi_nodes", {}) if isinstance(snapshot, dict) else {}
        hardware = snapshot.get("hardware", {}) if isinstance(snapshot, dict) else {}
        metrics_intel = snapshot.get("metrics_intel", {}) if isinstance(snapshot, dict) else {}
        ai = snapshot.get("ai_timeline", {}) if isinstance(snapshot, dict) else {}
        brain = snapshot.get("label_brain", {}) if isinstance(snapshot, dict) else {}
        top_source = (incident.get("blocked_sources") or [{}])[0] if isinstance(incident.get("blocked_sources"), list) else {}
        top_port = (incident.get("blocked_ports") or [{}])[0] if isinstance(incident.get("blocked_ports"), list) else {}
        top_dns = (incident.get("dnsbl_domains") or [{}])[0] if isinstance(incident.get("dnsbl_domains"), list) else {}
        ids = incident.get("ids") if isinstance(incident.get("ids"), dict) else {}
        paths = speed.get("paths") if isinstance(speed.get("paths"), list) else []
        direct = next((p for p in paths if str(p.get("path", "")).lower() == "direct"), {})
        vpn = next((p for p in paths if str(p.get("path", "")).lower() == "vpn"), {})
        now_watching = brain.get("now_watching") if isinstance(brain.get("now_watching"), list) else []
        anomalies = brain.get("anomalies") if isinstance(brain.get("anomalies"), list) else []
        ai_rows = ai.get("rows") if isinstance(ai.get("rows"), list) else []
        changed_rows = changed.get("rows") if isinstance(changed.get("rows"), list) else []
        memory_sources = memory.get("sources") if isinstance(memory.get("sources"), list) else []
        memory_ports = memory.get("ports") if isinstance(memory.get("ports"), list) else []
        story_lines = [
            f"SOCX is {truth.get('label', 'UNKNOWN')} with data score {truth.get('score', '--')}/100.",
            str(truth.get("reason") or "Collector freshness is still being evaluated."),
            f"Autopilot is {str(center.get('mode') or 'UNKNOWN').upper()} score {center.get('score', '--')}/100.",
        ]
        if top_source.get("name"):
            story_lines.append(f"Firewall pressure is led by {top_source.get('name')} with {top_source.get('count', 0)} sampled blocks; top port is {top_port.get('name', 'mixed')}.")
        else:
            story_lines.append("Firewall pressure is quiet in the current sample.")
        if top_dns.get("name"):
            story_lines.append(f"DNSBL activity is led by {top_dns.get('name')} x{top_dns.get('count', 0)}.")
        if int(ids.get("high_signal", 0) or 0):
            story_lines.append(f"IDS has {ids.get('high_signal')} high-signal alerts; preserve evidence before tuning.")
        else:
            story_lines.append(f"IDS signal is routine/watch level: {ids.get('watch', 0)} watch, {ids.get('routine', 0)} routine.")
        story_lines.append(f"Pi fleet is {pi.get('online', 0)}/{pi.get('count', 0)} online; Pi AI role status is visible in the AI page.")
        if hardware:
            story_lines.append(f"Hardware headroom: {hardware.get('summary', 'hardware profile waiting')}; powerd {hardware.get('powerd', 'unknown')}.")
        if metrics_intel:
            story_lines.append(f"Metrics intelligence is {metrics_intel.get('severity', 'UNKNOWN')}: {metrics_intel.get('summary', 'waiting')}")
        if changed_rows:
            story_lines.append(f"Most recent meaningful change: {changed_rows[0].get('title')} - {changed_rows[0].get('detail')}.")
        next_steps = []
        if str(truth.get("label", "")).upper() != "LIVE":
            next_steps.append("Open /health and review Data Truth reason.")
        if top_source.get("name") or top_port.get("name"):
            next_steps.append("Open /why for the top source or port before changing firewall policy.")
        if int(ids.get("high_signal", 0) or 0):
            next_steps.append("Build an incident bundle before IDS tuning.")
        if not next_steps:
            next_steps.append("Keep SOCX in watch mode and let history accumulate.")
        metric_steps = metrics_intel.get("next_steps", []) if isinstance(metrics_intel.get("next_steps"), list) else []
        for step in metric_steps[:2]:
            if step not in next_steps:
                next_steps.append(step)
        return {
            "title": "SOCX Daily Story",
            "generated": time.strftime("%Y-%m-%d %H:%M:%S"),
            "hostname": socket.gethostname(),
            "status": "watch" if str(truth.get("tone", "")) in {"yellow", "red"} else "normal",
            "story": story_lines,
            "next_steps": next_steps[:5],
            "at_a_glance": {
                "data_truth": f"{truth.get('label', 'UNKNOWN')} {truth.get('score', '--')}/100",
                "autopilot": f"{center.get('mode', 'UNKNOWN')} {center.get('score', '--')}/100",
                "firewall": f"{top_source.get('name', 'none')} x{top_source.get('count', 0)}",
                "dnsbl": f"{top_dns.get('name', 'none')} x{top_dns.get('count', 0)}",
                "ids": f"high {ids.get('high_signal', 0)} watch {ids.get('watch', 0)}",
                "pi": f"{pi.get('online', 0)}/{pi.get('count', 0)} online",
                "hardware": hardware.get("summary", "--") if isinstance(hardware, dict) else "--",
                "metrics": f"{metrics_intel.get('severity', 'UNKNOWN')} {metrics_intel.get('summary', '')}" if isinstance(metrics_intel, dict) else "--",
            },
            "metrics_intel": metrics_intel,
            "hardware": hardware,
            "speedtest": {
                "direct": direct,
                "vpn": vpn,
                "history_count": speed.get("count", 0),
                "truth": center.get("speed_truth", {}),
            },
            "repeated_memory": {
                "sources": memory_sources[:5],
                "ports": memory_ports[:5],
                "dnsbl": (memory.get("dnsbl") if isinstance(memory.get("dnsbl"), list) else [])[:5],
                "samples": memory.get("count") or memory.get("samples") or 0,
            },
            "now_watching": now_watching[:6],
            "anomalies": anomalies[:6],
            "what_changed": changed_rows[:8],
            "ai": ai_rows[:5],
            "updated_ms": now_ms(),
        }

    def story_text(self, story: dict[str, Any]) -> str:
        lines = [
            str(story.get("title") or "SOCX Daily Story"),
            f"Generated: {story.get('generated', '')}",
            f"Host: {story.get('hostname', '')}",
            "",
            "At a glance:",
        ]
        glance = story.get("at_a_glance") if isinstance(story.get("at_a_glance"), dict) else {}
        for key, value in glance.items():
            lines.append(f"- {key}: {value}")
        lines.extend(["", "Story:"])
        for item in story.get("story", []) if isinstance(story.get("story"), list) else []:
            lines.append(f"- {item}")
        lines.extend(["", "Next steps:"])
        for item in story.get("next_steps", []) if isinstance(story.get("next_steps"), list) else []:
            lines.append(f"- {item}")
        lines.extend(["", "What changed:"])
        for item in story.get("what_changed", []) if isinstance(story.get("what_changed"), list) else []:
            lines.append(f"- {item.get('time', '')} {item.get('title', '')}: {item.get('detail', '')}")
        return "\n".join(lines).strip() + "\n"

    def create_story_archive(self) -> dict[str, Any]:
        story = self.collect_daily_story()
        base = Path(os.environ.get("SOCX_STORY_DIR", "/root/socx-stories"))
        stamp = time.strftime("%Y%m%d-%H%M%S")
        try:
            base.mkdir(parents=True, exist_ok=True)
            json_path = base / f"socx-story-{stamp}.json"
            txt_path = base / f"socx-story-{stamp}.txt"
            json_path.write_text(json.dumps(story, indent=2), encoding="utf-8")
            txt_path.write_text(self.story_text(story), encoding="utf-8")
            return {"ok": True, "json": str(json_path), "text": str(txt_path), "story": story}
        except Exception as exc:
            return {"ok": False, "error": str(exc), "story": story}

    def create_incident_bundle(self) -> dict[str, Any]:
        result = run_cmd_capture(["/usr/local/bin/socx", "incident", "quick"], timeout=120.0)
        output = result.get("output", "")
        latest = ""
        archive = ""
        checksum = ""
        for line in str(output).splitlines():
            low = line.lower()
            if "latest bundle:" in low:
                latest = line.split(":", 1)[1].strip()
            elif "snapshot archive:" in low or "archive:" in low:
                archive = line.split(":", 1)[1].strip()
            elif "sha256" in low:
                checksum = line.strip()
        return {
            "action": "incident-bundle",
            "title": "Incident bundle",
            "latest": latest,
            "archive": archive,
            "checksum": checksum,
            **result,
        }

    def remember_change(self, key: str, value: str, title: str, detail: str, severity: str = "LOW") -> None:
        old = self.change_last.get(key)
        if old == value:
            return
        self.change_last[key] = value
        if old is None:
            return
        self.change_events.appendleft({
            "time": time.strftime("%H:%M:%S"),
            "key": key,
            "severity": severity,
            "title": title,
            "detail": detail[:220],
            "from": old,
            "to": value,
            "ts": now_ms(),
        })

    def collect_what_changed(
        self,
        center: dict[str, Any],
        incident: dict[str, Any],
        label_brain: dict[str, Any],
        pi_nodes: dict[str, Any],
        net: dict[str, Any],
        ups: dict[str, Any],
        data_truth: dict[str, Any],
        flows: list[dict[str, Any]],
    ) -> dict[str, Any]:
        top_flow = ""
        if flows:
            row = flows[0]
            top_flow = f"{row.get('asset', '')}->{row.get('peer', '')} {row.get('service', '')}"
        self.remember_change("top_flow", top_flow, "Top flow changed", top_flow or "no flow", "LOW")
        direct = center.get("direct") if isinstance(center.get("direct"), dict) else {}
        vpn = center.get("vpn") if isinstance(center.get("vpn"), dict) else {}
        self.remember_change("speed_direct", f"{direct.get('path_state') or direct.get('status')}:{direct.get('down')}/{direct.get('up')}", "Direct Speedtest changed", f"{direct.get('down') or '--'}/{direct.get('up') or '--'} Mbps", "LOW")
        self.remember_change("speed_vpn", f"{vpn.get('path_state') or vpn.get('status')}:{vpn.get('down')}/{vpn.get('up')}", "VPN Speedtest changed", f"{vpn.get('path_state') or vpn.get('status') or 'waiting'} {vpn.get('down') or '--'}/{vpn.get('up') or '--'} Mbps", "MED")
        self.remember_change("pi_fleet", f"{pi_nodes.get('online', 0)}/{pi_nodes.get('count', 0)}:{pi_nodes.get('state', '')}", "Pi fleet changed", f"{pi_nodes.get('online', 0)}/{pi_nodes.get('count', 0)} online, cache {pi_nodes.get('state', 'unknown')}", "MED")
        self.remember_change("incident", str(incident.get("verdict") or ""), "Incident verdict changed", str(incident.get("headline") or incident.get("verdict") or ""), "MED")
        top_src = (incident.get("blocked_sources") or [{}])[0].get("name", "") if isinstance(incident.get("blocked_sources"), list) else ""
        self.remember_change("top_blocked_source", str(top_src), "Top blocked source changed", str(top_src or "none"), "MED")
        anomalies = label_brain.get("anomalies") if isinstance(label_brain.get("anomalies"), list) else []
        self.remember_change("device_anomalies", str(len(anomalies)), "Learned-normal changes changed", f"{len(anomalies)} unusual device observations", "MED" if anomalies else "LOW")
        self.remember_change("data_truth", str(data_truth.get("label")), "Data truth changed", f"{data_truth.get('label')} score {data_truth.get('score')}", "MED")
        self.remember_change("ups", "stale" if ups.get("stale") else "fresh", "UPS freshness changed", "UPS cache " + ("stale" if ups.get("stale") else "fresh"), "LOW")
        return {
            "count": len(self.change_events),
            "rows": list(self.change_events)[:12],
            "headline": (self.change_events[0]["title"] if self.change_events else "No major changes yet"),
            "updated_ms": now_ms(),
        }

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
                result["role_details"] = self.pi_role_details(data)
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

    def pi_role_details(self, data: dict[str, Any]) -> list[dict[str, Any]]:
        raw = data.get("roles")
        rows: list[dict[str, Any]] = []
        if isinstance(raw, dict):
            for name in ("triage", "evidence", "action"):
                role = raw.get(name)
                if isinstance(role, dict):
                    rows.append({
                        "role": name,
                        "state": role.get("state") or role.get("status") or data.get("latest_status") or "unknown",
                        "model": role.get("model") or role.get("model_name") or "",
                        "summary": role.get("summary") or role.get("reason") or role.get("last") or "",
                    })
                elif role:
                    rows.append({"role": name, "state": str(role), "model": "", "summary": ""})
        elif isinstance(raw, list):
            for item in raw:
                if isinstance(item, dict):
                    rows.append({
                        "role": item.get("role") or item.get("name") or "role",
                        "state": item.get("state") or item.get("status") or "unknown",
                        "model": item.get("model") or "",
                        "summary": item.get("summary") or item.get("reason") or "",
                    })
        if not rows:
            status = str(data.get("latest_status") or "online")
            models = data.get("models") if isinstance(data.get("models"), dict) else {}
            for name in ("triage", "evidence", "action"):
                rows.append({
                    "role": name,
                    "state": status if str(data.get("roles_online") or "") == "3/3" else "waiting",
                    "model": models.get(name, ""),
                    "summary": "visible role summary available from Pi health" if status == "ok" else "waiting for fresh Pi role state",
                })
        return rows[:6]

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
        now_watching = (label_brain.get("now_watching") if isinstance(label_brain.get("now_watching"), list) else [])[:4]
        profiles = (label_brain.get("profiles") if isinstance(label_brain.get("profiles"), list) else [])[:4]
        anomalies = (label_brain.get("anomalies") if isinstance(label_brain.get("anomalies"), list) else [])[:4]
        status = "green" if unknown_bytes < 50000 and pi_online == pi_count else "yellow"
        if pi_count and pi_online < pi_count:
            status = "red"
        if anomalies:
            status = "yellow"
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
            "now_watching": now_watching,
            "profiles": profiles,
            "anomalies": anomalies,
            "headline": headline,
        }

    def collect_netflow_intel(self) -> dict[str, Any]:
        cfg = parse_env_file(Path(os.environ.get("SOCX_OBSERVABILITY_CONF", "/usr/local/etc/socx_observability.conf")))
        influx_url = (cfg.get("SOCX_INFLUX_URL") or os.environ.get("SOCX_INFLUX_URL", "http://192.168.1.180:8086")).rstrip("/")
        influx_db = cfg.get("SOCX_INFLUX_DB") or os.environ.get("SOCX_INFLUX_DB", "pfsense")
        window = os.environ.get("SOCX_NETFLOW_WINDOW", "10m")
        query = (
            "SELECT src,dst,src_port,dst_port,protocol,in_bytes,in_packets "
            f"FROM netflow WHERE time > now() - {window} "
            "ORDER BY time DESC LIMIT 1200"
        )
        url = f"{influx_url}/query?{urlencode({'db': influx_db, 'q': query})}"
        rows: list[dict[str, Any]] = []
        error = ""
        try:
            req = Request(url, headers={"User-Agent": "SOCX/netflow-intel"})
            with urlopen(req, timeout=float(os.environ.get("SOCX_NETFLOW_TIMEOUT", "3.0"))) as resp:
                data = json.loads(resp.read(900000).decode("utf-8", "replace") or "{}")
            grouped: dict[tuple[str, str, str, str], dict[str, Any]] = {}
            for series in data.get("results", [{}])[0].get("series", []) or []:
                columns = [str(col) for col in series.get("columns", [])]
                for values in series.get("values") or []:
                    row = dict(zip(columns, values))
                    byte_count = int(float(row.get("in_bytes") or 0))
                    packet_count = int(float(row.get("in_packets") or 0))
                    if byte_count <= 0 and packet_count <= 0:
                        continue
                    src = str(row.get("src") or "")
                    dst = str(row.get("dst") or "")
                    src_port = str(row.get("src_port") or "")
                    dst_port = str(row.get("dst_port") or "")
                    proto = str(row.get("protocol") or "").lower()
                    key = (src, dst, src_port, dst_port, proto)
                    item = grouped.setdefault(key, {
                        "src": src,
                        "dst": dst,
                        "src_port": src_port,
                        "dst_port": dst_port,
                        "proto": proto,
                        "bytes": 0,
                        "packets": 0,
                    })
                    item["bytes"] += byte_count
                    item["packets"] += packet_count
            for item in grouped.values():
                src = str(item.get("src") or "")
                dst = str(item.get("dst") or "")
                src_port = str(item.get("src_port") or "")
                dst_port = str(item.get("dst_port") or "")
                port = self.flow_service_port(src, dst, src_port, dst_port)
                proto = str(item.get("proto") or "").lower()
                direction = self.flow_direction(src, dst)
                asset_ip = src if src.startswith("192.168.1.") else dst if dst.startswith("192.168.1.") else src
                peer_ip = dst if asset_ip == src else src
                service = app_label(peer_ip) or service_name(port)
                app = app_label(peer_ip) or app_label(service) or service
                rows.append({
                    "src": src,
                    "dst": dst,
                    "asset": self.pretty_host(asset_ip),
                    "peer": self.pretty_host(peer_ip),
                    "direction": direction,
                    "port": port,
                    "src_port": src_port,
                    "dst_port": dst_port,
                    "proto": proto.upper() if proto else "--",
                    "service": service,
                    "app": app,
                    "bytes": int(item.get("bytes", 0) or 0),
                    "bytes_h": human_bytes(item.get("bytes", 0)),
                    "packets": int(item.get("packets", 0) or 0),
                })
        except Exception as exc:
            error = str(exc)[:180]
        rows = self.collapse_nat_flow_rows(rows)
        rows = self.apply_flow_baselines(rows)
        rows.sort(key=lambda row: int(row.get("bytes", 0) or 0), reverse=True)

        def rank(key: str, label_key: str = "") -> list[dict[str, Any]]:
            totals: dict[str, dict[str, Any]] = {}
            for row in rows:
                name = str(row.get(key) or "--")
                if not name or name == "--":
                    continue
                item = totals.setdefault(name, {"name": name, "bytes": 0, "packets": 0, "count": 0})
                item["bytes"] += int(row.get("bytes", 0) or 0)
                item["packets"] += int(row.get("packets", 0) or 0)
                item["count"] += 1
                if label_key and row.get(label_key):
                    item[label_key] = row.get(label_key)
            ranked = sorted(totals.values(), key=lambda item: int(item.get("bytes", 0) or 0), reverse=True)[:8]
            for item in ranked:
                item["bytes_h"] = human_bytes(item.get("bytes", 0))
            return ranked

        total_bytes = sum(int(row.get("bytes", 0) or 0) for row in rows)
        top_flow = rows[0] if rows else {}
        status = "OK" if rows else ("WAITING" if not error else "WARN")
        summary = f"{len(rows)} flow groups, {human_bytes(total_bytes)} in {window}"
        if top_flow:
            top_path = top_flow.get("display_path") or f"{top_flow.get('asset')} -> {top_flow.get('peer')}"
            summary = f"top {top_path} {top_flow.get('bytes_h')} via {top_flow.get('app')}"
        elif error:
            summary = f"netflow waiting: {error}"
        stories = []
        for row in rows[:5]:
            story_path = row.get("display_path") or f"{row.get('asset')} -> {row.get('peer')}"
            stories.append(f"{story_path} | {row.get('app')} | {row.get('bytes_h')} | {row.get('baseline_state')}")
        baseline_counts: dict[str, int] = {}
        for row in rows:
            state = str(row.get("baseline_state") or "unknown")
            baseline_counts[state] = baseline_counts.get(state, 0) + 1
        watch_rows = [row for row in rows if str(row.get("baseline_state")) in {"watch", "new"}][:8]
        return {
            "status": status,
            "summary": summary,
            "window": window,
            "influx_url": influx_url,
            "rows": rows[:30],
            "top_assets": rank("asset"),
            "top_peers": rank("peer"),
            "top_apps": rank("app"),
            "total_bytes": total_bytes,
            "total_bytes_h": human_bytes(total_bytes),
            "stories": stories,
            "baseline_counts": baseline_counts,
            "watch_rows": watch_rows,
            "error": error,
            "updated_ms": now_ms(),
        }

    def flow_service_port(self, src: str, dst: str, src_port: str, dst_port: str) -> str:
        src_lan = src.startswith("192.168.1.")
        dst_lan = dst.startswith("192.168.1.")
        src_known = service_name(src_port)
        dst_known = service_name(dst_port)
        src_is_named = not src_known.startswith("port ") and src_known != "other"
        dst_is_named = not dst_known.startswith("port ") and dst_known != "other"
        if src_lan and not dst_lan:
            return dst_port or src_port
        if dst_lan and not src_lan:
            return src_port if src_is_named or not dst_is_named else dst_port
        if src == "WAN" or self.pretty_host(src) == "WAN":
            return dst_port or src_port
        if dst == "WAN" or self.pretty_host(dst) == "WAN":
            return src_port if src_port else dst_port
        if src_is_named and not dst_is_named:
            return src_port
        return dst_port or src_port

    def collapse_nat_flow_rows(self, rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
        """Prefer the LAN endpoint when IPFIX reports paired firewall/WAN and LAN legs."""
        lan_by_peer: dict[tuple[str, str, str], dict[str, Any]] = {}
        for row in rows:
            asset = str(row.get("asset") or "")
            peer = str(row.get("peer") or "")
            app = str(row.get("app") or "")
            port = str(row.get("port") or "")
            if asset.startswith("LAN.") or "192.168.1." in asset or asset.startswith("MIRANDA") or asset.startswith("Columbia") or asset.startswith("Pi"):
                key = (peer, app, port)
                best = lan_by_peer.get(key)
                if not best or int(row.get("bytes", 0) or 0) > int(best.get("bytes", 0) or 0):
                    lan_by_peer[key] = row
        collapsed: list[dict[str, Any]] = []
        seen_keys: set[tuple[str, str, str, str]] = set()
        for original in rows:
            row = dict(original)
            asset = str(row.get("asset") or "")
            peer = str(row.get("peer") or "")
            app = str(row.get("app") or "")
            port = str(row.get("port") or "")
            replacement = lan_by_peer.get((peer, app, port))
            if asset == "WAN" and replacement:
                row["asset"] = replacement.get("asset")
                row["src"] = replacement.get("src")
                row["dst"] = replacement.get("dst")
                row["direction"] = replacement.get("direction") or "LAN-WAN"
                row["path_note"] = "via WAN"
            row["display_path"] = self.flow_display_path(row)
            key = (str(row.get("asset")), str(row.get("peer")), str(row.get("app")), str(row.get("port")))
            if key in seen_keys:
                continue
            seen_keys.add(key)
            collapsed.append(row)
        return collapsed

    def flow_display_path(self, row: dict[str, Any]) -> str:
        asset = str(row.get("asset") or "--")
        peer = str(row.get("peer") or "--")
        path_note = str(row.get("path_note") or "")
        if path_note:
            return f"{asset} {path_note} -> {peer}"
        return f"{asset} -> {peer}"

    def flow_expected_apps(self, asset: str) -> tuple[str, set[str]]:
        lower = asset.lower()
        if "miranda" in lower or "workstation" in lower:
            return "AI/research workstation", {"https", "dns", "OpenAI", "Anthropic", "Hugging Face", "IBM Quantum", "NVIDIA AI", "GitHub", "Microsoft", "Google APIs", "AWS/CloudFront"}
        if "pi" in lower or "columbia" in lower or "192.168.1.121" in lower or "192.168.1.180" in lower:
            return "SOCX/Pi AI node", {"https", "dns", "ssh", "Ollama", "vLLM", "pi-llm", "socx-web", "influx", "metrics", "node-exporter", "Google APIs"}
        if "apple" in lower or "tv" in lower or "roku" in lower:
            return "streaming/media device", {"https", "dns", "Netflix", "Prime Video", "YouTube", "Apple/iCloud", "Apple Push", "Disney+", "Hulu", "Max", "Peacock", "Roku"}
        if asset.startswith("LAN."):
            return "unknown LAN learner", {"https", "dns", "ntp", "Apple/iCloud", "Apple Push", "Google APIs", "Microsoft"}
        if asset == "WAN":
            return "firewall/WAN transit", {"https", "dns", "ntp", "ipsec-nat", "ike"}
        return "known network device", {"https", "dns", "ntp", "ssh", "Microsoft", "Google APIs", "Apple/iCloud"}

    def apply_flow_baselines(self, rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
        for row in rows:
            asset = str(row.get("asset") or "")
            app = str(row.get("app") or row.get("service") or "other")
            profile, expected = self.flow_expected_apps(asset)
            byte_count = int(row.get("bytes", 0) or 0)
            is_expected = app in expected or str(row.get("service") or "") in expected
            if is_expected:
                state = "normal"
                tone = "green"
                why = f"{app} is expected for {profile}"
            elif app.startswith("port ") or app in {"other", "unknown"}:
                state = "watch" if byte_count > 1_000_000 else "new"
                tone = "yellow"
                why = f"{app} is not yet mapped for {profile}"
            else:
                state = "new"
                tone = "yellow"
                why = f"{app} is new for {profile}"
            row["profile"] = profile
            row["expected_apps"] = sorted(expected)[:8]
            row["baseline_state"] = state
            row["baseline_tone"] = tone
            row["why"] = why
            row["display_path"] = row.get("display_path") or self.flow_display_path(row)
        return rows

    def flow_direction(self, src: str, dst: str) -> str:
        src_lan = src.startswith("192.168.1.")
        dst_lan = dst.startswith("192.168.1.")
        if src_lan and dst_lan:
            return "LAN-LAN"
        if src_lan:
            return "LAN-WAN"
        if dst_lan:
            return "WAN-LAN"
        return "WAN"

    def collect_device_trust(
        self,
        flows: list[dict[str, Any]],
        label_brain: dict[str, Any],
        netflow: dict[str, Any],
        incident: dict[str, Any],
        pi_nodes: dict[str, Any],
    ) -> dict[str, Any]:
        devices: dict[str, dict[str, Any]] = {}
        for dev in label_brain.get("devices", []) if isinstance(label_brain.get("devices"), list) else []:
            asset = str(dev.get("asset") or dev.get("friendly_name") or "device")
            confidence = str(dev.get("identity_confidence") or dev.get("confidence") or "unknown")
            score = 86
            if confidence == "confirmed":
                score += 8
            elif confidence == "unknown":
                score -= 18
            unusual = dev.get("unusual") if isinstance(dev.get("unusual"), list) else []
            score -= min(24, len(unusual) * 8)
            devices[asset] = {
                "asset": asset,
                "score": int(clamp(score, 0, 100)),
                "confidence": confidence,
                "profile": dev.get("profile", "device"),
                "apps": dev.get("apps", [])[:4],
                "unusual": unusual[:4],
                "bytes": 0,
                "bytes_h": "--",
                "reason": dev.get("summary", "local passive identity"),
            }
        for row in netflow.get("top_assets", []) if isinstance(netflow.get("top_assets"), list) else []:
            asset = str(row.get("name") or "device")
            item = devices.setdefault(asset, {
                "asset": asset,
                "score": 74 if asset.startswith("LAN.") else 84,
                "confidence": "likely" if not asset.startswith("LAN.") else "unknown",
                "profile": "network-active",
                "apps": [],
                "unusual": [],
                "bytes": 0,
                "bytes_h": "--",
                "reason": "active in NetFlow",
            })
            item["bytes"] = int(row.get("bytes", 0) or 0)
            item["bytes_h"] = row.get("bytes_h") or human_bytes(item["bytes"])
            if item["bytes"] > 2_000_000_000:
                item["score"] = int(item["score"]) - 8
                item["reason"] = f"heavy NetFlow usage {item['bytes_h']}"
        for row in netflow.get("watch_rows", []) if isinstance(netflow.get("watch_rows"), list) else []:
            asset = str(row.get("asset") or "device")
            item = devices.setdefault(asset, {
                "asset": asset,
                "score": 74 if asset.startswith("LAN.") else 84,
                "confidence": "likely" if not asset.startswith("LAN.") else "unknown",
                "profile": row.get("profile") or "network-active",
                "apps": [],
                "unusual": [],
                "bytes": 0,
                "bytes_h": "--",
                "reason": "active in NetFlow",
            })
            penalty = 10 if row.get("baseline_state") == "watch" else 5
            item["score"] = max(0, int(item.get("score", 74)) - penalty)
            item["reason"] = str(row.get("why") or "new flow outside learned baseline")
        blocked_lan = {str(x.get("name")) for x in incident.get("lan_hosts", [])} if isinstance(incident.get("lan_hosts"), list) else set()
        for asset, item in devices.items():
            if any(host and host in asset for host in blocked_lan):
                item["score"] = int(item["score"]) - 12
                item["reason"] = "recent LAN policy block plus " + str(item.get("reason", "activity"))
        for node in pi_nodes.get("nodes", []) if isinstance(pi_nodes.get("nodes"), list) else []:
            name = str(node.get("name") or node.get("ip") or "")
            if not name:
                continue
            item = devices.setdefault(name, {
                "asset": name,
                "score": 92 if node.get("status") == "online" else 58,
                "confidence": "confirmed",
                "profile": node.get("role_h") or "Pi node",
                "apps": [],
                "unusual": [],
                "bytes": 0,
                "bytes_h": "--",
                "reason": f"Pi fleet node {node.get('status', 'unknown')}",
            })
            if node.get("status") != "online":
                item["score"] = min(int(item["score"]), 58)
        rows = sorted(devices.values(), key=lambda item: int(item.get("score", 0)), reverse=False)[:12]
        avg = round(sum(int(item.get("score", 0) or 0) for item in devices.values()) / max(1, len(devices)))
        label = "TRUSTED" if avg >= 85 else "WATCH" if avg >= 70 else "INVESTIGATE"
        tone = "green" if label == "TRUSTED" else "yellow" if label == "WATCH" else "red"
        return {
            "label": label,
            "tone": tone,
            "score": avg,
            "summary": f"{len(devices)} devices scored; lowest {rows[0].get('asset', '--') if rows else '--'} {rows[0].get('score', '--') if rows else '--'}",
            "rows": rows,
            "updated_ms": now_ms(),
        }

    def collect_mission_assurance(
        self,
        center: dict[str, Any],
        data_truth: dict[str, Any],
        incident: dict[str, Any],
        power_mods: dict[str, Any],
        netflow: dict[str, Any],
        device_trust: dict[str, Any],
        pi_nodes: dict[str, Any],
        hardware: dict[str, Any],
    ) -> dict[str, Any]:
        items = power_mods.get("items") if isinstance(power_mods.get("items"), dict) else {}
        ids = incident.get("ids") if isinstance(incident.get("ids"), dict) else {}
        scores = {
            "govern": 92 if (items.get("config_drift", {}).get("status") == "OK" and items.get("evidence_vault", {}).get("status") == "OK") else 72,
            "identify": int(device_trust.get("score", 70) or 70),
            "protect": int(center.get("scores", {}).get("network") or 82) if isinstance(center.get("scores"), dict) else 82,
            "detect": 94 if items.get("flow_export", {}).get("status") == "OK" and netflow.get("status") == "OK" else 74,
            "respond": 88 if items.get("quarantine_draft", {}).get("status") in {"PLAN", "OK"} else 70,
            "recover": 90 if items.get("config_drift", {}).get("status") == "OK" else 70,
        }
        if int(ids.get("high_signal", 0) or 0) > 0:
            scores["detect"] = min(100, scores["detect"] + 3)
            scores["respond"] = max(60, scores["respond"] - 8)
        if str(data_truth.get("label", "")).upper() != "LIVE":
            scores["detect"] = max(55, scores["detect"] - 10)
        if int(pi_nodes.get("online", 0) or 0) < int(pi_nodes.get("count", 0) or 0):
            scores["respond"] = max(55, scores["respond"] - 8)
        ht = hardware.get("trend") if isinstance(hardware.get("trend"), dict) else {}
        if ht.get("headroom_c") is not None and float(ht.get("headroom_c") or 0) < 5:
            scores["recover"] = max(55, scores["recover"] - 8)
        overall = round(sum(scores.values()) / len(scores))
        label = "READY" if overall >= 88 else "WATCH" if overall >= 72 else "GAP"
        tone = "green" if label == "READY" else "yellow" if label == "WATCH" else "red"
        weakest = sorted(scores.items(), key=lambda kv: kv[1])[:2]
        return {
            "framework": "NIST CSF 2.0 + CISA Zero Trust + SOCX local evidence",
            "label": label,
            "tone": tone,
            "score": overall,
            "scores": scores,
            "weakest": [{"name": k, "score": v} for k, v in weakest],
            "summary": f"Mission {label} {overall}/100 | weakest {weakest[0][0]} {weakest[0][1]}",
            "next": [
                "Keep NetFlow receiver green and review /flows for top talkers",
                "Enable LLDP on switch/AP side for topology truth" if items.get("lldp", {}).get("status") != "OK" else "Review topology changes after switch/AP updates",
                "Use Evidence before Drift/Rules/Quarantine changes",
            ],
            "updated_ms": now_ms(),
        }

    def collect_label_brain(self, flows: list[dict[str, Any]], incident: dict[str, Any]) -> dict[str, Any]:
        """Build a local, passive label model from pf states and DNS/DNSBL evidence."""
        devices: dict[str, dict[str, Any]] = {}
        apps: dict[str, int] = {}
        history = self.label_history_cache if isinstance(self.label_history_cache, dict) else {"devices": {}}

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
            profile = self.device_profile(row["asset"], app_rank, service_rank)
            baseline = self.baseline_for_asset(history, row["asset"])
            unusual = self.unusual_for_asset(baseline, app_rank, service_rank)
            rows.append({
                "asset": row["asset"],
                "friendly_name": row["asset"].split("/", 1)[0],
                "identity_confidence": self.identity_confidence(row["asset"], confidence),
                "flows": row["flows"],
                "apps": app_rank,
                "services": service_rank,
                "domains": domain_rank,
                "confidence": confidence,
                "profile": profile,
                "normal_apps": baseline.get("apps", [])[:4],
                "normal_services": baseline.get("services", [])[:4],
                "unusual": unusual,
                "summary": self.device_identity_summary(row["asset"], app_rank, service_rank, confidence),
            })
        rows.sort(key=lambda item: (len(item.get("apps", [])), int(item.get("flows", 0))), reverse=True)
        anomalies = [
            {"asset": row["asset"], "items": row["unusual"], "profile": row.get("profile", "device")}
            for row in rows if row.get("unusual")
        ][:8]
        top_apps = rank_map(apps, 8)
        self.update_label_history(rows)
        return {
            "mode": "local-passive",
            "privacy": "local DNS, pf states, DNSBL and host labels only",
            "devices": rows[:10],
            "top_apps": top_apps,
            "now_watching": self.now_watching_groups(rank_map(apps, 12)),
            "profiles": self.profile_counts(rows),
            "anomalies": anomalies,
            "updated_ms": now_ms(),
        }

    def identity_confidence(self, asset: str, activity_confidence: str) -> str:
        if "/" in asset and not asset.startswith(("LAN.", "EXT.")):
            return "confirmed"
        if activity_confidence in {"high", "medium"}:
            return "likely"
        return "unknown"

    def collect_incident_timeline(self, incident: dict[str, Any], label_brain: dict[str, Any], ai_timeline: dict[str, Any]) -> dict[str, Any]:
        rows: list[dict[str, Any]] = []
        generated = now_ms()

        def add(kind: str, severity: str, title: str, detail: str, evidence: str = "") -> None:
            rows.append({
                "time": time.strftime("%H:%M:%S"),
                "kind": kind,
                "severity": severity,
                "title": title[:80],
                "detail": detail[:220],
                "evidence": evidence[:160],
                "ts": generated,
            })

        sources = incident.get("blocked_sources") if isinstance(incident.get("blocked_sources"), list) else []
        ports = incident.get("blocked_ports") if isinstance(incident.get("blocked_ports"), list) else []
        dns = incident.get("dnsbl_domains") if isinstance(incident.get("dnsbl_domains"), list) else []
        ids = incident.get("ids") if isinstance(incident.get("ids"), dict) else {}
        anomalies = label_brain.get("anomalies") if isinstance(label_brain.get("anomalies"), list) else []
        ai_rows = ai_timeline.get("rows") if isinstance(ai_timeline.get("rows"), list) else []

        if sources:
            top = sources[0]
            port_text = ", ".join(str(item.get("name")) for item in ports[:3]) or "mixed"
            sev = "HIGH" if int(top.get("count", 0) or 0) >= 80 else "MED"
            add("FW", sev, "WAN scan pressure", f"{top.get('count', 0)} blocked events from {top.get('name', 'unknown')}; top ports {port_text}", "filter.log")
        if dns:
            top = dns[0]
            sev = "MED" if int(top.get("count", 0) or 0) >= 25 else "LOW"
            add("DNSBL", sev, "DNS block activity", f"{top.get('name', 'domain')} blocked x{top.get('count', 0)}", "pfBlockerNG DNSBL")
        if int(ids.get("high_signal", 0) or 0) > 0:
            add("IDS", "HIGH", "High-signal IDS activity", f"{ids.get('high_signal')} high-signal alerts; {ids.get('watch', 0)} watch alerts", "Suricata")
        elif int(ids.get("watch", 0) or 0) > 0:
            add("IDS", "LOW", "Routine IDS watch noise", f"{ids.get('watch')} watch alerts and {ids.get('routine', 0)} routine decoder/stream lines", "Suricata")
        for anomaly in anomalies[:2]:
            add("DEVICE", "MED", "Learned-normal deviation", f"{anomaly.get('asset', 'device')} showed unusual {', '.join(anomaly.get('items', [])[:3])}", "Label Brain")
        for ai in ai_rows[:2]:
            sev = str(ai.get("severity") or "INFO").upper()
            add("AI", "MED" if "WARN" in sev or "WATCH" in sev else "LOW", f"{ai.get('source', 'AI')} verdict", str(ai.get("reason") or "analysis available"), f"confidence {ai.get('confidence', '--')}")
        if not rows:
            add("SOCX", "LOW", "Quiet cycle", "No meaningful firewall, DNSBL, IDS, device, or AI pressure in the current sample.", "live collectors")
        return {"rows": rows[:8], "count": len(rows), "generated_ms": generated}

    def collect_daily_brief(
        self,
        center: dict[str, Any],
        incident: dict[str, Any],
        label_brain: dict[str, Any],
        pi_nodes: dict[str, Any],
        ai_timeline: dict[str, Any],
        metrics_intel: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        ids = incident.get("ids") if isinstance(incident.get("ids"), dict) else {}
        counts = incident.get("counts") if isinstance(incident.get("counts"), dict) else {}
        top_apps = label_brain.get("top_apps") if isinstance(label_brain.get("top_apps"), list) else []
        anomalies = label_brain.get("anomalies") if isinstance(label_brain.get("anomalies"), list) else []
        rows = ai_timeline.get("rows") if isinstance(ai_timeline.get("rows"), list) else []
        speed_truth = center.get("speed_truth") if isinstance(center.get("speed_truth"), dict) else {}
        direct = center.get("direct") if isinstance(center.get("direct"), dict) else {}
        vpn = center.get("vpn") if isinstance(center.get("vpn"), dict) else {}
        summary = [
            f"Autopilot {str(center.get('mode') or 'UNKNOWN').upper()} score {center.get('score', '--')}/100",
            f"FW blocks {counts.get('sources', 0)} | DNSBL {counts.get('dnsbl', 0)} | IDS signal {ids.get('high_signal', 0)}",
            f"Pi fleet {pi_nodes.get('online', 0)}/{pi_nodes.get('count', 0)} online",
        ]
        if metrics_intel:
            summary.append(f"Metrics {metrics_intel.get('severity', 'UNKNOWN')}: {metrics_intel.get('summary', 'waiting')}")
        if top_apps:
            summary.append("Now watching " + ", ".join(str(item.get("name")) for item in top_apps[:4]))
        if anomalies:
            summary.append(f"{len(anomalies)} learned-normal device changes need a look")
        elif rows:
            summary.append(f"AI verdicts present from {', '.join(str(row.get('source')) for row in rows[:2])}")
        else:
            summary.append("No unusual learned-normal changes in the current window")
        mode_text = str(center.get("mode") or "").upper()
        metric_watch = bool(metrics_intel and str(metrics_intel.get("severity", "")).upper() not in {"", "OK"})
        watch = metric_watch or bool(anomalies) or int(ids.get("high_signal", 0) or 0) or any(token in mode_text for token in ["WATCH", "DEGRADED", "INCIDENT", "INVESTIGATE"])
        top_app_text = ", ".join(str(item.get("name")) for item in top_apps[:3]) if top_apps else "local apps still learning"
        speed_text = str(speed_truth.get("label") or "speed truth waiting")
        if direct.get("down") or vpn.get("down"):
            speed_text = f"{speed_text}: direct {direct.get('down', '--')}/{direct.get('up', '--')} Mbps, vpn {vpn.get('down', '--')}/{vpn.get('up', '--')} Mbps"
        sections = [
            {"label": "What changed", "value": summary[0], "tone": "yellow" if watch else "green"},
            {"label": "Security", "value": summary[1], "tone": "red" if int(ids.get("high_signal", 0) or 0) else "yellow" if int(counts.get("sources", 0) or 0) else "green"},
            {"label": "Metrics", "value": metrics_intel.get("summary", "metrics waiting") if metrics_intel else "metrics waiting", "tone": "green" if metrics_intel and metrics_intel.get("severity") == "OK" else "yellow"},
            {"label": "Speed", "value": speed_text, "tone": "cyan"},
            {"label": "AI/Fleet", "value": f"Pi {pi_nodes.get('online', 0)}/{pi_nodes.get('count', 0)} online; apps: {top_app_text}", "tone": "green" if int(pi_nodes.get("online", 0) or 0) else "yellow"},
        ]
        next_steps = (center.get("actions") if isinstance(center.get("actions"), list) else ["socx status"])[:4]
        if metrics_intel and isinstance(metrics_intel.get("next_steps"), list):
            for step in metrics_intel.get("next_steps", [])[:2]:
                if step not in next_steps:
                    next_steps.append(step)
        return {
            "title": "SOCX Morning Brief",
            "generated": time.strftime("%Y-%m-%d %H:%M:%S"),
            "headline": "WATCH: review the highlighted signals" if watch else "STABLE: no urgent action needed",
            "summary": summary,
            "sections": sections,
            "next": next_steps[:5],
            "status": "watch" if watch else "normal",
        }

    def collect_rule_assistant(self, incident: dict[str, Any], label_brain: dict[str, Any]) -> dict[str, Any]:
        drafts: list[dict[str, Any]] = []
        sources = incident.get("blocked_sources") if isinstance(incident.get("blocked_sources"), list) else []
        ports = incident.get("blocked_ports") if isinstance(incident.get("blocked_ports"), list) else []
        dns = incident.get("dnsbl_domains") if isinstance(incident.get("dnsbl_domains"), list) else []
        ids = incident.get("ids") if isinstance(incident.get("ids"), dict) else {}
        anomalies = label_brain.get("anomalies") if isinstance(label_brain.get("anomalies"), list) else []

        if sources and int(sources[0].get("count", 0) or 0) >= 40:
            drafts.append({
                "kind": "firewall-review",
                "confidence": "medium",
                "recommendation": f"Review WAN scan source {sources[0].get('name')} before creating any persistent block.",
                "evidence": f"{sources[0].get('count')} blocked events; top ports {', '.join(str(p.get('name')) for p in ports[:3]) or 'mixed'}",
                "safe_command": "socx incident quick",
            })
        if dns:
            drafts.append({
                "kind": "dnsbl-review",
                "confidence": "medium",
                "recommendation": f"Inspect DNSBL domain/app {dns[0].get('name')} and affected LAN hosts before allowlisting or suppressing.",
                "evidence": f"{dns[0].get('count')} DNSBL observations from local pfBlockerNG logs",
                "safe_command": "socx-doctor dnsbl-review",
            })
        if int(ids.get("high_signal", 0) or 0) > 0:
            drafts.append({
                "kind": "ids-review",
                "confidence": "medium",
                "recommendation": "Preserve evidence and review matching Suricata signatures before suppressing or tuning IDS rules.",
                "evidence": f"{ids.get('high_signal')} high-signal IDS lines; {ids.get('watch', 0)} watch lines; {ids.get('routine', 0)} routine lines",
                "safe_command": "socx snapshot",
            })
        if anomalies:
            first = anomalies[0]
            drafts.append({
                "kind": "device-profile-review",
                "confidence": "low",
                "recommendation": f"Verify whether {first.get('asset')} should normally use {', '.join(first.get('items', [])[:3])}.",
                "evidence": "Label Brain learned-normal history saw a new app/service pattern",
                "safe_command": "socx label-brain",
            })
        if not drafts:
            drafts.append({
                "kind": "no-rule-change",
                "confidence": "high",
                "recommendation": "No firewall/DNSBL/IDS policy change is recommended from the current evidence.",
                "evidence": "Current sample lacks high-confidence repeated harmful behavior.",
                "safe_command": "socx status",
            })
        return {"mode": "approval-only", "applies_changes": False, "drafts": drafts[:4], "updated_ms": now_ms()}

    def read_jsonl_tail(self, path: Path, limit: int = 240) -> list[dict[str, Any]]:
        try:
            lines = path.read_text(errors="ignore").splitlines()[-limit:]
        except Exception:
            return []
        rows: list[dict[str, Any]] = []
        for raw in lines:
            try:
                item = json.loads(raw)
            except Exception:
                continue
            if isinstance(item, dict):
                rows.append(item)
        return rows

    def collect_speedtest_history(self) -> dict[str, Any]:
        path = Path(os.environ.get("SOCX_SPEEDTEST_HISTORY_FILE", "/var/db/socx_speedtest_history.jsonl"))
        rows = self.read_jsonl_tail(path, 500)
        now = time.time()
        windows = {"24h": 86400, "7d": 604800}
        paths: dict[str, dict[str, Any]] = {}
        for row in rows:
            slug = str(row.get("path_slug") or row.get("profile") or "unknown")
            paths.setdefault(slug, {"path": slug, "samples": 0, "ok": 0, "errors": 0, "download": [], "upload": [], "ping": []})
            bucket = paths[slug]
            bucket["samples"] += 1
            if str(row.get("status") or "").lower() != "ok":
                bucket["errors"] += 1
                continue
            down = float(row.get("download_mbps") or 0)
            up = float(row.get("upload_mbps") or 0)
            ping = float(row.get("ping_ms") or 0)
            if down > 0 and up > 0:
                bucket["ok"] += 1
                bucket["download"].append(down)
                bucket["upload"].append(up)
                if ping > 0:
                    bucket["ping"].append(ping)
        summaries: list[dict[str, Any]] = []
        for slug, bucket in paths.items():
            downs = bucket.pop("download")
            ups = bucket.pop("upload")
            pings = bucket.pop("ping")
            if downs:
                avg_down = sum(downs) / len(downs)
                worst_down = min(downs)
                status = "watch" if avg_down and worst_down < avg_down * 0.55 else "stable"
                summaries.append({
                    **bucket,
                    "avg_down": round(avg_down),
                    "avg_up": round(sum(ups) / len(ups)) if ups else "",
                    "best_down": round(max(downs)),
                    "worst_down": round(worst_down),
                    "avg_ping": round(sum(pings) / len(pings)) if pings else "",
                    "status": status,
                })
            else:
                summaries.append({**bucket, "avg_down": "", "avg_up": "", "best_down": "", "worst_down": "", "avg_ping": "", "status": "waiting"})
        latest = rows[-1] if rows else {}
        age = int(max(0, now - float(latest.get("ts") or 0))) if latest.get("ts") else None
        by_window: dict[str, int] = {}
        for label, seconds in windows.items():
            by_window[label] = sum(1 for row in rows if float(row.get("ts") or 0) >= now - seconds)
        return {"rows": rows[-80:], "paths": sorted(summaries, key=lambda item: str(item.get("path"))), "count": len(rows), "latest_age_sec": age, "windows": by_window}

    def collect_replay(self, window_seconds: int | None = None) -> dict[str, Any]:
        """Build a calm, read-only flight recorder from existing SOCX history."""
        snap = self.snapshot()
        rows = self.collect_history(720)
        now = time.time()
        window_seconds = window_seconds or env_int("SOCX_REPLAY_WINDOW_SECONDS", 21600)
        window_seconds = int(clamp(window_seconds, 900, 86400))
        recent = [row for row in rows if self.replay_row_ts(row) >= now - window_seconds]
        if not recent:
            recent = rows[-180:]
        trend = self.history_trend(recent)
        speed = snap.get("speedtest_history") or self.collect_speedtest_history()
        incident = snap.get("incident") if isinstance(snap.get("incident"), dict) else {}
        memory = snap.get("incident_memory") if isinstance(snap.get("incident_memory"), dict) else self.collect_incident_memory()
        data_truth = snap.get("data_truth") if isinstance(snap.get("data_truth"), dict) else {}
        mission = snap.get("mission") if isinstance(snap.get("mission"), dict) else {}
        netflow = snap.get("netflow_intel") if isinstance(snap.get("netflow_intel"), dict) else {}

        buckets = self.replay_buckets(recent, 18)
        score_vals = [self.safe_float(row.get("score")) for row in recent if self.safe_float(row.get("score")) > 0]
        cpu_vals = [self.safe_float(row.get("cpu_temp_max") or row.get("cpu_temp")) for row in recent if self.safe_float(row.get("cpu_temp_max") or row.get("cpu_temp")) > 0]
        ups_vals = [self.safe_float(row.get("ups_watts")) for row in recent if self.safe_float(row.get("ups_watts")) > 0]
        direct_vals = [self.safe_float(row.get("direct_down")) for row in recent if self.safe_float(row.get("direct_down")) > 0]
        vpn_vals = [self.safe_float(row.get("vpn_down")) for row in recent if self.safe_float(row.get("vpn_down")) > 0]

        what_changed = snap.get("what_changed", {}).get("rows", []) if isinstance(snap.get("what_changed"), dict) else []
        incident_rows = snap.get("incident_timeline", {}).get("rows", []) if isinstance(snap.get("incident_timeline"), dict) else []
        ai_rows = snap.get("ai_timeline", {}).get("rows", []) if isinstance(snap.get("ai_timeline"), dict) else []
        replay_rows: list[dict[str, Any]] = []
        for row in what_changed[:6]:
            replay_rows.append({
                "time": row.get("time") or time.strftime("%H:%M:%S"),
                "lane": "CHANGE",
                "severity": row.get("severity") or "MED",
                "title": row.get("title") or "SOCX change",
                "detail": row.get("detail") or row.get("to") or "--",
            })
        for row in incident_rows[:6]:
            replay_rows.append({
                "time": row.get("time") or time.strftime("%H:%M:%S"),
                "lane": row.get("kind") or "INCIDENT",
                "severity": row.get("severity") or "LOW",
                "title": row.get("title") or "Incident signal",
                "detail": row.get("detail") or row.get("evidence") or "--",
            })
        for row in ai_rows[:4]:
            replay_rows.append({
                "time": row.get("age_h") or "live",
                "lane": "AI",
                "severity": row.get("severity") or "INFO",
                "title": row.get("source") or "AI verdict",
                "detail": row.get("reason") or "--",
            })

        speed_rows = []
        for row in (speed.get("rows") if isinstance(speed, dict) else [])[-10:]:
            status = str(row.get("status") or "").upper()
            speed_rows.append({
                "time": self.replay_time_label(self.replay_row_ts(row)),
                "path": str(row.get("path_slug") or row.get("profile") or "unknown").upper(),
                "status": status or "UNKNOWN",
                "down": round(self.safe_float(row.get("download_mbps"))),
                "up": round(self.safe_float(row.get("upload_mbps"))),
                "ping": round(self.safe_float(row.get("ping_ms")), 1),
                "message": str(row.get("message") or row.get("server_name") or "")[:120],
            })

        bookmarks = self.replay_bookmarks(recent, snap, speed_rows, replay_rows)
        return {
            "updated_ms": now_ms(),
            "window_h": human_duration(window_seconds),
            "window_seconds": window_seconds,
            "samples": len(recent),
            "status": data_truth.get("label") or "UNKNOWN",
            "score": {
                "trend": trend,
                "current": score_vals[-1] if score_vals else "",
                "min": round(min(score_vals)) if score_vals else "",
                "max": round(max(score_vals)) if score_vals else "",
                "avg": round(sum(score_vals) / len(score_vals)) if score_vals else "",
            },
            "thermal": {
                "current": round(cpu_vals[-1], 1) if cpu_vals else "",
                "peak": round(max(cpu_vals), 1) if cpu_vals else "",
                "avg": round(sum(cpu_vals) / len(cpu_vals), 1) if cpu_vals else "",
            },
            "ups": {
                "current": round(ups_vals[-1]) if ups_vals else snap.get("ups", {}).get("watts", ""),
                "peak": round(max(ups_vals)) if ups_vals else "",
                "avg": round(sum(ups_vals) / len(ups_vals)) if ups_vals else "",
            },
            "speed": {
                "direct_avg": round(sum(direct_vals) / len(direct_vals)) if direct_vals else trend.get("direct_avg", ""),
                "vpn_avg": round(sum(vpn_vals) / len(vpn_vals)) if vpn_vals else trend.get("vpn_avg", ""),
                "paths": speed.get("paths", []) if isinstance(speed, dict) else [],
                "rows": speed_rows,
            },
            "security": {
                "verdict": incident.get("verdict") or "--",
                "headline": incident.get("headline") or mission.get("headline") or "--",
                "fw_blocks": incident.get("counts", {}).get("sources", 0) if isinstance(incident.get("counts"), dict) else 0,
                "dnsbl": incident.get("counts", {}).get("dnsbl", 0) if isinstance(incident.get("counts"), dict) else 0,
                "ids_high": incident.get("ids", {}).get("high_signal", 0) if isinstance(incident.get("ids"), dict) else 0,
                "memory_samples": memory.get("count", 0),
            },
            "flow": {
                "status": netflow.get("status") or "--",
                "summary": netflow.get("summary") or "NetFlow waiting",
                "top": (netflow.get("rows") or [])[:6] if isinstance(netflow.get("rows"), list) else [],
            },
            "buckets": buckets,
            "bookmarks": bookmarks,
            "timeline": replay_rows[:14],
            "commands": [
                {"label": "Open Mission", "href": "/mission", "why": "read the current plain-English SOCX mission"},
                {"label": "Open Flows", "href": "/flows", "why": "check top talkers and new/watch flows"},
                {"label": "Open Incidents", "href": "/incidents", "why": "review firewall, DNSBL, and IDS evidence"},
                {"label": "Ask Chat", "href": "/chat", "why": "ask SOCX what changed or what to check next"},
            ],
        }

    def replay_bookmarks(
        self,
        rows: list[dict[str, Any]],
        snap: dict[str, Any],
        speed_rows: list[dict[str, Any]],
        replay_rows: list[dict[str, Any]],
    ) -> list[dict[str, Any]]:
        bookmarks: list[dict[str, Any]] = []

        def add(kind: str, severity: str, title: str, detail: str, page: str = "/mission") -> None:
            bookmarks.append({
                "kind": kind,
                "severity": severity,
                "title": title[:80],
                "detail": detail[:180],
                "page": page,
                "question": f"Explain this SOCX replay bookmark. Kind: {kind}. Severity: {severity}. Title: {title}. Detail: {detail}. Tell me why it matters and what safe thing to check next.",
            })

        if replay_rows:
            for row in replay_rows[:6]:
                sev = str(row.get("severity") or "LOW").upper()
                page = "/incidents" if str(row.get("lane", "")).upper() in {"FW", "DNSBL", "IDS", "INCIDENT"} else "/mission"
                add(str(row.get("lane") or "CHANGE"), sev, str(row.get("title") or "SOCX change"), str(row.get("detail") or "--"), page)
        for row in speed_rows[-4:]:
            status = str(row.get("status") or "UNKNOWN")
            if status not in {"OK", "STABLE", ""} or self.safe_float(row.get("down")) <= 0:
                add("SPEED", "MED", f"{row.get('path', 'path')} Speedtest {status}", f"{row.get('down', '--')}/{row.get('up', '--')} Mbps {row.get('ping', '--')}ms {row.get('message', '')}", "/speedtest")
        truth = snap.get("data_truth") if isinstance(snap.get("data_truth"), dict) else {}
        if str(truth.get("label") or "").upper() != "LIVE":
            add("TRUTH", "MED", f"Data Truth {truth.get('label', 'UNKNOWN')}", str(truth.get("reason") or "collector freshness needs review"), "/health")
        flow = snap.get("netflow_intel") if isinstance(snap.get("netflow_intel"), dict) else {}
        watch_rows = flow.get("watch_rows") if isinstance(flow.get("watch_rows"), list) else []
        if watch_rows:
            top = watch_rows[0]
            add("FLOW", "MED", "New/watch flow", f"{top.get('display_path') or top.get('asset')} {top.get('app') or top.get('service')} {top.get('why')}", "/flows")
        cpu_vals = [self.safe_float(row.get("cpu_temp_max") or row.get("cpu_temp")) for row in rows if self.safe_float(row.get("cpu_temp_max") or row.get("cpu_temp")) > 0]
        if cpu_vals and max(cpu_vals) >= env_int("SOCX_CPU_WARN_C", 75):
            add("THERMAL", "MED", "CPU thermal headroom tight", f"peak {max(cpu_vals):.1f}C in replay window", "/health")
        return bookmarks[:12]

    def collect_threat_map(self) -> dict[str, Any]:
        snap = self.snapshot()
        incident = snap.get("incident") if isinstance(snap.get("incident"), dict) else self.collect_incident_light(snap.get("command_center", {}))
        sources = incident.get("blocked_sources") if isinstance(incident.get("blocked_sources"), list) else []
        ports = incident.get("blocked_ports") if isinstance(incident.get("blocked_ports"), list) else []
        top_ports = ", ".join(str(item.get("name")) for item in ports[:4]) or "mixed"
        nodes: list[dict[str, Any]] = []
        max_count = max([int(item.get("count", 0) or 0) for item in sources] + [1])
        for idx, item in enumerate(sources[:16]):
            label = str(item.get("name") or "EXT")
            count = int(item.get("count", 0) or 0)
            hint = self.threat_geo_hint(label)
            severity = "HIGH" if count >= 80 else "MED" if count >= 20 else "LOW"
            disposition = "investigate" if severity == "HIGH" else "probably internet noise"
            nodes.append({
                "label": label,
                "count": count,
                "severity": severity,
                "country": hint["country"],
                "region": hint["region"],
                "angle": round((idx / max(1, min(16, len(sources)))) * 360),
                "radius": round(0.28 + (0.62 * (count / max_count)), 3),
                "top_ports": top_ports,
                "disposition": disposition,
                "reputation": "abuse:high" if count >= 20 else "scanner?",
                "question": f"Explain this SOCX Threat Map source. Source: {label}. Count: {count}. Severity: {severity}. Ports: {top_ports}. Geo hint: {hint['country']}. Disposition: {disposition}. Is it probably routine WAN scan noise or something to investigate?",
            })
        return {
            "updated_ms": now_ms(),
            "status": "WATCH" if nodes else "QUIET",
            "summary": f"{len(nodes)} blocked WAN sources mapped; top ports {top_ports}" if nodes else "No blocked WAN sources in the current sample",
            "top_ports": top_ports,
            "nodes": nodes,
            "note": "Geo is a lightweight SOCX hint from local evidence, not authoritative GeoIP.",
        }

    def threat_geo_hint(self, label: str) -> dict[str, str]:
        match = re.search(r"EXT\.(\d+)\.(\d+)", label)
        if not match:
            return {"country": "--", "region": "unknown"}
        a = int(match.group(1))
        if a < 32:
            return {"country": "US", "region": "North America"}
        if a < 64:
            return {"country": "EU?", "region": "Europe"}
        if a < 96:
            return {"country": "AP?", "region": "Asia-Pacific"}
        if a < 128:
            return {"country": "US?", "region": "North America"}
        if a < 160:
            return {"country": "EU?", "region": "Europe"}
        if a < 192:
            return {"country": "AP?", "region": "Asia-Pacific"}
        if a < 224:
            return {"country": "US?", "region": "North America"}
        return {"country": "GL?", "region": "global"}

    def replay_row_ts(self, row: dict[str, Any]) -> float:
        raw = row.get("ts") or row.get("updated") or row.get("time") or row.get("timestamp") or 0
        if isinstance(raw, (int, float)):
            value = float(raw)
        else:
            text = str(raw)
            try:
                value = float(text)
            except ValueError:
                try:
                    value = time.mktime(time.strptime(text[:19], "%Y-%m-%d %H:%M:%S"))
                except Exception:
                    return 0.0
        if value > 100000000000:
            value /= 1000.0
        return value

    def replay_time_label(self, ts: float) -> str:
        return time.strftime("%H:%M", time.localtime(ts)) if ts > 0 else "--"

    def replay_buckets(self, rows: list[dict[str, Any]], count: int = 18) -> list[dict[str, Any]]:
        if not rows:
            return []
        timestamps = [self.replay_row_ts(row) for row in rows if self.replay_row_ts(row) > 0]
        if not timestamps:
            return []
        start = min(timestamps)
        end = max(max(timestamps), start + 1)
        width = max(1.0, (end - start) / max(1, count))
        buckets: list[dict[str, Any]] = []
        for idx in range(count):
            lo = start + (idx * width)
            hi = lo + width
            items = [row for row in rows if lo <= self.replay_row_ts(row) < hi or (idx == count - 1 and self.replay_row_ts(row) <= hi)]
            score_vals = [self.safe_float(row.get("score")) for row in items if self.safe_float(row.get("score")) > 0]
            sec_vals = [self.safe_float(row.get("security_score")) for row in items if self.safe_float(row.get("security_score")) > 0]
            net_vals = [self.safe_float(row.get("network_score")) for row in items if self.safe_float(row.get("network_score")) > 0]
            buckets.append({
                "label": self.replay_time_label(lo),
                "samples": len(items),
                "score": round(sum(score_vals) / len(score_vals)) if score_vals else 0,
                "security": round(sum(sec_vals) / len(sec_vals)) if sec_vals else 0,
                "network": round(sum(net_vals) / len(net_vals)) if net_vals else 0,
            })
        return buckets

    def safe_float(self, value: Any) -> float:
        try:
            return float(value)
        except (TypeError, ValueError):
            return 0.0

    def collect_incident_memory(self) -> dict[str, Any]:
        path = Path(os.environ.get("SOCX_INCIDENT_MEMORY_FILE", "/var/db/socx_incident_memory.jsonl"))
        rows = self.read_jsonl_tail(path, 500)
        counters: dict[str, dict[str, int]] = {"sources": {}, "ports": {}, "dnsbl": {}}
        ids_seen = 0
        for row in rows:
            src = str(row.get("top_source") or "")
            port = str(row.get("top_port") or "")
            dns = str(row.get("top_dnsbl") or "")
            if src:
                counters["sources"][src] = counters["sources"].get(src, 0) + 1
            if port:
                counters["ports"][port] = counters["ports"].get(port, 0) + 1
            if dns:
                counters["dnsbl"][dns] = counters["dnsbl"].get(dns, 0) + 1
            if int(row.get("ids_high") or 0) > 0:
                ids_seen += 1

        def top(kind: str) -> list[dict[str, Any]]:
            data = counters.get(kind, {})
            return [{"name": key, "count": val} for key, val in sorted(data.items(), key=lambda kv: kv[1], reverse=True)[:5]]

        return {
            "count": len(rows),
            "sources": top("sources"),
            "ports": top("ports"),
            "dnsbl": top("dnsbl"),
            "ids_high_samples": ids_seen,
            "rows": rows[-40:],
            "status": "learning" if rows else "waiting",
        }

    def maybe_append_incident_memory(self) -> None:
        now = time.time()
        if now - self.last_incident_memory_write < env_int("SOCX_INCIDENT_MEMORY_SECONDS", 60):
            return
        self.last_incident_memory_write = now
        run_cmd("/usr/local/bin/socx-incident-memory append 2>/dev/null", timeout=1.8)

    def baseline_for_asset(self, history: dict[str, Any], asset: str) -> dict[str, Any]:
        devices = history.get("devices") if isinstance(history.get("devices"), dict) else {}
        row = devices.get(asset) if isinstance(devices.get(asset), dict) else {}
        return {
            "apps": [str(item) for item in row.get("apps", []) if item],
            "services": [str(item) for item in row.get("services", []) if item],
            "seen": int(row.get("seen", 0) or 0),
        }

    def unusual_for_asset(self, baseline: dict[str, Any], apps: list[dict[str, Any]], services: list[dict[str, Any]]) -> list[str]:
        if int(baseline.get("seen", 0) or 0) < 3:
            return []
        known_apps = set(str(item) for item in baseline.get("apps", []))
        known_services = set(str(item) for item in baseline.get("services", []))
        unusual: list[str] = []
        for item in apps[:3]:
            name = str(item.get("name") or "")
            if name and name not in known_apps:
                unusual.append(name)
        for item in services[:3]:
            name = str(item.get("name") or "")
            if name and name.startswith("port") and name not in known_services:
                unusual.append(name)
        return unusual[:4]

    def update_label_history(self, rows: list[dict[str, Any]]) -> None:
        now = time.time()
        if now - self.label_history_last_write < env_int("SOCX_LABEL_BRAIN_WRITE_SECONDS", 20):
            return
        self.label_history_last_write = now
        history = self.label_history_cache if isinstance(self.label_history_cache, dict) else {"devices": {}}
        devices = history.setdefault("devices", {})
        if not isinstance(devices, dict):
            devices = {}
            history["devices"] = devices
        for row in rows[:30]:
            asset = str(row.get("asset") or "")
            if not asset:
                continue
            entry = devices.setdefault(asset, {"seen": 0, "apps": [], "services": [], "profile": "device"})
            entry["seen"] = int(entry.get("seen", 0) or 0) + 1
            entry["profile"] = row.get("profile") or entry.get("profile") or "device"
            for key in ("apps", "services"):
                current = [str(item) for item in entry.get(key, []) if item]
                observed = [str(item.get("name")) for item in row.get(key, []) if item.get("name")]
                merged = []
                for item in [*observed, *current]:
                    if item and item not in merged:
                        merged.append(item)
                entry[key] = merged[:12]
            entry["last_seen"] = int(now)
        history["updated"] = int(now)
        try:
            self.label_history_path.parent.mkdir(parents=True, exist_ok=True)
            tmp = self.label_history_path.with_suffix(".tmp")
            tmp.write_text(json.dumps(history, separators=(",", ":")), encoding="utf-8")
            tmp.replace(self.label_history_path)
            self.label_history_cache = history
        except Exception:
            pass

    def device_profile(self, asset: str, apps: list[dict[str, Any]], services: list[dict[str, Any]]) -> str:
        text = " ".join([asset, " ".join(str(item.get("name")) for item in apps), " ".join(str(item.get("name")) for item in services)]).lower()
        if any(token in text for token in ["ollama", "vllm", "hugging face", "openai", "miranda", "pi-llm", "gradio", "jupyter", "ray"]):
            return "AI lab"
        if any(token in text for token in ["netflix", "prime video", "youtube", "disney", "hulu", "max", "plex", "roku"]):
            return "streaming"
        if any(token in text for token in ["nas", "smb", "imaps", "mail"]):
            return "storage"
        if any(token in text for token in ["ups", "nut", "snmp"]):
            return "sensor"
        if any(token in text for token in ["pfsense", "gateway", "router", "dns", "dhcp"]):
            return "network"
        if any(token in text for token in ["ibm quantum", "qiskit", "civitai", "github"]):
            return "research"
        return "workstation" if "lan." in text else "device"

    def profile_counts(self, rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
        counts: dict[str, int] = {}
        for row in rows:
            profile = str(row.get("profile") or "device")
            counts[profile] = counts.get(profile, 0) + 1
        return [{"name": k, "count": v} for k, v in sorted(counts.items(), key=lambda kv: kv[1], reverse=True)]

    def now_watching_groups(self, apps: list[dict[str, Any]]) -> list[dict[str, Any]]:
        groups: dict[str, list[str]] = {"Streaming": [], "AI Lab": [], "Quantum": [], "Cloud": []}
        for item in apps:
            name = str(item.get("name") or "")
            lower = name.lower()
            if any(x in lower for x in ["netflix", "prime", "youtube", "disney", "hulu", "max", "plex", "roku"]):
                groups["Streaming"].append(name)
            elif any(x in lower for x in ["hugging", "openai", "anthropic", "grok", "nvidia", "ollama", "vllm", "civitai"]):
                groups["AI Lab"].append(name)
            elif "ibm" in lower or "quantum" in lower:
                groups["Quantum"].append(name)
            elif any(x in lower for x in ["microsoft", "google", "aws", "cloudfront", "apple"]):
                groups["Cloud"].append(name)
        return [{"group": group, "apps": values[:3]} for group, values in groups.items() if values][:4]

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
        status_l = str(status).lower()
        if status_l == "ok" and age < env_int("SOCX_SPEEDTEST_STALE_SECONDS", 25200):
            path_state = "ready"
        elif status_l == "ok":
            path_state = "stale"
        elif status_l in {"unavailable", "missing"}:
            path_state = "inactive"
        elif status_l in {"error", "failed"}:
            path_state = "error"
        else:
            path_state = "waiting"
        return {
            "label": label,
            "status": status,
            "path_state": path_state,
            "down": data.get("download_mbps", ""),
            "up": data.get("upload_mbps", ""),
            "ping": data.get("ping_ms", ""),
            "server": data.get("server_name") or data.get("server") or "",
            "message": data.get("message", ""),
            "external_ip": data.get("external_ip", ""),
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

    def operator_chat(self, question: str) -> dict[str, Any]:
        started = time.time()
        text = re.sub(r"\s+", " ", str(question or "")).strip()[:900]
        if not text:
            return {
                "ok": False,
                "mode": "DENIED",
                "answer": "Ask me about firewall blocks, DNSBL, IDS, VPN, speedtest, Pi AI, devices, or a draft-only pfSense change plan.",
                "phases": ["waiting for an operator question"],
                "read_only": True,
            }
        snap = self.snapshot()
        intent = self.chat_intent(text)
        context = self.chat_context(snap)
        phases = ["collected pfSense telemetry", f"classified request as {intent}"]
        local = self.local_chat_answer(text, intent, context)
        pi_answer: dict[str, Any] = {}
        prefer_local_truth = bool(re.search(r"\b(weird|strange|unusual|anomal|normal|bandwidth|top talker|top talkers|who is using|netflow|ipfix|flow|traffic)\b", text.lower()))
        if intent in {"explain", "diagnose", "draft"} and not prefer_local_truth:
            pi_answer = self.ask_pi_operator_chat(text, intent, context)
            if pi_answer.get("ok"):
                phases.extend(pi_answer.get("phases") or ["Pi LLM answered"])
            else:
                phases.append("Pi LLM unavailable; using pfSense explanation")
        elif prefer_local_truth:
            phases.append("used local NetFlow truth before Pi narration")
        if intent == "blocked":
            answer = local
            mode = "DENIED"
        elif pi_answer.get("answer"):
            answer = str(pi_answer.get("answer"))[:2400]
            mode = str(pi_answer.get("mode") or ("PLAN" if intent == "draft" else "ANSWER")).upper()
        else:
            answer = local
            mode = "PLAN" if intent == "draft" else "ANSWER"
        return {
            "ok": intent != "blocked",
            "mode": mode,
            "intent": intent,
            "answer": answer,
            "phases": phases,
            "action_cards": self.chat_action_cards(intent, text, context, mode),
            "context": context,
            "pi": pi_answer,
            "read_only": True,
            "approval_required": intent == "draft",
            "elapsed_ms": int((time.time() - started) * 1000),
            "safe_commands": self.chat_safe_commands(intent, text),
        }

    def chat_intent(self, text: str) -> str:
        lower = text.lower()
        if re.search(r"\b(delete|wipe|factory reset|disable firewall|turn off firewall|bypass|exploit|attack|stealth|exfiltrate)\b", lower):
            return "blocked"
        if re.search(r"\b(configure|change|add rule|open port|block|allow|quarantine|enable|disable|apply|fix|draft|plan|recommend|tune|suppress|whitelist|allowlist)\b", lower):
            return "draft"
        if re.search(r"\b(why|diagnose|broken|error|down|slow|stale|crash|not working|fail)\b", lower):
            return "diagnose"
        return "explain"

    def chat_context(self, snap: dict[str, Any]) -> dict[str, Any]:
        incident = snap.get("incident") or {}
        intel = snap.get("intel") or {}
        pulse = snap.get("threat_pulse") or {}
        command = snap.get("command_center") or {}
        pi_nodes = snap.get("pi_nodes") or {}
        brief = snap.get("daily_brief") or {}
        metrics = snap.get("metrics_intel") or {}
        observability = snap.get("observability") or {}
        power_mods = snap.get("power_mods") or {}
        netflow_intel = snap.get("netflow_intel") or {}
        device_trust = snap.get("device_trust") or {}
        mission_assurance = snap.get("mission_assurance") or {}
        return {
            "generated_at": snap.get("generated_at"),
            "health": snap.get("status", {}).get("health"),
            "wan": snap.get("wan_status") or snap.get("status", {}).get("wan"),
            "vpn": snap.get("vpn_status") or command.get("vpn"),
            "dns": snap.get("dns_status") or snap.get("status", {}).get("dns"),
            "speed": command.get("speed_truth") or command.get("direct") or {},
            "threat": {"label": pulse.get("label"), "score": pulse.get("score"), "fw_blocks": pulse.get("fw_blocks"), "dnsbl_hits": pulse.get("dnsbl_hits"), "ids_high": pulse.get("ids_high"), "ids_watch": pulse.get("ids_watch")},
            "incident": {"mode": incident.get("mode"), "summary": incident.get("summary"), "top_sources": incident.get("blocked_sources", [])[:3], "top_ports": incident.get("blocked_ports", [])[:3], "dnsbl": incident.get("dnsbl_domains", [])[:3], "ids": incident.get("ids", {})},
            "intel": {"status": intel.get("status"), "priority": (intel.get("priority") or {}).get("label"), "kev": intel.get("kev", {}), "rows": (intel.get("rows") or [])[:4]},
            "brief": {"headline": brief.get("headline"), "status": brief.get("status"), "sections": (brief.get("sections") or [])[:5], "next": (brief.get("next") or [])[:5]},
            "metrics_intel": {"severity": metrics.get("severity"), "summary": metrics.get("summary"), "alerts": (metrics.get("alerts") or [])[:8], "next_steps": (metrics.get("next_steps") or [])[:5]},
            "observability": {"label": observability.get("label"), "summary": observability.get("summary"), "core_measurements": observability.get("core_measurements"), "grafana_url": observability.get("grafana_url")},
            "power_mods": power_mods,
            "netflow_intel": {
                "status": netflow_intel.get("status"),
                "summary": netflow_intel.get("summary"),
                "window": netflow_intel.get("window"),
                "total": netflow_intel.get("total_bytes_h"),
                "top_assets": (netflow_intel.get("top_assets") or [])[:5],
                "top_apps": (netflow_intel.get("top_apps") or [])[:5],
                "stories": (netflow_intel.get("stories") or [])[:5],
                "watch_rows": (netflow_intel.get("watch_rows") or [])[:6],
                "baseline_counts": netflow_intel.get("baseline_counts") or {},
                "rows": (netflow_intel.get("rows") or [])[:8],
            },
            "device_trust": {
                "label": device_trust.get("label"),
                "score": device_trust.get("score"),
                "summary": device_trust.get("summary"),
                "rows": (device_trust.get("rows") or [])[:8],
            },
            "mission_assurance": {
                "label": mission_assurance.get("label"),
                "score": mission_assurance.get("score"),
                "summary": mission_assurance.get("summary"),
                "weakest": mission_assurance.get("weakest"),
                "next": (mission_assurance.get("next") or [])[:5],
            },
            "flows": (snap.get("flows") or [])[:6],
            "packets": (snap.get("packets") or [])[:6],
            "pi": {"summary": pi_nodes.get("summary"), "nodes": (pi_nodes.get("nodes") or [])[:3]},
        }

    def ask_pi_operator_chat(self, question: str, intent: str, context: dict[str, Any]) -> dict[str, Any]:
        urls = []
        configured = os.environ.get("SOCX_PI_CHAT_URL", "").strip()
        if configured:
            urls.append(configured)
        for node in (context.get("pi") or {}).get("nodes", []):
            ip = str(node.get("ip") or "").strip()
            ports = str(node.get("ports") or "")
            if ip and "8095" in ports:
                urls.append(f"http://{ip}:8095/api/socx/chat")
        seen: set[str] = set()
        for url in urls:
            if url in seen:
                continue
            seen.add(url)
            try:
                result = post_json(url, {"question": question, "intent": intent, "context": context}, timeout=float(os.environ.get("SOCX_PI_CHAT_TIMEOUT", "95")))
                result["url"] = url
                return result
            except Exception as exc:
                last = str(exc)[:180]
        return {"ok": False, "error": locals().get("last", "no Pi chat URL available")}

    def local_chat_answer(self, question: str, intent: str, context: dict[str, Any]) -> str:
        q = question.lower()
        incident = context.get("incident") or {}
        threat = context.get("threat") or {}
        intel = context.get("intel") or {}
        brief = context.get("brief") or {}
        metrics = context.get("metrics_intel") or {}
        power = context.get("power_mods") or {}
        netflow = context.get("netflow_intel") or {}
        trust = context.get("device_trust") or {}
        assurance = context.get("mission_assurance") or {}
        lines = []
        if intent == "blocked":
            return "I cannot help with destructive, bypass, or stealth requests. I can explain the alert, preserve evidence, or draft a safe approval-only pfSense change plan."
        if "morning brief" in q or "what changed" in q:
            lines.append(f"{brief.get('headline') or 'SOCX brief is available.'}")
            for item in (brief.get("sections") or [])[:5]:
                lines.append(f"{item.get('label')}: {item.get('value')}")
            next_steps = brief.get("next") or []
            if next_steps:
                lines.append("Safe next steps: " + " | ".join(str(step) for step in next_steps[:3]))
        if "metric" in q or "grafana" in q or "influx" in q:
            lines.append(f"Metrics Intelligence is {metrics.get('severity', 'UNKNOWN')}: {metrics.get('summary', 'waiting')}")
            watch_items = [a for a in (metrics.get("alerts") or []) if str(a.get("state")) != "ok"]
            if watch_items:
                lines.append("Metrics to watch: " + ", ".join(f"{a.get('signal')} {a.get('state')}" for a in watch_items[:4]))
            else:
                lines.append("Metrics look stable: CPU, memory, swap, PF states/search, WAN ping, processes, and disk are in normal bounds.")
        if "power" in q or "flow" in q or "netflow" in q or "ipfix" in q or "lldp" in q or "drift" in q or "vault" in q or "quarantine" in q or "eve" in q:
            lines.append(f"Power-user pack is {power.get('status', 'UNKNOWN')}: {power.get('summary', 'waiting')}")
            items = power.get("items") or {}
            watch = []
            for key, item in items.items():
                if str(item.get("status", "")).upper() not in {"", "OK", "LIVE", "READY"}:
                    watch.append(f"{key.replace('_', ' ')} {item.get('status')}: {item.get('summary')}")
            if watch:
                lines.append("Power-user watch items: " + "; ".join(watch[:4]))
            lines.append("Safe buttons: Evidence preserves a hashed vault, Drift checks config.xml, IDS EVE summarizes Suricata, Flow checks softflowd, Topology checks LLDP, and Quarantine only drafts a plan.")
        if "doctor" in q or "vnstat" in q or "lldp" in q or "service" in q or "repair" in q:
            lines.append("pfSense Doctor is the read-only troubleshooting path. Open /doctor to see SOCX status, pfSense service parsing, WAN quality, VPN gateway truth, DNSBL, IDS, vnStat, LLDP topology, Speedtest profiles, and Pi LLM checks in one place.")
            lines.append("Treat WARN rows as review targets first. Preserve evidence with Bundle or Snapshot before changing service/package configuration.")
        if "unknown" in q or "label" in q or "name" in q or "identify" in q:
            lines.append("Unknown Fixer uses local DHCP, ARP, DNS/DNSBL, mDNS-style names, PF states, NetFlow, and SOCX host maps. Start with /devices, then run socx hosts audit and socx services to see which devices or ports need labels.")
            lines.append("Best evidence to reduce unknowns: DHCP hostnames, DNS resolver logs, repeated flow apps, local service ports, and stable manual labels in SOCX host/service maps.")
        if any(word in q for word in ["weird", "strange", "unusual", "anomaly", "anomalies", "normal", "bandwidth", "top talker", "top talkers", "who is using", "netflow", "ipfix", "flow", "app", "traffic"]):
            lines.append(f"NetFlow story is {netflow.get('status', 'UNKNOWN')}: {netflow.get('summary', 'waiting for Pi4 Influx flow data')}")
            stories = netflow.get("stories") or []
            if stories:
                lines.append("Top flow story: " + " | ".join(str(story) for story in stories[:3]))
            watch_rows = netflow.get("watch_rows") or []
            if watch_rows:
                lines.append("Unusual/new flow checks: " + " | ".join(f"{r.get('display_path') or r.get('asset')} {r.get('app')} {r.get('bytes_h')}: {r.get('why')}" for r in watch_rows[:3]))
            else:
                lines.append("No high-confidence flow anomaly is standing out in the current NetFlow window.")
            top_assets = netflow.get("top_assets") or []
            if top_assets:
                lines.append("Top assets: " + ", ".join(f"{x.get('name')} {x.get('bytes_h')}" for x in top_assets[:4]))
            top_apps = netflow.get("top_apps") or []
            if top_apps:
                lines.append("Top apps: " + ", ".join(f"{x.get('name')} {x.get('bytes_h')}" for x in top_apps[:4]))
            if trust.get("summary"):
                lines.append(f"Device trust is {trust.get('label', 'UNKNOWN')} {trust.get('score', '--')}/100: {trust.get('summary')}")
            if assurance.get("summary"):
                lines.append(f"Mission assurance is {assurance.get('label', 'UNKNOWN')} {assurance.get('score', '--')}/100: {assurance.get('summary')}")
        if "dnsbl" in q:
            lines.append("DNSBL means DNS Block List. pfBlockerNG blocked or redirected a domain lookup because the domain matched a reputation/category list.")
        if "ids" in q or "suricata" in q:
            ids = incident.get("ids") or {}
            lines.append(f"IDS/Suricata is inspection telemetry. Current sample: high-signal {ids.get('high_signal', ids.get('signal', 0))}, watch {ids.get('watch', 0)}, routine {ids.get('routine', 0)}.")
        if "firewall" in q or "block" in q or "drop" in q:
            ports = ", ".join(str(x.get("name")) for x in (incident.get("top_ports") or [])[:3]) or "none"
            lines.append(f"Firewall blocks are packets pfSense refused by policy. Top blocked ports in the sample: {ports}.")
        if "vpn" in q:
            lines.append(f"VPN status is read from gateway/interface truth, not just whether VPN is configured: {context.get('vpn') or 'unknown'}.")
        if "speed" in q:
            lines.append(f"Speedtest truth: {context.get('speed') or 'waiting for speed cache'}.")
        if not lines:
            lines.append(f"SOCX is in {threat.get('label', 'watch')} mode with threat score {threat.get('score', '--')}. Firewall blocks {threat.get('fw_blocks', 0)}, DNSBL hits {threat.get('dnsbl_hits', 0)}, IDS high {threat.get('ids_high', 0)}.")
        priority = intel.get("priority")
        if priority:
            lines.append(f"Intel priority is {priority}; ATT&CK/D3FEND rows are advisory evidence, not proof by themselves.")
        if intent == "draft":
            lines.append("Because this is a configuration request, I will only draft the pfSense plan and commands. No rule or service change is applied from chat.")
        return "\n".join(lines)

    def chat_safe_commands(self, intent: str, text: str) -> list[str]:
        commands = ["socx status", "socx mission", "socx intel"]
        lower = text.lower()
        if "ids" in lower or "suricata" in lower:
            commands.append("socx-doctor ids")
            commands.append("socx eve")
        if "dnsbl" in lower:
            commands.append("socx-doctor dnsbl-review")
        if "doctor" in lower or "repair" in lower or "service" in lower:
            commands.append("open /doctor")
            commands.append("socx-doctor")
            commands.append("socx-doctor php-services")
        if "unknown" in lower or "label" in lower or "identify" in lower:
            commands.append("open /devices")
            commands.append("socx-hosts-audit")
            commands.append("socx services")
        if re.search(r"\b(power|flow|netflow|ipfix|bandwidth|top talker|weird|unusual|anomal)\b", lower):
            commands.append("socx flow-export status")
            commands.append("open /flows")
        if "lldp" in lower or "topology" in lower:
            commands.append("socx topology")
        if "drift" in lower or "config" in lower:
            commands.append("socx drift status")
        if "vault" in lower or "evidence" in lower:
            commands.append("socx vault")
        if intent == "draft":
            commands.append("socx rules")
            commands.append("socx snapshot")
        return commands[:6]

    def chat_action_cards(self, intent: str, text: str, context: dict[str, Any], mode: str) -> list[dict[str, Any]]:
        threat = context.get("threat") or {}
        incident = context.get("incident") or {}
        intel = context.get("intel") or {}
        cards = [
            {
                "title": "Current Evidence",
                "status": "WATCH" if str(threat.get("label", "")).lower() == "watch" else "LIVE",
                "tone": "yellow" if str(threat.get("label", "")).lower() == "watch" else "green",
                "detail": f"FW {threat.get('fw_blocks', 0)} | DNSBL {threat.get('dnsbl_hits', 0)} | IDS high {threat.get('ids_high', 0)} | IDS watch {threat.get('ids_watch', 0)}",
                "command": "socx mission",
            },
            {
                "title": "Intel Context",
                "status": str((intel.get("priority") or "P4")).upper(),
                "tone": "yellow" if str(intel.get("priority", "")).upper() in {"P2", "P3"} else "cyan",
                "detail": f"KEV {(intel.get('kev') or {}).get('status', 'unknown')} | rows {len(intel.get('rows') or [])}",
                "command": "socx intel",
            },
        ]
        if any(word in text.lower() for word in ["dnsbl", "domain", "blocked dns"]):
            cards.append({"title": "DNSBL Review", "status": "SAFE", "tone": "purple", "detail": "Review blocked domains and false-positive candidates before allowlisting.", "command": "socx-doctor dnsbl-review"})
        if any(word in text.lower() for word in ["ids", "suricata", "alert"]):
            ids = incident.get("ids") or {}
            cards.append({"title": "IDS Review", "status": "SAFE", "tone": "purple", "detail": f"High {ids.get('high_signal', ids.get('signal', 0))}, watch {ids.get('watch', 0)}, routine {ids.get('routine', 0)}.", "command": "socx-doctor ids"})
        if intent == "draft":
            cards.append({"title": "Draft Only", "status": "APPROVAL REQUIRED", "tone": "yellow", "detail": "Chat prepared guidance only. Preserve evidence, then review any pfSense rule or service change manually.", "command": "socx snapshot"})
            cards.append({"title": "Rule Assistant", "status": "NO CHANGE APPLIED", "tone": "cyan", "detail": "Generate approval-only rule, IDS, DNSBL, or device-profile recommendations.", "command": "socx rules"})
        elif mode == "DENIED":
            cards.append({"title": "Safety Guard", "status": "DENIED", "tone": "red", "detail": "SOCX can explain evidence or draft safe plans, but will not help bypass or disable protections.", "command": "socx status"})
        else:
            cards.append({"title": "Preserve If Unsure", "status": "OPTIONAL", "tone": "cyan", "detail": "Create an evidence bundle before changing policy or tuning detections.", "command": "socx snapshot"})
        return cards[:5]

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
        ids_watch = max(0, len(ids_lines) - ids_high - ids_routine)
        return {
            "mode": center.get("mode"),
            "score": center.get("score"),
            "summary": center.get("summary"),
            "generated_ms": now_ms(),
            "blocked_sources": rank(src_counts),
            "blocked_ports": rank(port_counts),
            "lan_hosts": rank(lan_counts),
            "dnsbl_domains": [{"name": app_label(k) or k, "raw": k, "count": v} for k, v in sorted(domains.items(), key=lambda kv: kv[1], reverse=True)[:8]],
            "ids": {"signal": ids_high, "high_signal": ids_high, "watch": ids_watch, "routine": ids_routine, "total": len(ids_lines)},
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
            "data_truth": {
                "label": "WATCH",
                "tone": "yellow",
                "score": 84,
                "ok": 7,
                "warn": 2,
                "red": 0,
                "signals": [
                    {"name": "Wall/API", "state": "LIVE", "tone": "green", "age_h": "now", "source": "socxweb", "detail": "browser state is being generated"},
                    {"name": "UPS", "state": "FRESH", "tone": "green", "age_h": "now", "source": "NUT/APC", "detail": "603 W"},
                    {"name": "Speed Direct", "state": "READY", "tone": "green", "age_h": "15m", "source": "speedtest-cache", "detail": "3223/2372 Mbps 9ms"},
                    {"name": "Speed VPN", "state": "READY", "tone": "green", "age_h": "16m", "source": "speedtest-cache", "detail": "740/510 Mbps 52ms"},
                    {"name": "Pi Fleet", "state": "FRESH", "tone": "green", "age_h": "now", "source": "socx-pi-nodes", "detail": "2/2 online"},
                    {"name": "Pi AI", "state": "READY", "tone": "green", "age_h": "now", "source": "Pi 3-LLM", "detail": "role health from Pi dashboard"},
                    {"name": "Label Brain", "state": "READY", "tone": "green", "age_h": "now", "source": "local memory", "detail": "4 devices"},
                    {"name": "Incident Memory", "state": "READY", "tone": "green", "age_h": "now", "source": "jsonl memory", "detail": "37 samples"},
                ],
            },
            "what_changed": {
                "count": 3,
                "headline": "VPN Speedtest changed",
                "rows": [
                    {"time": "19:44:10", "severity": "MED", "title": "VPN Speedtest changed", "detail": "ready 740/510 Mbps", "from": "waiting", "to": "ready:740/510"},
                    {"time": "19:43:22", "severity": "MED", "title": "Incident verdict changed", "detail": "WATCH: top source EXT.217.142, top port 443", "from": "QUIET", "to": "WATCH"},
                    {"time": "19:42:58", "severity": "LOW", "title": "Top flow changed", "detail": "JupiterLXI/192.168.1.161->EXT.155.209 https", "from": "none", "to": "web"},
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
        if parsed.path == "/api/health":
            self.send_json(self.collector.collect_release_health())
            return
        if parsed.path == "/api/doctor":
            self.send_json(self.collector.collect_doctor())
            return
        if parsed.path == "/api/observability":
            self.send_json(self.collector.collect_observability())
            return
        if parsed.path == "/api/metrics-intel":
            self.send_json(self.collector.collect_metrics_intel())
            return
        if parsed.path == "/api/power-mods":
            self.send_json(self.collector.collect_power_mods())
            return
        if parsed.path == "/api/flows":
            snap = self.collector.snapshot()
            self.send_json({
                "netflow_intel": snap.get("netflow_intel", {}),
                "device_trust": snap.get("device_trust", {}),
                "mission_assurance": snap.get("mission_assurance", {}),
                "flows": snap.get("flows", []),
                "asset_watch": snap.get("asset_watch", {}),
                "updated_ms": now_ms(),
            })
            return
        if parsed.path == "/api/replay":
            params = parse_qs(parsed.query)
            raw_window = params.get("window", ["21600"])[0]
            try:
                window = int(float(raw_window))
            except ValueError:
                window = 21600
            self.send_json(self.collector.collect_replay(window))
            return
        if parsed.path == "/api/threat-map":
            self.send_json(self.collector.collect_threat_map())
            return
        if parsed.path == "/api/autonomy-loop":
            self.send_json(self.collector.collect_autonomy_loop())
            return
        if parsed.path == "/api/command-center":
            self.send_json(self.collector.snapshot().get("command_center", {}))
            return
        if parsed.path == "/api/mission":
            self.send_json(self.collector.snapshot().get("mission", {}))
            return
        if parsed.path == "/api/intel":
            self.send_json(self.collector.snapshot().get("intel", {}))
            return
        if parsed.path == "/api/history":
            rows = self.collector.collect_history(240)
            self.send_json({"count": len(rows), "trend": self.collector.history_trend(rows), "rows": rows})
            return
        if parsed.path == "/api/top-talkers":
            self.send_json(self.collector.collect_top_talkers())
            return
        if parsed.path == "/api/label-brain":
            self.send_json(self.collector.snapshot().get("label_brain", {}))
            return
        if parsed.path == "/api/incident":
            self.send_json(self.collector.collect_incident())
            return
        if parsed.path == "/api/incident-timeline":
            self.send_json(self.collector.snapshot().get("incident_timeline", {}))
            return
        if parsed.path == "/api/daily-brief":
            self.send_json(self.collector.snapshot().get("daily_brief", {}))
            return
        if parsed.path == "/api/story":
            self.send_json(self.collector.collect_daily_story())
            return
        if parsed.path == "/api/rule-assistant":
            self.send_json(self.collector.snapshot().get("rule_assistant", {}))
            return
        if parsed.path == "/api/speedtest-history":
            self.send_json(self.collector.snapshot().get("speedtest_history", {}))
            return
        if parsed.path == "/api/incident-memory":
            self.send_json(self.collector.snapshot().get("incident_memory", {}))
            return
        if parsed.path == "/api/why":
            params = parse_qs(parsed.query)
            target = params.get("target", [""])[0]
            self.send_json(self.collector.collect_why(target))
            return
        if parsed.path == "/api/config":
            self.send_json({"refresh_ms": int(self.collector.interval * 1000), "demo": self.collector.demo})
            return
        self.serve_static(parsed.path)

    def do_POST(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path == "/api/incident-bundle":
            self.send_json(self.collector.create_incident_bundle())
            return
        if parsed.path == "/api/story-archive":
            self.send_json(self.collector.create_story_archive())
            return
        if parsed.path not in {"/api/commander", "/api/chat"}:
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
        if parsed.path == "/api/chat":
            question = str(data.get("question") or data.get("message") or "")
            self.send_json(self.collector.operator_chat(question))
            return
        action = str(data.get("action", "")).strip().lower()
        self.send_json(self.run_commander_action(action))

    def run_commander_action(self, action: str) -> dict[str, Any]:
        commands: dict[str, tuple[list[str], float, str]] = {
            "snapshot": (["/usr/local/bin/socx", "snapshot"], 90.0, "Evidence snapshot"),
            "bundle": (["/usr/local/bin/socx", "incident", "quick"], 120.0, "Incident bundle"),
            "vault": (["/usr/local/bin/socx", "vault"], 160.0, "Evidence vault"),
            "incident-bundle": (["/usr/local/bin/socx", "incident", "quick"], 120.0, "Incident bundle"),
            "incident": (["/usr/local/bin/socx", "incident-mode", "120"], 25.0, "Incident Mode"),
            "drift": (["/usr/local/bin/socx", "drift", "status"], 35.0, "Config drift"),
            "eve": (["/usr/local/bin/socx", "eve"], 45.0, "Suricata EVE"),
            "flow-export": (["/usr/local/bin/socx", "flow-export", "status"], 35.0, "Flow export"),
            "topology": (["/usr/local/bin/socx", "topology"], 35.0, "LLDP topology"),
            "quarantine-draft": (["/usr/local/bin/socx", "quarantine", "192.168.1.121"], 35.0, "Quarantine draft"),
            "zeek": (["/usr/local/bin/socx-doctor", "zeek"], 25.0, "Zeek health"),
            "speedtest": (["/usr/local/bin/socx-doctor", "speedtest-profiles"], 25.0, "Speedtest profiles"),
            "pi": (["/usr/local/bin/socx", "pi-llm"], 330.0, "Pi 3-LLM analysis"),
            "status": (["/usr/local/bin/socx", "status"], 25.0, "SOCX status"),
            "explain": (["/usr/local/bin/socx", "explain-screen"], 25.0, "SOCX explanation"),
            "brief": (["/usr/local/bin/socx", "brief"], 40.0, "Daily SOC brief"),
            "story": (["/usr/local/bin/socx", "story"], 40.0, "Daily Story"),
            "story-archive": (["/usr/local/bin/socx", "story", "archive"], 40.0, "Daily Story archive"),
            "timeline": (["/usr/local/bin/socx", "timeline", "120"], 35.0, "Incident timeline"),
            "rules": (["/usr/local/bin/socx", "rules"], 35.0, "Rule assistant"),
            "doctor": (["/usr/local/bin/socx", "status"], 35.0, "pfSense health doctor"),
            "speed-history": (["/usr/local/bin/socx", "speedtest-history", "summary", "500"], 25.0, "Speedtest history"),
            "memory": (["/usr/local/bin/socx", "incident-memory", "summary", "500"], 25.0, "Incident memory"),
            "lab": (["/usr/local/bin/socx", "pi-lab", "llm"], 25.0, "Pi lab experiment"),
            "observability": (["/usr/local/bin/socx", "observability"], 25.0, "SOCX observability"),
            "metrics-intel": (["/usr/local/bin/socx", "metrics-intel"], 35.0, "Metrics intelligence"),
            "metrics-ai": (["/usr/local/bin/socx", "metrics-ai", "--pi"], 220.0, "Pi metrics narrator"),
            "autonomy": (["/usr/local/bin/socx", "autonomy-loop"], 170.0, "SOCX autonomy loop"),
            "autonomy-cron": (["/usr/local/bin/socx", "autonomy-cron", "status"], 20.0, "SOCX autonomy schedule"),
        }
        if action not in commands:
            return {"ok": False, "action": action, "title": "Unknown action", "output": "Allowed: snapshot, bundle, vault, drift, eve, flow-export, topology, quarantine-draft, incident, zeek, speedtest, pi, status, explain, brief, story, timeline, rules, doctor, speed-history, memory, lab, observability, metrics-intel, metrics-ai, autonomy, autonomy-cron"}
        args, timeout, title = commands[action]
        result = run_cmd_capture(args, timeout=timeout)
        return {"action": action, "title": title, **result}

    def serve_static(self, path: str) -> None:
        if path in {"/speedtest", "/devices", "/incidents", "/ai", "/health", "/doctor", "/why", "/story", "/mission", "/flows", "/replay", "/map", "/observability", "/metrics", "/guide", "/chat"}:
            target = STATIC_DIR / "detail.html"
        elif path in {"", "/"}:
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
