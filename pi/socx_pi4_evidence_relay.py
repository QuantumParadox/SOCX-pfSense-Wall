#!/usr/bin/env python3
"""LAN-restricted, dependency-free pfSense evidence relay for a Raspberry Pi 4.

The process accepts newline-delimited TCP syslog, stores each received record
with its SHA-256, rotates daily, and emits a daily integrity manifest. It is
not a SIEM and intentionally has no command or policy-control endpoint.
"""

from __future__ import annotations

import hashlib
import json
import os
import socket
import socketserver
import threading
import time
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any


LOG_DIR = Path(os.getenv("SOCX_EVIDENCE_DIR", "/var/lib/socx-evidence-relay"))
RETENTION_DAYS = max(1, int(os.getenv("SOCX_EVIDENCE_RETENTION_DAYS", "30")))
MAX_LINE_BYTES = max(1024, int(os.getenv("SOCX_EVIDENCE_MAX_LINE_BYTES", "65536")))
ALLOWED_SOURCES = {item.strip() for item in os.getenv("SOCX_EVIDENCE_ALLOWED_SOURCES", "192.168.1.1,127.0.0.1").split(",") if item.strip()}
LOCK = threading.Lock()
STARTED = int(time.time())
COUNTS = {"accepted": 0, "rejected": 0, "bytes": 0, "latest": 0}


def utc_day(ts: float | None = None) -> str:
    return datetime.fromtimestamp(ts or time.time(), tz=timezone.utc).strftime("%Y-%m-%d")


def log_path(day: str | None = None) -> Path:
    return LOG_DIR / f"pfsense-{day or utc_day()}.jsonl"


def ensure_dir() -> None:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    try:
        LOG_DIR.chmod(0o750)
    except OSError:
        pass


def write_manifest(day: str) -> None:
    path = log_path(day)
    if not path.exists():
        return
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    lines = sum(1 for _ in path.open("r", encoding="utf-8", errors="ignore"))
    manifest = {
        "ts": int(time.time()),
        "day": day,
        "file": path.name,
        "bytes": path.stat().st_size,
        "lines": lines,
        "sha256": digest,
        "kind": "socx-evidence-relay-daily-manifest",
        "read_only": True,
    }
    with (LOG_DIR / "manifests.jsonl").open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(manifest, separators=(",", ":")) + "\n")


def prune() -> None:
    cutoff = time.time() - RETENTION_DAYS * 86400
    for path in LOG_DIR.glob("pfsense-*.jsonl"):
        try:
            if path.stat().st_mtime < cutoff:
                path.unlink()
        except OSError:
            pass


def append_message(source: str, raw: bytes) -> None:
    now = time.time()
    message = raw[:MAX_LINE_BYTES].decode("utf-8", "replace").strip()
    if not message:
        return
    record = {
        "ts": int(now),
        "received_utc": datetime.fromtimestamp(now, tz=timezone.utc).isoformat(),
        "source": source,
        "message": message,
        "sha256": hashlib.sha256(raw).hexdigest(),
        "truncated": len(raw) > MAX_LINE_BYTES,
        "read_only": True,
    }
    with LOCK:
        ensure_dir()
        with log_path().open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(record, separators=(",", ":")) + "\n")
        COUNTS["accepted"] += 1
        COUNTS["bytes"] += len(raw)
        COUNTS["latest"] = int(now)
        prune()


class SyslogHandler(socketserver.StreamRequestHandler):
    def handle(self) -> None:
        source = self.client_address[0]
        if source not in ALLOWED_SOURCES:
            with LOCK:
                COUNTS["rejected"] += 1
            return
        total = 0
        while total <= MAX_LINE_BYTES * 8:
            raw = self.rfile.readline(MAX_LINE_BYTES + 1)
            if not raw:
                break
            total += len(raw)
            append_message(source, raw)


class ThreadingSyslogServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


def health_payload() -> dict[str, Any]:
    ensure_dir()
    files = sorted(LOG_DIR.glob("pfsense-*.jsonl"))
    latest_path = files[-1] if files else None
    latest_age = None
    if latest_path:
        latest_age = max(0, int(time.time() - latest_path.stat().st_mtime))
    return {
        "status": "ok",
        "service": "SOCX Pi 4 evidence relay",
        "updated": int(time.time()),
        "uptime_seconds": max(0, int(time.time()) - STARTED),
        "allowed_sources": sorted(ALLOWED_SOURCES),
        "retention_days": RETENTION_DAYS,
        "files": len(files),
        "latest_age_seconds": latest_age,
        "manifest_count": sum(1 for _ in (LOG_DIR / "manifests.jsonl").open("r", encoding="utf-8", errors="ignore")) if (LOG_DIR / "manifests.jsonl").exists() else 0,
        "counts": dict(COUNTS),
        "read_only": True,
    }


class HealthHandler(BaseHTTPRequestHandler):
    def log_message(self, _fmt: str, *_args: Any) -> None:
        return

    def do_GET(self) -> None:
        if self.path not in {"/", "/health", "/api/health"}:
            self.send_error(404)
            return
        body = json.dumps(health_payload(), separators=(",", ":")).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)


def manifest_loop() -> None:
    previous = ""
    while True:
        day = utc_day()
        if previous and day != previous:
            write_manifest(previous)
        previous = day
        time.sleep(60)


def main() -> int:
    ensure_dir()
    host = os.getenv("SOCX_EVIDENCE_HOST", "0.0.0.0")
    syslog_port = int(os.getenv("SOCX_EVIDENCE_PORT", "5514"))
    health_port = int(os.getenv("SOCX_EVIDENCE_HEALTH_PORT", "8097"))
    threading.Thread(target=manifest_loop, daemon=True).start()
    threading.Thread(target=ThreadingHTTPServer((host, health_port), HealthHandler).serve_forever, daemon=True).start()
    with ThreadingSyslogServer((host, syslog_port), SyslogHandler) as server:
        server.serve_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
