const $ = (id) => document.getElementById(id);

let lastTickerText = "";
let pollingTimer = null;

const fmtPct = (v) => `${Number(v || 0).toFixed(0)}%`;
const safe = (v, fallback = "--") => (v === undefined || v === null || v === "" ? fallback : String(v));

function escapeHtml(value) {
  return safe(value, "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
  }[c]));
}

function setText(id, value) {
  const el = $(id);
  if (el) el.textContent = safe(value);
}

function setMeter(id, value) {
  const el = $(id);
  if (el) el.style.width = `${Math.max(0, Math.min(100, Number(value || 0)))}%`;
}

function updateClock() {
  setText("clock", new Date().toLocaleTimeString([], { hour12: false }));
}

function drawSparkline(canvas, values, options = {}) {
  if (!canvas) return;
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();
  const width = Math.max(1, Math.floor(rect.width * dpr));
  const height = Math.max(1, Math.floor(rect.height * dpr));
  if (canvas.width !== width || canvas.height !== height) {
    canvas.width = width;
    canvas.height = height;
  }
  const ctx = canvas.getContext("2d");
  ctx.clearRect(0, 0, width, height);
  const data = Array.isArray(values) && values.length ? values.map(Number) : [0, 0];
  const max = Math.max(1, ...data);
  const min = Math.min(0, ...data);
  const span = Math.max(1, max - min);
  const pad = 4 * dpr;
  const usableW = width - pad * 2;
  const usableH = height - pad * 2;

  const gradient = ctx.createLinearGradient(0, pad, 0, height - pad);
  gradient.addColorStop(0, options.fill || "rgba(52, 215, 242, .25)");
  gradient.addColorStop(1, "rgba(52, 215, 242, 0)");

  const points = data.map((v, i) => {
    const x = pad + (i / Math.max(1, data.length - 1)) * usableW;
    const y = pad + usableH - ((v - min) / span) * usableH;
    return [x, y];
  });

  ctx.beginPath();
  points.forEach(([x, y], i) => {
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.lineTo(width - pad, height - pad);
  ctx.lineTo(pad, height - pad);
  ctx.closePath();
  ctx.fillStyle = gradient;
  ctx.fill();

  ctx.beginPath();
  points.forEach(([x, y], i) => {
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.strokeStyle = options.stroke || "#34d7f2";
  ctx.lineWidth = Math.max(2, 2.4 * dpr);
  ctx.lineJoin = "round";
  ctx.lineCap = "round";
  ctx.stroke();
}

function renderCores(cores = []) {
  const root = $("cpu-cores");
  if (!root) return;
  root.innerHTML = cores.map((core) => `
    <div class="core-row">
      <b>C${escapeHtml(core.id)}</b>
      <div class="meter"><i style="width:${Math.max(0, Math.min(100, Number(core.pct || 0)))}%"></i></div>
      <span>${fmtPct(core.pct)}</span>
    </div>
  `).join("");
}

function row(cells, cls = "") {
  return `<div class="row ${cls}">${cells.map((cell) => `<span title="${escapeHtml(cell)}">${escapeHtml(cell)}</span>`).join("")}</div>`;
}

function renderProcesses(processes = []) {
  const root = $("processes");
  if (!root) return;
  const lines = [row(["PID", "user", "CPU", "mem", "command"], "header")];
  processes.slice(0, 13).forEach((p) => {
    lines.push(row([
      p.pid,
      p.user,
      `${Number(p.cpu || 0).toFixed(1)}%`,
      p.rss_h,
      p.command,
    ]));
  });
  root.innerHTML = lines.join("");
}

function renderFlows(flows = []) {
  const root = $("flows");
  if (!root) return;
  const lines = [row(["#", "asset", "peer", "proto", "service", "tag"], "header")];
  flows.slice(0, 13).forEach((f, idx) => {
    lines.push(row([
      String(idx + 1).padStart(2, "0"),
      f.asset,
      f.peer,
      f.proto,
      f.service,
      `${f.tag || "state"} x${f.count || 1}`,
    ]));
  });
  root.innerHTML = lines.join("");
}

function renderPackets(packets = []) {
  const root = $("packets");
  if (!root) return;
  const lines = [row(["time", "act", "proto", "source", "dest", "svc", "info"], "header")];
  packets.slice(0, 13).forEach((p) => {
    const severity = p.severity === "HIGH" ? "red" : p.action === "BLOCK" ? "yellow" : "green";
    lines.push(`
      <div class="row">
        <span>${escapeHtml(p.time)}</span>
        <span class="${severity}">${escapeHtml(p.action)}</span>
        <span class="cyan">${escapeHtml(p.proto)}</span>
        <span title="${escapeHtml(p.src || p.src_label)}">${escapeHtml(p.src_label)}</span>
        <span title="${escapeHtml(p.dst || p.dst_label)}">${escapeHtml(p.dst_label)}</span>
        <span>${escapeHtml(p.service)}</span>
        <span>${escapeHtml(p.info)}</span>
      </div>
    `);
  });
  root.innerHTML = lines.join("");
}

function renderTicker(events = []) {
  const ticker = $("ticker");
  if (!ticker) return;
  const pieces = events.length
    ? events.slice().reverse().map((event) => {
        const count = Number(event.count || 1) > 1 ? ` x${event.count}` : "";
        return `${event.text}${count}`;
      })
    : ["Waiting for live firewall events"];
  const text = pieces.join("   ◆   ");
  if (text === lastTickerText) return;
  lastTickerText = text;
  ticker.innerHTML = `<span>${escapeHtml(text)}</span><span aria-hidden="true">${escapeHtml(text)}</span>`;
  const duration = Math.max(9, Math.min(42, text.length / 9));
  document.documentElement.style.setProperty("--ticker-duration", `${duration}s`);
  ticker.style.animation = "none";
  ticker.offsetHeight;
  ticker.style.animation = "";
}

function render(state) {
  if (!state) return;
  setText("hostname", `${safe(state.hostname, "pfSense")} / ${safe(state.mode, "live")}`);
  setText("health", safe(state.status?.health, "online"));
  setText("refresh", `${safe(state.status?.refresh_ms, 500)}ms`);

  const cpu = state.cpu || {};
  setText("cpu-total", fmtPct(cpu.overall));
  setText("cpu-extra", `${safe(cpu.freq_mhz, "--")}MHz ${cpu.temp_c ? `${Number(cpu.temp_c).toFixed(0)}C` : ""}`);
  setMeter("cpu-meter", cpu.overall);
  renderCores(cpu.cores || []);
  drawSparkline($("cpu-spark"), cpu.history || [], { stroke: "#76f27d", fill: "rgba(118, 242, 125, .18)" });

  const mem = state.memory || {};
  setText("mem-total", mem.total_h);
  setText("mem-pct", fmtPct(mem.used_pct));
  setText("mem-free", mem.free_h);
  setText("mem-arc", mem.arc_h);
  setMeter("mem-meter", mem.used_pct);
  drawSparkline($("mem-spark"), mem.history || [], { stroke: "#b78cff", fill: "rgba(183, 140, 255, .18)" });

  const pf = state.pf || {};
  setText("pf-states", pf.states_h);
  setText("pf-search", pf.searches_h);
  setText("pf-passed", pf.passed_h);
  setText("pf-blocked", pf.blocked_h);
  drawSparkline($("pf-spark"), pf.history || [], { stroke: "#ffe267", fill: "rgba(255, 226, 103, .18)" });

  const wan = state.net?.wan || {};
  const lan = state.net?.lan || {};
  setText("net-ifaces", `${safe(wan.name, "wan")} / ${safe(lan.name, "lan")}`);
  setText("wan-rx", wan.rx_h);
  setText("wan-tx", wan.tx_h);
  setText("lan-rx", lan.rx_h);
  setText("lan-tx", lan.tx_h);
  drawSparkline($("net-spark"), [...(wan.history_rx || []), ...(lan.history_tx || [])], { stroke: "#34d7f2", fill: "rgba(52, 215, 242, .18)" });

  const ups = state.ups || {};
  setText("ups-status", `${safe(ups.status).toUpperCase()}${ups.stale ? " stale" : ""}`);
  setText("ups-watts", ups.watts_h);
  setText("ups-load", fmtPct(ups.load));
  setText("ups-batt", fmtPct(ups.battery));
  setText("ups-run", ups.runtime_h);
  setText("ups-line", ups.linev_h);
  drawSparkline($("ups-spark"), ups.history || [], { stroke: "#ff9c43", fill: "rgba(255, 156, 67, .20)" });

  setText("proc-note", cpu.process_text || "top cpu");
  renderProcesses(state.processes || []);
  renderFlows(state.flows || []);
  renderPackets(state.packets || []);
  renderTicker(state.events || []);
}

async function poll() {
  try {
    const res = await fetch("/api/state", { cache: "no-store" });
    if (res.ok) render(await res.json());
  } catch (_) {
    setText("health", "offline");
  }
}

function connect() {
  const proto = location.protocol === "https:" ? "wss" : "ws";
  const ws = new WebSocket(`${proto}://${location.host}/ws`);
  ws.onopen = () => {
    setText("health", "online");
    if (pollingTimer) {
      clearInterval(pollingTimer);
      pollingTimer = null;
    }
  };
  ws.onmessage = (event) => {
    try {
      render(JSON.parse(event.data));
    } catch (_) {
      setText("health", "decode error");
    }
  };
  ws.onclose = () => {
    setText("health", "reconnecting");
    if (!pollingTimer) pollingTimer = setInterval(poll, 1200);
    setTimeout(connect, 1800);
  };
  ws.onerror = () => {
    ws.close();
  };
}

$("fullscreen")?.addEventListener("click", () => {
  if (!document.fullscreenElement) document.documentElement.requestFullscreen?.();
  else document.exitFullscreen?.();
});

setInterval(updateClock, 500);
updateClock();
connect();
poll();
