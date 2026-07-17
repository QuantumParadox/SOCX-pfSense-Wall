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

function speedLine(item = {}) {
  const status = safe(item.status, "WAIT").toUpperCase();
  const down = item.down ? `${Number(item.down).toFixed(0)}↓` : "--↓";
  const up = item.up ? `${Number(item.up).toFixed(0)}↑` : "--↑";
  const ping = item.ping ? `${Number(item.ping).toFixed(0)}ms` : "--ms";
  const age = item.age_sec ? `${Math.floor(Number(item.age_sec) / 60)}m` : "fresh";
  return `${safe(item.label, "PATH")} ${status} ${down}/${up} ${ping} ${age}`;
}

function renderCommandCenter(center = {}) {
  const root = $("command-center");
  if (!root) return;
  const mode = safe(center.mode, "UNKNOWN").toUpperCase();
  const score = center.score !== undefined && center.score !== "" ? `${center.score}/100` : "--";
  const modeClass = mode.includes("INCIDENT") || mode.includes("DEGRADED") ? "red" : mode.includes("WATCH") || mode.includes("INVESTIGATE") ? "yellow" : "green";
  const rows = [
    `<div class="command-verdict"><span class="${modeClass}">${escapeHtml(mode)}</span><b>${escapeHtml(score)}</b></div>`,
    `<div class="command-summary" title="${escapeHtml(center.summary)}">${escapeHtml(center.summary || "Run socx autopilot for a fresh verdict")}</div>`,
    row([
      "score",
      `net ${safe(center.scores?.network, "--")} sec ${safe(center.scores?.security, "--")} ai ${safe(center.scores?.ai, "--")} sens ${safe(center.scores?.sensors, "--")}`,
    ], "command-row"),
    row(["speed", `${speedLine(center.direct)} | ${speedLine(center.vpn)}`], "command-row"),
  ];
  (center.vpn_paths || []).slice(0, 2).forEach((path) => rows.push(row(["path", speedLine(path)], "command-row")));
  rows.push(row([
    "history",
    `${safe(center.history_count, 0)} samples ${safe(center.history_trend?.label, "")} avg ${safe(center.history_trend?.score_avg, "--")} d/v ${safe(center.history_trend?.direct_avg, "--")}/${safe(center.history_trend?.vpn_avg, "--")}`,
  ], "command-row"));
  (center.actions || []).slice(0, 4).forEach((action, idx) => {
    rows.push(row([idx === 0 ? "next" : "", action], "command-row action"));
  });
  root.innerHTML = rows.join("");
  drawSparkline($("cmd-spark"), (center.history || []).map((p) => p.score || 0), { stroke: "#34d7f2", fill: "rgba(52, 215, 242, .16)" });
}

function renderIncident(incident = {}) {
  const noteClass = (incident.verdict || "").includes("INVESTIGATE") || (incident.verdict || "").includes("INCIDENT") ? "red" : (incident.verdict || "").includes("WATCH") ? "yellow" : "green";
  const note = $("incident-note");
  if (note) {
    note.textContent = safe(incident.verdict, "quiet");
    note.className = noteClass;
  }
  setText("incident-headline", incident.headline || incident.summary || "No current incident pressure");
  const counts = incident.counts || {};
  const grid = $("incident-grid");
  if (grid) {
    grid.innerHTML = [
      ["FW", counts.sources || 0],
      ["DNSBL", counts.dnsbl || 0],
      ["IDS", counts.ids_high || 0],
      ["LAN", counts.lan || 0],
    ].map(([label, value]) => `<div><span>${escapeHtml(label)}</span><b>${escapeHtml(value)}</b></div>`).join("");
  }
  const table = $("incident-table");
  if (!table) return;
  const lines = [row(["type", "item", "count"], "header")];
  const addRows = (kind, items = []) => {
    items.slice(0, 3).forEach((item) => lines.push(row([kind, item.name, item.count])));
  };
  addRows("src", incident.blocked_sources || []);
  addRows("port", incident.blocked_ports || []);
  addRows("dns", incident.dnsbl_domains || []);
  addRows("lan", incident.lan_hosts || []);
  table.innerHTML = lines.slice(0, 11).join("");
}

function renderPiNodes(piNodes = {}) {
  const root = $("pi-nodes");
  if (!root) return;
  const nodes = Array.isArray(piNodes.nodes) ? piNodes.nodes : [];
  if (!nodes.length) {
    root.innerHTML = row(["pi", "no Pi nodes discovered yet"], "command-row");
    return;
  }
  root.innerHTML = nodes.slice(0, 4).map((node) => row([
    node.role || "pi",
    `${node.name || node.ip} ${node.ip || ""} ${node.service || ""} ${node.ports ? `:${node.ports}` : ""}`,
  ], "command-row")).join("");
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

  setText("cmd-note", `${safe(state.command_center?.mode, "autopilot")} ${safe(state.command_center?.score, "--")}`);
  renderCommandCenter(state.command_center || {});
  renderPiNodes(state.pi_nodes || {});
  renderIncident(state.incident || {});
  renderFlows(state.flows || []);
  renderPackets(state.packets || []);
  renderTicker(state.events || []);
}

async function runCommander(action) {
  const output = $("command-output");
  const title = $("command-output-title");
  const body = $("command-output-body");
  if (output) output.hidden = false;
  if (title) title.textContent = `Running ${action}`;
  if (body) body.textContent = "Working...";
  try {
    const res = await fetch("/api/commander", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action }),
    });
    const data = await res.json();
    if (title) title.textContent = `${data.title || action} ${data.ok ? "OK" : "WARN"}`;
    if (body) body.textContent = data.output || "No output returned.";
  } catch (err) {
    if (title) title.textContent = "Command Error";
    if (body) body.textContent = String(err);
  }
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

document.querySelectorAll(".commander-buttons button").forEach((button) => {
  button.addEventListener("click", () => runCommander(button.dataset.action || ""));
});

$("command-output-close")?.addEventListener("click", () => {
  const output = $("command-output");
  if (output) output.hidden = true;
});

setInterval(updateClock, 500);
updateClock();
connect();
poll();
