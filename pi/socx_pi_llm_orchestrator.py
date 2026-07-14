#!/usr/bin/env python3
"""Raspberry Pi SOCX 3-LLM orchestration endpoint.

Run this on the Raspberry Pi 5. pfSense posts compact SOCX JSON here; the Pi
fans it out to three small model roles and returns a tiny wall-safe verdict.
"""

from __future__ import annotations

import asyncio
import json
import os
import time
import urllib.error
import urllib.request
from typing import Any

from fastapi import FastAPI, Request

APP_NAME = "SOCX Pi 3-LLM Orchestrator"
DEFAULT_ROLES = {
    "triage": "http://127.0.0.1:11434/api/generate",
    "evidence": "http://127.0.0.1:11434/api/generate",
    "action": "http://127.0.0.1:11434/api/generate",
}
DEFAULT_MODELS = {
    "triage": os.getenv("SOCX_PI_LLM_TRIAGE_MODEL", "llama3.2:3b"),
    "evidence": os.getenv("SOCX_PI_LLM_EVIDENCE_MODEL", "qwen2.5:3b"),
    "action": os.getenv("SOCX_PI_LLM_ACTION_MODEL", "phi3:mini"),
}

app = FastAPI(title=APP_NAME)


def role_url(role: str) -> str:
    return os.getenv(f"SOCX_PI_LLM_{role.upper()}_URL", DEFAULT_ROLES[role])


def compact_payload(payload: dict[str, Any]) -> str:
    summary = payload.get("summary", {})
    return json.dumps(
        {
            "generated_at": payload.get("generated_at"),
            "summary": summary,
            "vpn_gateway_status": str(payload.get("vpn_gateway_status", ""))[:700],
            "speedtest_cache": str(payload.get("speedtest_cache", ""))[:500],
            "unknown_services": str(payload.get("unknown_services", ""))[:500],
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
        "Return one short JSON object with severity, confidence, reasons, recommended_next_step.\n"
        f"SOCX compact evidence: {compact_payload(payload)}"
    )


def call_ollama_generate(url: str, model: str, prompt: str, timeout: float) -> dict[str, Any]:
    body = json.dumps({"model": model, "prompt": prompt, "stream": False}).encode()
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8", "replace")
    data = json.loads(raw)
    text = str(data.get("response") or data.get("text") or raw)
    return {"ok": True, "model": model, "text": text[:1200]}


async def run_role(role: str, payload: dict[str, Any]) -> dict[str, Any]:
    url = role_url(role)
    model = DEFAULT_MODELS[role]
    timeout = float(os.getenv("SOCX_PI_LLM_TIMEOUT", "18"))
    prompt = build_prompt(role, payload)
    try:
        return await asyncio.to_thread(call_ollama_generate, url, model, prompt, timeout)
    except (OSError, TimeoutError, urllib.error.URLError, json.JSONDecodeError) as exc:
        return {"ok": False, "model": model, "error": str(exc)[:220]}


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
    return {
        "status": "ok",
        "service": APP_NAME,
        "roles": list(DEFAULT_ROLES),
        "ollama_hint": "curl http://127.0.0.1:11434/api/tags",
    }


@app.post("/api/socx/triage")
async def triage(request: Request) -> dict[str, Any]:
    payload = await request.json()
    started = time.time()
    results_list = await asyncio.gather(*(run_role(role, payload) for role in DEFAULT_ROLES))
    role_results = dict(zip(DEFAULT_ROLES, results_list))
    verdict = heuristic_verdict(payload, role_results)
    verdict.update(
        {
            "source": "raspberry-pi-5-ai-hat-3llm",
            "elapsed_ms": int((time.time() - started) * 1000),
            "models": {role: result.get("model", "") for role, result in role_results.items()},
        }
    )
    return verdict
