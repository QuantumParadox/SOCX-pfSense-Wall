#!/usr/bin/env python3
"""Raspberry Pi SOCX 3-LLM orchestration endpoint.

Run this on the Raspberry Pi 5. pfSense posts compact SOCX JSON here; the Pi
fans it out to three small model roles and returns a tiny wall-safe verdict.
"""

from __future__ import annotations

import asyncio
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import time
import urllib.error
import urllib.request
from datetime import datetime
from typing import Any

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, JSONResponse, StreamingResponse

APP_NAME = "SOCX Pi 3-LLM Orchestrator"
DEFAULT_ROLES = {
    "triage": "http://127.0.0.1:11434/api/generate",
    "evidence": "http://127.0.0.1:11434/api/generate",
    "action": "http://127.0.0.1:11434/api/generate",
}
DEFAULT_MODELS = {
    "triage": os.getenv("SOCX_PI_LLM_TRIAGE_MODEL", "llama3.2:3b"),
    "evidence": os.getenv("SOCX_PI_LLM_EVIDENCE_MODEL", "qwen2.5-instruct:1.5b"),
    "action": os.getenv("SOCX_PI_LLM_ACTION_MODEL", "qwen2.5-coder:1.5b"),
}
HAILO_URL = os.getenv("SOCX_PI_HAILO_URL", "http://127.0.0.1:8000/api/generate")
HAILO_CHAT_URL = os.getenv("SOCX_PI_HAILO_CHAT_URL", "http://127.0.0.1:8000/api/chat")
CPU_URL = os.getenv("SOCX_PI_CPU_URL", "http://127.0.0.1:11434/api/generate")
CPU_FALLBACK_MODELS = {
    "triage": os.getenv("SOCX_PI_CPU_TRIAGE_MODEL", "llama3.2:1b"),
    "evidence": os.getenv("SOCX_PI_CPU_EVIDENCE_MODEL", "qwen2.5:1.5b"),
    "action": os.getenv("SOCX_PI_CPU_ACTION_MODEL", "qwen2.5-coder:1.5b"),
}
HAILO_CAPABILITIES = {
    "voice": "Whisper",
    "vision": "qwen2-vl",
    "complex": "deepseek-r1-distill:1.5b",
    "fallback": "qwen3:1.7b",
}
HAILO_CACHE: dict[str, Any] = {"checked": 0.0, "ok": False, "models": [], "error": "not checked"}
ROLE_STARTED: dict[str, float] = {}
ROLE_TIMEOUTS: dict[str, float] = {}

app = FastAPI(title=APP_NAME)
LATEST_ANALYSIS: dict[str, Any] = {
    "status": "waiting",
    "service": APP_NAME,
    "updated": None,
    "updated_iso": None,
    "roles_online": "0/3",
    "severity": "INFO",
    "confidence": 0,
    "reasons": ["waiting for pfSense SOCX evidence"],
    "recommended_next_step": "Run socx pi-llm from pfSense or wait for the cron job.",
    "role_results": {},
    "models": DEFAULT_MODELS,
    "elapsed_ms": 0,
    "payload_summary": {},
    "source": "raspberry-pi-5-ai-hat-3llm",
    "autonomy": {},
    "system": {},
    "network": {"nodes": [], "links": [], "source": "waiting for pfSense flow telemetry"},
}
EVENTS: list[dict[str, Any]] = []
MAX_EVENTS = 80
HISTORY: list[dict[str, Any]] = []
MAX_HISTORY = 48
NETWORK_HISTORY: list[dict[str, Any]] = []
MAX_NETWORK_HISTORY = 120
STATE_DIR = Path(os.getenv("SOCX_PI_STATE_DIR", "/tmp/socx-pi-state"))
HISTORY_FILE = STATE_DIR / "history.json"
EVIDENCE_DIR = STATE_DIR / "evidence"
NETWORK_HISTORY_FILE = STATE_DIR / "network-history.json"
DRAFTS_FILE = STATE_DIR / "drafts.json"
DRAFTS: list[dict[str, Any]] = []
INGEST_TOKEN = os.getenv("SOCX_PI_INGEST_TOKEN", "").strip()
AUTONOMY_STATE: dict[str, Any] = {
    "mode": "observe",
    "score": 0,
    "summary": "waiting for SOCX evidence",
    "read_only": True,
    "last_cycle_iso": None,
    "experiments": [],
    "recommendations": ["Keep SOCX collecting evidence."],
}
EXPERIMENT_STATE: dict[str, Any] = {
    "active": False,
    "kind": None,
    "started_iso": None,
    "finished_iso": None,
    "progress": 0,
    "status": "ready",
    "result": "No experiment running. All actions are read-only.",
    "history": [],
}
EXPERIMENT_TASK: asyncio.Task[Any] | None = None


def load_history() -> None:
    try:
        data = json.loads(HISTORY_FILE.read_text(encoding="utf-8"))
        if isinstance(data, list):
            HISTORY.extend(data[-MAX_HISTORY:])
    except (OSError, ValueError, TypeError):
        pass
    try:
        data = json.loads(NETWORK_HISTORY_FILE.read_text(encoding="utf-8"))
        if isinstance(data, list):
            NETWORK_HISTORY.extend(data[-MAX_NETWORK_HISTORY:])
    except (OSError, ValueError, TypeError):
        pass
    try:
        data = json.loads(DRAFTS_FILE.read_text(encoding="utf-8"))
        if isinstance(data, list):
            DRAFTS.extend(data[-40:])
    except (OSError, ValueError, TypeError):
        pass


def save_history() -> None:
    try:
        STATE_DIR.mkdir(parents=True, exist_ok=True)
        HISTORY_FILE.write_text(json.dumps(HISTORY[-MAX_HISTORY:], separators=(",", ":")), encoding="utf-8")
        NETWORK_HISTORY_FILE.write_text(json.dumps(NETWORK_HISTORY[-MAX_NETWORK_HISTORY:], separators=(",", ":")), encoding="utf-8")
        DRAFTS_FILE.write_text(json.dumps(DRAFTS[-40:], separators=(",", ":")), encoding="utf-8")
    except OSError:
        event("STATE", "history persistence unavailable", "WARN")


load_history()


def role_url(role: str) -> str:
    return os.getenv(f"SOCX_PI_LLM_{role.upper()}_URL", DEFAULT_ROLES[role])


def now_iso() -> str:
    return datetime.utcnow().replace(microsecond=0).isoformat() + "Z"


def event(kind: str, message: str, severity: str = "INFO") -> None:
    EVENTS.append({"ts": time.time(), "iso": now_iso(), "kind": kind, "severity": severity, "message": message[:300]})
    del EVENTS[:-MAX_EVENTS]


def preserve_evidence(reason: str = "operator request") -> dict[str, Any]:
    bundle = {
        "created_iso": now_iso(),
        "reason": reason[:200],
        "latest_analysis": LATEST_ANALYSIS,
        "events": EVENTS[-40:],
        "autonomy": AUTONOMY_STATE,
        "experiment": EXPERIMENT_STATE,
        "system": system_metrics(),
    }
    raw = json.dumps(bundle, sort_keys=True, separators=(",", ":")).encode("utf-8")
    digest = hashlib.sha256(raw).hexdigest()
    try:
        EVIDENCE_DIR.mkdir(parents=True, exist_ok=True)
        path = EVIDENCE_DIR / f"socx-{int(time.time())}-{digest[:12]}.json"
        path.write_bytes(raw)
        event("EVIDENCE", f"bundle preserved {path.name} sha256 {digest[:12]}", "INFO")
        return {"ok": True, "file": str(path), "sha256": digest, "bytes": len(raw)}
    except OSError as exc:
        return {"ok": False, "error": str(exc)[:220], "sha256": digest}


def command_result(command: str) -> dict[str, Any]:
    normalized = " ".join(command.lower().strip().split())
    summary = LATEST_ANALYSIS.get("payload_summary") if isinstance(LATEST_ANALYSIS.get("payload_summary"), dict) else {}
    if normalized in {"why warn", "why", "explain warn", "why warning"}:
        quality = model_quality_summary(LATEST_ANALYSIS.get("role_results") if isinstance(LATEST_ANALYSIS.get("role_results"), dict) else {})
        reasons = LATEST_ANALYSIS.get("reasons") if isinstance(LATEST_ANALYSIS.get("reasons"), list) else []
        lines = [
            f"severity: {LATEST_ANALYSIS.get('severity', 'INFO')} confidence {round(float(LATEST_ANALYSIS.get('confidence') or 0) * 100)}%",
            f"autopilot: {AUTONOMY_STATE.get('mode', 'observe').upper()} score {AUTONOMY_STATE.get('score', 0)}/100",
            f"signals: FW {summary.get('firewall_blocks_sampled', 0)} DNSBL {summary.get('dnsbl_lines_sampled', 0)} IDS watch {summary.get('ids_watch_sampled', 0)} high {summary.get('ids_high_sampled', 0)}",
            f"model quality: {quality.get('good', 0)} good, {quality.get('weak', 0)} weak, {quality.get('offline', 0)} offline",
        ]
        lines.extend([f"reason: {str(reason)[:140]}" for reason in reasons[:4]])
        lines.extend([
            "plain English: this is a WATCH state because routine security telemetry is busy or AI evidence is not fully clean.",
            "safe next step: preserve evidence, review DNSBL/IDS if the warning persists, and avoid rule changes from this panel.",
        ])
        return {"command": command, "title": "WHY WARN", "lines": lines}
    if normalized in {"status", "show status"}:
        return {"command": command, "title": "SYSTEM STATUS", "lines": [
            f"service: {LATEST_ANALYSIS.get('status', 'waiting')}",
            f"roles: {LATEST_ANALYSIS.get('roles_online', '0/3')}",
            f"severity: {LATEST_ANALYSIS.get('severity', 'INFO')}",
            f"autopilot: {AUTONOMY_STATE.get('mode', 'observe').upper()} (read-only)",
            f"temperature: {system_metrics().get('temp_c', '--')} C",
        ]}
    if normalized in {"vpn", "show vpn"}:
        raw = str(LATEST_ANALYSIS.get("vpn_gateway_status") or "not present in latest evidence")
        return {"command": command, "title": "VPN HEALTH", "lines": [raw[:700]]}
    if normalized in {"top talkers", "show top talkers", "flows"}:
        return {"command": command, "title": "TOP TALKERS", "lines": [
            f"firewall blocks sampled: {summary.get('firewall_blocks_sampled', 0)}",
            f"DNSBL hits sampled: {summary.get('dnsbl_lines_sampled', 0)}",
            "Detailed flow rows remain on the pfSense SOCX wall.",
        ]}
    if normalized in {"network", "show network", "digital twin"}:
        graph = build_network(LATEST_ANALYSIS.get("network_payload") or {})
        return {"command": command, "title": "NETWORK DIGITAL TWIN", "lines": [
            f"nodes: {len(graph['nodes'])} | links: {len(graph['links'])} | source: {graph['source']}",
            *[f"{link['source']} -> {link['target']} | {link['label']}" for link in graph['links'][:6]],
        ]}
    if normalized.startswith("explain host"):
        target = command[len("explain host"):].strip() or "selected host"
        graph = build_network(LATEST_ANALYSIS.get("network_payload") or {})
        hits = [f"{n.get('label')} ({n.get('address', 'no address')})" for n in graph["nodes"] if target.lower() in str(n).lower()]
        return {"command": command, "title": "HOST EXPLANATION", "lines": [
            f"target: {target}", f"identity matches: {', '.join(hits) if hits else 'none in current flow sample'}",
            f"current links: {len(graph['links'])}; use preserve evidence before response changes.",
        ]}
    if normalized in {"trace flow", "show anomalies", "compare normal"}:
        graph = build_network(LATEST_ANALYSIS.get("network_payload") or {})
        if normalized == "trace flow":
            lines = [f"{link['source']} -> {link['target']} [{link['label']}]" for link in graph["links"][:8]] or ["no live flow rows"]
            return {"command": command, "title": "FLOW TRACE", "lines": lines}
        if normalized == "show anomalies":
            return {"command": command, "title": "ANOMALY WATCH", "lines": [
                f"autopilot: {AUTONOMY_STATE.get('mode', 'observe').upper()} score {AUTONOMY_STATE.get('score', 0)}/100",
                str(AUTONOMY_STATE.get("summary", "no anomaly summary")),
            ]}
        return {"command": command, "title": "BASELINE COMPARISON", "lines": [
            f"network snapshots retained: {len(NETWORK_HISTORY)}",
            "Baseline comparison becomes meaningful after several snapshots.",
        ]}
    if normalized.startswith("draft "):
        parts = normalized.split(maxsplit=2)
        kind = parts[1] if len(parts) > 1 else "block"
        target = parts[2] if len(parts) > 2 else "selected host"
        return {"command": command, "title": "RESPONSE DRAFT", "lines": [json.dumps(response_draft(kind, target), separators=(",", ":"))]}
    if normalized in {"thermal", "show thermal"}:
        sysm = system_metrics()
        return {"command": command, "title": "PI THERMAL", "lines": [
            f"temperature: {sysm.get('temp_c', '--')} C",
            f"load 1m: {(sysm.get('load') or {}).get('one', '--')}",
            f"memory used: {(sysm.get('memory') or {}).get('used_pct', '--')}%",
        ]}
    if normalized in {"models", "show models"}:
        quality = model_quality_summary(LATEST_ANALYSIS.get("role_results") if isinstance(LATEST_ANALYSIS.get("role_results"), dict) else {})
        return {"command": command, "title": "LOCAL MODELS", "lines": [
            *[f"{role}: {LATEST_ANALYSIS.get('models', {}).get(role, DEFAULT_MODELS[role])}" for role in DEFAULT_ROLES],
            f"quality: {quality.get('good', 0)} good, {quality.get('weak', 0)} weak, {quality.get('offline', 0)} offline",
        ]}
    if normalized in {"preserve evidence", "capture evidence"}:
        saved = preserve_evidence("operator command")
        return {"command": command, "title": "EVIDENCE VAULT", "lines": [json.dumps(saved, separators=(",", ":"))]}
    if normalized in {"help", "?"}:
        return {"command": command, "title": "COMMANDS", "lines": [
            "status | why warn | vpn | network | top talkers | thermal | models | explain host | trace flow",
            "show anomalies | compare normal | draft block <host> | preserve evidence",
            "All commands are read-only except local evidence preservation.",
        ]}
    return {"command": command, "title": "UNKNOWN COMMAND", "lines": ["Try: status, vpn, network, top talkers, thermal, models, explain host, draft block <host>"]}


def build_network(payload: dict[str, Any]) -> dict[str, Any]:
    """Build a small truthful graph from pfSense flow rows when supplied."""
    nodes: dict[str, dict[str, Any]] = {
        "pfsense": {"id": "pfsense", "label": "pfSense", "kind": "firewall", "status": "online"},
        "wan": {"id": "wan", "label": "WAN", "kind": "internet", "status": "online"},
        "pi": {"id": "pi", "label": "Pi 5 AI", "kind": "ai", "status": "online"},
    }
    links: list[dict[str, Any]] = []
    rows = payload.get("flows") if isinstance(payload.get("flows"), list) else []
    if not rows:
        raw = str(payload.get("flow_lines") or "")
        rows = [{"raw": line} for line in raw.split(";") if line.strip()]
    for idx, row in enumerate(rows[:12]):
        if not isinstance(row, dict):
            row = {"raw": str(row)}
        source = str(row.get("src") or row.get("source") or "LAN").strip()
        target = str(row.get("dst") or row.get("target") or "WAN").strip()
        label = str(row.get("service") or row.get("proto") or "flow").strip()
        source_name = str(row.get("src_name") or row.get("hostname") or source).strip()
        target_name = str(row.get("dst_name") or row.get("peer_name") or target).strip()
        source_id = "lan-" + source.replace(" ", "-")[:24].lower()
        target_id = "wan-" + target.replace(" ", "-")[:24].lower()
        nodes.setdefault(source_id, {"id": source_id, "label": source_name, "address": source, "kind": "host", "status": "active"})
        nodes.setdefault(target_id, {"id": target_id, "label": target_name, "address": target, "kind": "peer", "status": "active"})
        links.append({"id": f"flow-{idx}", "source": source_id, "target": target_id, "label": label[:18], "state": "live"})
    arp_raw = str(payload.get("arp_lines") or "")
    for match in re.finditer(r"\? \((\d{1,3}(?:\.\d{1,3}){3})\) at ([0-9a-f:]{11,})", arp_raw, re.I):
        address, mac = match.groups()
        node_id = "host-" + address.replace(".", "-")
        nodes.setdefault(node_id, {"id": node_id, "label": address, "address": address, "mac": mac.lower(), "kind": "host", "status": "discovered"})
    return {
        "nodes": list(nodes.values()),
        "links": links,
        "source": "pfSense live flow rows" if links else "waiting for pfSense flow telemetry",
        "updated_iso": now_iso(),
    }


def response_draft(kind: str, target: str = "") -> dict[str, Any]:
    target = target.strip()[:80] or "selected host"
    actions = {
        "block": f"Create a temporary pfSense block alias entry for {target}.",
        "quarantine": f"Create a restricted quarantine alias/rule for {target}.",
        "allow": f"Review and draft a narrow allow rule for {target}.",
    }
    action = kind if kind in actions else "block"
    draft = {
        "id": secrets.token_hex(6),
        "created_iso": now_iso(),
        "type": action,
        "target": target,
        "approval_required": True,
        "applied": False,
        "status": "DRAFT ONLY",
        "proposal": actions[action],
        "guardrails": ["No pfSense API was called.", "Operator approval is required.", "Preserve evidence before applying changes."],
    }
    DRAFTS.append(draft)
    del DRAFTS[:-40]
    save_history()
    return draft


def ingest_authorized(request: Request) -> bool:
    if not INGEST_TOKEN:
        return True
    supplied = request.headers.get("authorization", "")
    return secrets.compare_digest(supplied, f"Bearer {INGEST_TOKEN}")


def summarize_role_text(text: str) -> str:
    text = " ".join(str(text or "").replace("\n", " ").split())
    if not text:
        return "no visible role summary returned"
    parsed_text = extract_first_json_object(text)
    if parsed_text:
        text = parsed_text
    try:
        parsed = json.loads(text)
        if isinstance(parsed, dict):
            bits = []
            severity = parsed.get("severity")
            confidence = parsed.get("confidence")
            reasons = parsed.get("reasons")
            step = parsed.get("recommended_next_step")
            if severity:
                bits.append(f"severity {severity}")
            if confidence is not None:
                bits.append(f"confidence {confidence}")
            if isinstance(reasons, list) and reasons:
                bits.append("; ".join(str(reason) for reason in reasons[:2]))
            elif isinstance(reasons, str):
                bits.append(reasons)
            if step:
                bits.append(f"next: {step}")
            return operator_safe_summary(" | ".join(bits)) if bits else operator_safe_summary(text)
    except json.JSONDecodeError:
        pass
    return operator_safe_summary(text)


def extract_first_json_object(text: str) -> str:
    start = text.find("{")
    if start < 0:
        return ""
    depth = 0
    in_string = False
    escape = False
    for index, char in enumerate(text[start:], start):
        if escape:
            escape = False
            continue
        if char == "\\" and in_string:
            escape = True
            continue
        if char == '"':
            in_string = not in_string
            continue
        if in_string:
            continue
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                candidate = text[start:index + 1]
                try:
                    json.loads(candidate)
                    return candidate
                except json.JSONDecodeError:
                    return ""
    return ""


def operator_safe_summary(text: str, limit: int = 180) -> str:
    text = " ".join(str(text or "").split())
    if not text:
        return "no visible role summary returned"
    lower = text.lower()
    if lower in {"{}", "[]", "null", "none"}:
        return "Model route online, but no clear role finding returned."
    if re.fullmatch(r"[0-9.\s\\nrt,:;_-]{3,}", text):
        return "Model route online, but output was token noise; use heuristic SOCX verdict."
    if re.search(r"(?:n1,?){4,}", lower) or len(re.findall(r"\d+\.\d+", text)) >= 4:
        return "Model route online, but output was numeric token noise; use heuristic SOCX verdict."
    if "done_reason" in lower and ("created_at" in lower or "total_duration" in lower):
        return "Model route online, but returned transport metadata instead of a SOC finding."
    if len(re.findall(r"->", text)) >= 5:
        return "Model route online, but output was flow-token noise; use heuristic SOCX verdict."
    low_signal_patterns = [
        "i'm not sure",
        "i am not sure",
        "based on the information provided",
        "cannot determine",
        "as an ai",
    ]
    if any(pattern in lower for pattern in low_signal_patterns):
        return "No clear finding from model output; use heuristic SOCX verdict and latest evidence."
    text = re.sub(r"\(\s*[A-Z0-9]\s*\)(?:\s*\(\s*[A-Z0-9]\s*\)){3,}", " repeated token noise", text)
    text = re.sub(r"\s+", " ", text).strip(" |")
    if len(text) > limit:
        text = text[: limit - 1].rstrip(" ,;|") + "…"
    return text


def is_low_signal_summary(summary: str) -> bool:
    lower = str(summary or "").lower()
    return any(
        marker in lower
        for marker in [
            "no clear role finding",
            "token noise",
            "transport metadata",
            "no clear model finding",
            "flow-token noise",
            "numeric token noise",
        ]
    )


def model_quality_summary(role_results: dict[str, Any]) -> dict[str, Any]:
    roles = []
    good = weak = fallback = offline = 0
    for role in DEFAULT_ROLES:
        result = role_results.get(role) if isinstance(role_results, dict) else {}
        if not isinstance(result, dict):
            result = {}
        ok = bool(result.get("ok"))
        backend = str(result.get("backend") or "unknown")
        summary = str(result.get("summary") or result.get("error") or "")
        low_signal = ok and is_low_signal_summary(summary)
        if not ok:
            state = "offline"
            offline += 1
        elif low_signal:
            state = "weak"
            weak += 1
        else:
            state = "good"
            good += 1
        if backend == "cpu-ollama":
            fallback += 1
        roles.append({
            "role": role,
            "state": state,
            "backend": backend,
            "model": str(result.get("model") or DEFAULT_MODELS.get(role) or ""),
            "elapsed_ms": int(result.get("elapsed_ms") or 0),
            "summary": summary[:180],
            "fallback_error": str(result.get("fallback_error") or "")[:180],
        })
    return {"good": good, "weak": weak, "fallback": fallback, "offline": offline, "roles": roles}


def role_stage_summary(role_results: dict[str, Any]) -> list[dict[str, Any]]:
    stages = {
        "triage": "Classifies severity and decides whether this is routine, watch, or incident.",
        "evidence": "Checks firewall, DNSBL, IDS, flow, and metric evidence for support.",
        "action": "Drafts safe next steps and keeps policy changes approval-gated.",
    }
    rows = []
    for role in DEFAULT_ROLES:
        result = role_results.get(role) if isinstance(role_results, dict) else {}
        if not isinstance(result, dict):
            result = {}
        ok = bool(result.get("ok"))
        backend = str(result.get("backend") or "waiting")
        summary = summarize_role_text(str(result.get("summary") or result.get("error") or "waiting for role output"))[:190]
        if not ok:
            state = "offline or timed out"
        elif is_low_signal_summary(summary):
            state = "weak signal"
        elif backend == "cpu-ollama":
            state = "CPU fallback"
        else:
            state = "online"
        rows.append({
            "role": role,
            "state": state,
            "purpose": stages.get(role, "SOCX model role"),
            "backend": backend,
            "model": str(result.get("model") or DEFAULT_MODELS.get(role) or ""),
            "elapsed_ms": int(result.get("elapsed_ms") or 0),
            "summary": summary,
        })
    return rows


def wall_bridge_summary(data: dict[str, Any]) -> dict[str, Any]:
    role_results = data.get("role_results") if isinstance(data.get("role_results"), dict) else {}
    quality = data.get("model_quality") if isinstance(data.get("model_quality"), dict) else model_quality_summary(role_results)
    severity = str(data.get("severity") or "INFO").upper()
    confidence = float(data.get("confidence") or 0)
    reason = ""
    reasons = data.get("reasons") if isinstance(data.get("reasons"), list) else []
    if reasons:
        reason = summarize_role_text(str(reasons[0]))[:120]
    elif data.get("recommended_next_step"):
        reason = summarize_role_text(str(data.get("recommended_next_step")))[:120]
    text = f"Pi AI {severity} {round(confidence * 100)}% / roles {data.get('roles_online', '0/3')} / {reason or 'waiting for SOCX evidence'}"
    return {
        "text": text,
        "severity": severity,
        "confidence": round(confidence, 2),
        "roles": data.get("roles_online", "0/3"),
        "quality": quality,
        "age_seconds": int(time.time() - float(data.get("updated") or time.time())),
        "read_only": True,
    }


def clean_model_text(text: str) -> str:
    cleaned = str(text or "").strip()
    cleaned = re.sub(r"^```(?:json)?\s*", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\s*```$", "", cleaned)
    return cleaned.strip()


async def check_ollama_tags(timeout: float = 2.0) -> dict[str, Any]:
    def call() -> dict[str, Any]:
        req = urllib.request.Request("http://127.0.0.1:11434/api/tags")
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", "replace")
        data = json.loads(raw)
        models = data.get("models", [])
        names = [str(model.get("name", "")) for model in models if isinstance(model, dict)]
        return {"ok": True, "model_count": len(names), "models": names[:12]}

    try:
        return await asyncio.to_thread(call)
    except Exception as exc:  # health endpoint should never crash the dashboard
        return {"ok": False, "error": str(exc)[:220]}


async def check_hailo_tags(timeout: float = 3.0) -> dict[str, Any]:
    now = time.time()
    if now - float(HAILO_CACHE.get("checked") or 0) < 5:
        return dict(HAILO_CACHE)
    def call() -> dict[str, Any]:
        req = urllib.request.Request("http://127.0.0.1:8000/hailo/v1/list")
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", "replace")
        data = json.loads(raw)
        models = data.get("models", [])
        names = [str(x.get("name", x) if isinstance(x, dict) else x) for x in models]
        return {"ok": True, "model_count": len(names), "models": names[:24]}
    try:
        result = await asyncio.to_thread(call)
    except Exception as exc:
        result = {"ok": False, "model_count": 0, "models": [], "error": str(exc)[:220]}
    result["checked"] = now
    HAILO_CACHE.clear()
    HAILO_CACHE.update(result)
    return dict(result)


async def runtime_inventory() -> dict[str, Any]:
    hailo, cpu = await asyncio.gather(check_hailo_tags(), check_ollama_tags())
    hailo_models = set(hailo.get("models", [])) if hailo.get("ok") else set()
    role_routes = {}
    for role, model in DEFAULT_MODELS.items():
        role_routes[role] = {
            "preferred": {"backend": "hailo", "model": model},
            "available": model in hailo_models,
            "fallback": {"backend": "cpu-ollama", "model": CPU_FALLBACK_MODELS[role]},
        }
    return {
        "hailo": hailo,
        "cpu_ollama": cpu,
        "role_routes": role_routes,
        "capabilities": HAILO_CAPABILITIES,
        "router_policy": {
            "hailo_chat_url": HAILO_CHAT_URL,
            "cpu_generate_url": CPU_URL,
            "mode": "hailo-chat-stream-first; cpu-ollama-fallback",
        },
    }


async def run_experiment(kind: str, duration: int = 30) -> None:
    """Run a bounded, read-only Pi lab experiment.

    The experiment runner deliberately never calls pfSense configuration APIs,
    changes firewall policy, or generates unbounded model traffic.
    """
    global EXPERIMENT_TASK
    duration = max(5, min(120, int(duration)))
    names = {
        "thermal_watch": "Thermal watch",
        "stress_llm": "LLM latency pulse",
        "model_inventory": "Model inventory",
        "role_benchmark": "Role benchmark",
        "explain_socx": "SOCX explain pulse",
        "compare_models": "Model comparison",
    }
    label = names.get(kind, "Telemetry probe")
    EXPERIMENT_STATE.update({
        "active": True,
        "kind": kind,
        "started_iso": now_iso(),
        "finished_iso": None,
        "progress": 0,
        "status": "running",
        "result": f"{label} started; read-only telemetry only.",
    })
    event("LAB", f"{label} started for {duration}s", "INFO")
    started = time.time()
    samples: list[dict[str, Any]] = []
    while time.time() - started < duration:
        sysm = system_metrics()
        samples.append({"ts": time.time(), **sysm})
        EXPERIMENT_STATE["progress"] = min(99, round((time.time() - started) / duration * 100))
        await asyncio.sleep(1)
    ollama = await check_ollama_tags() if kind in {"stress_llm", "model_inventory", "role_benchmark", "compare_models"} else {}
    hailo = await check_hailo_tags() if kind in {"model_inventory", "role_benchmark", "compare_models"} else {}
    temps = [float(s.get("temp_c")) for s in samples if s.get("temp_c") is not None]
    loads = [float((s.get("load") or {}).get("one") or 0) for s in samples]
    result = (
        f"{label} complete | {len(samples)} samples | "
        f"temp {min(temps):.1f}-{max(temps):.1f}C | load peak {max(loads, default=0):.2f}"
    )
    if kind == "model_inventory":
        result += f" | Hailo {'online' if hailo.get('ok') else 'offline'} ({hailo.get('model_count', 0)} models) | Ollama {'online' if ollama.get('ok') else 'offline'} ({ollama.get('model_count', 0)} models)"
    elif kind == "stress_llm":
        result += " | use SOCX triage for the full three-role benchmark"
    elif kind == "role_benchmark":
        roles = role_stage_summary(LATEST_ANALYSIS.get("role_results") if isinstance(LATEST_ANALYSIS.get("role_results"), dict) else {})
        result += " | " + "; ".join(f"{r['role']} {r['state']} {r['elapsed_ms']}ms" for r in roles)
    elif kind == "explain_socx":
        bridge = wall_bridge_summary(LATEST_ANALYSIS)
        result += f" | {bridge.get('text')}"
    elif kind == "compare_models":
        quality = model_quality_summary(LATEST_ANALYSIS.get("role_results") if isinstance(LATEST_ANALYSIS.get("role_results"), dict) else {})
        result += f" | good {quality.get('good', 0)} weak {quality.get('weak', 0)} fallback {quality.get('fallback', 0)} offline {quality.get('offline', 0)}"
    entry = {"kind": kind, "label": label, "finished_iso": now_iso(), "result": result}
    EXPERIMENT_STATE.update({
        "active": False,
        "finished_iso": entry["finished_iso"],
        "progress": 100,
        "status": "complete",
        "result": result,
    })
    EXPERIMENT_STATE["history"].append(entry)
    del EXPERIMENT_STATE["history"][:-8]
    event("LAB", result, "INFO")
    EXPERIMENT_TASK = None


def system_metrics() -> dict[str, Any]:
    metrics: dict[str, Any] = {}
    try:
        one, five, fifteen = os.getloadavg()
        metrics["load"] = {"one": round(one, 2), "five": round(five, 2), "fifteen": round(fifteen, 2)}
    except OSError:
        pass
    try:
        meminfo: dict[str, int] = {}
        with open("/proc/meminfo", "r", encoding="utf-8") as fh:
            for line in fh:
                key, rest = line.split(":", 1)
                meminfo[key] = int(rest.strip().split()[0])
        total = meminfo.get("MemTotal", 0)
        available = meminfo.get("MemAvailable", 0)
        if total:
            metrics["memory"] = {
                "total_gb": round(total / 1024 / 1024, 1),
                "available_gb": round(available / 1024 / 1024, 1),
                "used_pct": round((1 - available / total) * 100),
            }
    except (OSError, ValueError, IndexError):
        pass
    try:
        with open("/sys/class/thermal/thermal_zone0/temp", "r", encoding="utf-8") as fh:
            metrics["temp_c"] = round(int(fh.read().strip()) / 1000, 1)
    except (OSError, ValueError):
        pass
    return metrics


def autonomy_cycle(verdict: dict[str, Any]) -> dict[str, Any]:
    summary = verdict.get("payload_summary") if isinstance(verdict.get("payload_summary"), dict) else {}
    roles_online = str(verdict.get("roles_online") or "0/3")
    elapsed_ms = int(verdict.get("elapsed_ms") or 0)
    fw = int(summary.get("firewall_blocks_sampled") or 0)
    dns = int(summary.get("dnsbl_lines_sampled") or 0)
    ids_high = int(summary.get("ids_high_sampled") or 0)
    ids_watch = int(summary.get("ids_watch_sampled") or 0)
    sysm = system_metrics()
    temp_c = float(sysm.get("temp_c") or 0)
    load_one = float((sysm.get("load") or {}).get("one") or 0)
    score = 0
    score += min(35, fw // 80)
    score += min(25, dns // 120)
    score += min(20, ids_watch // 25)
    score += min(30, ids_high * 10)
    if roles_online != "3/3":
        score += 20
    if elapsed_ms > 180000:
        score += 10
    if temp_c >= 75:
        score += 20
    mode = "observe"
    if score >= 75 or ids_high > 0:
        mode = "investigate"
    elif score >= 45:
        mode = "watch"
    if temp_c >= 78 or load_one >= 6:
        mode = "cooldown"
    previous = HISTORY[-1] if HISTORY else {}
    prev_summary = previous.get("payload_summary") if isinstance(previous.get("payload_summary"), dict) else {}
    delta_fw = fw - int(prev_summary.get("firewall_blocks_sampled") or fw)
    delta_dns = dns - int(prev_summary.get("dnsbl_lines_sampled") or dns)
    experiments = [
        {
            "name": "LLM latency watch",
            "status": "pass" if roles_online == "3/3" and elapsed_ms < 180000 else "watch",
            "detail": f"roles {roles_online}, analysis {round(elapsed_ms / 1000, 1)}s",
        },
        {
            "name": "signal drift",
            "status": "watch" if abs(delta_fw) > 250 or abs(delta_dns) > 250 else "stable",
            "detail": f"FW delta {delta_fw:+d}, DNSBL delta {delta_dns:+d}",
        },
        {
            "name": "Pi thermal headroom",
            "status": "cooldown" if temp_c >= 75 else "pass",
            "detail": f"{temp_c or 0}C, load {load_one}",
        },
    ]
    recommendations = []
    if mode == "investigate":
        recommendations.append("Capture an incident bundle before changing policy.")
    elif mode == "watch":
        recommendations.append("Keep monitoring; compare the next two cycles for drift.")
    elif mode == "cooldown":
        recommendations.append("Reduce test frequency until Pi temperature/load drops.")
    else:
        recommendations.append("Continue autonomous read-only monitoring.")
    recommendations.append("No firewall changes are made by Autopilot.")
    state = {
        "mode": mode,
        "score": min(100, score),
        "summary": f"{mode.upper()} score {min(100, score)} | FW {fw} DNSBL {dns} IDS watch {ids_watch}",
        "read_only": True,
        "last_cycle_iso": now_iso(),
        "experiments": experiments,
        "recommendations": recommendations,
        "deltas": {"firewall_blocks": delta_fw, "dnsbl": delta_dns},
    }
    AUTONOMY_STATE.clear()
    AUTONOMY_STATE.update(state)
    HISTORY.append(
        {
            "ts": time.time(),
            "iso": state["last_cycle_iso"],
            "severity": verdict.get("severity"),
            "roles_online": roles_online,
            "elapsed_ms": elapsed_ms,
            "payload_summary": summary,
            "score": state["score"],
            "mode": mode,
            "system": sysm,
            "model_quality": verdict.get("model_quality") or {},
        }
    )
    del HISTORY[:-MAX_HISTORY]
    save_history()
    event("AUTO", state["summary"], "WARN" if mode in {"watch", "investigate", "cooldown"} else "INFO")
    return state


def compact_payload(payload: dict[str, Any]) -> str:
    summary = payload.get("summary", {})
    return json.dumps(
        {
            "generated_at": payload.get("generated_at"),
            "summary": summary,
            "vpn_gateway_status": str(payload.get("vpn_gateway_status", ""))[:280],
            "speedtest_cache": str(payload.get("speedtest_cache", ""))[:180],
            "unknown_services": str(payload.get("unknown_services", ""))[:180],
            "flow_lines": str(payload.get("flow_lines", ""))[:420],
            "incident_mode": str(payload.get("incident_mode", ""))[:360],
            "intel": payload.get("intel", {}) if isinstance(payload.get("intel"), dict) else str(payload.get("intel", ""))[:360],
            "pi_nodes": str(payload.get("pi_nodes", ""))[:300],
            "top_talkers": str(payload.get("top_talkers", ""))[:240],
            "operator_question": str(payload.get("operator_question", ""))[:220],
            "operator_intent": str(payload.get("operator_intent", ""))[:80],
        },
        separators=(",", ":"),
    )


def build_prompt(role: str, payload: dict[str, Any]) -> str:
    instructions = {
        "triage": "Classify SOCX network health severity. Separate routine noise from urgent items.",
        "evidence": "Identify evidence sources and what should be preserved. Do not invent CVEs.",
        "action": "Recommend safe next steps for an authorized home/lab pfSense SOC dashboard.",
    }
    return (
        f"{instructions[role]}\n"
        "Return only JSON under 45 words: severity, confidence, reasons, recommended_next_step. "
        "Use max two very short reasons. No markdown.\n"
        f"SOCX compact evidence: {compact_payload(payload)}"
    )


def build_operator_prompt(question: str, intent: str, context: dict[str, Any]) -> str:
    return (
        "You are SOCX Operator Chat for an authorized pfSense home/lab SOC dashboard. "
        "Explain firewall, IDS, DNSBL, VPN, speedtest, and Pi AI telemetry in plain English. "
        "Do not reveal hidden chain-of-thought. Show short visible steps and cite the telemetry used. "
        "If the operator asks to configure pfSense, produce a draft-only plan with approval_required true; do not claim a change was applied. "
        "Return one compact JSON object with mode, answer, visible_steps, confidence, approval_required, and safe_commands.\n"
        f"operator_intent={intent}\n"
        f"operator_question={question[:700]}\n"
        f"current_socx_context={json.dumps(context, separators=(',', ':'))[:5500]}"
    )


async def answer_operator_chat(question: str, intent: str, context: dict[str, Any]) -> dict[str, Any]:
    started = time.time()
    event("CHAT", f"operator chat received: {question[:72]}", "INFO")
    prompt = build_operator_prompt(question, intent, context)
    result: dict[str, Any] = {}
    errors: list[str] = []
    inventory = await runtime_inventory()
    preferred = inventory["role_routes"]["action"]
    attempts = []
    if preferred.get("available"):
        attempts.append((HAILO_CHAT_URL, preferred["preferred"]["model"], "hailo", float(os.getenv("SOCX_PI_CHAT_HAILO_TIMEOUT", "120"))))
    attempts.append((role_url("action"), CPU_FALLBACK_MODELS["action"], "cpu-ollama", float(os.getenv("SOCX_PI_CHAT_TIMEOUT", "75"))))
    for url, model, backend, timeout in attempts:
        event("CHAT", f"operator answer thinking with {model} [{backend}]", "INFO")
        try:
            if backend == "hailo":
                result = await asyncio.to_thread(call_hailo_chat, url, model, prompt, timeout)
            else:
                result = await asyncio.to_thread(call_ollama_generate, url, model, prompt, timeout)
            result.update({"role": "operator_chat", "backend": backend, "summary": summarize_role_text(result.get("text", "")), "url": url})
            if backend == "hailo" and is_low_signal_summary(str(result.get("summary") or "")):
                errors.append(f"{backend}: low-signal output")
                continue
            break
        except (OSError, TimeoutError, urllib.error.URLError, json.JSONDecodeError) as exc:
            errors.append(f"{backend}: {str(exc)[:160]}")
            result = {"ok": False, "error": "; ".join(errors)[:360]}
    text = str(result.get("text") or result.get("summary") or "")
    parsed: dict[str, Any] = {}
    if text:
        start = text.find("{")
        end = text.rfind("}")
        if start >= 0 and end > start:
            try:
                parsed = json.loads(text[start : end + 1])
            except json.JSONDecodeError:
                parsed = {}
    loose_answer = loose_json_field(text, "answer") if text and not parsed else ""
    answer = polish_operator_answer(question, str(parsed.get("answer") or loose_answer or clean_model_text(text) or local_operator_answer(question, intent, context)))[:2400]
    if operator_answer_is_weak(answer):
        answer = local_operator_answer(question, intent, context)[:2400]
    phases = parsed.get("visible_steps") if isinstance(parsed.get("visible_steps"), list) else []
    if not phases and text:
        phases = loose_json_list(text, "visible_steps")
    if not phases:
        phases = [
            "received operator question",
            "loaded latest SOCX evidence",
            f"routed through {result.get('backend', 'local fallback')}",
            "returned draft-only guidance" if intent == "draft" else "returned plain-English explanation",
        ]
    mode = str(parsed.get("mode") or loose_json_field(text, "mode") or ("PLAN" if intent == "draft" else "ANSWER")).upper()
    safe_commands = parsed.get("safe_commands") if isinstance(parsed.get("safe_commands"), list) else loose_json_list(text, "safe_commands") or ["socx status", "socx mission", "socx intel"]
    event("CHAT", f"operator chat complete mode {mode}", "INFO")
    return {
        "ok": bool(result.get("ok")) or bool(answer),
        "mode": mode,
        "answer": answer,
        "phases": [str(x)[:140] for x in phases[:6]],
        "confidence": parsed.get("confidence", "medium"),
        "approval_required": bool(parsed.get("approval_required", intent == "draft")),
        "safe_commands": [str(x)[:80] for x in safe_commands[:6]],
        "role": {"model": result.get("model"), "backend": result.get("backend"), "ok": result.get("ok"), "summary": result.get("summary")},
        "elapsed_ms": int((time.time() - started) * 1000),
        "read_only": True,
    }


def local_operator_answer(question: str, intent: str, context: dict[str, Any]) -> str:
    summary = context.get("summary") if isinstance(context.get("summary"), dict) else context.get("payload_summary") if isinstance(context.get("payload_summary"), dict) else {}
    if not summary and isinstance(context.get("threat"), dict):
        threat = context.get("threat") or {}
        summary = {
            "firewall_blocks_sampled": threat.get("fw_blocks"),
            "dnsbl_lines_sampled": threat.get("dnsbl_hits"),
            "ids_watch_sampled": threat.get("ids_watch"),
        }
    intel = context.get("intel") if isinstance(context.get("intel"), dict) else {}
    rows = intel.get("rows") if isinstance(intel.get("rows"), list) else []
    bits = [
        f"SOCX sees firewall blocks {summary.get('firewall_blocks_sampled', '--')}, DNSBL {summary.get('dnsbl_lines_sampled', '--')}, IDS watch {summary.get('ids_watch_sampled', '--')}.",
    ]
    if rows:
        top = rows[0]
        bits.append(f"Top intel context: {top.get('signal', 'network signal')} maps to {top.get('attack', 'ATT&CK advisory')} with {top.get('d3fend', 'defensive review')}.")
    if intent == "draft":
        bits.append("This is a draft-only configuration answer. Preserve evidence first, then review the exact pfSense rule or service change before applying anything.")
    else:
        bits.append("The safest next step is to compare this with Mission, Intel, and Incident views before changing policy.")
    return " ".join(bits)


def polish_operator_answer(question: str, answer: str) -> str:
    text = re.sub(r"\s+", " ", str(answer or "")).strip()
    text = re.sub(r"DNSBL\s*\(\s*Domain[- ]based Denial of Service\s*\)", "DNSBL (DNS Block List)", text, flags=re.I)
    text = re.sub(r"DNSBL\s+means\s+Domain[- ]based Denial of Service", "DNSBL means DNS Block List", text, flags=re.I)
    text = re.sub(r"Domain[- ]based Denial of Service", "DNS Block List", text, flags=re.I)
    text = re.sub(r"\bD3[- ]FEND framework\b", "SOCX defensive-intel layer", text, flags=re.I)
    text = re.sub(r"\bD3[- ]DNSDL technique\b", "DNS/DNSBL defensive evidence", text, flags=re.I)
    if "dnsbl" in question.lower() and "dns block list" not in text.lower():
        text = "DNSBL means DNS Block List: pfBlockerNG blocked a domain lookup because it matched a reputation or category list. " + text
    return text


def operator_answer_is_weak(answer: str) -> bool:
    lower = str(answer or "").lower()
    return any(
        marker in lower
        for marker in [
            "i'm not sure",
            "i am not sure",
            "could you please provide more information",
            "as an ai",
            "cannot determine",
            "no clear model finding",
            "token noise",
        ]
    )


def loose_json_field(text: str, field: str) -> str:
    match = re.search(rf'"{re.escape(field)}"\s*:\s*"((?:\\.|[^"\\])*)"', text, re.S)
    if not match:
        return ""
    value = match.group(1)
    try:
        return json.loads(f'"{value}"')
    except Exception:
        return value.replace("\\n", "\n").replace('\\"', '"')


def loose_json_list(text: str, field: str) -> list[str]:
    match = re.search(rf'"{re.escape(field)}"\s*:\s*\[(.*?)\]', text, re.S)
    if not match:
        return []
    out: list[str] = []
    for item in re.findall(r'"((?:\\.|[^"\\])*)"', match.group(1), re.S):
        try:
            out.append(json.loads(f'"{item}"'))
        except Exception:
            out.append(item.replace("\\n", "\n").replace('\\"', '"'))
    return out[:6]


def call_ollama_generate(url: str, model: str, prompt: str, timeout: float) -> dict[str, Any]:
    body_data = {
        "model": model,
        "prompt": prompt,
        "stream": False,
        "format": "json",
        "keep_alive": os.getenv("SOCX_PI_OLLAMA_KEEP_ALIVE", "6h"),
        "options": {
            "temperature": float(os.getenv("SOCX_PI_LLM_TEMPERATURE", "0.2")),
            "num_predict": int(os.getenv("SOCX_PI_LLM_NUM_PREDICT", "32")),
            "num_ctx": int(os.getenv("SOCX_PI_LLM_NUM_CTX", "768")),
        },
    }
    body = json.dumps(body_data).encode()
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8", "replace")
    data = json.loads(raw)
    text = clean_model_text(str(data.get("response") or data.get("text") or raw))
    return {"ok": True, "model": model, "text": text[:1200]}


def call_hailo_chat(url: str, model: str, prompt: str, timeout: float) -> dict[str, Any]:
    body_data = {
        "model": model,
        "messages": [{"role": "user", "content": prompt}],
        "stream": os.getenv("SOCX_PI_HAILO_STREAM", "false").lower() in {"1", "true", "yes"},
        "options": {
            "temperature": float(os.getenv("SOCX_PI_LLM_TEMPERATURE", "0.2")),
            "num_predict": int(os.getenv("SOCX_PI_LLM_NUM_PREDICT", "32")),
            "num_ctx": int(os.getenv("SOCX_PI_LLM_NUM_CTX", "768")),
        },
    }
    started = time.time()
    body = json.dumps(body_data).encode()
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
    parts: list[str] = []
    last_frame: dict[str, Any] = {}
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        for raw_line in resp:
            line = raw_line.decode("utf-8", "replace").strip()
            if not line:
                continue
            if line.startswith("data:"):
                line = line[5:].strip()
            if line == "[DONE]":
                break
            try:
                frame = json.loads(line)
            except json.JSONDecodeError:
                parts.append(line)
                continue
            last_frame = frame
            message = frame.get("message")
            if isinstance(message, dict):
                parts.append(str(message.get("content") or ""))
            elif frame.get("response") is not None:
                parts.append(str(frame.get("response") or ""))
            elif frame.get("text") is not None:
                parts.append(str(frame.get("text") or ""))
            if frame.get("done"):
                break
    text = clean_model_text("".join(parts))
    if not text:
        text = str(last_frame)[:1200]
    return {"ok": True, "model": model, "text": text[:1200], "elapsed_ms": round((time.time() - started) * 1000)}


async def warm_cpu_fallback_models() -> None:
    if os.getenv("SOCX_PI_WARM_CPU_MODELS", "true").lower() in {"0", "false", "no"}:
        return
    models = list(dict.fromkeys(CPU_FALLBACK_MODELS.values()))
    timeout = float(os.getenv("SOCX_PI_WARM_TIMEOUT", "180"))
    for model in models:
        started = time.time()
        event("WARMUP", f"warming CPU fallback model {model}", "INFO")
        try:
            await asyncio.to_thread(call_ollama_generate, CPU_URL, model, "Return {\"severity\":\"INFO\",\"confidence\":0.5,\"reasons\":[\"warmup\"],\"recommended_next_step\":\"monitor\"}", timeout)
            event("WARMUP", f"CPU fallback model {model} warm in {round(time.time() - started, 1)}s", "INFO")
        except Exception as exc:
            event("WARMUP", f"CPU fallback model {model} warmup failed: {str(exc)[:160]}", "WARN")


@app.on_event("startup")
async def startup_warmup() -> None:
    asyncio.create_task(warm_cpu_fallback_models())


async def run_role(role: str, payload: dict[str, Any]) -> dict[str, Any]:
    role_started = time.time()
    ROLE_STARTED[role] = role_started
    inventory = await runtime_inventory()
    preferred = inventory["role_routes"][role]
    hailo_available = bool(preferred.get("available"))
    attempts = []
    if hailo_available:
        attempts.append((HAILO_CHAT_URL, preferred["preferred"]["model"], "hailo", float(os.getenv("SOCX_PI_HAILO_TIMEOUT", "3"))))
    attempts.append((role_url(role), CPU_FALLBACK_MODELS[role], "cpu-ollama", float(os.getenv("SOCX_PI_LLM_TIMEOUT", "25"))))
    prompt = build_prompt(role, payload)
    errors: list[str] = []
    low_signal_result: dict[str, Any] | None = None
    for url, model, backend, timeout in attempts:
        timeout = max(3.0, min(timeout, float(os.getenv("SOCX_PI_ROLE_MAX_SECONDS", "28"))))
        ROLE_TIMEOUTS[role] = timeout
        event("ROLE", f"{role} thinking with {model} [{backend}]", "INFO")
        try:
            if backend == "hailo":
                result = await asyncio.wait_for(asyncio.to_thread(call_hailo_chat, url, model, prompt, timeout), timeout=timeout + 3)
            else:
                result = await asyncio.wait_for(asyncio.to_thread(call_ollama_generate, url, model, prompt, timeout), timeout=timeout + 3)
            result.update({
                "role": role,
                "backend": backend,
                "summary": summarize_role_text(result.get("text", "")),
                "url": url,
                "status": "ok",
                "duration_ms": int((time.time() - role_started) * 1000),
                "timeout_sec": timeout,
            })
            if backend == "hailo" and is_low_signal_summary(str(result.get("summary") or "")):
                event("ROUTER", f"{role} Hailo output low-signal; falling back to CPU Ollama", "WARN")
                errors.append(f"{backend}: low-signal output")
                result["status"] = "weak"
                result["summary"] = "Model route online, but returned low-signal output."
                low_signal_result = dict(result)
                if os.getenv("SOCX_PI_ACCEPT_WEAK_HAILO", "true").lower() not in {"0", "false", "no"}:
                    event("ROLE", f"{role} accepted weak Hailo result to keep SOCX responsive", "WARN")
                    return result
                continue
            event("ROLE", f"{role} complete with {model} [{backend}]", "INFO")
            return result
        except (asyncio.TimeoutError, OSError, TimeoutError, urllib.error.URLError, json.JSONDecodeError) as exc:
            error = ("timeout" if isinstance(exc, asyncio.TimeoutError) else str(exc))[:220]
            event("ROLE", f"{role} failed with {model} [{backend}]: {error}", "WARN")
            if backend == "hailo":
                event("ROUTER", f"{role} falling back to CPU Ollama", "WARN")
            errors.append(f"{backend}: {error}")
    if low_signal_result:
        low_signal_result["fallback_error"] = "; ".join(errors)[:420]
        low_signal_result["status"] = "weak"
        low_signal_result["duration_ms"] = int((time.time() - role_started) * 1000)
        event("ROLE", f"{role} using low-signal Hailo result after fallback exhaustion", "WARN")
        return low_signal_result
    return {
        "ok": False,
        "role": role,
        "model": CPU_FALLBACK_MODELS[role],
        "backend": "unavailable",
        "status": "timeout" if any("timeout" in e.lower() for e in errors) else "error",
        "summary": "Hailo and CPU Ollama unavailable or timed out",
        "error": "; ".join(errors)[:420],
        "duration_ms": int((time.time() - role_started) * 1000),
        "timeout_sec": ROLE_TIMEOUTS.get(role),
    }


async def run_roles(payload: dict[str, Any]) -> dict[str, dict[str, Any]]:
    concurrency = max(1, int(os.getenv("SOCX_PI_LLM_CONCURRENCY", "1")))
    total_timeout = float(os.getenv("SOCX_PI_TOTAL_TIMEOUT", "90"))
    started = time.time()
    if concurrency <= 1:
        results: dict[str, dict[str, Any]] = {}
        for role in DEFAULT_ROLES:
            remaining = total_timeout - (time.time() - started)
            if remaining <= 2:
                results[role] = {"ok": False, "role": role, "model": DEFAULT_MODELS.get(role), "backend": "watchdog", "status": "timeout", "summary": "Skipped because total Pi analysis budget expired.", "error": "total timeout"}
                continue
            try:
                results[role] = await asyncio.wait_for(run_role(role, payload), timeout=max(2.0, remaining))
            except asyncio.TimeoutError:
                event("WATCHDOG", f"{role} exceeded remaining Pi analysis budget", "WARN")
                results[role] = {"ok": False, "role": role, "model": DEFAULT_MODELS.get(role), "backend": "watchdog", "status": "timeout", "summary": "Role exceeded remaining total Pi analysis budget.", "error": "total timeout", "timeout_sec": round(remaining, 1)}
        return results
    semaphore = asyncio.Semaphore(concurrency)

    async def guarded(role: str) -> tuple[str, dict[str, Any]]:
        async with semaphore:
            return role, await run_role(role, payload)

    try:
        result_pairs = await asyncio.wait_for(asyncio.gather(*(guarded(role) for role in DEFAULT_ROLES)), timeout=total_timeout)
    except asyncio.TimeoutError:
        event("WATCHDOG", f"Pi role analysis exceeded {total_timeout}s total budget", "WARN")
        partial = {}
        for role in DEFAULT_ROLES:
            partial[role] = {"ok": False, "role": role, "model": DEFAULT_MODELS.get(role), "backend": "watchdog", "status": "timeout", "summary": "Role did not finish inside total Pi analysis budget.", "error": "total timeout"}
        return partial
    return dict(result_pairs)


def heuristic_verdict(payload: dict[str, Any], role_results: dict[str, dict[str, Any]]) -> dict[str, Any]:
    summary = payload.get("summary") if isinstance(payload.get("summary"), dict) else {}
    ids_high = int(summary.get("ids_high_sampled") or 0)
    ids_watch = int(summary.get("ids_watch_sampled") or 0)
    fw = int(summary.get("firewall_blocks_sampled") or 0)
    dns = int(summary.get("dnsbl_lines_sampled") or 0)
    online = sum(1 for result in role_results.values() if result.get("ok"))
    severity = "INFO"
    reasons: list[str] = []
    if ids_high > 0:
        severity = "WARN"
        reasons.append(f"{ids_high} high-signal IDS lines")
    elif ids_watch > 200:
        severity = "WARN"
        reasons.append(f"{ids_watch} routine IDS watch lines")
    if fw > 100:
        reasons.append(f"{fw} firewall blocks sampled")
    if dns > 500:
        reasons.append(f"{dns} DNSBL lines sampled")
    confidence = 0.55 + (online * 0.12)
    if online == 0:
        reasons.insert(0, "model endpoints offline; heuristic fallback")
    if not reasons:
        reasons.append("primary SOCX signals nominal")
    return {
        "severity": severity,
        "confidence": round(min(confidence, 0.91), 2),
        "reasons": reasons[:3],
        "recommended_next_step": "Run socx-doctor ids/dnsbl-review if WARN persists; preserve incident bundle before changes.",
        "roles_online": f"{online}/3",
    }


@app.get("/health")
async def health() -> dict[str, Any]:
    inventory = await runtime_inventory()
    return {
        "status": "ok",
        "service": APP_NAME,
        "roles": list(DEFAULT_ROLES),
        "latest_status": LATEST_ANALYSIS.get("status"),
        "roles_online": LATEST_ANALYSIS.get("roles_online"),
        "updated_iso": LATEST_ANALYSIS.get("updated_iso"),
        "ollama": inventory["cpu_ollama"],
        "runtime": inventory,
        "system": system_metrics(),
        "autonomy": AUTONOMY_STATE,
        "ollama_hint": "curl http://127.0.0.1:11434/api/tags",
    }


@app.get("/", response_class=HTMLResponse)
async def root() -> str:
    return DASHBOARD_HTML


@app.get("/dashboard", response_class=HTMLResponse)
async def dashboard() -> str:
    return DASHBOARD_HTML


@app.get("/kiosk", response_class=HTMLResponse)
async def kiosk() -> str:
    return DASHBOARD_HTML


@app.get("/api/socx/latest")
async def latest() -> JSONResponse:
    data = dict(LATEST_ANALYSIS)
    data["events"] = EVENTS[-30:]
    data["age_seconds"] = int(time.time() - float(data["updated"] or time.time()))
    data["ollama"] = await check_ollama_tags()
    data["system"] = system_metrics()
    data["autonomy"] = AUTONOMY_STATE
    data["experiment"] = EXPERIMENT_STATE
    data["network"] = build_network(data.get("network_payload") or {})
    data["network_history"] = NETWORK_HISTORY[-30:]
    data["drafts"] = DRAFTS[-12:]
    data["runtime"] = await runtime_inventory()
    data["history"] = HISTORY[-18:]
    data["model_quality"] = model_quality_summary(data.get("role_results") if isinstance(data.get("role_results"), dict) else {})
    data["role_timeline"] = role_stage_summary(data.get("role_results") if isinstance(data.get("role_results"), dict) else {})
    data["wall_bridge"] = wall_bridge_summary(data)
    return JSONResponse(data)


@app.get("/api/socx/autonomy")
async def autonomy() -> JSONResponse:
    return JSONResponse({"autonomy": AUTONOMY_STATE, "history": HISTORY[-24:], "system": system_metrics(), "experiment": EXPERIMENT_STATE})


@app.get("/api/socx/network")
async def network() -> JSONResponse:
    return JSONResponse(build_network(LATEST_ANALYSIS.get("network_payload") or {}))


@app.post("/api/socx/network")
async def network_ingest(request: Request) -> JSONResponse:
    if not ingest_authorized(request):
        return JSONResponse({"error": "telemetry authorization required"}, status_code=401)
    payload = await request.json()
    LATEST_ANALYSIS["network_payload"] = payload
    graph = build_network(payload)
    NETWORK_HISTORY.append({"ts": time.time(), "iso": graph["updated_iso"], "nodes": len(graph["nodes"]), "links": len(graph["links"]), "graph": graph})
    del NETWORK_HISTORY[:-MAX_NETWORK_HISTORY]
    save_history()
    event("FLOW", f"network twin refreshed nodes {len(graph['nodes'])} links {len(graph['links'])}", "INFO")
    return JSONResponse(graph)


@app.get("/api/socx/network/history")
async def network_history() -> JSONResponse:
    return JSONResponse({"history": NETWORK_HISTORY[-60:]})


@app.get("/api/socx/wall-bridge")
async def wall_bridge() -> JSONResponse:
    data = dict(LATEST_ANALYSIS)
    data["model_quality"] = model_quality_summary(data.get("role_results") if isinstance(data.get("role_results"), dict) else {})
    return JSONResponse(wall_bridge_summary(data))


@app.post("/api/socx/draft")
async def draft(request: Request) -> JSONResponse:
    payload = await request.json()
    return JSONResponse(response_draft(str(payload.get("kind") or "block"), str(payload.get("target") or "selected host")))


@app.get("/api/socx/drafts")
async def drafts() -> JSONResponse:
    return JSONResponse({"drafts": DRAFTS[-40:]})


@app.post("/api/socx/draft/{draft_id}/review")
async def review_draft(draft_id: str) -> JSONResponse:
    for draft in reversed(DRAFTS):
        if draft.get("id") == draft_id:
            draft["reviewed"] = True
            draft["reviewed_iso"] = now_iso()
            save_history()
            event("REVIEW", f"draft {draft_id} acknowledged; no change applied", "INFO")
            return JSONResponse({"ok": True, "draft": draft, "applied": False})
    return JSONResponse({"ok": False, "error": "draft not found"}, status_code=404)


@app.get("/api/socx/backup")
async def backup() -> JSONResponse:
    return JSONResponse({"created_iso": now_iso(), "history": HISTORY[-48:], "network_history": NETWORK_HISTORY[-120:], "drafts": DRAFTS[-40:], "read_only": True})


@app.post("/api/socx/experiment")
async def experiment(request: Request) -> JSONResponse:
    global EXPERIMENT_TASK
    payload = await request.json()
    action = str(payload.get("action") or "start").lower()
    kind = str(payload.get("kind") or "thermal_watch").lower()
    if action == "stop":
        if EXPERIMENT_TASK and not EXPERIMENT_TASK.done():
            EXPERIMENT_TASK.cancel()
        EXPERIMENT_TASK = None
        EXPERIMENT_STATE.update({"active": False, "status": "stopped", "progress": 0, "result": "Experiment stopped by operator."})
        event("LAB", "Experiment stopped by operator", "WARN")
        return JSONResponse(EXPERIMENT_STATE)
    if kind not in {"thermal_watch", "stress_llm", "model_inventory", "role_benchmark", "explain_socx", "compare_models"}:
        return JSONResponse({"error": "unsupported experiment"}, status_code=400)
    if EXPERIMENT_TASK and not EXPERIMENT_TASK.done():
        return JSONResponse({"error": "an experiment is already running", "experiment": EXPERIMENT_STATE}, status_code=409)
    duration = max(5, min(120, int(payload.get("duration", 30))))
    EXPERIMENT_TASK = asyncio.create_task(run_experiment(kind, duration))
    return JSONResponse({"accepted": True, "experiment": EXPERIMENT_STATE})


@app.post("/api/socx/command")
async def command(request: Request) -> JSONResponse:
    payload = await request.json()
    text = str(payload.get("command") or "help")[:120]
    result = command_result(text)
    return JSONResponse(result)


@app.post("/api/socx/chat")
async def operator_chat(request: Request) -> JSONResponse:
    payload = await request.json()
    question = str(payload.get("question") or payload.get("message") or "")[:900]
    intent = str(payload.get("intent") or "explain")[:80]
    context = payload.get("context") if isinstance(payload.get("context"), dict) else {}
    return JSONResponse(await answer_operator_chat(question, intent, context))


@app.post("/api/socx/evidence")
async def evidence(request: Request) -> JSONResponse:
    payload = await request.json()
    return JSONResponse(preserve_evidence(str(payload.get("reason") or "operator request")))


@app.get("/api/socx/stream")
async def stream() -> StreamingResponse:
    async def gen():
        while True:
            data = dict(LATEST_ANALYSIS)
            data["events"] = EVENTS[-30:]
            data["age_seconds"] = int(time.time() - float(data["updated"] or time.time()))
            data["ollama"] = await check_ollama_tags()
            data["system"] = system_metrics()
            data["autonomy"] = AUTONOMY_STATE
            data["experiment"] = EXPERIMENT_STATE
            data["network"] = build_network(data.get("network_payload") or {})
            data["network_history"] = NETWORK_HISTORY[-30:]
            data["drafts"] = DRAFTS[-12:]
            data["runtime"] = await runtime_inventory()
            data["history"] = HISTORY[-18:]
            data["model_quality"] = model_quality_summary(data.get("role_results") if isinstance(data.get("role_results"), dict) else {})
            data["role_timeline"] = role_stage_summary(data.get("role_results") if isinstance(data.get("role_results"), dict) else {})
            data["wall_bridge"] = wall_bridge_summary(data)
            yield f"data: {json.dumps(data, separators=(',', ':'))}\n\n"
            await asyncio.sleep(1)

    return StreamingResponse(gen(), media_type="text/event-stream")


@app.post("/api/socx/triage")
async def triage(request: Request) -> dict[str, Any]:
    payload = await request.json()
    started = time.time()
    event("SOCX", "pfSense evidence received; running triage/evidence/action roles", "INFO")
    role_results = await run_roles(payload)
    verdict = heuristic_verdict(payload, role_results)
    updated = time.time()
    verdict.update(
        {
            "source": "raspberry-pi-5-ai-hat-3llm",
            "elapsed_ms": int((time.time() - started) * 1000),
            "models": {role: result.get("model", "") for role, result in role_results.items()},
            "role_results": role_results,
            "payload_summary": payload.get("summary", {}) if isinstance(payload.get("summary"), dict) else {},
            "network_payload": payload,
            "status": "ok" if verdict.get("roles_online") != "0/3" else "degraded",
            "updated": updated,
            "updated_iso": now_iso(),
            "system": system_metrics(),
        }
    )
    verdict["model_quality"] = model_quality_summary(role_results)
    verdict["autonomy"] = autonomy_cycle(verdict)
    LATEST_ANALYSIS.clear()
    LATEST_ANALYSIS.update(verdict)
    event("LLM", f"analysis complete roles {verdict.get('roles_online')} severity {verdict.get('severity')}", str(verdict.get("severity", "INFO")))
    return verdict


DASHBOARD_HTML = r"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SOCX Pi 3-LLM Dashboard</title>
<style>
:root{color-scheme:dark;--bg:#05070a;--panel:#091116;--ink:#e8fbff;--muted:#88a3aa;--cyan:#49fff4;--green:#66ff7c;--yellow:#ffe35b;--red:#ff5e78;--mag:#ff56f4;--blue:#6687ff;--lcars:#ff9f43;--lcars2:#c76dff}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 50% -10%,#15313a 0,#05070a 48%,#020304 100%);color:var(--ink);font-family:Inter,system-ui,Segoe UI,Arial,sans-serif;overflow:hidden}
.shell{height:100vh;display:grid;grid-template-rows:48px minmax(0,1fr) 124px;gap:8px;padding:10px}
.top{display:grid;grid-template-columns:1fr auto;align-items:center;border:1px solid #1cfff355;background:linear-gradient(90deg,#071215,#0b1218);box-shadow:0 0 22px #1cfff322;padding:7px 11px;min-width:0}
.brand{font-weight:800;letter-spacing:.08em;color:var(--cyan);font-size:17px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.brand:before{content:'◖ ';color:var(--lcars)}.status{display:flex;gap:10px;align-items:center;font-weight:800;min-width:0}.pill{padding:3px 8px;border:1px solid #ffffff22;background:#ffffff08;white-space:nowrap}.ok{color:var(--green)}.warn{color:var(--yellow)}.bad{color:var(--red)}.mag{color:var(--mag)}.muted{color:var(--muted)}
.main{display:grid;grid-template-columns:minmax(340px,1.05fr) minmax(430px,1.45fr) minmax(340px,1.05fr);gap:8px;min-height:0}
.panel{border:1px solid #1cfff355;background:linear-gradient(180deg,#071116dd,#05090ddd);box-shadow:inset 0 0 22px #1cfff310,0 0 18px #1cfff314;min-height:0;padding:10px;overflow:hidden}
.panel.scroll{overflow:auto}
h2{margin:0 0 7px;color:var(--cyan);font-size:14px;letter-spacing:.08em}.metric{display:grid;grid-template-columns:122px 1fr;gap:8px;margin:5px 0;color:var(--muted)}.metric b{color:var(--ink)}
.role{display:grid;grid-template-columns:84px 1fr;gap:10px;border-top:1px solid #ffffff17;padding:8px 0;min-width:0}.role:first-of-type{border-top:0}.role-name{font-weight:900;color:var(--blue);text-transform:uppercase}.role.ok .role-name{color:var(--green)}.role.fail .role-name{color:var(--red)}.role-text{font-size:13px;line-height:1.26;white-space:normal;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.role-meta{font-size:11px;color:var(--muted);margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.role-timeline{display:grid;gap:7px;margin-top:8px}.role-step{display:grid;grid-template-columns:78px 78px 1fr;gap:8px;align-items:center;border:1px solid #ffffff17;background:#ffffff07;padding:7px 8px}.role-step b{text-transform:uppercase;color:var(--cyan)}.role-step .state{font-weight:900}.role-step .state.good{color:var(--green)}.role-step .state.warn{color:var(--yellow)}.role-step .state.bad{color:var(--red)}.role-step span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink)}
.viz{position:relative;height:100%;min-height:360px;overflow:hidden}.viz canvas{position:absolute;inset:0;width:100%;height:100%}.core{position:absolute;left:50%;top:50%;width:132px;height:132px;margin:-66px;border:2px solid var(--cyan);border-radius:50%;display:grid;place-items:center;text-align:center;font-weight:900;color:var(--cyan);box-shadow:0 0 36px #49fff466, inset 0 0 24px #49fff41e;animation:pulse 2.2s infinite}
.vizhud{position:absolute;left:12px;right:12px;bottom:12px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;pointer-events:none}.vizstat{border:1px solid #ffffff22;background:#02080caa;padding:7px 8px;font:700 12px ui-monospace,Consolas,monospace;color:var(--muted)}.vizstat b{display:block;color:var(--ink);font-size:16px;margin-top:2px}.twin-status{position:absolute;top:12px;left:12px;border:1px solid #ff9f4366;background:#1b1018cc;color:var(--lcars);padding:6px 8px;font:700 11px ui-monospace,Consolas,monospace;letter-spacing:.05em}
.thermal-strip{position:absolute;left:12px;right:12px;bottom:70px;height:42px;border:1px solid #ffffff20;background:#02080caa;padding:6px 8px;display:grid;grid-template-columns:auto 1fr;gap:10px;align-items:center;pointer-events:none}.thermal-strip span{font:700 11px ui-monospace,Consolas,monospace;color:var(--muted);white-space:nowrap}.thermal-strip canvas{position:static;width:100%;height:26px}
@keyframes pulse{50%{transform:scale(1.045);box-shadow:0 0 54px #49fff488,inset 0 0 34px #49fff433}}
.feed{display:grid;grid-template-columns:1fr 1fr;gap:8px;min-height:0}.events{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px;line-height:1.34;overflow:hidden}.event{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.json{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px;color:#d8fbff;overflow:hidden;white-space:pre-wrap}
.bar{height:9px;background:#ffffff16;margin-top:6px;overflow:hidden}.bar span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--lcars),var(--cyan),var(--green))}
.lab{border:1px solid #ff9f4355;background:#1b1018;padding:9px 10px;margin-top:10px}.lab-title{display:flex;justify-content:space-between;color:var(--lcars);font-weight:900;letter-spacing:.06em}.lab-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:8px}.lab button{border:1px solid #49fff466;background:#071c21;color:var(--cyan);padding:7px 5px;font:700 11px ui-monospace,Consolas,monospace;cursor:pointer}.lab button:hover{background:#49fff422;color:#fff}.lab button.stop{color:var(--red);border-color:#ff5e7866}.lab-status{margin-top:8px;font:12px ui-monospace,Consolas,monospace;color:var(--ink);white-space:normal}.lab-history{margin-top:6px;color:var(--muted);font:11px ui-monospace,Consolas,monospace}
.route-grid,.bridge-grid{display:grid;gap:7px;margin-top:8px}.route-grid{grid-template-columns:repeat(3,1fr)}.route-card,.bridge-card{border:1px solid #ffffff1f;background:#ffffff08;padding:7px 8px;min-width:0}.route-card b,.bridge-card b{display:block;color:var(--cyan);font-size:11px;text-transform:uppercase;letter-spacing:.05em}.route-card span,.bridge-card span{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bridge-card strong{color:var(--green);font-size:16px}.bridge-grid{grid-template-columns:1fr 1fr}
.command{border:1px solid #c76dff66;background:#130d1b;padding:9px 10px;margin-top:10px}.command-title{display:flex;justify-content:space-between;color:var(--lcars2);font-weight:900;letter-spacing:.06em}.command-row{display:grid;grid-template-columns:1fr auto;gap:6px;margin-top:7px}.command input{min-width:0;border:1px solid #ffffff22;background:#020609;color:var(--ink);padding:7px;font:12px ui-monospace,Consolas,monospace}.command button{border:1px solid #c76dff88;background:#21102d;color:var(--lcars2);padding:6px 8px;font:700 11px ui-monospace,Consolas,monospace;cursor:pointer}.command-output{margin-top:7px;min-height:42px;white-space:pre-wrap;color:var(--ink);font:12px/1.4 ui-monospace,Consolas,monospace}.command-help{color:var(--muted);font:10px ui-monospace,Consolas,monospace;margin-top:5px}
.quick-row{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px}.quick-row button{border:1px solid #ffe35b77;background:#231d0a;color:var(--yellow);padding:6px 8px;font:700 10px ui-monospace,Consolas,monospace;cursor:pointer}
.review{border:1px solid #ffe35b66;background:#1d180b;padding:9px 10px;margin-top:10px}.review-title{display:flex;justify-content:space-between;color:var(--yellow);font-weight:900;letter-spacing:.06em}.review-item{border-top:1px solid #ffffff17;padding:7px 0;font:12px/1.35 ui-monospace,Consolas,monospace}.review-item:first-child{border-top:0}.review-item b{color:var(--ink)}.review button{float:right;border:1px solid #66ff7c88;background:#0e2815;color:var(--green);padding:4px 6px;font:700 10px ui-monospace,Consolas,monospace;cursor:pointer}
.model-grid,.summary-grid,.experiment-grid,.quality-grid{display:grid;gap:7px}.model-card,.summary-card,.experiment-card,.quality-card{border:1px solid #ffffff1f;background:#ffffff08;padding:7px 9px}.model-card{display:grid;grid-template-columns:84px 1fr auto;gap:8px;align-items:center}.model-role{font-weight:900;text-transform:uppercase;color:var(--cyan)}.model-name{font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.quality-grid{grid-template-columns:repeat(4,1fr)}.quality-card span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.05em}.quality-card b{display:block;font-size:18px}.summary-grid{grid-template-columns:repeat(5,1fr)}.summary-card span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.summary-card b{display:block;color:var(--ink);font-size:18px;margin-top:1px}.summary-card small{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.plain{font-size:13px;line-height:1.36;color:var(--ink)}.autopilot{border:1px solid #ff56f455;background:#210a2438;padding:8px 9px;margin-top:8px}.autopilot-title{display:flex;justify-content:space-between;gap:10px;font-weight:900;color:var(--mag);letter-spacing:.06em}.experiment-card{font-size:12px}.experiment-card b{display:block;color:var(--ink)}.trend{height:28px;width:100%;margin-top:6px}.timeline{width:100%;height:42px;margin-top:7px;border:1px solid #ffffff18;background:#02080c88}
body.kiosk .shell{grid-template-rows:48px minmax(0,1fr) 98px}body.kiosk .main{grid-template-columns:300px minmax(560px,1fr) 300px}body.kiosk .review,body.kiosk .lab,body.kiosk #models,body.kiosk #reasons,body.kiosk h2.hide-kiosk{display:none}body.kiosk .viz{min-height:520px}body.kiosk .role-text{-webkit-line-clamp:4}body.kiosk .feed{grid-template-columns:1fr}
@media(max-width:1100px){.main{grid-template-columns:1fr}.shell{overflow:auto;height:auto}.viz{height:360px}.feed{grid-template-columns:1fr}body{overflow:auto}}
</style>
</head>
<body>
<div class="shell">
  <header class="top">
    <div class="brand">SOCX PI 3-LLM DASHBOARD</div>
    <div class="status">
      <span id="clock"></span><span id="svc" class="pill muted">WAIT</span><span id="roles" class="pill warn">0/3</span><span id="hailo" class="pill muted">HAILO</span><span id="ollama" class="pill bad">OLLAMA</span><span id="age" class="pill muted">age ?</span>
    </div>
  </header>
  <main class="main">
    <section class="panel">
      <h2>AI SOC Verdict</h2>
      <div class="metric"><span>Severity</span><b id="severity">INFO</b></div>
      <div class="metric"><span>Confidence</span><b id="confidence">0</b></div>
      <div class="metric"><span>Elapsed</span><b id="elapsed">0ms</b></div>
      <div class="metric"><span>Evidence</span><b id="evidence">waiting</b></div>
      <div class="bar"><span id="confbar"></span></div>
      <h2 style="margin-top:18px">Visible Role Reasoning</h2>
      <div id="rolebox"></div>
      <h2 style="margin-top:18px">Role Timeline</h2>
      <div id="roleTimeline" class="role-timeline"></div>
      <h2 style="margin-top:18px">SOCX Bridge</h2>
      <div id="bridge" class="bridge-grid"></div>
    </section>
    <section class="panel viz">
      <canvas id="space"></canvas>
      <div class="core"><div>SOCX<br>PI<br><span id="coreRoles">0/3</span></div></div>
      <div class="twin-status" id="twin-status">TWIN WAITING FOR FLOWS</div>
      <div class="thermal-strip"><span id="thermalLabel">PI TEMP --C | LOAD --</span><canvas id="thermalChart"></canvas></div>
      <div class="vizhud">
        <div class="vizstat">FW BLOCKS<b id="vizFw">0</b></div>
        <div class="vizstat">DNSBL<b id="vizDns">0</b></div>
        <div class="vizstat">IDS WATCH<b id="vizIds">0</b></div>
        <div class="vizstat">AUTO<b id="vizAuto">OBSERVE</b></div>
      </div>
    </section>
    <section class="panel">
      <h2>Recommended Next Step</h2>
      <div id="next" class="role-text">waiting for pfSense evidence</div>
      <div class="command">
        <div class="command-title"><span>COMMAND CENTER</span><span class="muted">READ-ONLY</span></div>
        <div class="command-row"><input id="command-input" value="status" aria-label="SOCX command"><button id="command-run">RUN</button></div>
        <div class="quick-row"><button id="why-warn">WHY WARN?</button><button id="kiosk-link">KIOSK</button></div>
        <div class="command-output" id="command-output">Awaiting operator command.</div>
        <div class="command-help">status | why warn | vpn | top talkers | thermal | models | preserve evidence | or ask a plain-English SOCX question</div>
      </div>
      <div class="review">
        <div class="review-title"><span>REVIEW QUEUE</span><span id="review-count">0</span></div>
        <div id="review-queue" class="review-item">No response drafts awaiting review.</div>
      </div>
      <h2 style="margin-top:18px">Model Quality</h2>
      <div id="modelQuality" class="quality-grid"></div>
      <h2 class="hide-kiosk" style="margin-top:18px">Model Health</h2>
      <div id="models" class="model-grid"></div>
      <h2 style="margin-top:18px">Route Health</h2>
      <div id="routes" class="route-grid"></div>
      <h2 class="hide-kiosk" style="margin-top:18px">Latest Reasons</h2>
      <div id="reasons" class="events"></div>
      <h2 style="margin-top:18px">Autopilot</h2>
      <div id="autopilot"></div>
      <h2 style="margin-top:18px">Experiment Lab</h2>
      <div class="lab">
        <div class="lab-title"><span>AI HAT TEST RANGE</span><span id="lab-progress">READY</span></div>
        <div class="lab-actions">
          <button data-experiment="thermal_watch">THERMAL</button>
          <button data-experiment="stress_llm">LLM PULSE</button>
          <button data-experiment="model_inventory">MODELS</button>
          <button data-experiment="role_benchmark">BENCH</button>
          <button data-experiment="explain_socx">EXPLAIN</button>
          <button data-experiment="compare_models">COMPARE</button>
        </div>
        <button class="stop" id="lab-stop" style="width:100%;margin-top:6px">STOP TEST</button>
        <div class="lab-status" id="lab-status">All experiments are bounded and read-only.</div>
        <div class="lab-history" id="lab-history"></div>
      </div>
    </section>
  </main>
  <footer class="feed">
    <section class="panel events"><h2>Live Events</h2><div id="events"></div></section>
    <section class="panel"><h2>Payload Summary</h2><div id="payload" class="summary-grid"></div><h2 style="margin-top:8px">Signal Timeline</h2><canvas id="historyTimeline" class="timeline"></canvas></section>
  </footer>
</div>
<script>
const $=id=>document.getElementById(id);
const colors={INFO:'#49fff4',WARN:'#ffe35b',ERROR:'#ff5e78',CRITICAL:'#ff5e78'};
let state={roles_online:'0/3',severity:'INFO',role_results:{}};
if(location.pathname.includes('/kiosk'))document.body.classList.add('kiosk');
function cls(sev){return sev==='INFO'||sev==='OK'?'ok':(sev==='WARN'?'warn':'bad')}
function fmt(n){n=Number(n||0);return n>=1000?(n/1000).toFixed(n>=10000?0:1)+'K':String(n)}
function ms(v){v=Number(v||0);return v>=1000?(v/1000).toFixed(1)+'s':v+'ms'}
function healthWord(x){return x?'online':'offline'}
function tempF(c){c=Number(c||0);return c?Math.round(c*9/5+32):0}
function shortStateTone(s){s=String(s||'').toLowerCase();return s.includes('online')||s.includes('good')?'good':s.includes('offline')||s.includes('timeout')?'bad':'warn'}
function cleanRoleText(s){
  s=String(s??'').replace(/\s+/g,' ').trim();
  if(!s)return 'waiting for role output';
  const start=s.indexOf('{'), end=s.lastIndexOf('}');
  if(start>=0&&end>start){try{const j=JSON.parse(s.slice(start,end+1));const bits=[];if(j.severity)bits.push('severity '+j.severity);if(j.confidence!==undefined)bits.push('confidence '+j.confidence);if(Array.isArray(j.reasons)&&j.reasons.length)bits.push(j.reasons.slice(0,2).join('; '));else if(j.reasons)bits.push(String(j.reasons));if(j.recommended_next_step)bits.push('next: '+j.recommended_next_step);if(bits.length)s=bits.join(' | ')}catch(_){}}
  const low=s.toLowerCase();
  if(s==='{}'||s==='[]'||low==='null'||low==='none')return 'Model route online, but no clear role finding returned.';
  if(/^[0-9.\s\\nrt,:;_-]{3,}$/.test(s))return 'Model route online, but output was token noise; use SOCX verdict.';
  if(/(?:n1,?){4,}/i.test(s)||(s.match(/\d+\.\d+/g)||[]).length>=4)return 'Model route online, but output was numeric token noise; use SOCX verdict.';
  if(low.includes('done_reason')&&(low.includes('created_at')||low.includes('total_duration')))return 'Model route online, but returned transport metadata instead of a SOC finding.';
  if((s.match(/->/g)||[]).length>=5)return 'Model route online, but output was flow-token noise; use SOCX verdict.';
  if(low.includes("i'm not sure")||low.includes('i am not sure')||low.includes('based on the information provided')||low.includes('as an ai'))return 'No clear model finding; use SOCX verdict, reasons, and latest evidence.';
  s=s.replace(/\(\s*[A-Z0-9]\s*\)(?:\s*\(\s*[A-Z0-9]\s*\)){3,}/g,'repeated token noise');
  return s.length>170?s.slice(0,169).replace(/[ ,;|]+$/,'')+'…':s;
}
function renderRoleTimeline(d){
  const rows=d.role_timeline||[];
  $('roleTimeline').innerHTML=rows.map(r=>`<div class="role-step"><b>${esc(r.role)}</b><em class="state ${shortStateTone(r.state)}">${esc(r.state)}</em><span title="${esc(r.purpose+' '+r.summary)}">${esc(r.backend)} · ${esc(r.model)} · ${ms(r.elapsed_ms||0)} · ${esc(r.summary)}</span></div>`).join('')||'<div class="role-step"><b>WAIT</b><em class="state warn">waiting</em><span>Waiting for SOCX role output.</span></div>';
}
function renderRoutes(d){
  const rt=d.runtime||{}, routes=rt.role_routes||{}, hailo=rt.hailo||{}, cpu=rt.cpu_ollama||{};
  const roleCards=['triage','evidence','action'].map(r=>{const x=routes[r]||{};const pref=x.preferred||{},fb=x.fallback||{};return `<div class="route-card"><b>${r}</b><span class="${x.available?'ok':'warn'}">${x.available?'Hailo ready':'CPU fallback ready'}</span><span title="${esc((pref.model||'')+' -> '+(fb.model||''))}">${esc(pref.model||'hailo')} → ${esc(fb.model||'ollama')}</span></div>`});
  roleCards.push(`<div class="route-card"><b>Hailo</b><span class="${hailo.ok?'ok':'warn'}">${hailo.ok?'online '+(hailo.model_count||0):'offline'}</span><span>${esc((hailo.models||[]).slice(0,2).join(', ')||hailo.error||'AI HAT route')}</span></div>`);
  roleCards.push(`<div class="route-card"><b>Ollama</b><span class="${cpu.ok?'ok':'bad'}">${cpu.ok?'online '+(cpu.model_count||0):'offline'}</span><span>${esc((cpu.models||[]).slice(0,2).join(', ')||cpu.error||'CPU fallback')}</span></div>`);
  $('routes').innerHTML=roleCards.join('');
}
function renderBridge(d){
  const b=d.wall_bridge||{}, obs=((d.network_payload||{}).observability)||{}, net=d.network||{};
  $('bridge').innerHTML=[
    `<div class="bridge-card"><b>Wall Line</b><strong>${esc(b.text||'Pi AI waiting for SOCX evidence')}</strong><span>${esc(b.roles||'0/3')} roles · age ${esc(b.age_seconds??0)}s</span></div>`,
    `<div class="bridge-card"><b>Pi 4 Metrics</b><strong>${esc(obs.label||obs.status||'metrics watch')}</strong><span>${esc(obs.summary||('network twin '+((net.nodes||[]).length)+' nodes / '+((net.links||[]).length)+' links'))}</span></div>`,
  ].join('');
}
function render(d){
  state=d; $('clock').textContent=new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'});
  $('svc').textContent=(d.status||'waiting').toUpperCase(); $('svc').className='pill '+(d.status==='ok'?'ok':d.status==='degraded'?'warn':'muted');
  $('roles').textContent='ROLES '+(d.roles_online||'0/3'); $('roles').className='pill '+((d.roles_online||'').startsWith('3/')?'ok':(d.roles_online||'').startsWith('0/')?'bad':'warn');
  $('coreRoles').textContent=d.roles_online||'0/3'; const rt=d.runtime||{}; const hh=rt.hailo||{}; $('hailo').textContent=hh.ok?'HAILO '+(hh.model_count||0):'HAILO OFF'; $('hailo').className='pill '+(hh.ok?'ok':'warn'); $('ollama').textContent=d.ollama&&d.ollama.ok?'OLLAMA '+d.ollama.model_count:'OLLAMA OFF'; $('ollama').className='pill '+(d.ollama&&d.ollama.ok?'ok':'bad');
  $('age').textContent='age '+(d.age_seconds??0)+'s'; $('severity').textContent=d.severity||'INFO'; $('severity').style.color=colors[d.severity]||colors.INFO;
  $('confidence').textContent=Math.round((d.confidence||0)*100)+'%'; $('confbar').style.width=Math.max(0,Math.min(100,(d.confidence||0)*100))+'%';
  $('elapsed').textContent=ms(d.elapsed_ms||0); $('evidence').textContent=(d.payload_summary?Object.keys(d.payload_summary).length:0)+' signal groups';
  $('next').textContent=d.recommended_next_step||'waiting';
  const roles=d.role_results||{}; $('rolebox').innerHTML=['triage','evidence','action'].map(r=>{const x=roles[r]||{};return `<div class="role ${x.ok?'ok':'fail'}"><div class="role-name">${r}</div><div><div class="role-text">${esc(cleanRoleText(x.summary||x.error||'waiting for role output'))}</div><div class="role-meta">${esc(x.model||'model?')} | ${esc(x.backend||'route?')} | ${x.ok?'online':'offline'} ${x.error?' | '+esc(x.error):''}</div></div></div>`}).join('');
  renderRoleTimeline(d); renderBridge(d);
  const models=d.models||{}; const ollama=d.ollama||{}; $('models').innerHTML=['triage','evidence','action'].map(r=>{const x=roles[r]||{};const ok=!!x.ok;return `<div class="model-card"><div class="model-role">${r}</div><div><div class="model-name">${esc(models[r]||x.model||'not set')}</div><div class="muted">${ok?'last role completed':'waiting or timed out'}</div></div><b class="${ok?'ok':'warn'}">${healthWord(ok)}</b></div>`}).join('')+`<div class="model-card"><div class="model-role">ollama</div><div><div class="model-name">${ollama.ok?fmt(ollama.model_count)+' local models':'not reachable'}</div><div class="muted">${esc((ollama.models||[]).slice(0,3).join(', ')||ollama.error||'local model server')}</div></div><b class="${ollama.ok?'ok':'bad'}">${ollama.ok?'online':'offline'}</b></div>`;
  renderRoutes(d);
  const q=d.model_quality||{}; $('modelQuality').innerHTML=[['GOOD',q.good||0,'ok'],['WEAK',q.weak||0,'warn'],['FALLBACK',q.fallback||0,'mag'],['OFFLINE',q.offline||0,'bad']].map(([k,v,c])=>`<div class="quality-card"><span>${k}</span><b class="${c}">${v}</b></div>`).join('');
  $('reasons').innerHTML=(d.reasons||[]).map(r=>`<div class="event">${esc(r)}</div>`).join('');
  $('events').innerHTML=(d.events||[]).slice(-8).reverse().map(e=>`<div class="event"><span class="${cls(e.severity)}">[${esc(e.kind)}]</span> ${esc(e.message)}</div>`).join('');
  const a=d.autonomy||{}; const ex=a.experiments||[]; $('autopilot').innerHTML=`<div class="autopilot"><div class="autopilot-title"><span>${esc((a.mode||'observe').toUpperCase())}</span><span>${a.score??0}/100</span></div><div class="plain">${esc(a.summary||'waiting for SOCX evidence')}</div><canvas id="trend" class="trend"></canvas><div class="experiment-grid">${ex.map(x=>`<div class="experiment-card"><b>${esc(x.name)}</b><span class="${x.status==='pass'||x.status==='stable'?'ok':x.status==='cooldown'?'bad':'warn'}">${esc(x.status)}</span> ${esc(x.detail)}</div>`).join('')}</div><div class="plain muted" style="margin-top:8px">${esc((a.recommendations||[]).join(' '))}</div></div>`; drawTrend(d.history||[]);
  const lab=d.experiment||{}; $('lab-progress').textContent=lab.active?((lab.progress||0)+'% '+String(lab.kind||'RUN').toUpperCase()):String(lab.status||'READY').toUpperCase(); $('lab-progress').className=lab.active?'warn':lab.status==='complete'?'ok':'muted'; $('lab-status').textContent=lab.result||'All experiments are bounded and read-only.'; $('lab-history').textContent=(lab.history||[]).slice(-2).map(x=>x.result).join(' | ');
  const p=d.payload_summary||{}; const cards=[['Firewall blocks',p.firewall_blocks_sampled,'blocked samples'],['DNSBL hits',p.dnsbl_lines_sampled,'DNS blocks'],['IDS watch',p.ids_watch_sampled,'routine alerts'],['High IDS',p.ids_high_sampled,'urgent alerts'],['IDS lines',p.ids_alert_lines_sampled,'sample size']]; $('payload').innerHTML=cards.map(([k,v,s])=>`<div class="summary-card"><span>${esc(k)}</span><b>${fmt(v)}</b><small class="muted">${esc(s)}</small></div>`).join('');
  $('vizFw').textContent=fmt(p.firewall_blocks_sampled); $('vizDns').textContent=fmt(p.dnsbl_lines_sampled); $('vizIds').textContent=fmt(p.ids_watch_sampled); $('vizAuto').textContent=(a.mode||'observe').toUpperCase(); const twin=d.network||{}; $('twin-status').textContent='TWIN '+(twin.links||[]).length+' LINKS | '+(twin.nodes||[]).length+' NODES | '+(d.network_history||[]).length+' SNAP'; $('twin-status').style.color=(twin.links||[]).length?'var(--green)':'var(--lcars)';
  const sys=d.system||{}; const tc=Number(sys.temp_c||0), load=Number((sys.load||{}).one||0); $('thermalLabel').textContent=`PI ${tc?tc.toFixed(1):'--'}C/${tc?tempF(tc):'--'}F | LOAD ${load.toFixed(2)}`; drawThermal(d.history||[], sys); drawSignalTimeline(d.history||[]);
  const drafts=d.drafts||[]; const pending=drafts.filter(x=>!x.reviewed); $('review-count').textContent=pending.length+' PENDING'; $('review-queue').innerHTML=pending.length?pending.slice(-4).reverse().map(x=>`<div class="review-item"><button data-review="${esc(x.id)}">ACKNOWLEDGE</button><b>${esc(String(x.type||'draft').toUpperCase())}</b> ${esc(x.target||'selected host')}<br><span class="muted">${esc(x.proposal||'draft response')} | no change applied</span></div>`).join(''):'No response drafts awaiting review.';
}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function drawTrend(hist){const cv=$('trend'); if(!cv)return; const g=cv.getContext('2d'),r=cv.getBoundingClientRect(),dpr=devicePixelRatio||1; cv.width=Math.max(1,r.width*dpr); cv.height=Math.max(1,r.height*dpr); g.clearRect(0,0,cv.width,cv.height); const pts=(hist||[]).slice(-18); g.strokeStyle='rgba(73,255,244,.25)'; g.beginPath(); g.moveTo(0,cv.height-1); g.lineTo(cv.width,cv.height-1); g.stroke(); if(!pts.length)return; g.strokeStyle='#ff56f4'; g.lineWidth=2*dpr; g.beginPath(); pts.forEach((p,i)=>{const x=pts.length===1?0:i*(cv.width/(pts.length-1)); const y=cv.height-(Math.min(100,p.score||0)/100)*cv.height; if(i===0)g.moveTo(x,y); else g.lineTo(x,y)}); g.stroke();}
function spark(canvas,series,color,maxValue){if(!canvas)return;const g=canvas.getContext('2d'),r=canvas.getBoundingClientRect(),dpr=devicePixelRatio||1;canvas.width=Math.max(1,r.width*dpr);canvas.height=Math.max(1,r.height*dpr);g.clearRect(0,0,canvas.width,canvas.height);g.strokeStyle='rgba(255,255,255,.14)';g.beginPath();g.moveTo(0,canvas.height-1);g.lineTo(canvas.width,canvas.height-1);g.stroke();if(!series.length)return;g.strokeStyle=color;g.lineWidth=2*dpr;g.beginPath();series.forEach((v,i)=>{const x=series.length===1?0:i*(canvas.width/(series.length-1));const y=canvas.height-(Math.max(0,Math.min(maxValue,v))/maxValue)*canvas.height;if(i===0)g.moveTo(x,y);else g.lineTo(x,y)});g.stroke();}
function drawThermal(hist,sys){const vals=(hist||[]).slice(-24).map(x=>Number(((x.system||{}).temp_c)||0)).filter(Boolean);const now=Number(sys.temp_c||0);if(now)vals.push(now);spark($('thermalChart'),vals,'#ff9f43',85)}
function drawSignalTimeline(hist){const cv=$('historyTimeline');if(!cv)return;const g=cv.getContext('2d'),r=cv.getBoundingClientRect(),dpr=devicePixelRatio||1;cv.width=Math.max(1,r.width*dpr);cv.height=Math.max(1,r.height*dpr);g.clearRect(0,0,cv.width,cv.height);const pts=(hist||[]).slice(-24);const rows=[['FW','#ff5e78','firewall_blocks_sampled',2200],['DNS','#c76dff','dnsbl_lines_sampled',2200],['IDS','#ff9f43','ids_watch_sampled',500],['SCORE','#66ff7c','score',100]];rows.forEach(([label,color,key,max],ri)=>{const y0=(ri+.5)*(cv.height/rows.length);g.fillStyle='rgba(216,251,255,.62)';g.font=`${8*dpr}px ui-monospace,Consolas,monospace`;g.fillText(label,4*dpr,y0+3*dpr);g.strokeStyle='rgba(255,255,255,.08)';g.beginPath();g.moveTo(45*dpr,y0);g.lineTo(cv.width,y0);g.stroke();if(!pts.length)return;g.strokeStyle=color;g.lineWidth=1.8*dpr;g.beginPath();pts.forEach((p,i)=>{const summary=p.payload_summary||{};const raw=key==='score'?p.score:summary[key];const x=45*dpr+i*((cv.width-50*dpr)/Math.max(1,pts.length-1));const v=Math.max(0,Math.min(Number(max),Number(raw||0)))/Number(max);const y=y0-(v*(cv.height/rows.length*.42));if(i===0)g.moveTo(x,y);else g.lineTo(x,y)});g.stroke()})}
async function poll(){try{render(await (await fetch('/api/socx/latest',{cache:'no-store'})).json())}catch(e){}}
if(window.EventSource){const es=new EventSource('/api/socx/stream');es.onmessage=e=>{try{render(JSON.parse(e.data))}catch(_){}};es.onerror=poll}else setInterval(poll,1000); poll();
document.querySelectorAll('[data-experiment]').forEach(button=>button.addEventListener('click',async()=>{try{await fetch('/api/socx/experiment',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({kind:button.dataset.experiment,action:'start',duration:30})})}catch(_){}})); $('lab-stop').addEventListener('click',async()=>{try{await fetch('/api/socx/experiment',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'stop'})})}catch(_){}});
async function runCommand(){const input=$('command-input'),output=$('command-output'); const command=input.value.trim()||'help'; const known=/^(help|status|why warn|vpn|top talkers|thermal|models|preserve evidence|network|show network|explain host|trace flow|show anomalies|compare normal|draft block|draft quarantine)/i.test(command); output.textContent=known?'Querying local SOCX telemetry...':'Thinking with SOCX Pi LLM...'; try{const url=known?'/api/socx/command':'/api/socx/chat';const body=known?{command}:{question:command,intent:/\b(configure|change|block|allow|quarantine|enable|disable|fix)\b/i.test(command)?'draft':'explain',context:state};const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); const d=await r.json(); output.textContent=known?((d.title||'SOCX')+'\n'+(d.lines||[]).join('\n')):((d.mode||'ANSWER')+'\n'+(d.answer||'No answer returned.')+'\n\nSteps: '+((d.phases||[]).join(' -> ')||'complete'))}catch(e){output.textContent='Command service unavailable'}} $('command-run').addEventListener('click',runCommand); $('command-input').addEventListener('keydown',e=>{if(e.key==='Enter')runCommand()});
$('why-warn').addEventListener('click',()=>{$('command-input').value='why warn';runCommand()}); $('kiosk-link').addEventListener('click',()=>{location.href='/kiosk'});
document.addEventListener('click',async e=>{const button=e.target.closest('[data-review]');if(!button)return;button.disabled=true;try{await fetch('/api/socx/draft/'+encodeURIComponent(button.dataset.review)+'/review',{method:'POST'})}catch(_){button.disabled=false}});
const c=$('space'),ctx=c.getContext('2d');let t=0;
function resize(){c.width=c.clientWidth*devicePixelRatio;c.height=c.clientHeight*devicePixelRatio}addEventListener('resize',resize);resize();
function line(a,b,color,width=1,alpha=1){ctx.strokeStyle=color.replace(')',`,`+alpha+')').replace('rgb','rgba');ctx.lineWidth=width*devicePixelRatio;ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.stroke()}
function drawNode(x,y,label,color,size=8,sub=''){ctx.save();ctx.shadowColor=color;ctx.shadowBlur=14*devicePixelRatio;ctx.fillStyle=color;ctx.beginPath();ctx.arc(x,y,size*devicePixelRatio,0,Math.PI*2);ctx.fill();ctx.shadowBlur=0;ctx.fillStyle='#d8fbff';ctx.font=`${10*devicePixelRatio}px ui-monospace,Consolas,monospace`;ctx.fillText(String(label).slice(0,18),x+12*devicePixelRatio,y+4*devicePixelRatio);if(sub){ctx.fillStyle='rgba(216,251,255,.62)';ctx.font=`${8*devicePixelRatio}px ui-monospace,Consolas,monospace`;ctx.fillText(String(sub).slice(0,22),x+12*devicePixelRatio,y+16*devicePixelRatio)}ctx.restore()}
function packet(a,b,phase,color,label){const p=(phase%1);const x=a.x+(b.x-a.x)*p,y=a.y+(b.y-a.y)*p;ctx.save();ctx.shadowColor=color;ctx.shadowBlur=10*devicePixelRatio;ctx.fillStyle=color;ctx.beginPath();ctx.arc(x,y,3.2*devicePixelRatio,0,Math.PI*2);ctx.fill();if(label&&p>.48&&p<.55){ctx.shadowBlur=0;ctx.fillStyle='#e8fbff';ctx.font=`${8*devicePixelRatio}px ui-monospace,Consolas,monospace`;ctx.fillText(label,x+7*devicePixelRatio,y-5*devicePixelRatio)}ctx.restore()}
function draw(){t+=0.0016;ctx.clearRect(0,0,c.width,c.height);const w=c.width,h=c.height,cx=w/2,cy=h/2;const dpr=devicePixelRatio||1;const roles=['triage','evidence','action'];const online=(state.roles_online||'0/3').split('/')[0]*1;const p=state.payload_summary||{};const auto=state.autonomy||{};const fw=Math.min(1,(p.firewall_blocks_sampled||0)/2200),dns=Math.min(1,(p.dnsbl_lines_sampled||0)/2200),ids=Math.min(1,(p.ids_watch_sampled||0)/500),ascore=Math.min(1,(auto.score||0)/100);const pulse=.5+.5*Math.sin(t*3.2);const watch=(auto.mode||'observe').toUpperCase();
 ctx.fillStyle='rgba(73,255,244,.025)';for(let gx=0;gx<w;gx+=42*dpr){ctx.fillRect(gx,0,1,h)}for(let gy=0;gy<h;gy+=42*dpr){ctx.fillRect(0,gy,w,1)}
 ctx.save();ctx.translate(cx,cy);for(let ring=0;ring<4;ring++){const rx=(72+ring*42+(pulse*5))*dpr,ry=rx*.52;ctx.strokeStyle=`rgba(73,255,244,${.12+ring*.035})`;ctx.lineWidth=(1.2+ring*.2)*dpr;ctx.beginPath();ctx.ellipse(0,0,rx,ry,0,0,Math.PI*2);ctx.stroke()}ctx.restore();
 const core={x:cx,y:cy};const nodes=[{id:'pf',label:'pfSense',x:cx-w*.31,y:cy-h*.13,color:'#ffe35b',sub:'firewall'},{id:'wan',label:'WAN',x:cx+w*.33,y:cy-h*.18,color:'#ff5e78',sub:fmt(p.firewall_blocks_sampled||0)+' blocks'},{id:'lan',label:'LAN',x:cx+w*.34,y:cy+h*.05,color:'#49fff4',sub:'35 nodes'},{id:'pi',label:'Pi 5 AI',x:cx+w*.18,y:cy-h*.34,color:'#ff56f4',sub:(state.roles_online||'0/3')+' roles'},{id:'dns',label:'DNSBL',x:cx-w*.27,y:cy+h*.27,color:'#c76dff',sub:fmt(p.dnsbl_lines_sampled||0)+' hits'},{id:'ids',label:'IDS',x:cx+w*.2,y:cy+h*.31,color:'#ff9f43',sub:fmt(p.ids_watch_sampled||0)+' watch'}];const byId={};nodes.forEach(n=>byId[n.id]=n);
 [['pf','wan',fw,'#ff5e78','FW'],['pf','lan',.45,'#49fff4','LAN'],['pf','pi',ascore,'#ff56f4','AI'],['pf','dns',dns,'#c76dff','DNS'],['pf','ids',ids,'#ff9f43','IDS'],['pi','ids',ids*.7,'#66ff7c','triage']].forEach(([a,b,intensity,color,label],i)=>{line(byId[a],byId[b],color,1.1+Number(intensity)*2,.25+Number(intensity)*.45);packet(byId[a],byId[b],t*(.08+Number(intensity)*.08)+i*.17,color,label)});
 nodes.forEach(n=>drawNode(n.x,n.y,n.label,n.color,8+(n.id==='pi'?online:0),n.sub));
 roles.forEach((r,i)=>{const y=cy-h*.05+i*h*.1;const x=core.x-w*.09;const role={x,y};const ok=i<online;const color=ok?'#66ff7c':'#ff5e78';line(role,core,color,1.4,ok?.7:.28);drawNode(x,y,r.toUpperCase(),color,10,ok?'online':'waiting')});
 ctx.save();ctx.translate(core.x,core.y);ctx.strokeStyle=watch==='WATCH'||watch==='INVESTIGATE'?'rgba(255,227,91,.7)':'rgba(102,255,124,.55)';ctx.lineWidth=(2+pulse*1.4)*dpr;ctx.beginPath();ctx.arc(0,0,(55+pulse*5)*dpr,0,Math.PI*2);ctx.stroke();ctx.fillStyle='rgba(73,255,244,.08)';ctx.beginPath();ctx.arc(0,0,42*dpr,0,Math.PI*2);ctx.fill();ctx.restore();
 const bars=[['FW',fw,'#ff5e78'],['DNS',dns,'#c76dff'],['IDS',ids,'#ff9f43'],['AUTO',ascore,'#66ff7c']];bars.forEach(([label,val,color],i)=>{const x=16*dpr+i*w*.18,y=h-64*dpr,bw=w*.15,bh=7*dpr;ctx.fillStyle='rgba(255,255,255,.08)';ctx.fillRect(x,y,bw,bh);ctx.fillStyle=color;ctx.fillRect(x,y,bw*val,bh);ctx.fillStyle='#a9c3c8';ctx.font=`${9*dpr}px ui-monospace,Consolas,monospace`;ctx.fillText(label,x,y-6*dpr)});
 requestAnimationFrame(draw)}draw();
</script>
</body></html>"""
