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
    "evidence": os.getenv("SOCX_PI_LLM_EVIDENCE_MODEL", "qwen2.5:3b"),
    "action": os.getenv("SOCX_PI_LLM_ACTION_MODEL", "phi3:mini"),
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
}
EVENTS: list[dict[str, Any]] = []
MAX_EVENTS = 80


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
        result = await asyncio.to_thread(call_ollama_generate, url, model, prompt, timeout)
        result.update({"role": role, "summary": summarize_role_text(result.get("text", "")), "url": url})
        return result
    except (OSError, TimeoutError, urllib.error.URLError, json.JSONDecodeError) as exc:
        return {"ok": False, "role": role, "model": model, "url": url, "summary": "offline or timed out", "error": str(exc)[:220]}


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
    return JSONResponse(data)


@app.get("/api/socx/stream")
async def stream() -> StreamingResponse:
    async def gen():
        while True:
            data = dict(LATEST_ANALYSIS)
            data["events"] = EVENTS[-30:]
            data["age_seconds"] = int(time.time() - float(data["updated"] or time.time()))
            yield f"data: {json.dumps(data, separators=(',', ':'))}\n\n"
            await asyncio.sleep(1)

    return StreamingResponse(gen(), media_type="text/event-stream")


@app.post("/api/socx/triage")
async def triage(request: Request) -> dict[str, Any]:
    payload = await request.json()
    started = time.time()
    event("SOCX", "pfSense evidence received; running triage/evidence/action roles", "INFO")
    results_list = await asyncio.gather(*(run_role(role, payload) for role in DEFAULT_ROLES))
    role_results = dict(zip(DEFAULT_ROLES, results_list))
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
        }
    )
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
:root{color-scheme:dark;--bg:#05070a;--panel:#091116;--ink:#e8fbff;--muted:#88a3aa;--cyan:#49fff4;--green:#66ff7c;--yellow:#ffe35b;--red:#ff5e78;--mag:#ff56f4;--blue:#6687ff}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 50% -10%,#15313a 0,#05070a 48%,#020304 100%);color:var(--ink);font-family:Inter,system-ui,Segoe UI,Arial,sans-serif;overflow:hidden}
.shell{height:100vh;display:grid;grid-template-rows:54px 1fr 146px;gap:10px;padding:12px}
.top{display:grid;grid-template-columns:1fr auto;align-items:center;border:1px solid #1cfff355;background:linear-gradient(90deg,#071215,#0b1218);box-shadow:0 0 22px #1cfff322;padding:8px 12px}
.brand{font-weight:800;letter-spacing:.08em;color:var(--cyan);font-size:18px}.status{display:flex;gap:14px;align-items:center;font-weight:800}.pill{padding:3px 8px;border:1px solid #ffffff22;background:#ffffff08}.ok{color:var(--green)}.warn{color:var(--yellow)}.bad{color:var(--red)}.mag{color:var(--mag)}.muted{color:var(--muted)}
.main{display:grid;grid-template-columns:minmax(360px,1.1fr) minmax(420px,1.4fr) minmax(360px,1.1fr);gap:10px;min-height:0}
.panel{border:1px solid #1cfff355;background:linear-gradient(180deg,#071116dd,#05090ddd);box-shadow:inset 0 0 22px #1cfff310,0 0 18px #1cfff314;min-height:0;padding:12px;overflow:hidden}
h2{margin:0 0 8px;color:var(--cyan);font-size:15px;letter-spacing:.08em}.metric{display:grid;grid-template-columns:140px 1fr;gap:8px;margin:6px 0;color:var(--muted)}.metric b{color:var(--ink)}
.role{display:grid;grid-template-columns:88px 1fr;gap:10px;border-top:1px solid #ffffff17;padding:10px 0}.role:first-of-type{border-top:0}.role-name{font-weight:900;color:var(--blue);text-transform:uppercase}.role.ok .role-name{color:var(--green)}.role.fail .role-name{color:var(--red)}.role-text{font-size:14px;line-height:1.32;white-space:normal}.role-meta{font-size:12px;color:var(--muted);margin-top:4px}
.viz{position:relative;height:100%;min-height:360px;overflow:hidden}.viz canvas{position:absolute;inset:0;width:100%;height:100%}.core{position:absolute;left:50%;top:50%;width:132px;height:132px;margin:-66px;border:2px solid var(--cyan);border-radius:50%;display:grid;place-items:center;text-align:center;font-weight:900;color:var(--cyan);box-shadow:0 0 36px #49fff466, inset 0 0 24px #49fff41e;animation:pulse 2.2s infinite}
@keyframes pulse{50%{transform:scale(1.045);box-shadow:0 0 54px #49fff488,inset 0 0 34px #49fff433}}
.feed{display:grid;grid-template-columns:1fr 1fr;gap:10px;min-height:0}.events{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:14px;line-height:1.45;overflow:hidden}.event{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.json{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px;color:#d8fbff;overflow:hidden;white-space:pre-wrap}
.bar{height:9px;background:#ffffff16;margin-top:6px;overflow:hidden}.bar span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--cyan),var(--green))}
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
    </section>
    <section class="panel">
      <h2>Recommended Next Step</h2>
      <div id="next" class="role-text">waiting for pfSense evidence</div>
      <h2 style="margin-top:18px">Model Health</h2>
      <div id="models" class="json"></div>
      <h2 style="margin-top:18px">Latest Reasons</h2>
      <div id="reasons" class="events"></div>
    </section>
  </main>
  <footer class="feed">
    <section class="panel events"><h2>Live Events</h2><div id="events"></div></section>
    <section class="panel json"><h2>Payload Summary</h2><div id="payload"></div></section>
  </footer>
</div>
<script>
const $=id=>document.getElementById(id);
const colors={INFO:'#49fff4',WARN:'#ffe35b',ERROR:'#ff5e78',CRITICAL:'#ff5e78'};
let state={roles_online:'0/3',severity:'INFO',role_results:{}};
function cls(sev){return sev==='INFO'||sev==='OK'?'ok':(sev==='WARN'?'warn':'bad')}
function render(d){
  state=d; $('clock').textContent=new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'});
  $('svc').textContent=(d.status||'waiting').toUpperCase(); $('svc').className='pill '+(d.status==='ok'?'ok':d.status==='degraded'?'warn':'muted');
  $('roles').textContent='ROLES '+(d.roles_online||'0/3'); $('roles').className='pill '+((d.roles_online||'').startsWith('3/')?'ok':(d.roles_online||'').startsWith('0/')?'bad':'warn');
  $('coreRoles').textContent=d.roles_online||'0/3'; $('ollama').textContent=d.ollama&&d.ollama.ok?'OLLAMA '+d.ollama.model_count:'OLLAMA OFF'; $('ollama').className='pill '+(d.ollama&&d.ollama.ok?'ok':'bad');
  $('age').textContent='age '+(d.age_seconds??0)+'s'; $('severity').textContent=d.severity||'INFO'; $('severity').style.color=colors[d.severity]||colors.INFO;
  $('confidence').textContent=Math.round((d.confidence||0)*100)+'%'; $('confbar').style.width=Math.max(0,Math.min(100,(d.confidence||0)*100))+'%';
  $('elapsed').textContent=(d.elapsed_ms||0)+'ms'; $('evidence').textContent=(d.payload_summary?Object.keys(d.payload_summary).length:0)+' summary fields';
  $('next').textContent=d.recommended_next_step||'waiting';
  const roles=d.role_results||{}; $('rolebox').innerHTML=['triage','evidence','action'].map(r=>{const x=roles[r]||{};return `<div class="role ${x.ok?'ok':'fail'}"><div class="role-name">${r}</div><div><div class="role-text">${esc(x.summary||x.error||'waiting for role output')}</div><div class="role-meta">${esc(x.model||'model?')} ${x.ok?'online':'offline'} ${x.error?' | '+esc(x.error):''}</div></div></div>`}).join('');
  $('models').textContent=JSON.stringify({models:d.models,ollama:d.ollama}, null, 2);
  $('reasons').innerHTML=(d.reasons||[]).map(r=>`<div class="event">${esc(r)}</div>`).join('');
  $('events').innerHTML=(d.events||[]).slice(-8).reverse().map(e=>`<div class="event"><span class="${cls(e.severity)}">[${esc(e.kind)}]</span> ${esc(e.message)}</div>`).join('');
  $('payload').textContent=JSON.stringify(d.payload_summary||{}, null, 2);
}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
async function poll(){try{render(await (await fetch('/api/socx/latest',{cache:'no-store'})).json())}catch(e){}}
if(window.EventSource){const es=new EventSource('/api/socx/stream');es.onmessage=e=>{try{render(JSON.parse(e.data))}catch(_){}};es.onerror=poll}else setInterval(poll,1000); poll();
const c=$('space'),ctx=c.getContext('2d');let t=0;
function resize(){c.width=c.clientWidth*devicePixelRatio;c.height=c.clientHeight*devicePixelRatio}addEventListener('resize',resize);resize();
function draw(){t+=0.012;ctx.clearRect(0,0,c.width,c.height);const w=c.width,h=c.height,cx=w/2,cy=h/2;const roles=['triage','evidence','action'];const online=(state.roles_online||'0/3').split('/')[0]*1;
 for(let i=0;i<90;i++){const z=((i*37+t*120)%100)/100;const a=i*2.399+t;const r=(80+z*380)*devicePixelRatio;ctx.fillStyle=`rgba(73,255,244,${0.05+z*0.22})`;ctx.fillRect(cx+Math.cos(a)*r,cy+Math.sin(a)*r*.55,2+z*3,2+z*3)}
 roles.forEach((r,i)=>{const a=t*1.8+i*Math.PI*2/3;const x=cx+Math.cos(a)*w*.28,y=cy+Math.sin(a)*h*.22;ctx.strokeStyle=i<online?'#66ff7c':'#ff5e78';ctx.lineWidth=2*devicePixelRatio;ctx.beginPath();ctx.moveTo(cx,cy);ctx.lineTo(x,y);ctx.stroke();ctx.fillStyle=i<online?'#66ff7c':'#ff5e78';ctx.beginPath();ctx.arc(x,y,14*devicePixelRatio,0,Math.PI*2);ctx.fill();ctx.fillStyle='#e8fbff';ctx.font=`${12*devicePixelRatio}px monospace`;ctx.fillText(r.toUpperCase(),x+18*devicePixelRatio,y+4*devicePixelRatio)})
 requestAnimationFrame(draw)}draw();
</script>
</body></html>"""
