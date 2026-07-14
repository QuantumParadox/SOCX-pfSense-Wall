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
    "evidence": os.getenv("SOCX_PI_LLM_EVIDENCE_MODEL", "llama3.2:3b"),
    "action": os.getenv("SOCX_PI_LLM_ACTION_MODEL", "llama3.2:3b"),
}

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
}
EVENTS: list[dict[str, Any]] = []
MAX_EVENTS = 80
HISTORY: list[dict[str, Any]] = []
MAX_HISTORY = 48
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


def role_url(role: str) -> str:
    return os.getenv(f"SOCX_PI_LLM_{role.upper()}_URL", DEFAULT_ROLES[role])


def now_iso() -> str:
    return datetime.utcnow().replace(microsecond=0).isoformat() + "Z"


def event(kind: str, message: str, severity: str = "INFO") -> None:
    EVENTS.append({"ts": time.time(), "iso": now_iso(), "kind": kind, "severity": severity, "message": message[:300]})
    del EVENTS[:-MAX_EVENTS]


def summarize_role_text(text: str) -> str:
    text = " ".join(str(text or "").replace("\n", " ").split())
    if not text:
        return "no visible role summary returned"
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
            return " | ".join(bits)[:420] if bits else text[:420]
    except json.JSONDecodeError:
        pass
    return text[:420]


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
    ollama = await check_ollama_tags() if kind in {"stress_llm", "model_inventory"} else {}
    temps = [float(s.get("temp_c")) for s in samples if s.get("temp_c") is not None]
    loads = [float((s.get("load") or {}).get("one") or 0) for s in samples]
    result = (
        f"{label} complete | {len(samples)} samples | "
        f"temp {min(temps):.1f}-{max(temps):.1f}C | load peak {max(loads, default=0):.2f}"
    )
    if kind == "model_inventory":
        result += f" | Ollama {'online' if ollama.get('ok') else 'offline'} ({ollama.get('model_count', 0)} models)"
    elif kind == "stress_llm":
        result += " | use SOCX triage for the full three-role benchmark"
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
        }
    )
    del HISTORY[:-MAX_HISTORY]
    event("AUTO", state["summary"], "WARN" if mode in {"watch", "investigate", "cooldown"} else "INFO")
    return state


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
        "Return only one compact JSON object under 80 words with severity, confidence, reasons, recommended_next_step. "
        "Keep reasons to two short strings. Do not include markdown.\n"
        f"SOCX compact evidence: {compact_payload(payload)}"
    )


def call_ollama_generate(url: str, model: str, prompt: str, timeout: float) -> dict[str, Any]:
    body_data = {
        "model": model,
        "prompt": prompt,
        "stream": False,
        "format": "json",
        "options": {
            "temperature": float(os.getenv("SOCX_PI_LLM_TEMPERATURE", "0.2")),
            "num_predict": int(os.getenv("SOCX_PI_LLM_NUM_PREDICT", "96")),
            "num_ctx": int(os.getenv("SOCX_PI_LLM_NUM_CTX", "2048")),
        },
    }
    body = json.dumps(body_data).encode()
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8", "replace")
    data = json.loads(raw)
    text = str(data.get("response") or data.get("text") or raw)
    return {"ok": True, "model": model, "text": text[:1200]}


async def run_role(role: str, payload: dict[str, Any]) -> dict[str, Any]:
    url = role_url(role)
    model = DEFAULT_MODELS[role]
    timeout = float(os.getenv("SOCX_PI_LLM_TIMEOUT", "75"))
    prompt = build_prompt(role, payload)
    event("ROLE", f"{role} thinking with {model}", "INFO")
    try:
        result = await asyncio.to_thread(call_ollama_generate, url, model, prompt, timeout)
        result.update({"role": role, "summary": summarize_role_text(result.get("text", "")), "url": url})
        event("ROLE", f"{role} complete with {model}", "INFO")
        return result
    except (OSError, TimeoutError, urllib.error.URLError, json.JSONDecodeError) as exc:
        error = str(exc)[:220]
        event("ROLE", f"{role} failed with {model}: {error}", "WARN")
        return {"ok": False, "role": role, "model": model, "url": url, "summary": "offline or timed out", "error": error}


async def run_roles(payload: dict[str, Any]) -> dict[str, dict[str, Any]]:
    concurrency = max(1, int(os.getenv("SOCX_PI_LLM_CONCURRENCY", "1")))
    if concurrency <= 1:
        results: dict[str, dict[str, Any]] = {}
        for role in DEFAULT_ROLES:
            results[role] = await run_role(role, payload)
        return results
    semaphore = asyncio.Semaphore(concurrency)

    async def guarded(role: str) -> tuple[str, dict[str, Any]]:
        async with semaphore:
            return role, await run_role(role, payload)

    result_pairs = await asyncio.gather(*(guarded(role) for role in DEFAULT_ROLES))
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
    ollama = await check_ollama_tags()
    return {
        "status": "ok",
        "service": APP_NAME,
        "roles": list(DEFAULT_ROLES),
        "latest_status": LATEST_ANALYSIS.get("status"),
        "roles_online": LATEST_ANALYSIS.get("roles_online"),
        "updated_iso": LATEST_ANALYSIS.get("updated_iso"),
        "ollama": ollama,
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


@app.get("/api/socx/latest")
async def latest() -> JSONResponse:
    data = dict(LATEST_ANALYSIS)
    data["events"] = EVENTS[-30:]
    data["age_seconds"] = int(time.time() - float(data["updated"] or time.time()))
    data["ollama"] = await check_ollama_tags()
    data["system"] = system_metrics()
    data["autonomy"] = AUTONOMY_STATE
    data["experiment"] = EXPERIMENT_STATE
    data["history"] = HISTORY[-18:]
    return JSONResponse(data)


@app.get("/api/socx/autonomy")
async def autonomy() -> JSONResponse:
    return JSONResponse({"autonomy": AUTONOMY_STATE, "history": HISTORY[-24:], "system": system_metrics(), "experiment": EXPERIMENT_STATE})


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
    if kind not in {"thermal_watch", "stress_llm", "model_inventory"}:
        return JSONResponse({"error": "unsupported experiment"}, status_code=400)
    if EXPERIMENT_TASK and not EXPERIMENT_TASK.done():
        return JSONResponse({"error": "an experiment is already running", "experiment": EXPERIMENT_STATE}, status_code=409)
    duration = max(5, min(120, int(payload.get("duration", 30))))
    EXPERIMENT_TASK = asyncio.create_task(run_experiment(kind, duration))
    return JSONResponse({"accepted": True, "experiment": EXPERIMENT_STATE})


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
            data["history"] = HISTORY[-18:]
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
            "status": "ok" if verdict.get("roles_online") != "0/3" else "degraded",
            "updated": updated,
            "updated_iso": now_iso(),
            "system": system_metrics(),
        }
    )
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
.shell{height:100vh;display:grid;grid-template-rows:54px 1fr 146px;gap:10px;padding:12px}
.top{display:grid;grid-template-columns:1fr auto;align-items:center;border:1px solid #1cfff355;background:linear-gradient(90deg,#071215,#0b1218);box-shadow:0 0 22px #1cfff322;padding:8px 12px}
.brand{font-weight:800;letter-spacing:.08em;color:var(--cyan);font-size:18px}.brand:before{content:'◖ ';color:var(--lcars)}.status{display:flex;gap:14px;align-items:center;font-weight:800}.pill{padding:3px 8px;border:1px solid #ffffff22;background:#ffffff08}.ok{color:var(--green)}.warn{color:var(--yellow)}.bad{color:var(--red)}.mag{color:var(--mag)}.muted{color:var(--muted)}
.main{display:grid;grid-template-columns:minmax(360px,1.1fr) minmax(420px,1.4fr) minmax(360px,1.1fr);gap:10px;min-height:0}
.panel{border:1px solid #1cfff355;background:linear-gradient(180deg,#071116dd,#05090ddd);box-shadow:inset 0 0 22px #1cfff310,0 0 18px #1cfff314;min-height:0;padding:12px;overflow:auto}
h2{margin:0 0 8px;color:var(--cyan);font-size:15px;letter-spacing:.08em}.metric{display:grid;grid-template-columns:140px 1fr;gap:8px;margin:6px 0;color:var(--muted)}.metric b{color:var(--ink)}
.role{display:grid;grid-template-columns:88px 1fr;gap:10px;border-top:1px solid #ffffff17;padding:10px 0}.role:first-of-type{border-top:0}.role-name{font-weight:900;color:var(--blue);text-transform:uppercase}.role.ok .role-name{color:var(--green)}.role.fail .role-name{color:var(--red)}.role-text{font-size:14px;line-height:1.32;white-space:normal}.role-meta{font-size:12px;color:var(--muted);margin-top:4px}
.viz{position:relative;height:100%;min-height:360px;overflow:hidden}.viz canvas{position:absolute;inset:0;width:100%;height:100%}.core{position:absolute;left:50%;top:50%;width:132px;height:132px;margin:-66px;border:2px solid var(--cyan);border-radius:50%;display:grid;place-items:center;text-align:center;font-weight:900;color:var(--cyan);box-shadow:0 0 36px #49fff466, inset 0 0 24px #49fff41e;animation:pulse 2.2s infinite}
.vizhud{position:absolute;left:12px;right:12px;bottom:12px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;pointer-events:none}.vizstat{border:1px solid #ffffff22;background:#02080caa;padding:7px 8px;font:700 12px ui-monospace,Consolas,monospace;color:var(--muted)}.vizstat b{display:block;color:var(--ink);font-size:16px;margin-top:2px}
@keyframes pulse{50%{transform:scale(1.045);box-shadow:0 0 54px #49fff488,inset 0 0 34px #49fff433}}
.feed{display:grid;grid-template-columns:1fr 1fr;gap:10px;min-height:0}.events{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:14px;line-height:1.45;overflow:hidden}.event{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.json{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px;color:#d8fbff;overflow:hidden;white-space:pre-wrap}
.bar{height:9px;background:#ffffff16;margin-top:6px;overflow:hidden}.bar span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--lcars),var(--cyan),var(--green))}
.lab{border:1px solid #ff9f4355;background:#1b1018;padding:9px 10px;margin-top:10px}.lab-title{display:flex;justify-content:space-between;color:var(--lcars);font-weight:900;letter-spacing:.06em}.lab-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:8px}.lab button{border:1px solid #49fff466;background:#071c21;color:var(--cyan);padding:7px 5px;font:700 11px ui-monospace,Consolas,monospace;cursor:pointer}.lab button:hover{background:#49fff422;color:#fff}.lab button.stop{color:var(--red);border-color:#ff5e7866}.lab-status{margin-top:8px;font:12px ui-monospace,Consolas,monospace;color:var(--ink);white-space:normal}.lab-history{margin-top:6px;color:var(--muted);font:11px ui-monospace,Consolas,monospace}
.model-grid,.summary-grid,.experiment-grid{display:grid;gap:8px}.model-card,.summary-card,.experiment-card{border:1px solid #ffffff1f;background:#ffffff08;padding:8px 10px}.model-card{display:grid;grid-template-columns:92px 1fr auto;gap:8px;align-items:center}.model-role{font-weight:900;text-transform:uppercase;color:var(--cyan)}.model-name{font-weight:800}.summary-grid{grid-template-columns:1fr 1fr}.summary-card span{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em}.summary-card b{display:block;color:var(--ink);font-size:20px;margin-top:2px}.plain{font-size:14px;line-height:1.45;color:var(--ink)}.autopilot{border:1px solid #ff56f455;background:#210a2438;padding:9px 10px;margin-top:10px}.autopilot-title{display:flex;justify-content:space-between;gap:10px;font-weight:900;color:var(--mag);letter-spacing:.06em}.experiment-card{font-size:13px}.experiment-card b{display:block;color:var(--ink)}.trend{height:34px;width:100%;margin-top:8px}
@media(max-width:1100px){.main{grid-template-columns:1fr}.shell{overflow:auto;height:auto}.viz{height:360px}.feed{grid-template-columns:1fr}body{overflow:auto}}
</style>
</head>
<body>
<div class="shell">
  <header class="top">
    <div class="brand">SOCX PI 3-LLM DASHBOARD</div>
    <div class="status">
      <span id="clock"></span><span id="svc" class="pill muted">WAIT</span><span id="roles" class="pill warn">0/3</span><span id="ollama" class="pill bad">OLLAMA</span><span id="age" class="pill muted">age ?</span>
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
    </section>
    <section class="panel viz">
      <canvas id="space"></canvas>
      <div class="core"><div>SOCX<br>PI<br><span id="coreRoles">0/3</span></div></div>
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
      <h2 style="margin-top:18px">Model Health</h2>
      <div id="models" class="model-grid"></div>
      <h2 style="margin-top:18px">Latest Reasons</h2>
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
        </div>
        <button class="stop" id="lab-stop" style="width:100%;margin-top:6px">STOP TEST</button>
        <div class="lab-status" id="lab-status">All experiments are bounded and read-only.</div>
        <div class="lab-history" id="lab-history"></div>
      </div>
    </section>
  </main>
  <footer class="feed">
    <section class="panel events"><h2>Live Events</h2><div id="events"></div></section>
    <section class="panel"><h2>Payload Summary</h2><div id="payload" class="summary-grid"></div></section>
  </footer>
</div>
<script>
const $=id=>document.getElementById(id);
const colors={INFO:'#49fff4',WARN:'#ffe35b',ERROR:'#ff5e78',CRITICAL:'#ff5e78'};
let state={roles_online:'0/3',severity:'INFO',role_results:{}};
function cls(sev){return sev==='INFO'||sev==='OK'?'ok':(sev==='WARN'?'warn':'bad')}
function fmt(n){n=Number(n||0);return n>=1000?(n/1000).toFixed(n>=10000?0:1)+'K':String(n)}
function ms(v){v=Number(v||0);return v>=1000?(v/1000).toFixed(1)+'s':v+'ms'}
function healthWord(x){return x?'online':'offline'}
function render(d){
  state=d; $('clock').textContent=new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'});
  $('svc').textContent=(d.status||'waiting').toUpperCase(); $('svc').className='pill '+(d.status==='ok'?'ok':d.status==='degraded'?'warn':'muted');
  $('roles').textContent='ROLES '+(d.roles_online||'0/3'); $('roles').className='pill '+((d.roles_online||'').startsWith('3/')?'ok':(d.roles_online||'').startsWith('0/')?'bad':'warn');
  $('coreRoles').textContent=d.roles_online||'0/3'; $('ollama').textContent=d.ollama&&d.ollama.ok?'OLLAMA '+d.ollama.model_count:'OLLAMA OFF'; $('ollama').className='pill '+(d.ollama&&d.ollama.ok?'ok':'bad');
  $('age').textContent='age '+(d.age_seconds??0)+'s'; $('severity').textContent=d.severity||'INFO'; $('severity').style.color=colors[d.severity]||colors.INFO;
  $('confidence').textContent=Math.round((d.confidence||0)*100)+'%'; $('confbar').style.width=Math.max(0,Math.min(100,(d.confidence||0)*100))+'%';
  $('elapsed').textContent=ms(d.elapsed_ms||0); $('evidence').textContent=(d.payload_summary?Object.keys(d.payload_summary).length:0)+' signal groups';
  $('next').textContent=d.recommended_next_step||'waiting';
  const roles=d.role_results||{}; $('rolebox').innerHTML=['triage','evidence','action'].map(r=>{const x=roles[r]||{};return `<div class="role ${x.ok?'ok':'fail'}"><div class="role-name">${r}</div><div><div class="role-text">${esc(x.summary||x.error||'waiting for role output')}</div><div class="role-meta">${esc(x.model||'model?')} ${x.ok?'online':'offline'} ${x.error?' | '+esc(x.error):''}</div></div></div>`}).join('');
  const models=d.models||{}; const ollama=d.ollama||{}; $('models').innerHTML=['triage','evidence','action'].map(r=>{const x=roles[r]||{};const ok=!!x.ok;return `<div class="model-card"><div class="model-role">${r}</div><div><div class="model-name">${esc(models[r]||x.model||'not set')}</div><div class="muted">${ok?'last role completed':'waiting or timed out'}</div></div><b class="${ok?'ok':'warn'}">${healthWord(ok)}</b></div>`}).join('')+`<div class="model-card"><div class="model-role">ollama</div><div><div class="model-name">${ollama.ok?fmt(ollama.model_count)+' local models':'not reachable'}</div><div class="muted">${esc((ollama.models||[]).slice(0,3).join(', ')||ollama.error||'local model server')}</div></div><b class="${ollama.ok?'ok':'bad'}">${ollama.ok?'online':'offline'}</b></div>`;
  $('reasons').innerHTML=(d.reasons||[]).map(r=>`<div class="event">${esc(r)}</div>`).join('');
  $('events').innerHTML=(d.events||[]).slice(-8).reverse().map(e=>`<div class="event"><span class="${cls(e.severity)}">[${esc(e.kind)}]</span> ${esc(e.message)}</div>`).join('');
  const a=d.autonomy||{}; const ex=a.experiments||[]; $('autopilot').innerHTML=`<div class="autopilot"><div class="autopilot-title"><span>${esc((a.mode||'observe').toUpperCase())}</span><span>${a.score??0}/100</span></div><div class="plain">${esc(a.summary||'waiting for SOCX evidence')}</div><canvas id="trend" class="trend"></canvas><div class="experiment-grid">${ex.map(x=>`<div class="experiment-card"><b>${esc(x.name)}</b><span class="${x.status==='pass'||x.status==='stable'?'ok':x.status==='cooldown'?'bad':'warn'}">${esc(x.status)}</span> ${esc(x.detail)}</div>`).join('')}</div><div class="plain muted" style="margin-top:8px">${esc((a.recommendations||[]).join(' '))}</div></div>`; drawTrend(d.history||[]);
  const lab=d.experiment||{}; $('lab-progress').textContent=lab.active?((lab.progress||0)+'% '+String(lab.kind||'RUN').toUpperCase()):String(lab.status||'READY').toUpperCase(); $('lab-progress').className=lab.active?'warn':lab.status==='complete'?'ok':'muted'; $('lab-status').textContent=lab.result||'All experiments are bounded and read-only.'; $('lab-history').textContent=(lab.history||[]).slice(-2).map(x=>x.result).join(' | ');
  const p=d.payload_summary||{}; const cards=[['Firewall blocks',p.firewall_blocks_sampled,'blocked samples'],['DNSBL hits',p.dnsbl_lines_sampled,'DNS blocks'],['IDS watch',p.ids_watch_sampled,'routine alerts'],['High IDS',p.ids_high_sampled,'urgent alerts'],['IDS lines',p.ids_alert_lines_sampled,'sample size']]; $('payload').innerHTML=cards.map(([k,v,s])=>`<div class="summary-card"><span>${esc(k)}</span><b>${fmt(v)}</b><small class="muted">${esc(s)}</small></div>`).join('');
  $('vizFw').textContent=fmt(p.firewall_blocks_sampled); $('vizDns').textContent=fmt(p.dnsbl_lines_sampled); $('vizIds').textContent=fmt(p.ids_watch_sampled); $('vizAuto').textContent=(a.mode||'observe').toUpperCase();
}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function drawTrend(hist){const cv=$('trend'); if(!cv)return; const g=cv.getContext('2d'),r=cv.getBoundingClientRect(),dpr=devicePixelRatio||1; cv.width=Math.max(1,r.width*dpr); cv.height=Math.max(1,r.height*dpr); g.clearRect(0,0,cv.width,cv.height); const pts=(hist||[]).slice(-18); g.strokeStyle='rgba(73,255,244,.25)'; g.beginPath(); g.moveTo(0,cv.height-1); g.lineTo(cv.width,cv.height-1); g.stroke(); if(!pts.length)return; g.strokeStyle='#ff56f4'; g.lineWidth=2*dpr; g.beginPath(); pts.forEach((p,i)=>{const x=pts.length===1?0:i*(cv.width/(pts.length-1)); const y=cv.height-(Math.min(100,p.score||0)/100)*cv.height; if(i===0)g.moveTo(x,y); else g.lineTo(x,y)}); g.stroke();}
async function poll(){try{render(await (await fetch('/api/socx/latest',{cache:'no-store'})).json())}catch(e){}}
if(window.EventSource){const es=new EventSource('/api/socx/stream');es.onmessage=e=>{try{render(JSON.parse(e.data))}catch(_){}};es.onerror=poll}else setInterval(poll,1000); poll();
document.querySelectorAll('[data-experiment]').forEach(button=>button.addEventListener('click',async()=>{try{await fetch('/api/socx/experiment',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({kind:button.dataset.experiment,action:'start',duration:30})})}catch(_){}})); $('lab-stop').addEventListener('click',async()=>{try{await fetch('/api/socx/experiment',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'stop'})})}catch(_){}});
const c=$('space'),ctx=c.getContext('2d');let t=0;
function resize(){c.width=c.clientWidth*devicePixelRatio;c.height=c.clientHeight*devicePixelRatio}addEventListener('resize',resize);resize();
function draw(){t+=0.014;ctx.clearRect(0,0,c.width,c.height);const w=c.width,h=c.height,cx=w/2,cy=h/2;const roles=['triage','evidence','action'];const online=(state.roles_online||'0/3').split('/')[0]*1;const p=state.payload_summary||{};const auto=state.autonomy||{};const fw=Math.min(1,(p.firewall_blocks_sampled||0)/2000),dns=Math.min(1,(p.dnsbl_lines_sampled||0)/2000),ids=Math.min(1,(p.ids_watch_sampled||0)/300),ascore=Math.min(1,(auto.score||0)/100);const energy=.45+fw*.22+dns*.18+ids*.12+ascore*.2;
 ctx.save();ctx.translate(cx,cy);for(let ring=0;ring<4;ring++){ctx.strokeStyle=`rgba(${ring%2?102:73},255,${ring%2?124:244},${0.16+ring*.05})`;ctx.lineWidth=(1+ring*.35)*devicePixelRatio;ctx.beginPath();const rx=(78+ring*38+Math.sin(t*3+ring)*8)*devicePixelRatio,ry=rx*(.48+ring*.045);for(let a=0;a<=Math.PI*2+.05;a+=.06){const twist=a+t*(ring%2?-1:1);const x=Math.cos(twist)*rx,y=Math.sin(twist)*ry;if(a===0)ctx.moveTo(x,y);else ctx.lineTo(x,y)}ctx.stroke()}ctx.restore();
 for(let i=0;i<150;i++){const z=((i*37+t*(90+energy*150))%100)/100;const a=i*2.399+t*(.8+energy);const r=(40+z*(260+fw*240))*devicePixelRatio;const hue=i%5===0?'255,86,244':i%3===0?'255,227,91':i%3===1?'73,255,244':'102,255,124';ctx.fillStyle=`rgba(${hue},${0.035+z*(0.16+ascore*.12)})`;ctx.fillRect(cx+Math.cos(a)*r,cy+Math.sin(a)*r*.55,1+z*3,1+z*3)}
 roles.forEach((r,i)=>{const a=t*(1.1+energy)+i*Math.PI*2/3;const radius=w*(.21+.05*Math.sin(t+i));const x=cx+Math.cos(a)*radius,y=cy+Math.sin(a)*h*.22;ctx.strokeStyle=i<online?'#66ff7c':'#ff5e78';ctx.lineWidth=(2+energy*2)*devicePixelRatio;ctx.shadowColor=ctx.strokeStyle;ctx.shadowBlur=18*devicePixelRatio;ctx.beginPath();ctx.moveTo(cx,cy);ctx.bezierCurveTo(cx+Math.cos(a-.6)*90,cy+Math.sin(a-.6)*60,x-Math.cos(a)*40,y-Math.sin(a)*20,x,y);ctx.stroke();ctx.shadowBlur=0;ctx.fillStyle=i<online?'#66ff7c':'#ff5e78';ctx.beginPath();ctx.arc(x,y,(13+energy*5)*devicePixelRatio,0,Math.PI*2);ctx.fill();ctx.fillStyle='#e8fbff';ctx.font=`${12*devicePixelRatio}px monospace`;ctx.fillText(r.toUpperCase(),x+18*devicePixelRatio,y+4*devicePixelRatio)})
 requestAnimationFrame(draw)}draw();
</script>
</body></html>"""
