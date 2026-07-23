const $ = (id) => document.getElementById(id);

let lastTickerText = "";
let pollingTimer = null;
let latestState = null;
let clusterTick = 0;
let tickerMode = localStorage.getItem("socxTickerMode") || "slow";
let chatBusy = false;
let tickerLastSwap = 0;

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

function whyButton(question, label = "Why?") {
  return `<button class="why-mini" data-question="${escapeHtml(question)}" title="${escapeHtml(question)}">${escapeHtml(label)}</button>`;
}

function explainRow(question) {
  const input = $("chat-input");
  if (input) input.value = question;
  runOperatorChat(question);
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
      ["why", whyButton("Explain the Incident Cockpit. What are the top firewall, DNSBL, IDS, LAN, and repeated-memory signals, and do I need to investigate?", "Why?")],
      ["FW", counts.sources || 0],
      ["DNSBL", counts.dnsbl || 0],
      ["IDS sig", ids.signal || counts.ids_high || 0],
      ["IDS watch", ids.watch || 0],
      ["routine", ids.routine || 0],
      ["LAN", counts.lan || 0],
    ].map(([label, value]) => label === "why" ? `<div class="mini-action-cell">${value}</div>` : `<div><span>${escapeHtml(label)}</span><b>${escapeHtml(value)}</b></div>`).join("");
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
  const sections = Array.isArray(brief.sections) ? brief.sections : [];
  const next = Array.isArray(brief.next) ? brief.next : [];
  const statusClass = brief.status === "watch" ? "yellow" : "green";
  root.innerHTML = `
    <div class="brief-title"><span>${escapeHtml(brief.title || "SOCX Brief")}</span><b class="${statusClass}">${escapeHtml(brief.status || "normal")}</b></div>
    ${brief.headline ? `<div class="brief-headline ${statusClass}">${escapeHtml(brief.headline)}</div>` : ""}
    ${sections.length ? `<div class="brief-sections">${sections.slice(0, 5).map((item) => `
      <div class="brief-section">
        <b class="${escapeHtml(item.tone || "cyan")}">${escapeHtml(item.label || "signal")}</b>
        <span title="${escapeHtml(item.value || "")}">${escapeHtml(item.value || "--")}</span>
      </div>
    `).join("")}</div>` : summary.slice(0, 4).map((line) => `<div class="brief-line">${escapeHtml(line)}</div>`).join("")}
    ${next.length ? `<div class="brief-next">${next.slice(0, 2).map(escapeHtml).join(" | ")}</div>` : ""}
  `;
}

function renderSpeedtestHistory(history = {}) {
  const root = $("speed-history");
  if (!root) return;
  const paths = Array.isArray(history.paths) ? history.paths : [];
  root.innerHTML = paths.slice(0, 4).map((item) => {
    const cls = item.status === "stable" ? "green" : item.status === "watch" ? "yellow" : "cyan";
    return `
      <div class="speed-row">
        <b class="${cls}">${escapeHtml(String(item.path || "path").toUpperCase())}</b>
        <span>${escapeHtml(safe(item.avg_down, "--"))}↓/${escapeHtml(safe(item.avg_up, "--"))}↑ avg</span>
        <em>best ${escapeHtml(safe(item.best_down, "--"))} worst ${escapeHtml(safe(item.worst_down, "--"))}</em>
      </div>
    `;
  }).join("") || `<div class="speed-row"><b class="cyan">SPD</b><span>waiting for history</span><em>run speedtest</em></div>`;
}

function renderObservability(obs = {}, intel = {}, autonomy = {}) {
  const root = $("observability");
  if (!root) return;
  const intelSeverity = String(intel.severity || "").toUpperCase();
  const intelTone = intelSeverity === "OK" ? "green" : intelSeverity === "CRITICAL" ? "red" : intelSeverity ? "yellow" : "";
  const tone = intelTone || obs.tone || (obs.label === "LIVE" ? "green" : obs.label === "DOWN" ? "red" : "yellow");
  const latest = obs.latest || {};
  const cpu = latest.cpu || {};
  const mem = latest.mem || {};
  const pf = latest.pf || {};
  const ping = latest.ping || {};
  const cpuBusy = Number(cpu.last || 0) + Number(cpu.last_1 || 0);
  const bits = [
    `<b class="${escapeHtml(tone)}">${escapeHtml(obs.label || "METRICS")}</b>`,
    `<span>${escapeHtml(obs.host || "Pi metrics")}</span>`,
    `<span>${escapeHtml((obs.core_measurements || []).length || 0)}/6 series</span>`,
    cpuBusy ? `<span>cpu ${escapeHtml(cpuBusy.toFixed(0))}%</span>` : "",
    mem.last !== undefined ? `<span>mem ${escapeHtml(Number(mem.last || 0).toFixed(0))}%</span>` : "",
    pf.last !== undefined ? `<span>pf ${escapeHtml(Number(pf.last || 0).toFixed(0))}</span>` : "",
    ping.last !== undefined ? `<span>ping ${escapeHtml(Number(ping.last || 0).toFixed(1))}ms</span>` : "",
    intel.severity ? `<span title="${escapeHtml(intel.summary || "")}">intel ${escapeHtml(intel.severity)}</span>` : "",
    autonomy.status ? `<span class="${escapeHtml(autonomy.tone || "cyan")}" title="${escapeHtml(autonomy.summary || "")}">auto ${escapeHtml(autonomy.status)}${autonomy.age_sec !== null && autonomy.age_sec !== undefined ? ` ${escapeHtml(String(autonomy.age_sec))}s` : ""}</span>` : "",
    obs.grafana_url ? `<a href="${escapeHtml(obs.grafana_url)}" target="_blank" rel="noreferrer">Grafana</a>` : "",
  ].filter(Boolean);
  root.innerHTML = bits.join("");
}

function renderPowerMods(power = {}) {
  const root = $("power-mods");
  if (!root) return;
  const items = power.items || {};
  const labels = [
    ["flow_export", "Flow"],
    ["suricata_eve", "IDS EVE"],
    ["lldp", "LLDP"],
    ["config_drift", "Drift"],
    ["evidence_vault", "Vault"],
    ["quarantine_draft", "Q Draft"],
  ];
  const toneFor = (status) => {
    const value = String(status || "").toUpperCase();
    if (["OK", "LIVE", "READY"].includes(value)) return "green";
    if (["WARN", "WATCH", "PLAN", "STOPPED", "DOWN"].includes(value)) return "yellow";
    if (["CRITICAL", "FAIL", "ERROR"].includes(value)) return "red";
    return "cyan";
  };
  root.innerHTML = `
    <div class="power-head">
      <span>Power Mods</span>
      <b class="${escapeHtml(toneFor(power.status))}" title="${escapeHtml(power.summary || "")}">${escapeHtml(power.status || "WAITING")}</b>
    </div>
    <div class="power-grid">
      ${labels.map(([key, label]) => {
        const item = items[key] || {};
        const status = String(item.status || "WAIT").toUpperCase();
        const age = item.age_sec === null || item.age_sec === undefined ? "" : `${item.age_sec}s`;
        return `<div class="power-chip ${escapeHtml(toneFor(status))}" title="${escapeHtml(item.summary || "")}">
          <span>${escapeHtml(label)}</span>
          <b>${escapeHtml(status)}</b>
          <em>${escapeHtml(age)}</em>
        </div>`;
      }).join("")}
    </div>
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

function renderIncidentMemory(memory = {}) {
  const root = $("incident-memory");
  if (!root) return;
  const src = (memory.sources || [])[0] || {};
  const port = (memory.ports || [])[0] || {};
  const dns = (memory.dnsbl || [])[0] || {};
  root.innerHTML = `
    <div class="memory-row"><b>MEM</b><span>source ${escapeHtml(src.name || "--")} x${escapeHtml(src.count || 0)}</span><em>${escapeHtml(memory.status || "waiting")}</em></div>
    <div class="memory-row"><b>PORT</b><span>${escapeHtml(port.name || "--")} x${escapeHtml(port.count || 0)}</span><em>IDS ${escapeHtml(memory.ids_high_samples || 0)}</em></div>
    <div class="memory-row"><b>DNS</b><span title="${escapeHtml(dns.name || "")}">${escapeHtml(dns.name || "--")} x${escapeHtml(dns.count || 0)}</span><em>${escapeHtml(memory.count || 0)} samples</em></div>
  `;
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
    `<div class="mini-action-row">${whyButton("Why is SOCX showing this Threat Pulse state? Explain firewall blocks, DNSBL, IDS high/watch, top source, top port, what is probably noise, and what I should check next.", "Why Watch")}</div>`,
    metricBox("FW blocks", safe(pulse.fw_blocks, 0), pulse.fw_blocks > 120 ? "red" : pulse.fw_blocks > 0 ? "yellow" : "green"),
    metricBox("DNSBL", safe(pulse.dnsbl_hits, 0), pulse.dnsbl_hits > 250 ? "yellow" : "cyan"),
    metricBox("IDS high", safe(pulse.ids_high, 0), pulse.ids_high > 0 ? "red" : "green"),
    metricBox("IDS watch", safe(pulse.ids_watch, 0), pulse.ids_watch > 200 ? "yellow" : "cyan"),
    metricBox("Top port", `${safe(pulse.top_port, "--")} x${safe(pulse.top_port_count, 0)}`, "cyan"),
    metricBox("Top source", `${safe(pulse.top_source, "--")} x${safe(pulse.top_source_count, 0)}`, "purple"),
  ].join("");
}

function renderDataTruth(truth = {}) {
  const root = $("data-truth");
  const state = $("truth-state");
  const signals = Array.isArray(truth.signals) ? truth.signals : [];
  if (state) {
    state.textContent = `${safe(truth.label, "--")} ${safe(truth.score, "--")}`;
    state.className = truth.tone || "";
  }
  if (!root) return;
  const priority = [...signals].sort((a, b) => {
    const rank = { red: 0, yellow: 1, cyan: 2, green: 3 };
    return (rank[a.tone] ?? 4) - (rank[b.tone] ?? 4);
  }).slice(0, 5);
  root.innerHTML = [
    `<div class="mini-action-row">${whyButton("Explain SOCX Data Truth. Which collectors are fresh, stale, unknown, or unreliable, and should I trust the wall right now?", "Why Truth")}</div>`,
    `<div class="truth-score ${escapeHtml(truth.tone || "cyan")}">${escapeHtml(truth.label || "UNKNOWN")} <b>${escapeHtml(truth.score ?? "--")}</b></div>`,
    `<div class="truth-reason" title="${escapeHtml(truth.reason || "")}">${escapeHtml(truth.reason || "waiting for collector freshness")}</div>`,
    ...priority.map((item) => `
      <div class="truth-row">
        <span>${escapeHtml(item.name || "--")}</span>
        <b class="${escapeHtml(item.tone || "cyan")}">${escapeHtml(item.state || "--")}</b>
        <em title="${escapeHtml(item.detail || item.source || "")}">${escapeHtml(item.age_h || "--")}</em>
      </div>
    `),
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
    `<div class="mini-action-row">${whyButton("Explain LAN Asset Watch. What are the top devices/apps, unknowns, Pi nodes, and any unusual device changes I should fix?", "Why Assets")}</div>`,
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

function renderNetworkTalkers(state = {}) {
  const root = $("network-talkers");
  if (!root) return;
  const nf = state.netflow_intel || {};
  const assets = Array.isArray(nf.top_assets) ? nf.top_assets.slice(0, 2) : [];
  const apps = Array.isArray(nf.top_apps) ? nf.top_apps.slice(0, 2) : [];
  const flow = (state.flows || [])[0] || {};
  const parts = [];
  if (assets[0]) parts.push(`top ${assets[0].name || "--"} ${assets[0].bytes_h || assets[0].count || ""}`.trim());
  if (assets[1]) parts.push(`${assets[1].name || "--"} ${assets[1].bytes_h || assets[1].count || ""}`.trim());
  if (apps[0]) parts.push(`app ${apps[0].name || "--"}`);
  if (!parts.length && flow.asset) parts.push(`${flow.asset} -> ${flow.peer || "--"} ${flow.service || flow.app || ""}`.trim());
  const question = "Who is using bandwidth right now? Explain the current Network card, top upload/download clients, top apps, and whether any flow is unusual.";
  root.innerHTML = `<span title="${escapeHtml(parts.join(" | ") || "Flow Truth warming up")}">${escapeHtml(parts.join(" | ") || "top talkers learning")}</span>${whyButton(question, "Who?")}`;
}

function renderAiTimeline(ai = {}) {
  const root = $("ai-timeline");
  const state = $("ai-state");
  const rows = Array.isArray(ai.rows) ? ai.rows : [];
  if (state) state.textContent = `${rows.length} signals`;
  if (!root) return;
  root.innerHTML = `<div class="mini-action-row">${whyButton("Explain the AI Verdict Timeline. What are the Pi and MIRANDA models saying, how confident are they, and what should I trust?", "Why AI")}</div>` + rows.slice(0, 4).map((item) => {
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
    const cells = [
      String(idx + 1).padStart(2, "0"),
      f.asset,
      f.peer,
      f.proto,
      service,
      `${f.tag || "state"} x${f.count || 1}`,
    ];
    const question = `Explain this network flow: asset ${safe(f.asset)} peer ${safe(f.peer)} protocol ${safe(f.proto)} service ${safe(service)} tag ${safe(f.tag)} count ${safe(f.count, 1)}. Is it normal and what should I check?`;
    lines.push(`<div class="row explainable" data-question="${escapeHtml(question)}" title="Click to explain this flow">${cells.map((cell) => `<span title="${escapeHtml(cell)}">${escapeHtml(cell)}</span>`).join("")}</div>`);
  });
  root.innerHTML = lines.join("");
}

function renderPackets(packets = []) {
  const root = $("packets");
  if (!root) return;
  const lines = [row(["time", "act", "proto", "source", "dest", "svc", "info"], "header")];
  packets.slice(0, 13).forEach((p) => {
    const severity = p.severity === "HIGH" ? "red" : p.action === "BLOCK" ? "yellow" : "green";
    const question = `Explain this packet story: time ${safe(p.time)} action ${safe(p.action)} protocol ${safe(p.proto)} source ${safe(p.src_label)} destination ${safe(p.dst_label)} service ${safe(p.service)} info ${safe(p.info)}. Is it expected, blocked, or suspicious?`;
    lines.push(`
      <div class="row explainable" data-question="${escapeHtml(question)}" title="Click to explain this packet">
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
  const now = Date.now();
  const minSwapMs = tickerMode === "fast" ? 18000 : tickerMode === "normal" ? 32000 : 52000;
  if (lastTickerText && now - tickerLastSwap < minSwapMs) return;
  lastTickerText = text;
  tickerLastSwap = now;
  ticker.innerHTML = `<span>${escapeHtml(text)}</span><span aria-hidden="true">${escapeHtml(text)}</span>`;
  const divisor = tickerMode === "fast" ? 2.55 : tickerMode === "normal" ? 1.65 : 1.05;
  const floor = tickerMode === "fast" ? 60 : tickerMode === "normal" ? 110 : 175;
  const ceiling = tickerMode === "fast" ? 140 : tickerMode === "normal" ? 260 : 420;
  const duration = Math.max(floor, Math.min(ceiling, text.length / divisor));
  document.documentElement.style.setProperty("--ticker-duration", `${duration}s`);
}

function isAutoNightNow(now = new Date()) {
  const hour = now.getHours();
  return hour >= 20 || hour < 8;
}

function nightModeState() {
  const mode = localStorage.getItem("socxNightWallMode") || "auto";
  if (mode === "on") return { mode, enabled: true };
  if (mode === "off") return { mode, enabled: false };
  return { mode: "auto", enabled: isAutoNightNow() };
}

function applyWallPrefs() {
  const night = nightModeState();
  document.body.classList.toggle("big-wall", localStorage.getItem("socxBigWall") === "1");
  document.body.classList.toggle("night-wall", night.enabled);
  const button = $("ticker-speed");
  if (button) button.textContent = tickerMode;
  const nightButton = $("night-mode");
  if (nightButton) {
    nightButton.textContent = night.mode === "auto" ? (night.enabled ? "auto night" : "auto day") : `night ${night.mode}`;
    nightButton.title = "Night mode: auto 8 PM-8 AM. Click for on/off/auto.";
    nightButton.setAttribute("aria-label", nightButton.title);
  }
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
  renderNetworkTalkers(state);

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
  renderDataTruth(state.data_truth || {});
  renderAssetWatch(state.asset_watch || {});
  renderAiTimeline(state.ai_timeline || {});
  renderIncident(state.incident || {});
  renderIncidentTimeline(state.incident_timeline || {});
  renderDailyBrief(state.daily_brief || {});
  renderRuleAssistant(state.rule_assistant || {});
  renderObservability(state.observability || {}, state.metrics_intel || {}, state.autonomy_loop || {});
  renderPowerMods(state.power_mods || {});
  renderSpeedtestHistory(state.speedtest_history || {});
  renderIncidentMemory(state.incident_memory || {});
  renderFlows(state.flows || []);
  renderPackets(state.packets || []);
  renderTicker(state.events || []);
}

function commanderActionFromSpeech(text) {
  const value = String(text || "").toLowerCase();
  if (/(speed|bandwidth|test)/.test(value)) return "speedtest";
  if (/(incident|watch|investigate)/.test(value)) return "incident";
  if (/(bundle|preserve|package)/.test(value)) return "bundle";
  if (/(snapshot|evidence|capture)/.test(value)) return "snapshot";
  if (/(vault|preserve evidence|evidence first)/.test(value)) return "vault";
  if (/(drift|config backup|config change)/.test(value)) return "drift";
  if (/(suricata|eve|ids json)/.test(value)) return "eve";
  if (/(flow export|netflow|ipfix|softflow)/.test(value)) return "flow-export";
  if (/(lldp|topology|neighbor)/.test(value)) return "topology";
  if (/(quarantine|containment)/.test(value)) return "quarantine-draft";
  if (/(zeek|logs?)/.test(value)) return "zeek";
  if (/(metrics ai|metric narrator|grafana ai)/.test(value)) return "metrics-ai";
  if (/(why metrics|metrics why|metric intelligence|metrics intel)/.test(value)) return "metrics-intel";
  if (/(grafana|influx|telegraf|metrics|observability)/.test(value)) return "observability";
  if (/(autonomy loop|brainstem|autonomous|autonomy)/.test(value)) return "autonomy";
  if (/(model tournament|tournament|pi compare|model compare|compare models)/.test(value)) return "model-tournament";
  if (/(pi bench|role benchmark|benchmark pi)/.test(value)) return "pi-bench";
  if (/(pi|raspberry|ai|llm)/.test(value)) return "pi";
  if (/(explain|why|summary|plain english)/.test(value)) return "explain";
  if (/(cockpit|operator cockpit|mobile view|tablet view)/.test(value)) {
    location.href = "/cockpit";
    return "";
  }
  if (/(threat story|story threat|what matters|probably noise)/.test(value)) {
    location.href = "/threat-story";
    return "";
  }
  if (/(story|daily story|today so far)/.test(value)) return "story";
  if (/(brief|daily|morning)/.test(value)) return "brief";
  if (/(timeline|history)/.test(value)) return "timeline";
  if (/(rule|draft|recommend)/.test(value)) return "rules";
  if (/(speed history|bandwidth history|speed trend)/.test(value)) return "speed-history";
  if (/(memory|repeat|repeated|baseline)/.test(value)) return "memory";
  if (/(lab|experiment|pulse|benchmark)/.test(value)) return "lab";
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
  if (body) body.textContent = "Say: status, incident, bundle, snapshot, speed test, Zeek, Pi AI, story, brief, timeline, rules, memory, lab, or explain.";
  recognition.onresult = (event) => {
    const transcript = event.results?.[0]?.[0]?.transcript || "";
    const action = commanderActionFromSpeech(transcript);
    if (!action) {
      if (title) title.textContent = "Voice command not mapped";
    if (body) body.textContent = `Heard: ${transcript}\nAllowed: status, incident, bundle, vault, drift, eve, flow, topology, quarantine, speedtest, zeek, pi, explain, story, brief, timeline, rules, doctor, speed-history, memory, lab.`;
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

async function runOperatorChat() {
  if (chatBusy) return;
  const input = $("chat-input");
  const output = $("chat-output");
  const mode = $("chat-mode");
  const button = $("chat-send");
  const question = String(arguments[0] || input?.value || "").trim();
  if (!question) {
    if (output) output.textContent = "Ask me something like: why is DNSBL high, explain IDS, diagnose VPN, or draft a block plan for this host.";
    return;
  }
  chatBusy = true;
  if (button) button.disabled = true;
  if (mode) {
    mode.textContent = "thinking";
    mode.className = "plan";
  }
  if (output) output.textContent = "Collecting pfSense telemetry...\nAsking SOCX/Pi LLM if available...";
  try {
    const res = await fetch("/api/chat", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ question }),
    });
    const data = await res.json();
    const label = String(data.mode || "ANSWER").toLowerCase();
    if (mode) {
      mode.textContent = `${data.mode || "ANSWER"}${data.pi?.ok ? " + PI" : ""}`;
      mode.className = label.includes("denied") ? "denied" : label.includes("plan") ? "plan" : "answer";
    }
    const phases = Array.isArray(data.phases) && data.phases.length ? `\n\nSteps: ${data.phases.join(" -> ")}` : "";
    const confidence = data.confidence ? `\n\nConfidence: ${data.confidence.label || "--"} ${data.confidence.score ?? "--"}/100` : "";
    const evidence = Array.isArray(data.evidence_used) && data.evidence_used.length ? `\nEvidence: ${data.evidence_used.map((e) => `${e.source} ${e.strength}`).join(" | ")}` : "";
    const queue = Array.isArray(data.safe_action_queue) && data.safe_action_queue.length ? `\nNext: ${data.safe_action_queue.slice(0, 3).map((a) => `${a.priority} ${a.action}`).join(" | ")}` : "";
    const commands = Array.isArray(data.safe_commands) && data.safe_commands.length ? `\n\nUseful: ${data.safe_commands.join(" | ")}` : "";
    if (output) output.textContent = `${data.answer || "No answer returned."}${confidence}${evidence}${queue}${phases}${commands}`;
    renderChatCards(data.action_cards || []);
  } catch (err) {
    if (mode) {
      mode.textContent = "error";
      mode.className = "denied";
    }
    if (output) output.textContent = `Chat service unavailable: ${err}`;
    renderChatCards([]);
  } finally {
    chatBusy = false;
    if (button) button.disabled = false;
  }
}

function renderChatCards(cards = []) {
  const root = $("chat-cards");
  if (!root) return;
  root.innerHTML = cards.slice(0, 3).map((card) => `
    <div class="chat-card ${escapeHtml(card.tone || "cyan")}">
      <b>${escapeHtml(card.title || "SOCX")}</b>
      <span>${escapeHtml(card.status || "READY")}</span>
      <em title="${escapeHtml(card.detail || "")}">${escapeHtml(card.detail || "--")}</em>
      ${card.command ? `<code>${escapeHtml(card.command)}</code>` : ""}
    </div>
  `).join("");
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
  tickerLastSwap = 0;
  applyWallPrefs();
  renderTicker(latestState?.events || []);
});

$("big-mode")?.addEventListener("click", () => {
  const next = localStorage.getItem("socxBigWall") === "1" ? "0" : "1";
  localStorage.setItem("socxBigWall", next);
  applyWallPrefs();
});

$("night-mode")?.addEventListener("click", () => {
  const current = localStorage.getItem("socxNightWallMode") || "auto";
  const next = current === "auto" ? "on" : current === "on" ? "off" : "auto";
  localStorage.setItem("socxNightWallMode", next);
  localStorage.removeItem("socxNightWall");
  if (nightModeState().enabled && tickerMode === "fast") {
    tickerMode = "slow";
    localStorage.setItem("socxTickerMode", tickerMode);
  }
  lastTickerText = "";
  tickerLastSwap = 0;
  applyWallPrefs();
  renderTicker(latestState?.events || []);
});

$("chat-send")?.addEventListener("click", runOperatorChat);
$("chat-input")?.addEventListener("keydown", (event) => {
  if (event.key === "Enter") runOperatorChat();
});

document.querySelectorAll(".chat-suggestions button").forEach((button) => {
  button.addEventListener("click", () => {
    const question = button.dataset.question || button.textContent || "";
    const input = $("chat-input");
    if (input) input.value = question;
    runOperatorChat(question);
  });
});

document.querySelectorAll(".commander-buttons button").forEach((button) => {
  button.addEventListener("click", () => runCommander(button.dataset.action || ""));
});

document.addEventListener("click", (event) => {
  const row = event.target.closest(".explainable, .why-mini");
  if (!row) return;
  const question = row.dataset.question || row.getAttribute("title") || "";
  if (question) explainRow(question);
});

$("command-output-close")?.addEventListener("click", () => {
  const output = $("command-output");
  if (output) output.hidden = true;
});

setInterval(updateClock, 500);
setInterval(applyWallPrefs, 60000);
setInterval(() => drawCluster($("cluster-canvas"), latestState?.pi_nodes?.nodes || []), 650);
applyWallPrefs();
updateClock();
connect();
poll();
