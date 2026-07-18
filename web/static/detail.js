const root = document.getElementById("detail-grid");
const title = document.getElementById("detail-title");
const subtitle = document.getElementById("detail-subtitle");
let whyTarget = new URLSearchParams(location.search).get("target") || "";
let bundleStatus = "";

const esc = (value) => String(value ?? "--").replace(/[&<>"']/g, (c) => ({
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#39;",
}[c]));

const pageName = () => {
  const path = location.pathname.replace(/^\/+/, "").toLowerCase();
  if (path.includes("device")) return "devices";
  if (path.includes("incident")) return "incidents";
  if (path.includes("why")) return "why";
  if (path.includes("ai")) return "ai";
  if (path.includes("health")) return "health";
  return "speedtest";
};

function card(label, body, tone = "") {
  return `<section class="detail-card ${tone}"><h2>${esc(label)}</h2>${body}</section>`;
}

async function buildIncidentBundle() {
  bundleStatus = "Building incident bundle...";
  await refresh();
  try {
    const result = await fetch("/api/incident-bundle", { method: "POST" }).then((r) => r.json());
    bundleStatus = `${result.ok ? "Bundle ready" : "Bundle warning"} ${result.latest || result.archive || ""}\n${result.output || ""}`;
  } catch (err) {
    bundleStatus = `Bundle error: ${err}`;
  }
  await refresh();
}

function wireWhyControls() {
  const input = document.getElementById("why-target");
  const run = document.getElementById("why-run");
  const bundle = document.getElementById("why-bundle");
  const setTarget = () => {
    whyTarget = input ? input.value.trim() : whyTarget;
    const url = new URL(location.href);
    if (whyTarget) url.searchParams.set("target", whyTarget);
    else url.searchParams.delete("target");
    history.replaceState(null, "", url);
    refresh();
  };
  run?.addEventListener("click", setTarget);
  input?.addEventListener("keydown", (event) => {
    if (event.key === "Enter") setTarget();
  });
  bundle?.addEventListener("click", buildIncidentBundle);
  document.querySelectorAll("[data-why-target]").forEach((button) => {
    button.addEventListener("click", () => {
      whyTarget = button.getAttribute("data-why-target") || "";
      const url = new URL(location.href);
      url.searchParams.set("target", whyTarget);
      history.replaceState(null, "", url);
      refresh();
    });
  });
}

function renderWhy(state, why) {
  title.textContent = "SOCX WHY BLOCKED";
  const candidates = why.candidates || [];
  const matches = why.matches || [];
  const command = why.command || {};
  const candidateRows = candidates.map((c) => [c.type, c.target, c.count]);
  const candidateButtons = candidates.slice(0, 8).map((c) => `<button class="why-target-button" data-why-target="${esc(c.target)}">${esc(c.type)} ${esc(c.target)} x${esc(c.count)}</button>`).join("");
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Ask Why", [
      `<div class="why-controls"><input id="why-target" value="${esc(why.target || whyTarget || "")}" placeholder="IP, domain, or port"><button id="why-run">Analyze</button><button id="why-bundle">Build Incident Bundle</button></div>`,
      `<div class="detail-reason">${esc(why.verdict || "waiting")} - ${esc(why.next_step || "")}</div>`,
      bundleStatus ? `<pre class="why-output">${esc(bundleStatus)}</pre>` : "",
    ].join("")),
    card("Evidence Summary", [
      kv("Target", why.target || "--", "cyan"),
      kv("Source Hits", why.counts?.source || 0, Number(why.counts?.source || 0) ? "yellow" : "cyan"),
      kv("Port Hits", why.counts?.port || 0, Number(why.counts?.port || 0) ? "yellow" : "cyan"),
      kv("DNSBL Hits", why.counts?.domain || 0, Number(why.counts?.domain || 0) ? "purple" : "cyan"),
      kv("Packet Rows", why.counts?.packet_matches || 0, Number(why.counts?.packet_matches || 0) ? "yellow" : "cyan"),
    ].join("")),
    card("Live Candidates", table(["Type", "Target", "Count"], candidateRows)),
    card("Quick Targets", `<div class="why-targets">${candidateButtons || "<span class=\"muted\">No recent candidates</span>"}</div>`),
    card("Recent Matching Packets", table(["Time", "Action", "Dir", "Source", "Destination", "Port", "Service"], matches.map((m) => [
      m.time,
      m.action,
      m.direction,
      m.source,
      m.destination,
      m.port,
      m.service,
    ]))),
    card("Terminal Evidence", `<pre class="why-output">${esc(command.output || "No terminal evidence returned.")}</pre>`),
  ].join("");
  wireWhyControls();
}

function kv(label, value, tone = "") {
  return `<div class="detail-kv"><span>${esc(label)}</span><b class="${tone}">${esc(value)}</b></div>`;
}

function table(headers, rows) {
  const cols = Math.max(1, headers.length);
  const style = `style="grid-template-columns: repeat(${cols}, minmax(0, 1fr))"`;
  const bodyRows = rows.length ? rows : [headers.map(() => "--")];
  return `<div class="detail-table"><div class="detail-row head" ${style}>${headers.map((h) => `<span>${esc(h)}</span>`).join("")}</div>${bodyRows.map((row) => `<div class="detail-row" ${style}>${row.map((cell) => `<span title="${esc(cell)}">${esc(cell)}</span>`).join("")}</div>`).join("")}</div>`;
}

function pill(value, tone = "cyan") {
  return `<span class="detail-pill ${tone}">${esc(value)}</span>`;
}

function renderTruthCards(state) {
  const truth = state.data_truth || {};
  const changed = state.what_changed || {};
  return [
    card("Data Truth", [
      `<div class="detail-score ${truth.tone || "cyan"}">${esc(truth.label || "UNKNOWN")} ${esc(truth.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(truth.reason || "waiting for collector freshness")}</div>`,
      table(["Signal", "State", "Age", "Source", "Detail"], (truth.signals || []).map((s) => [
        s.name,
        `${s.state || "--"}`,
        s.age_h || "--",
        s.source || "--",
        s.detail || "--",
      ])),
    ].join("")),
    card("What Changed", table(["Time", "Severity", "Change", "Detail"], (changed.rows || []).map((r) => [
      r.time || "--",
      r.severity || "--",
      r.title || "--",
      r.detail || "--",
    ]))),
  ];
}

function renderHealth(state, health) {
  title.textContent = "SOCX RELEASE HEALTH";
  const truth = health.data_truth || state.data_truth || {};
  const pi = health.pi_nodes || state.pi_nodes || {};
  const checks = health.checks || [];
  const wall = health.wall_error || {};
  const roleRows = [];
  (pi.nodes || []).forEach((node) => {
    (node.role_details || []).forEach((role) => {
      roleRows.push([
        node.name || node.ip || "--",
        role.role || "--",
        role.state || "--",
        role.model || "--",
        role.summary || node.autonomy_summary || "--",
      ]);
    });
  });
  root.innerHTML = [
    ...renderTruthCards({ ...state, data_truth: truth, what_changed: state.what_changed || {} }),
    card("Release Gate", [
      kv("Hostname", health.hostname || state.hostname || "--", "cyan"),
      kv("API", health.versions?.api || "--", "green"),
      kv("Refresh", `${health.versions?.refresh_ms || state.status?.refresh_ms || "--"}ms`, "cyan"),
      kv("Wall Errors", `${wall.bytes ?? "--"} bytes`, wall.clean ? "green" : "red"),
      kv("Data Reason", truth.reason || "--", truth.tone || "cyan"),
    ].join("")),
    card("Checks", table(["Check", "State", "Elapsed", "Output"], checks.map((c) => [
      c.name,
      c.ok ? "OK" : "WARN",
      `${c.elapsed_ms ?? "--"}ms`,
      c.output || "--",
    ]))),
    card("Pi Role Detail", table(["Node", "Role", "State", "Model", "Visible Summary"], roleRows)),
    card("Pi Fleet", table(["Name", "IP", "Role", "Temp", "Load", "Memory", "Service"], (pi.nodes || []).map((n) => [
      n.name,
      n.ip,
      n.role_h || n.role,
      n.temperature_h,
      n.load_h,
      n.memory_h,
      n.service_h || n.service,
    ]))),
  ].join("");
}

function renderSpeed(state) {
  title.textContent = "SOCX SPEEDTEST";
  const center = state.command_center || {};
  const hist = state.speedtest_history || {};
  const paths = hist.paths || [];
  const pathRows = paths.map((p) => [String(p.path || "").toUpperCase(), p.status, `${p.avg_down || "--"}/${p.avg_up || "--"}`, `${p.best_down || "--"}/${p.worst_down || "--"}`, `${p.avg_ping || "--"}ms`, `${p.ok || 0}/${p.samples || 0}`]);
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Current Paths", [
      kv("Active", `${center.router?.down || "--"}/${center.router?.up || "--"} Mbps ${center.router?.ping || "--"}ms`, center.router?.path_state === "ready" ? "green" : "yellow"),
      kv("Direct", `${center.direct?.down || "--"}/${center.direct?.up || "--"} Mbps ${center.direct?.ping || "--"}ms`, center.direct?.path_state === "ready" ? "green" : "yellow"),
      kv("VPN", `${center.vpn?.path_state || center.vpn?.status || "WAIT"} ${center.vpn?.down || "--"}/${center.vpn?.up || "--"}`, center.vpn?.path_state === "ready" ? "green" : "yellow"),
      kv("Truth", center.speed_truth?.label || "waiting", "cyan"),
    ].join("")),
    card("History", table(["Path", "State", "Avg", "Best/Worst", "Ping", "OK/Samples"], pathRows)),
    card("Named VPN Paths", table(["Path", "Status", "Down/Up", "Ping", "Age", "Message"], (center.vpn_paths || []).map((p) => [p.label, p.path_state || p.status, `${p.down || "--"}/${p.up || "--"}`, `${p.ping || "--"}ms`, `${Math.floor((p.age_sec || 0) / 60)}m`, p.message || p.external_ip || "--"]))),
  ].join("");
}

function renderDevices(state) {
  title.textContent = "SOCX DEVICES";
  const brain = state.label_brain || {};
  const asset = state.asset_watch || {};
  const devices = brain.devices || [];
  root.innerHTML = [
    ...renderTruthCards(state),
    card("LAN Asset Watch", [
      kv("Known", asset.known ?? "--", "green"),
      kv("Unknown", asset.unknown ?? "--", Number(asset.unknown || 0) > 5 ? "yellow" : "cyan"),
      kv("Pi Fleet", `${asset.pi_online || 0}/${asset.pi_count || 0}`, asset.pi_online === asset.pi_count ? "green" : "yellow"),
      kv("Learner", asset.unknown_h || "--", "cyan"),
    ].join("")),
    card("Label Brain Devices", table(["Device", "Profile", "Confidence", "Likely Apps", "Unusual"], devices.map((d) => [d.asset, d.profile, d.identity_confidence || d.confidence, (d.apps || []).map((a) => a.name).join(", "), (d.unusual || []).join(", ")]))),
    card("Now Watching", table(["Group", "Apps"], (brain.now_watching || []).map((g) => [g.group, (g.apps || []).join(", ")]))),
  ].join("");
}

function renderIncidents(state) {
  title.textContent = "SOCX INCIDENTS";
  const incident = state.incident || {};
  const memory = state.incident_memory || {};
  const timeline = state.incident_timeline || {};
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Incident Cockpit", [
      kv("Verdict", incident.verdict || "--", incident.verdict === "QUIET" ? "green" : "yellow"),
      kv("Headline", incident.headline || "--", "cyan"),
      kv("FW/DNSBL/IDS", `${incident.counts?.sources || 0}/${incident.counts?.dnsbl || 0}/${incident.ids?.high_signal || 0}`, "yellow"),
    ].join("")),
    card("Timeline", table(["Time", "Kind", "Severity", "Title", "Evidence"], (timeline.rows || []).map((r) => [r.time, r.kind, r.severity, r.title, r.evidence]))),
    card("Incident Memory", table(["Type", "Top Repeat", "Count"], [["Samples", "total", memory.count || memory.samples || 0], ["Source", memory.sources?.[0]?.name, memory.sources?.[0]?.count], ["Port", memory.ports?.[0]?.name, memory.ports?.[0]?.count], ["DNSBL", memory.dnsbl?.[0]?.name, memory.dnsbl?.[0]?.count], ["IDS High Samples", "samples", memory.ids_high_samples || 0]])),
  ].join("");
}

function renderAi(state) {
  title.textContent = "SOCX AI";
  const pi = state.pi_nodes || {};
  const ai = state.ai_timeline || {};
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Pi Fleet", [
      kv("Online", `${pi.online || 0}/${pi.count || 0}`, pi.online === pi.count ? "green" : "yellow"),
      kv("Score", pi.score ?? "--", Number(pi.score || 0) >= 80 ? "green" : "yellow"),
      kv("Freshness", `${pi.state || "--"} ${pi.age_sec ?? "--"}s`, pi.fresh ? "green" : "yellow"),
      kv("Summary", pi.summary || "--", "cyan"),
    ].join("")),
    card("AI Verdicts", table(["Source", "Severity", "Confidence", "Reason", "Age"], (ai.rows || []).map((r) => [r.source, r.severity, r.confidence, r.reason, r.age_h]))),
    card("Pi Nodes", table(["Name", "IP", "Role", "Temp", "Load", "Memory", "Service"], (pi.nodes || []).map((n) => [n.name, n.ip, n.role_h || n.role, n.temperature_h, n.load_h, n.memory_h, n.service_h || n.service]))),
    card("AI Role Health", table(["Node", "Roles", "Mode", "Score", "Last Thought"], (pi.nodes || []).map((n) => [
      n.name || n.ip,
      n.roles_online || "--",
      n.autonomy_mode || n.latest_status || "--",
      n.autonomy_score ?? "--",
      n.autonomy_summary || n.detail || "--",
    ]))),
  ].join("");
}

async function refresh() {
  const page = pageName();
  const state = await fetch("/api/state", { cache: "no-store" }).then((r) => r.json());
  const health = page === "health"
    ? await fetch("/api/health", { cache: "no-store" }).then((r) => r.json())
    : null;
  const why = page === "why"
    ? await fetch(`/api/why${whyTarget ? `?target=${encodeURIComponent(whyTarget)}` : ""}`, { cache: "no-store" }).then((r) => r.json())
    : null;
  subtitle.textContent = `${state.hostname || "pfSense"} / ${state.mode || "live"} / ${new Date().toLocaleTimeString()}`;
  if (page === "devices") renderDevices(state);
  else if (page === "incidents") renderIncidents(state);
  else if (page === "ai") renderAi(state);
  else if (page === "health") renderHealth(state, health || {});
  else if (page === "why") renderWhy(state, why || {});
  else renderSpeed(state);
}

refresh().catch((err) => {
  root.innerHTML = card("Load Error", `<pre>${esc(err)}</pre>`, "red");
});
setInterval(refresh, 3000);
