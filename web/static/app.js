const $ = (id) => document.getElementById(id);

let lastTickerText = "";
let pollingTimer = null;
let latestState = null;
let clusterTick = 0;
let tickerMode = localStorage.getItem("socxTickerMode") || "slow";

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
    row([
      "truth",
      `${safe(center.speed_truth?.label, "speed truth waiting")} C/R ${safe(center.speed_truth?.client_down, "--")}/${safe(center.speed_truth?.router_down, "--")} D/V ${safe(center.speed_truth?.direct_down, "--")}/${safe(center.speed_truth?.vpn_down, "--")}`,
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
  const ids = incident.ids || {};
  const grid = $("incident-grid");
  if (grid) {
    grid.innerHTML = [
      ["FW", counts.sources || 0],
      ["DNSBL", counts.dnsbl || 0],
      ["IDS sig", ids.signal || counts.ids_high || 0],
      ["IDS watch", ids.watch || 0],
      ["routine", ids.routine || 0],
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

function renderIncidentTimeline(timeline = {}) {
  const root = $("incident-timeline");
  if (!root) return;
  const rows = Array.isArray(timeline.rows) ? timeline.rows : [];
  root.innerHTML = rows.slice(0, 5).map((item) => {
    const sev = String(item.severity || "").toUpperCase();
    const cls = sev.includes("HIGH") ? "red" : sev.includes("MED") || sev.includes("WARN") ? "yellow" : "green";
    return `
      <div class="timeline-row">
        <span>${escapeHtml(item.time || "--")}</span>
        <b class="${cls}">${escapeHtml(item.kind || "SOCX")}</b>
        <em title="${escapeHtml(item.detail || "")}">${escapeHtml(item.title || item.detail || "--")}</em>
      </div>
    `;
  }).join("");
}

function renderDailyBrief(brief = {}) {
  const root = $("daily-brief");
  if (!root) return;
  const summary = Array.isArray(brief.summary) ? brief.summary : [];
  const next = Array.isArray(brief.next) ? brief.next : [];
  root.innerHTML = `
    <div class="brief-title"><span>${escapeHtml(brief.title || "SOCX Brief")}</span><b class="${brief.status === "watch" ? "yellow" : "green"}">${escapeHtml(brief.status || "normal")}</b></div>
    ${summary.slice(0, 4).map((line) => `<div class="brief-line">${escapeHtml(line)}</div>`).join("")}
    ${next.length ? `<div class="brief-next">${next.slice(0, 2).map(escapeHtml).join(" | ")}</div>` : ""}
  `;
}

function renderRuleAssistant(assistant = {}) {
  const root = $("rule-assistant");
  if (!root) return;
  const drafts = Array.isArray(assistant.drafts) ? assistant.drafts : [];
  root.innerHTML = drafts.slice(0, 2).map((draft) => {
    const cls = draft.confidence === "high" ? "green" : draft.confidence === "medium" ? "yellow" : "cyan";
    return `
      <div class="rule-row">
        <b class="${cls}">${escapeHtml(draft.kind || "review")}</b>
        <span title="${escapeHtml(draft.evidence || "")}">${escapeHtml(draft.recommendation || "--")}</span>
        <em>${escapeHtml(draft.safe_command || "socx status")}</em>
      </div>
    `;
  }).join("");
}

function renderPiNodes(piNodes = {}) {
  const root = $("pi-nodes");
  const score = $("pi-fleet-score");
  const canvas = $("cluster-canvas");
  if (!root) return;
  const nodes = Array.isArray(piNodes.nodes) ? piNodes.nodes : [];
  if (score) {
    const online = piNodes.online !== undefined ? piNodes.online : nodes.filter((node) => node.status === "online").length;
    const total = piNodes.count !== undefined ? piNodes.count : nodes.length;
    score.textContent = `${online}/${total} ${safe(piNodes.score, "--")}`;
    score.className = Number(piNodes.score || 0) >= 85 ? "green" : Number(piNodes.score || 0) >= 50 ? "yellow" : "red";
  }
  drawCluster(canvas, nodes);
  if (!nodes.length) {
    root.innerHTML = `<div class="pi-node-card empty">No Pi nodes discovered yet</div>`;
    return;
  }
  root.innerHTML = nodes.slice(0, 3).map((node) => {
    const stateClass = node.status === "online" ? "green" : node.status === "offline" ? "red" : "yellow";
    const aiLine = node.autonomy_mode || node.autonomy_score !== undefined
      ? `${safe(node.autonomy_mode, "watch")} ${safe(node.autonomy_score, "--")}`
      : safe(node.detail || node.model, "telemetry");
    return `
      <div class="pi-node-card">
        <div class="pi-node-top">
          <b>${escapeHtml(node.role_h || node.role || "PI")}</b>
          <span class="${stateClass}">${escapeHtml((node.status || "?").toUpperCase())}</span>
        </div>
        <div class="pi-node-name" title="${escapeHtml(`${node.name || ""} ${node.model || ""}`)}">${escapeHtml(node.name || node.ip || "Pi node")} <span>${escapeHtml(node.ip || "")}</span></div>
        <div class="pi-node-metrics">
          <span title="Temperature">${escapeHtml(node.temperature_h || piTemp(node))}</span>
          <span title="Memory">mem ${escapeHtml(node.memory_h || "--")}</span>
          <span title="Load">load ${escapeHtml(node.load_h || "--")}</span>
          <span title="Uptime">up ${escapeHtml(node.uptime_h || "--")}</span>
        </div>
        <div class="pi-node-ai" title="${escapeHtml(node.autonomy_summary || node.service_h || node.service || "")}">${escapeHtml(node.service_h || node.service || "--")} | ${escapeHtml(aiLine)}</div>
      </div>
    `;
  }).join("");
}

function metricBox(label, value, cls = "") {
  return `<div class="metric-box ${cls}"><span>${escapeHtml(label)}</span><b>${escapeHtml(value)}</b></div>`;
}

function renderThreatPulse(pulse = {}) {
  const root = $("threat-pulse");
  const state = $("threat-state");
  if (state) {
    state.textContent = `${safe(pulse.label, "--")} ${safe(pulse.score, "--")}`;
    state.className = pulse.severity || "";
  }
  if (!root) return;
  root.innerHTML = [
    metricBox("FW blocks", safe(pulse.fw_blocks, 0), pulse.fw_blocks > 120 ? "red" : pulse.fw_blocks > 0 ? "yellow" : "green"),
    metricBox("DNSBL", safe(pulse.dnsbl_hits, 0), pulse.dnsbl_hits > 250 ? "yellow" : "cyan"),
    metricBox("IDS high", safe(pulse.ids_high, 0), pulse.ids_high > 0 ? "red" : "green"),
    metricBox("IDS watch", safe(pulse.ids_watch, 0), pulse.ids_watch > 200 ? "yellow" : "cyan"),
    metricBox("Top port", `${safe(pulse.top_port, "--")} x${safe(pulse.top_port_count, 0)}`, "cyan"),
    metricBox("Top source", `${safe(pulse.top_source, "--")} x${safe(pulse.top_source_count, 0)}`, "purple"),
  ].join("");
}

function renderAssetWatch(asset = {}) {
  const root = $("asset-watch");
  const state = $("asset-state");
  if (state) {
    state.textContent = safe(asset.headline, "--");
    state.className = asset.status || "";
  }
  if (!root) return;
  const topAsset = (asset.top_assets || [])[0] || {};
  const topSvc = (asset.top_services || [])[0] || {};
  const topApp = (asset.top_apps || [])[0] || {};
  const devices = Array.isArray(asset.devices) ? asset.devices.slice(0, 3) : [];
  const now = Array.isArray(asset.now_watching) ? asset.now_watching.slice(0, 4) : [];
  const anomalies = Array.isArray(asset.anomalies) ? asset.anomalies.slice(0, 2) : [];
  const profiles = Array.isArray(asset.profiles) ? asset.profiles.slice(0, 3) : [];
  const boxes = [
    metricBox("Pi nodes", `${safe(asset.pi_online, 0)}/${safe(asset.pi_count, 0)}`, asset.pi_online === asset.pi_count ? "green" : "red"),
    metricBox("Unknown log", safe(asset.unknown_h, "--"), asset.unknown_bytes > 50000 ? "yellow" : "green"),
    metricBox("Top LAN", `${safe(topAsset.name, "--")} x${safe(topAsset.count, 0)}`, "cyan"),
    metricBox("Now app", `${safe(topApp.name || topSvc.name, "--")} x${safe(topApp.count || topSvc.count, 0)}`, "purple"),
  ].join("");
  const nowLine = now.length
    ? `<div class="now-strip">${now.map((item) => `<span><b>${escapeHtml(item.group)}</b>${escapeHtml((item.apps || []).join(", "))}</span>`).join("")}</div>`
    : "";
  const anomalyLine = anomalies.length
    ? `<div class="anomaly-strip">${anomalies.map((item) => `<span title="${escapeHtml((item.items || []).join(", "))}">${escapeHtml(item.asset || "--")}: ${escapeHtml((item.items || []).join(", "))}</span>`).join("")}</div>`
    : "";
  const profileLine = profiles.length
    ? `<div class="profile-strip">${profiles.map((item) => `<span>${escapeHtml(item.name)} ${escapeHtml(item.count)}</span>`).join("")}</div>`
    : "";
  const rows = devices.map((device) => {
    const apps = Array.isArray(device.apps) && device.apps.length
      ? device.apps.slice(0, 3).map((item) => item.name).join(", ")
      : (Array.isArray(device.services) ? device.services.slice(0, 2).map((item) => item.name).join(", ") : "--");
    const cls = Array.isArray(device.unusual) && device.unusual.length ? "red" : device.confidence === "high" ? "green" : device.confidence === "medium" ? "yellow" : "cyan";
    return `
      <div class="identity-row">
        <b>${escapeHtml(device.asset || "--")}</b>
        <span title="${escapeHtml(device.summary || apps)}">${escapeHtml(device.profile ? `${device.profile}: ${apps || "--"}` : apps || "--")}</span>
        <em class="${cls}">${escapeHtml((device.unusual || []).length ? "odd" : device.identity_confidence || device.confidence || "low")}</em>
      </div>
    `;
  }).join("");
  root.innerHTML = boxes + nowLine + anomalyLine + profileLine + (rows ? `<div class="identity-list">${rows}</div>` : "");
}

function renderAiTimeline(ai = {}) {
  const root = $("ai-timeline");
  const state = $("ai-state");
  const rows = Array.isArray(ai.rows) ? ai.rows : [];
  if (state) state.textContent = `${rows.length} signals`;
  if (!root) return;
  root.innerHTML = rows.slice(0, 4).map((item) => {
    const sev = String(item.severity || "").toUpperCase();
    const cls = sev.includes("HIGH") || sev.includes("INCIDENT") ? "red" : sev.includes("WARN") || sev.includes("WATCH") ? "yellow" : "green";
    return `
      <div class="ai-row">
        <span class="${cls}">${escapeHtml(item.source || "AI")}</span>
        <b>${escapeHtml(sev || "--")}${item.confidence !== "" && item.confidence !== undefined ? ` ${escapeHtml(item.confidence)}` : ""}</b>
        <em title="${escapeHtml(item.reason || "")}">${escapeHtml(item.reason || "--")}</em>
      </div>
    `;
  }).join("");
}

function piNodeStats(node = {}) {
  const bits = [];
  if (node.temperature_c !== undefined && node.temperature_c !== null) bits.push(`${Number(node.temperature_c).toFixed(0)}C`);
  if (node.memory_used_pct !== undefined && node.memory_used_pct !== null) bits.push(`mem ${Number(node.memory_used_pct).toFixed(0)}%`);
  if (node.load_one !== undefined && node.load_one !== null) bits.push(`ld ${Number(node.load_one).toFixed(2)}`);
  if (node.service) bits.push(node.service);
  return bits.join(" ");
}

function piTemp(node = {}) {
  if (node.temperature_c === undefined || node.temperature_c === null) return "--";
  const c = Number(node.temperature_c);
  const f = (c * 9 / 5) + 32;
  return `${c.toFixed(0)}C/${f.toFixed(0)}F`;
}

function drawCluster(canvas, nodes = []) {
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
  clusterTick = (clusterTick + 1) % 360;
  const cx = 56 * dpr;
  const cy = height / 2;
  const radius = Math.max(7 * dpr, Math.min(13 * dpr, height * .18));
  const active = nodes.filter((node) => node.status === "online");

  const glow = ctx.createRadialGradient(cx, cy, 0, cx, cy, 58 * dpr);
  glow.addColorStop(0, "rgba(52, 215, 242, .26)");
  glow.addColorStop(1, "rgba(52, 215, 242, 0)");
  ctx.fillStyle = glow;
  ctx.fillRect(0, 0, width, height);

  ctx.strokeStyle = "rgba(52, 215, 242, .38)";
  ctx.lineWidth = Math.max(1, 1.4 * dpr);
  ctx.beginPath();
  ctx.arc(cx, cy, radius * 1.65, 0, Math.PI * 2);
  ctx.stroke();

  ctx.fillStyle = "#34d7f2";
  ctx.beginPath();
  ctx.arc(cx, cy, radius, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = "#041016";
  ctx.font = `${Math.max(8, 9 * dpr)}px Consolas, monospace`;
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillText("FW", cx, cy);

  const usable = Math.max(90 * dpr, width - 130 * dpr);
  nodes.slice(0, 4).forEach((node, idx) => {
    const x = 116 * dpr + (idx / Math.max(1, Math.min(3, nodes.length - 1))) * usable;
    const phase = (clusterTick + idx * 42) * Math.PI / 180;
    const y = cy + Math.sin(phase) * 6 * dpr;
    const online = node.status === "online";
    const color = online ? "#76f27d" : "#ff5c7a";
    ctx.strokeStyle = online ? "rgba(118, 242, 125, .65)" : "rgba(255, 92, 122, .5)";
    ctx.beginPath();
    ctx.moveTo(cx + radius, cy);
    ctx.lineTo(x - radius, y);
    ctx.stroke();
    if (online) {
      ctx.strokeStyle = "rgba(255, 226, 103, .75)";
      const pulse = ((clusterTick + idx * 30) % 100) / 100;
      const px = cx + radius + (x - cx - radius * 2) * pulse;
      const py = cy + (y - cy) * pulse;
      ctx.beginPath();
      ctx.arc(px, py, Math.max(1.4 * dpr, 2), 0, Math.PI * 2);
      ctx.stroke();
    }
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(x, y, radius, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = online ? "#041016" : "#fff";
    ctx.font = `${Math.max(8, 9 * dpr)}px Consolas, monospace`;
    const label = (node.role || "pi").includes("5") ? "P5" : (node.role || node.name || "pi").includes("4") ? "P4" : `P${idx + 1}`;
    ctx.fillText(label, x, y);
  });

  ctx.fillStyle = "rgba(232, 241, 242, .72)";
  ctx.textAlign = "right";
  ctx.font = `${Math.max(8, 10 * dpr)}px Consolas, monospace`;
  ctx.fillText(`${active.length}/${nodes.length} online`, width - 8 * dpr, height - 9 * dpr);
}

function renderFlows(flows = []) {
  const root = $("flows");
  if (!root) return;
  const lines = [row(["#", "asset", "peer", "proto", "app/service", "tag"], "header")];
  flows.slice(0, 13).forEach((f, idx) => {
    const service = f.app_hint ? `${f.app_hint}*` : f.service;
    lines.push(row([
      String(idx + 1).padStart(2, "0"),
      f.asset,
      f.peer,
      f.proto,
      service,
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
  const divisor = tickerMode === "fast" ? 8 : tickerMode === "normal" ? 5.8 : 3.8;
  const floor = tickerMode === "fast" ? 12 : tickerMode === "normal" ? 20 : 26;
  const ceiling = tickerMode === "fast" ? 54 : tickerMode === "normal" ? 82 : 110;
  const duration = Math.max(floor, Math.min(ceiling, text.length / divisor));
  document.documentElement.style.setProperty("--ticker-duration", `${duration}s`);
  ticker.style.animation = "none";
  ticker.offsetHeight;
  ticker.style.animation = "";
}

function applyWallPrefs() {
  document.body.classList.toggle("big-wall", localStorage.getItem("socxBigWall") === "1");
  const button = $("ticker-speed");
  if (button) button.textContent = tickerMode;
}

function render(state) {
  if (!state) return;
  latestState = state;
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
  renderThreatPulse(state.threat_pulse || {});
  renderAssetWatch(state.asset_watch || {});
  renderAiTimeline(state.ai_timeline || {});
  renderIncident(state.incident || {});
  renderIncidentTimeline(state.incident_timeline || {});
  renderDailyBrief(state.daily_brief || {});
  renderRuleAssistant(state.rule_assistant || {});
  renderFlows(state.flows || []);
  renderPackets(state.packets || []);
  renderTicker(state.events || []);
}

function commanderActionFromSpeech(text) {
  const value = String(text || "").toLowerCase();
  if (/(speed|bandwidth|test)/.test(value)) return "speedtest";
  if (/(incident|watch|investigate)/.test(value)) return "incident";
  if (/(snapshot|evidence|capture)/.test(value)) return "snapshot";
  if (/(zeek|logs?)/.test(value)) return "zeek";
  if (/(pi|raspberry|ai|llm)/.test(value)) return "pi";
  if (/(explain|why|summary|plain english)/.test(value)) return "explain";
  if (/(brief|daily|morning)/.test(value)) return "brief";
  if (/(timeline|story|history)/.test(value)) return "timeline";
  if (/(rule|draft|recommend)/.test(value)) return "rules";
  if (/(status|health|doctor)/.test(value)) return "status";
  return "";
}

function runVoiceCommand() {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  const output = $("command-output");
  const title = $("command-output-title");
  const body = $("command-output-body");
  if (output) output.hidden = false;
  if (!SpeechRecognition) {
    if (title) title.textContent = "Voice unavailable";
    if (body) body.textContent = "This browser does not expose speech recognition here. Use the Commander buttons instead.";
    return;
  }
  const recognition = new SpeechRecognition();
  recognition.lang = "en-US";
  recognition.interimResults = false;
  recognition.maxAlternatives = 1;
  if (title) title.textContent = "Listening";
  if (body) body.textContent = "Say: status, incident, snapshot, speed test, Zeek, Pi AI, brief, timeline, rules, or explain.";
  recognition.onresult = (event) => {
    const transcript = event.results?.[0]?.[0]?.transcript || "";
    const action = commanderActionFromSpeech(transcript);
    if (!action) {
      if (title) title.textContent = "Voice command not mapped";
    if (body) body.textContent = `Heard: ${transcript}\nAllowed: status, incident, snapshot, speedtest, zeek, pi, explain, brief, timeline, rules, doctor.`;
      return;
    }
    runCommander(action);
  };
  recognition.onerror = (event) => {
    if (title) title.textContent = "Voice error";
    if (body) body.textContent = event.error || "Speech recognition failed.";
  };
  recognition.start();
}

async function runCommander(action) {
  if (action === "voice") {
    runVoiceCommand();
    return;
  }
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

$("ticker-speed")?.addEventListener("click", () => {
  tickerMode = tickerMode === "slow" ? "normal" : tickerMode === "normal" ? "fast" : "slow";
  localStorage.setItem("socxTickerMode", tickerMode);
  lastTickerText = "";
  applyWallPrefs();
  renderTicker(latestState?.events || []);
});

$("big-mode")?.addEventListener("click", () => {
  const next = localStorage.getItem("socxBigWall") === "1" ? "0" : "1";
  localStorage.setItem("socxBigWall", next);
  applyWallPrefs();
});

document.querySelectorAll(".commander-buttons button").forEach((button) => {
  button.addEventListener("click", () => runCommander(button.dataset.action || ""));
});

$("command-output-close")?.addEventListener("click", () => {
  const output = $("command-output");
  if (output) output.hidden = true;
});

setInterval(updateClock, 500);
setInterval(() => drawCluster($("cluster-canvas"), latestState?.pi_nodes?.nodes || []), 650);
applyWallPrefs();
updateClock();
connect();
poll();
