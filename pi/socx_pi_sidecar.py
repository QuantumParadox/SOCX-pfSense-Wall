#!/usr/bin/env python3
"""Tiny SOCX Pi telemetry sidecar.

This intentionally uses only the Python standard library so a Pi 4 can expose
basic health without installing FastAPI or model tooling.
"""

from __future__ import annotations

import json
import os
import platform
import socket
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any


def read_text(path: str) -> str:
    try:
        return Path(path).read_text(encoding="utf-8", errors="ignore").strip()
    except OSError:
        return ""


def meminfo() -> dict[str, Any]:
    values: dict[str, int] = {}
    try:
        for line in Path("/proc/meminfo").read_text(encoding="utf-8", errors="ignore").splitlines():
            key, rest = line.split(":", 1)
            values[key] = int(rest.strip().split()[0]) * 1024
    except (OSError, ValueError, IndexError):
        return {}
    total = values.get("MemTotal", 0)
    available = values.get("MemAvailable", 0)
    used_pct = round((1 - available / total) * 100, 1) if total else 0
    return {"total": total, "available": available, "used_pct": used_pct}


def cpu_temp() -> float | None:
    raw = read_text("/sys/class/thermal/thermal_zone0/temp")
    try:
        return round(int(raw) / 1000, 1)
    except (TypeError, ValueError):
        return None


def uptime_seconds() -> int:
    raw = read_text("/proc/uptime").split()
    try:
        return int(float(raw[0]))
    except (IndexError, ValueError):
        return 0


def payload() -> dict[str, Any]:
    load = os.getloadavg() if hasattr(os, "getloadavg") else (0.0, 0.0, 0.0)
    model = read_text("/proc/device-tree/model").replace("\x00", "")
    return {
        "status": "ok",
        "service": "SOCX Pi sidecar",
        "hostname": socket.gethostname(),
        "model": model or "Raspberry Pi",
        "platform": platform.platform(),
        "updated": int(time.time()),
        "uptime_seconds": uptime_seconds(),
        "load": {"one": round(load[0], 2), "five": round(load[1], 2), "fifteen": round(load[2], 2)},
        "temperature_c": cpu_temp(),
        "memory": meminfo(),
        "read_only": True,
    }


class Handler(BaseHTTPRequestHandler):
    server_version = "SOCXPiSidecar/0.1"

    def log_message(self, fmt: str, *args: Any) -> None:
        if os.getenv("SOCX_PI_SIDECAR_ACCESS_LOG", "").lower() in {"1", "true", "yes"}:
            super().log_message(fmt, *args)

    def do_GET(self) -> None:
        if self.path not in {"/", "/health", "/api/health"}:
            self.send_error(404)
            return
        body = json.dumps(payload(), separators=(",", ":")).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)


def main() -> int:
    host = os.getenv("SOCX_PI_SIDECAR_HOST", "0.0.0.0")
    port = int(os.getenv("SOCX_PI_SIDECAR_PORT", "8096"))
    ThreadingHTTPServer((host, port), Handler).serve_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
