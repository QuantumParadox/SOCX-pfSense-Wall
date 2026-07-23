const root = document.getElementById("detail-grid");
const title = document.getElementById("detail-title");
const subtitle = document.getElementById("detail-subtitle");
let whyTarget = new URLSearchParams(location.search).get("target") || "";
let replayWindow = Number(new URLSearchParams(location.search).get("window") || 21600);
let bundleStatus = "";
let storyArchiveStatus = "";

const esc = (value) => String(value ?? "--").replace(/[&<>"']/g, (c) => ({
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
  '"': "&quot;",
  "'": "&#39;",
}[c]));

const pageName = () => {
  const path = location.pathname.replace(/^\/+/, "").toLowerCase();
  if (path.includes("flow")) return "flows";
  if (path.includes("device")) return "devices";
  if (path.includes("incident")) return "incidents";
  if (path.includes("why")) return "why";
  if (path.includes("story")) return "story";
  if (path.includes("mission")) return "mission";
  if (path.includes("replay")) return "replay";
  if (path.includes("map")) return "map";
  if (path.includes("observability") || path.includes("metrics")) return "observability";
  if (path.includes("guide")) return "guide";
  if (path.includes("chat")) return "chat";
  if (path.includes("ai")) return "ai";
  if (path.includes("health")) return "health";
  return "speedtest";
};

function card(label, body, tone = "") {
  return `<section class="detail-card ${tone}"><h2>${esc(label)}</h2>${body}</section>`;
}

async function archiveStory() {
  storyArchiveStatus = "Saving story archive...";
  await refresh();
  try {
    const result = await fetch("/api/story-archive", { method: "POST" }).then((r) => r.json());
    storyArchiveStatus = result.ok
      ? `Story archive saved\ntext: ${result.text || "--"}\njson: ${result.json || "--"}`
      : `Story archive warning: ${result.error || "unknown"}`;
  } catch (err) {
    storyArchiveStatus = `Story archive error: ${err}`;
  }
  await refresh();
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

function askButton(question, label = "Ask SOCX") {
  return `<button class="ask-row-button" data-ask="${esc(question)}">${esc(label)}</button>`;
}

function flowPath(row) {
  return row?.display_path || `${row?.asset || "--"} -> ${row?.peer || "--"}`;
}

function askDock() {
  return card("SOCX Answer Dock", [
    `<div class="ask-dock">
      <div class="ask-dock-head">
        <span>Click any Ask SOCX button on this page for a plain-English explanation.</span>
        <b>read-only</b>
      </div>
      <pre id="context-answer">No row selected yet.</pre>
    </div>`,
  ].join(""));
}

async function runContextAsk(question) {
  const out = document.getElementById("context-answer");
  if (!out) {
    location.href = `/chat?q=${encodeURIComponent(question)}`;
    return;
  }
  out.textContent = "Collecting SOCX evidence and asking the operator chat path...";
  try {
    const result = await fetch("/api/chat", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ question }),
    }).then((r) => r.json());
    const phases = Array.isArray(result.phases) && result.phases.length ? `\n\nVisible steps:\n- ${result.phases.join("\n- ")}` : "";
    const commands = Array.isArray(result.safe_commands) && result.safe_commands.length ? `\n\nUseful commands:\n- ${result.safe_commands.join("\n- ")}` : "";
    out.textContent = `${result.mode || "ANSWER"}${result.approval_required ? " / APPROVAL REQUIRED" : ""}\n\n${result.answer || "No answer returned."}${phases}${commands}`;
  } catch (err) {
    out.textContent = `SOCX chat error: ${err}`;
  }
}

function wireAskButtons() {
  document.querySelectorAll("[data-ask]").forEach((button) => {
    button.addEventListener("click", () => runContextAsk(button.getAttribute("data-ask") || ""));
  });
}

function setReplayWindow(seconds) {
  replayWindow = Number(seconds || 21600);
  const url = new URL(location.href);
  url.searchParams.set("window", String(replayWindow));
  history.replaceState(null, "", url);
  refresh();
}

function drawThreatRadar(canvas, map) {
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
  const cx = width / 2;
  const cy = height / 2;
  const r = Math.min(width, height) * 0.42;
  ctx.strokeStyle = "rgba(52, 215, 242, .22)";
  ctx.lineWidth = Math.max(1, 1.2 * dpr);
  for (let i = 1; i <= 4; i++) {
    ctx.beginPath();
    ctx.arc(cx, cy, (r * i) / 4, 0, Math.PI * 2);
    ctx.stroke();
  }
  ctx.strokeStyle = "rgba(52, 215, 242, .12)";
  for (let a = 0; a < 360; a += 45) {
    const rad = (a * Math.PI) / 180;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(cx + Math.cos(rad) * r, cy + Math.sin(rad) * r);
    ctx.stroke();
  }
  ctx.fillStyle = "#34d7f2";
  ctx.beginPath();
  ctx.arc(cx, cy, 8 * dpr, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = "#041016";
  ctx.font = `${Math.max(9, 11 * dpr)}px Consolas, monospace`;
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillText("WAN", cx, cy);
  (map.nodes || []).forEach((node) => {
    const rad = ((Number(node.angle || 0) - 90) * Math.PI) / 180;
    const rr = r * Number(node.radius || .5);
    const x = cx + Math.cos(rad) * rr;
    const y = cy + Math.sin(rad) * rr;
    const sev = String(node.severity || "LOW");
    const color = sev === "HIGH" ? "#ff5c7a" : sev === "MED" ? "#ffe267" : "#76f27d";
    ctx.strokeStyle = color;
    ctx.globalAlpha = .58;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(x, y);
    ctx.stroke();
    ctx.globalAlpha = 1;
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(x, y, Math.max(4 * dpr, Math.min(12 * dpr, 4 * dpr + Number(node.count || 0) / 10)), 0, Math.PI * 2);
    ctx.fill();
  });
}

function deviceCards(devices) {
  if (!devices.length) return `<span class="muted">No device labels learned yet.</span>`;
  return `<div class="device-card-grid">${devices.slice(0, 12).map((d) => {
    const apps = (d.apps || []).map((a) => a.name).filter(Boolean);
    const services = (d.services || []).map((s) => s.name).filter(Boolean);
    const unusual = d.unusual || [];
    const question = `Explain this SOCX device card in plain English. Device: ${d.asset || "--"}. Profile: ${d.profile || "--"}. Confidence: ${d.identity_confidence || d.confidence || "--"}. Likely apps: ${apps.join(", ") || "none"}. Services: ${services.join(", ") || "none"}. Unusual: ${unusual.join(", ") || "none"}. Tell me what it likely is, whether it looks normal, and what I should check next.`;
    return `<article class="device-card">
      <div class="device-card-head">
        <b title="${esc(d.asset || "")}">${esc(d.friendly_name || d.asset || "Device")}</b>
        <span class="${unusual.length ? "yellow" : "green"}">${esc(d.profile || "device")}</span>
      </div>
      <div class="device-card-meta">
        <span>identity <b>${esc(d.identity_confidence || d.confidence || "--")}</b></span>
        <span>flows <b>${esc(d.flows ?? "--")}</b></span>
      </div>
      <div class="device-tags">${apps.slice(0, 4).map((a) => `<em>${esc(a)}</em>`).join("") || "<em>learning</em>"}</div>
      <div class="device-summary" title="${esc(d.summary || "")}">${esc(d.summary || "SOCX is still learning this device.")}</div>
      ${unusual.length ? `<div class="device-alert">unusual: ${esc(unusual.join(", "))}</div>` : ""}
      ${askButton(question, "Explain Device")}
    </article>`;
  }).join("")}</div>`;
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
  const hw = state.hardware || {};
  const ht = hw.trend || {};
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
    card("Hardware Health", [
      kv("System", [hw.vendor, hw.model, hw.type_model].filter(Boolean).join(" ") || hw.system_name || "--", "cyan"),
      kv("CPU", hw.cpu_model || hw.cpu_sysctl || "--", "green"),
      kv("Cores", `${hw.cores || "--"}C/${hw.threads || "--"}T`, "cyan"),
      kv("BIOS", `${hw.bios_version || "--"} ${hw.bios_release_date || ""}`, "cyan"),
      kv("Hottest Core", hw.max_temp_h || "--", hw.tone || "cyan"),
      kv("Frequency", hw.freq_h || "--", "cyan"),
      kv("AES-NI", hw.aesni ? "on" : "unknown", hw.aesni ? "green" : "yellow"),
      kv("Powerd", hw.powerd || "--", String(hw.powerd || "").includes("running") ? "green" : "yellow"),
      kv("Thermal Limits", `${hw.warning_c || "--"}C warn / ${hw.critical_c || "--"}C critical`, "cyan"),
      kv("Trend", `${ht.label || "--"} avg ${ht.avg_c || "--"}C peak ${ht.peak_c || "--"}C`, ht.label === "rising" ? "yellow" : "green"),
      kv("Headroom", `${ht.headroom_c ?? "--"}C below warn`, Number(ht.headroom_c || 0) < 5 ? "yellow" : "green"),
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

function renderStory(state, story) {
  title.textContent = "SOCX DAILY STORY";
  const glance = story.at_a_glance || {};
  const hw = story.hardware || state.hardware || {};
  const ht = hw.trend || {};
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Today So Far", [
      `<div class="story-title"><span>${esc(story.title || "SOCX Daily Story")}</span><b class="${story.status === "watch" ? "yellow" : "green"}">${esc(story.status || "normal")}</b></div>`,
      `<div class="detail-reason">${esc((story.story || [])[0] || "waiting for story")}</div>`,
      `<div class="story-actions"><button id="story-archive">Archive Story</button><a href="/api/story" target="_blank">JSON</a></div>`,
      storyArchiveStatus ? `<pre class="why-output">${esc(storyArchiveStatus)}</pre>` : "",
    ].join("")),
    card("At A Glance", [
      kv("Data Truth", glance.data_truth || "--", story.status === "watch" ? "yellow" : "green"),
      kv("Autopilot", glance.autopilot || "--", "cyan"),
      kv("Firewall", glance.firewall || "--", "yellow"),
      kv("DNSBL", glance.dnsbl || "--", "purple"),
      kv("IDS", glance.ids || "--", "cyan"),
      kv("Pi", glance.pi || "--", "green"),
      kv("Hardware", glance.hardware || hw.summary || "--", hw.tone || "cyan"),
    ].join("")),
    card("Story Lines", table(["#", "What SOCX Saw"], (story.story || []).map((line, idx) => [idx + 1, line]))),
    card("Recommended Next Steps", table(["#", "Action"], (story.next_steps || []).map((line, idx) => [idx + 1, line]))),
    card("Speedtest Truth", table(["Path", "State", "Avg Down/Up", "Ping", "Samples"], [
      ["Direct", story.speedtest?.direct?.status || "--", `${story.speedtest?.direct?.avg_down || "--"}/${story.speedtest?.direct?.avg_up || "--"}`, story.speedtest?.direct?.avg_ping || "--", story.speedtest?.direct?.samples || "--"],
      ["VPN", story.speedtest?.vpn?.status || "--", `${story.speedtest?.vpn?.avg_down || "--"}/${story.speedtest?.vpn?.avg_up || "--"}`, story.speedtest?.vpn?.avg_ping || "--", story.speedtest?.vpn?.samples || "--"],
    ])),
    card("Hardware Headroom", table(["Signal", "Value", "State"], [
      ["System", [hw.vendor, hw.model, hw.type_model].filter(Boolean).join(" ") || hw.system_name || "--", hw.status || "--"],
      ["CPU", hw.cpu_model || hw.cpu_sysctl || "--", `${hw.cores || "--"}C/${hw.threads || "--"}T`],
      ["BIOS", hw.bios_version || "--", hw.bios_release_date || "--"],
      ["Thermal", `${hw.max_temp_h || "--"} max / ${hw.avg_temp_c || "--"}C avg`, `${hw.warning_c || "--"}C warn / ${hw.critical_c || "--"}C crit`],
      ["Trend", `${ht.label || "--"} / avg ${ht.avg_c || "--"}C / peak ${ht.peak_c || "--"}C`, `${ht.headroom_c ?? "--"}C headroom`],
      ["Crypto", hw.aesni ? "AES-NI on" : "AES-NI unknown", hw.powerd || "--"],
    ])),
    card("Repeated Memory", table(["Type", "Name", "Count"], [
      ...(story.repeated_memory?.sources || []).map((x) => ["source", x.name, x.count]),
      ...(story.repeated_memory?.ports || []).map((x) => ["port", x.name, x.count]),
      ...(story.repeated_memory?.dnsbl || []).map((x) => ["dnsbl", x.name, x.count]),
    ])),
    card("AI And Changes", table(["Kind", "Severity", "Summary"], [
      ...(story.ai || []).map((x) => [x.source || "AI", x.severity || "--", x.reason || "--"]),
      ...(story.what_changed || []).slice(0, 4).map((x) => ["Change", x.severity || "--", `${x.title || "--"}: ${x.detail || "--"}`]),
    ])),
  ].join("");
  document.getElementById("story-archive")?.addEventListener("click", archiveStory);
}

function renderMission(state, mission) {
  title.textContent = "SOCX MISSION";
  const vpn = mission.vpn || {};
  const hw = mission.hardware || {};
  const pi = mission.pi_ai || {};
  const intel = mission.intel || state.intel || {};
  const kev = intel.kev || {};
  root.innerHTML = [
    ...renderTruthCards({ ...state, data_truth: mission.data_truth || state.data_truth || {}, what_changed: { rows: mission.what_changed || [] } }),
    card("Mission Readout", [
      `<div class="story-title"><span>${esc(mission.headline || "SOCX mission warming up")}</span><b class="${mission.mode === "NORMAL" ? "green" : "yellow"}">${esc(mission.mode || "--")} ${esc(mission.score || "--")}</b></div>`,
      `<div class="detail-reason">${esc((mission.what_matters || [])[0] || "No critical evidence in the current sample.")}</div>`,
    ].join("")),
    card("What Matters", table(["#", "Signal"], (mission.what_matters || []).map((line, idx) => [idx + 1, line]))),
    card("What To Check", table(["#", "Action"], (mission.what_to_check || []).map((line, idx) => [idx + 1, line]))),
    card("Probably Noise", table(["#", "Why It Is Probably Noise"], (mission.probably_noise || []).map((line, idx) => [idx + 1, line]))),
    card("ATT&CK / D3FEND", [
      kv("Priority", `${intel.priority?.level || "--"} ${intel.priority?.status || ""}`, intel.priority?.level === "P1" || intel.priority?.level === "P2" ? "red" : intel.priority?.level === "P3" ? "yellow" : "green"),
      kv("KEV", `${kev.status || "unknown"} ${kev.count ? `x${kev.count}` : ""}`, kev.count ? "red" : kev.status === "fresh" ? "green" : "yellow"),
      table(["Signal", "Disposition", "ATT&CK", "D3FEND", "Evidence"], (intel.rows || []).map((r) => [
        r.signal || "--",
        `${r.priority || "--"} ${r.disposition || ""}`,
        `${r.attack?.id || "--"} ${r.attack?.name || ""}`,
        r.d3fend?.name || "--",
        r.evidence || "--",
      ])),
    ].join("")),
    card("Kill Chain Story", table(["Stage", "Current SOCX Evidence"], (intel.story || []).map((r) => [r.stage || "--", r.summary || "--"]))),
    card("VPN Truth", [
      kv("Summary", vpn.summary || "--", "cyan"),
      kv("Crypto", vpn.crypto?.summary || "--", vpn.crypto?.tone || "cyan"),
      table(["Path", "State", "Down/Up", "Ping", "Age"], (vpn.paths || []).map((p) => [
        p.label || "--",
        p.path_state || p.status || "--",
        `${p.down || "--"}/${p.up || "--"}`,
        `${p.ping || "--"}ms`,
        `${Math.floor((p.age_sec || 0) / 60)}m`,
      ])),
    ].join("")),
    card("AI And Hardware", [
      kv("Pi AI", pi.summary || "--", "green"),
      kv("Hardware", hw.summary || "--", hw.tone || "cyan"),
      kv("Headroom", `${hw.headroom_c ?? "--"}C`, Number(hw.headroom_c || 0) < 5 ? "yellow" : "green"),
      kv("Trend", hw.trend || "--", hw.trend === "rising" ? "yellow" : "green"),
      table(["Source", "Severity", "Confidence", "Reason"], (pi.rows || []).map((r) => [
        r.source || "--",
        r.severity || "--",
        r.confidence || "--",
        r.reason || "--",
      ])),
    ].join("")),
    card("Next Actions", table(["#", "Command"], (mission.next_actions || []).map((line, idx) => [idx + 1, line]))),
  ].join("");
}

function renderReplay(state, replay) {
  title.textContent = "SOCX REPLAY";
  subtitle.textContent = `${state.hostname || "pfSense"} / flight recorder / ${replay.window_h || "--"} window`;
  const buckets = replay.buckets || [];
  const maxScore = Math.max(100, ...buckets.map((b) => Number(b.score || 0)));
  const bucketHtml = buckets.map((b) => {
    const score = Number(b.score || 0);
    const height = Math.max(8, Math.round((score / maxScore) * 88));
    const tone = score >= 85 ? "green" : score >= 65 ? "yellow" : score > 0 ? "red" : "cyan";
    return `<div class="replay-bucket" title="${esc(b.label)} score ${esc(score)} samples ${esc(b.samples)}">
      <i class="${tone}" style="height:${height}%"></i>
      <span>${esc(b.label)}</span>
    </div>`;
  }).join("");
  const timelineRows = (replay.timeline || []).map((r) => [
    r.time || "--",
    r.lane || "--",
    r.severity || "--",
    r.title || "--",
    r.detail || "--",
  ]);
  const speedRows = (replay.speed?.rows || []).map((r) => [
    r.time || "--",
    r.path || "--",
    r.status || "--",
    `${r.down || "--"}/${r.up || "--"} Mbps`,
    `${r.ping || "--"}ms`,
    r.message || "--",
  ]);
  const flowRows = (replay.flow?.top || []).map((r) => [
    r.display_path || `${r.asset || "--"} -> ${r.peer || "--"}`,
    r.app || r.service || "--",
    r.bytes_h || "--",
    r.baseline_state || "--",
    r.why || "--",
  ]);
  const askReplayRows = [
    ...(replay.timeline || []).slice(0, 5).map((r) => ({
      label: `${r.lane || "event"} ${r.severity || ""}`,
      detail: `${r.title || "--"} ${r.detail || ""}`,
      question: `Explain this SOCX Replay event in plain English. Time: ${r.time || "--"}. Lane: ${r.lane || "--"}. Severity: ${r.severity || "--"}. Signal: ${r.title || "--"}. Detail: ${r.detail || "--"}. Tell me whether it matters, whether it is probably noise, and what to check next.`,
    })),
    ...(replay.flow?.top || []).slice(0, 3).map((r) => ({
      label: `flow ${r.app || r.service || "--"}`,
      detail: flowPath(r),
      question: `Explain this SOCX Replay flow in plain English. Path: ${flowPath(r)}. App: ${r.app || r.service || "--"}. Bytes: ${r.bytes_h || "--"}. Baseline: ${r.baseline_state || "--"}. Why: ${r.why || "--"}.`,
    })),
  ];
  const windows = [
    ["15m", 900],
    ["1h", 3600],
    ["6h", 21600],
    ["24h", 86400],
  ];
  const bookmarkRows = (replay.bookmarks || []).map((b) => [
    b.kind || "--",
    b.severity || "--",
    b.title || "--",
    b.detail || "--",
    b.page || "--",
  ]);
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Replay Controls", `<div class="replay-controls">${windows.map(([label, seconds]) => `<button class="${Number(replay.window_seconds || replayWindow) === seconds ? "active" : ""}" data-replay-window="${seconds}">${label}</button>`).join("")}</div>`),
    card("Replay Readout", [
      `<div class="detail-score ${replay.status === "LIVE" ? "green" : "yellow"}">${esc(replay.status || "UNKNOWN")} ${esc(replay.score?.current || replay.score?.avg || "--")}/100</div>`,
      `<div class="detail-reason">Last ${esc(replay.window_h || "--")} from ${esc(replay.samples || 0)} retained SOCX samples. Trend is ${esc(replay.score?.trend?.label || "--")}.</div>`,
      `<div class="replay-chart">${bucketHtml || "<span class=\"muted\">No replay buckets yet</span>"}</div>`,
    ].join("")),
    card("Mission Spikes", [
      kv("Score", `avg ${replay.score?.avg || "--"} / min ${replay.score?.min || "--"} / max ${replay.score?.max || "--"}`, "cyan"),
      kv("Direct Speed", `${replay.speed?.direct_avg || "--"} Mbps average`, "green"),
      kv("VPN Speed", `${replay.speed?.vpn_avg || "--"} Mbps average`, Number(replay.speed?.vpn_avg || 0) ? "green" : "yellow"),
      kv("Thermal", `now ${replay.thermal?.current || "--"}C / peak ${replay.thermal?.peak || "--"}C`, Number(replay.thermal?.peak || 0) >= 75 ? "yellow" : "green"),
      kv("UPS", `now ${replay.ups?.current || "--"}W / peak ${replay.ups?.peak || "--"}W`, "cyan"),
    ].join("")),
    card("Security Playback", [
      kv("Verdict", replay.security?.verdict || "--", replay.security?.verdict === "QUIET" ? "green" : "yellow"),
      kv("Headline", replay.security?.headline || "--", "cyan"),
      kv("FW / DNSBL / IDS", `${replay.security?.fw_blocks || 0} / ${replay.security?.dnsbl || 0} / ${replay.security?.ids_high || 0}`, Number(replay.security?.ids_high || 0) ? "red" : "yellow"),
      kv("Memory Samples", replay.security?.memory_samples || 0, "cyan"),
    ].join("")),
    card("What Happened", table(["Time", "Lane", "Severity", "Signal", "Detail"], timelineRows)),
    card("Incident Bookmarks", [
      `<div class="detail-reason">Auto-marked moments worth revisiting. Use Explain for context, then open the related page if needed.</div>`,
      table(["Kind", "Severity", "Title", "Detail", "Page"], bookmarkRows),
      `<div class="ask-list">${(replay.bookmarks || []).slice(0, 8).map((b) => `<div><span title="${esc(b.detail)}">${esc(b.kind)} ${esc(b.severity)}: ${esc(b.title)}</span>${askButton(b.question || `Explain replay bookmark ${b.title || ""}`)}</div>`).join("") || "<span class=\"muted\">No bookmarks in this window.</span>"}</div>`,
    ].join("")),
    card("Speedtest Playback", table(["Time", "Path", "Status", "Down/Up", "Ping", "Detail"], speedRows)),
    card("Flow Playback", [
      `<div class="detail-reason">${esc(replay.flow?.summary || "NetFlow waiting")}</div>`,
      table(["Path", "App", "Bytes", "State", "Why"], flowRows),
    ].join("")),
    card("Replay Actions", table(["Action", "Open", "Why"], (replay.commands || []).map((cmd) => [
      cmd.label || "--",
      cmd.href || "--",
      cmd.why || "--",
    ]))),
    card("Ask About Replay Rows", `<div class="ask-list">${askReplayRows.map((item) => `<div><span title="${esc(item.detail)}">${esc(item.label)}: ${esc(item.detail)}</span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No replay rows ready yet.</span>"}</div>`),
  ].join("");
  document.querySelectorAll("[data-replay-window]").forEach((button) => {
    button.addEventListener("click", () => setReplayWindow(button.getAttribute("data-replay-window")));
  });
  wireAskButtons();
}

function renderGuide(state) {
  title.textContent = "SOCX GUIDE";
  subtitle.textContent = "how to read the wall and talk to pfSense";
  root.innerHTML = [
    card("Start Here", [
      `<div class="detail-reason">SOCX is a read-only pfSense SOC wall. Green means healthy, yellow means watch, red means urgent/failing, magenta means AI or threat-intel context, and cyan is normal telemetry.</div>`,
      table(["Area", "What It Means"], [
        ["Top bar", "Time, uptime, WAN, VPN, DNS, UPS, Speedtest, and data freshness."],
        ["Network", "Live WAN/LAN download and upload rates from pfSense interface counters."],
        ["Threat Pulse", "Firewall blocks, DNSBL, IDS, and repeated-event pressure summarized into one watch score."],
        ["Data Truth", "Whether the collectors are fresh enough to trust what the wall is showing."],
        ["Packet Story", "Human-readable firewall/filterlog packets, not raw tcpdump syntax."],
      ]),
    ].join("")),
    card("Operator Chat", [
      `<div class="detail-reason">Use the Command Center chatbox or terminal command <b>socx chat "question"</b>. It answers firewall, DNSBL, IDS, VPN, Speedtest, device, and Pi AI questions in plain English.</div>`,
      table(["Mode", "Behavior"], [
        ["ANSWER", "Explains current evidence using pfSense/SOCX telemetry."],
        ["PLAN", "Drafts a configuration plan only. No pfSense change is applied."],
        ["DENIED", "Refuses destructive, bypass, stealth, or unsafe requests."],
        ["Pi LLM", "If the Pi is reachable, SOCX asks the Pi model and shows visible steps."],
        ["Fallback", "If the Pi is stale/offline, SOCX gives a grounded local explanation."],
      ]),
    ].join("")),
    card("Command Center", table(["Button", "Use"], [
      ["Status", "Fast SOCX/pfSense health check."],
      ["Incident", "Summarize top firewall, DNSBL, IDS, LAN, and port evidence."],
      ["Bundle", "Preserve incident evidence for later review."],
      ["Snapshot", "Capture a read-only troubleshooting bundle."],
      ["Speed", "Check Speedtest path freshness and profile health."],
      ["Pi AI", "Trigger the Pi 3-LLM analysis path."],
      ["Rules", "Draft rule/IDS/DNSBL recommendations without applying them."],
      ["Doctor", "Run the repair/health doctor view."],
    ])),
    card("Ticker", [
      `<div class="detail-reason">The bottom ticker is a rotating event strip. Slow mode is best for wall viewing. It updates in batches so the sentence does not reset every time a new event count arrives.</div>`,
      table(["Control", "Meaning"], [
        ["SLOW", "Room-readable ticker speed."],
        ["NORMAL", "Balanced speed for a desktop browser."],
        ["FAST", "More movement when testing or sitting close."],
        ["BIG", "Larger wall text mode."],
      ]),
    ].join("")),
    card("Drilldowns", table(["Page", "Best For"], [
      ["Mission", "What matters, what changed, what to check, probable noise."],
      ["Replay", "A calm flight recorder for recent trends, Speedtest, security events, and Flow Truth."],
      ["Map", "Threat Map Lite radar for blocked WAN sources and scan pressure."],
      ["Speed", "Direct vs VPN speed tests and router/client truth."],
      ["Devices", "Friendly device and application labels."],
      ["Incidents", "Firewall/DNSBL/IDS evidence tables."],
      ["Why", "Why an IP, port, or domain was blocked."],
      ["AI", "Pi/MIRANDA model health and verdict timeline."],
      ["Health", "Collector freshness and release readiness."],
    ])),
    card("Safety", [
      `<div class="detail-reason">SOCX is monitoring, explanation, and draft-only guidance. Keep the dashboard on your trusted admin LAN.</div>`,
      table(["Rule", "Reason"], [
        ["No silent policy changes", "Firewall rules, aliases, DNSBL allowlists, IDS tuning, and services require operator review."],
        ["Preserve first", "Use Bundle or Snapshot before major investigation changes."],
        ["Treat AI as advisory", "Model answers summarize telemetry; pfSense remains the enforcement source of truth."],
      ]),
    ].join("")),
  ].join("");
}

function renderThreatMap(state, map) {
  title.textContent = "SOCX THREAT MAP";
  subtitle.textContent = `${state.hostname || "pfSense"} / blocked WAN radar / ${new Date().toLocaleTimeString()}`;
  const rows = (map.nodes || []).map((n) => [
    n.label || "--",
    n.count || 0,
    n.severity || "--",
    n.country || "--",
    n.top_ports || "--",
    n.disposition || "--",
  ]);
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Threat Radar", [
      `<div class="story-title"><span>${esc(map.summary || "Threat map waiting")}</span><b class="${map.status === "QUIET" ? "green" : "yellow"}">${esc(map.status || "--")}</b></div>`,
      `<canvas class="threat-map-canvas" id="threat-map-canvas"></canvas>`,
      `<div class="detail-reason">${esc(map.note || "Geo/reputation hints are local SOCX context, not proof.")}</div>`,
    ].join("")),
    card("Blocked Source Rows", table(["Source", "Count", "Severity", "Geo", "Top Ports", "Disposition"], rows)),
    card("Ask About Sources", `<div class="ask-list">${(map.nodes || []).slice(0, 10).map((n) => `<div><span title="${esc(n.disposition)}">${esc(n.label)} ${esc(n.country)} x${esc(n.count)} ports ${esc(n.top_ports)}</span>${askButton(n.question)}</div>`).join("") || "<span class=\"muted\">No mapped blocked sources yet.</span>"}</div>`),
  ].join("");
  requestAnimationFrame(() => drawThreatRadar(document.getElementById("threat-map-canvas"), map));
  wireAskButtons();
}

function renderChatPage(state) {
  title.textContent = "SOCX CHAT";
  subtitle.textContent = "ask pfSense and the Pi LLMs in plain English";
  if (document.getElementById("detail-chat-input")) return;
  const suggestions = [
    "Give me the SOCX morning brief. What changed, what matters, and what should I check first?",
    "Summarize Grafana and Influx metrics trends and tell me if anything is getting worse.",
    "Why is SOCX in watch or warning mode? Explain it in plain English.",
    "Why are firewall blocks high?",
    "Explain DNSBL and whether I should worry.",
    "Diagnose VPN speed and gateway health.",
    "Is my VPN healthy right now? Compare VPN state, latency, and speed truth.",
    "Draft a safe plan to quarantine a host.",
    "What should I check before tuning IDS?",
  ];
  root.innerHTML = [
    card("Operator Chat", [
      `<div class="chat-page-shell">
        <div class="chat-page-row">
          <input id="detail-chat-input" aria-label="Ask SOCX" placeholder="Ask SOCX about pfSense, IDS, DNSBL, VPN, Speedtest, devices, or a draft config plan">
          <button id="detail-chat-send">Ask SOCX</button>
        </div>
        <div class="chat-page-output" id="detail-chat-output">Ask a question. Configuration requests return draft-only plans and never apply changes.</div>
        <div class="chat-cards detail-chat-cards" id="detail-chat-cards"></div>
      </div>`,
    ].join("")),
    card("Try These", `<div class="why-targets">${suggestions.map((q) => `<button class="why-target-button detail-chat-suggestion" data-question="${esc(q)}">${esc(q)}</button>`).join("")}</div>`),
    card("Safety Modes", table(["Mode", "Meaning"], [
      ["ANSWER", "Plain-English explanation from SOCX/pfSense telemetry."],
      ["PLAN", "Draft-only configuration guidance with approval required."],
      ["DENIED", "Unsafe or destructive request refused."],
      ["Pi LLM", "SOCX delegates to the Pi when available and shows visible steps."],
    ])),
    card("Current Signals", table(["Signal", "Value"], [
      ["Threat Pulse", `${state.threat_pulse?.label || "--"} ${state.threat_pulse?.score ?? "--"}`],
      ["Data Truth", `${state.data_truth?.label || "--"} ${state.data_truth?.score ?? "--"}`],
      ["Pi Fleet", state.pi_nodes?.summary || "--"],
      ["Top App", state.asset_watch?.headline || "--"],
      ["Command Mode", `${state.command_center?.mode || "--"} ${state.command_center?.score ?? "--"}`],
    ])),
  ].join("");
  wireDetailChat();
}

function renderDetailChatCards(cards = []) {
  const rootCards = document.getElementById("detail-chat-cards");
  if (!rootCards) return;
  rootCards.innerHTML = cards.slice(0, 5).map((card) => `
    <div class="chat-card ${esc(card.tone || "cyan")}">
      <b>${esc(card.title || "SOCX")}</b>
      <span>${esc(card.status || "READY")}</span>
      <em title="${esc(card.detail || "")}">${esc(card.detail || "--")}</em>
      ${card.command ? `<code>${esc(card.command)}</code>` : ""}
    </div>
  `).join("");
}

async function runDetailChat(questionOverride = "") {
  const input = document.getElementById("detail-chat-input");
  const output = document.getElementById("detail-chat-output");
  const question = String(questionOverride || input?.value || "").trim();
  if (!question) {
    output.textContent = "Ask me something like: explain DNSBL, diagnose VPN, why is IDS watch high, or draft a safe rule plan.";
    return;
  }
  if (input) input.value = question;
  output.textContent = "Collecting SOCX evidence and asking the Pi LLM if available...";
  renderDetailChatCards([]);
  try {
    const result = await fetch("/api/chat", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ question }),
    }).then((r) => r.json());
    const phases = Array.isArray(result.phases) && result.phases.length ? `\n\nVisible steps:\n- ${result.phases.join("\n- ")}` : "";
    const commands = Array.isArray(result.safe_commands) && result.safe_commands.length ? `\n\nUseful commands:\n- ${result.safe_commands.join("\n- ")}` : "";
    output.textContent = `${result.mode || "ANSWER"}${result.approval_required ? " / APPROVAL REQUIRED" : ""}\n\n${result.answer || "No answer returned."}${phases}${commands}`;
    renderDetailChatCards(result.action_cards || []);
  } catch (err) {
    output.textContent = `Chat service unavailable: ${err}`;
  }
}

function wireDetailChat() {
  document.getElementById("detail-chat-send")?.addEventListener("click", () => runDetailChat());
  document.getElementById("detail-chat-input")?.addEventListener("keydown", (event) => {
    if (event.key === "Enter") runDetailChat();
  });
  document.querySelectorAll(".detail-chat-suggestion").forEach((button) => {
    button.addEventListener("click", () => runDetailChat(button.dataset.question || ""));
  });
}

function renderSpeed(state) {
  title.textContent = "SOCX SPEEDTEST";
  const center = state.command_center || {};
  const crypto = center.vpn_crypto || {};
  const hist = state.speedtest_history || {};
  const paths = hist.paths || [];
  const pathRows = paths.map((p) => [String(p.path || "").toUpperCase(), p.status, `${p.avg_down || "--"}/${p.avg_up || "--"}`, `${p.best_down || "--"}/${p.worst_down || "--"}`, `${p.avg_ping || "--"}ms`, `${p.ok || 0}/${p.samples || 0}`]);
  const speedAskRows = [
    { label: "Active Speedtest", question: `Explain the current SOCX Speedtest truth. Active/router: ${center.router?.down || "--"}/${center.router?.up || "--"} Mbps ${center.router?.ping || "--"}ms. Direct: ${center.direct?.down || "--"}/${center.direct?.up || "--"} Mbps. VPN: ${center.vpn?.path_state || center.vpn?.status || "WAIT"} ${center.vpn?.down || "--"}/${center.vpn?.up || "--"}. Truth: ${center.speed_truth?.label || "waiting"}.` },
    ...(center.vpn_paths || []).slice(0, 4).map((p) => ({ label: `VPN ${p.label || "--"}`, question: `Explain this SOCX VPN Speedtest path. Path: ${p.label || "--"}. State: ${p.path_state || p.status || "--"}. Down/up: ${p.down || "--"}/${p.up || "--"} Mbps. Ping: ${p.ping || "--"}ms. Age: ${Math.floor((p.age_sec || 0) / 60)}m. Message: ${p.message || p.external_ip || "--"}.` })),
  ];
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Current Paths", [
      kv("Active", `${center.router?.down || "--"}/${center.router?.up || "--"} Mbps ${center.router?.ping || "--"}ms`, center.router?.path_state === "ready" ? "green" : "yellow"),
      kv("Direct", `${center.direct?.down || "--"}/${center.direct?.up || "--"} Mbps ${center.direct?.ping || "--"}ms`, center.direct?.path_state === "ready" ? "green" : "yellow"),
      kv("VPN", `${center.vpn?.path_state || center.vpn?.status || "WAIT"} ${center.vpn?.down || "--"}/${center.vpn?.up || "--"}`, center.vpn?.path_state === "ready" ? "green" : "yellow"),
      kv("Truth", center.speed_truth?.label || "waiting", "cyan"),
      kv("VPN Crypto", crypto.summary || "--", crypto.tone || "cyan"),
    ].join("")),
    card("History", table(["Path", "State", "Avg", "Best/Worst", "Ping", "OK/Samples"], pathRows)),
    card("Named VPN Paths", table(["Path", "Status", "Down/Up", "Ping", "Age", "Message"], (center.vpn_paths || []).map((p) => [p.label, p.path_state || p.status, `${p.down || "--"}/${p.up || "--"}`, `${p.ping || "--"}ms`, `${Math.floor((p.age_sec || 0) / 60)}m`, p.message || p.external_ip || "--"]))),
    card("Ask About Speed Paths", `<div class="ask-list">${speedAskRows.map((item) => `<div><span>${esc(item.label)}</span>${askButton(item.question)}</div>`).join("")}</div>`),
  ].join("");
  wireAskButtons();
}

function renderDevices(state) {
  title.textContent = "SOCX DEVICES";
  const brain = state.label_brain || {};
  const asset = state.asset_watch || {};
  const devices = brain.devices || [];
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("LAN Asset Watch", [
      kv("Known", asset.known ?? "--", "green"),
      kv("Unknown", asset.unknown ?? "--", Number(asset.unknown || 0) > 5 ? "yellow" : "cyan"),
      kv("Pi Fleet", `${asset.pi_online || 0}/${asset.pi_count || 0}`, asset.pi_online === asset.pi_count ? "green" : "yellow"),
      kv("Learner", asset.unknown_h || "--", "cyan"),
    ].join("")),
    card("Device Cards", deviceCards(devices)),
    card("Label Brain Devices", table(["Device", "Profile", "Confidence", "Likely Apps", "Unusual"], devices.map((d) => [d.asset, d.profile, d.identity_confidence || d.confidence, (d.apps || []).map((a) => a.name).join(", "), (d.unusual || []).join(", ")]))),
    card("Now Watching", table(["Group", "Apps"], (brain.now_watching || []).map((g) => [g.group, (g.apps || []).join(", ")]))),
  ].join("");
  wireAskButtons();
}

function renderFlowsPage(state, data) {
  title.textContent = "SOCX NETFLOW STORY";
  subtitle.textContent = `${state.hostname || "pfSense"} / NetFlow, top talkers, device trust / ${new Date().toLocaleTimeString()}`;
  const nf = data.netflow_intel || state.netflow_intel || {};
  const trust = data.device_trust || state.device_trust || {};
  const assurance = data.mission_assurance || state.mission_assurance || {};
  const weakestNames = new Set((assurance.weakest || []).map((item) => item.name));
  const assuranceRows = Object.entries(assurance.scores || {}).map(([name, score]) => [
    name.toUpperCase(),
    score,
    weakestNames.has(name) ? "weakest watch item" : "supporting signal",
  ]);
  const flowRows = (nf.rows || []).slice(0, 12).map((r) => [
    r.display_path || `${r.asset || "--"} -> ${r.peer || "--"}`,
    r.app || r.service || "--",
    r.bytes_h || "--",
    r.baseline_state || "--",
    r.why || r.direction || "--",
  ]);
  const watchFlowRows = (nf.watch_rows || []).slice(0, 8).map((r) => [
    r.display_path || `${r.asset || "--"} -> ${r.peer || "--"}`,
    r.app || r.service || "--",
    r.bytes_h || "--",
    r.baseline_state || "--",
    r.why || "--",
  ]);
  const baselineRows = Object.entries(nf.baseline_counts || {}).map(([state, count]) => [state.toUpperCase(), count]);
  const trustRows = (trust.rows || []).slice(0, 12).map((r) => [
    r.asset || "--",
    `${r.score ?? "--"}/100`,
    r.profile || "--",
    r.bytes_h || "--",
    r.reason || "--",
  ]);
  const askFlowRows = [
    ...(nf.rows || []).slice(0, 8).map((r) => ({
      label: flowPath(r),
      detail: `${r.app || r.service || "--"} ${r.bytes_h || "--"} ${r.baseline_state || "--"}`,
      question: `Explain this SOCX Flow Truth row in plain English. Path: ${flowPath(r)}. App/service: ${r.app || r.service || "--"}. Bytes: ${r.bytes_h || "--"}. Baseline state: ${r.baseline_state || "--"}. Why: ${r.why || r.direction || "--"}. Tell me if this looks normal, new, suspicious, or just routine traffic.`,
    })),
    ...(trust.rows || []).slice(0, 4).map((r) => ({
      label: `trust ${r.asset || "--"}`,
      detail: `${r.score ?? "--"}/100 ${r.reason || ""}`,
      question: `Explain this SOCX Device Trust row. Device: ${r.asset || "--"}. Trust score: ${r.score ?? "--"}/100. Profile: ${r.profile || "--"}. Bytes: ${r.bytes_h || "--"}. Reason: ${r.reason || "--"}. Tell me what to check next.`,
    })),
  ];
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Mission Assurance", [
      `<div class="detail-score ${assurance.tone || "cyan"}">${esc(assurance.label || "WATCH")} ${esc(assurance.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(assurance.summary || "waiting for assurance score")}</div>`,
      table(["Function", "Score", "Why"], assuranceRows),
    ].join("")),
    card("NetFlow Story", [
      kv("Status", nf.status || "--", nf.status === "OK" ? "green" : "yellow"),
      kv("Window", nf.window || "--", "cyan"),
      kv("Total", nf.total_bytes_h || "--", "cyan"),
      kv("Normal/New/Watch", `${nf.baseline_counts?.normal || 0}/${nf.baseline_counts?.new || 0}/${nf.baseline_counts?.watch || 0}`, Number(nf.baseline_counts?.watch || 0) ? "yellow" : "green"),
      `<div class="detail-reason">${esc(nf.summary || "waiting for Pi4 Influx netflow data")}</div>`,
      table(["#", "Story"], (nf.stories || []).map((line, idx) => [idx + 1, line])),
    ].join("")),
    card("Who / What / Why", table(["Path", "App", "Bytes", "State", "Why"], flowRows)),
    card("New / Watch Flows", table(["Path", "App", "Bytes", "State", "Why"], watchFlowRows)),
    card("Device Trust", [
      `<div class="detail-score ${trust.tone || "cyan"}">${esc(trust.label || "WATCH")} ${esc(trust.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(trust.summary || "waiting for device trust score")}</div>`,
      table(["Device", "Trust", "Profile", "Bytes", "Reason"], trustRows),
    ].join("")),
    card("Top Apps", table(["App", "Bytes", "Groups"], (nf.top_apps || []).slice(0, 10).map((x) => [
      x.name || "--",
      x.bytes_h || "--",
      x.count ?? "--",
    ]))),
    card("Top Peers", table(["Peer", "Bytes", "Groups"], (nf.top_peers || []).slice(0, 10).map((x) => [
      x.name || "--",
      x.bytes_h || "--",
      x.count ?? "--",
    ]))),
    card("Top Assets", table(["Asset", "Bytes", "Groups"], (nf.top_assets || []).slice(0, 10).map((x) => [
      x.name || "--",
      x.bytes_h || "--",
      x.count ?? "--",
    ]))),
    card("Baseline Mix", table(["State", "Flow Groups"], baselineRows)),
    card("Safe Next Steps", table(["#", "Action"], (assurance.next || []).map((line, idx) => [idx + 1, line]))),
    card("Ask About Flows", `<div class="ask-list">${askFlowRows.map((item) => `<div><span title="${esc(item.detail)}">${esc(item.label)} <em>${esc(item.detail)}</em></span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No flow rows ready yet.</span>"}</div>`),
  ].join("");
  wireAskButtons();
}

function renderIncidents(state) {
  title.textContent = "SOCX INCIDENTS";
  const incident = state.incident || {};
  const memory = state.incident_memory || {};
  const timeline = state.incident_timeline || {};
  const incidentAskRows = (timeline.rows || []).slice(0, 8).map((r) => ({
    label: `${r.kind || "event"} ${r.severity || ""}`,
    detail: `${r.title || "--"} ${r.evidence || ""}`,
    question: `Explain this SOCX incident timeline row. Time: ${r.time || "--"}. Kind: ${r.kind || "--"}. Severity: ${r.severity || "--"}. Title: ${r.title || "--"}. Evidence: ${r.evidence || "--"}. Tell me if it is likely routine noise or needs investigation, and what safe command/page to check next.`,
  }));
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Incident Cockpit", [
      kv("Verdict", incident.verdict || "--", incident.verdict === "QUIET" ? "green" : "yellow"),
      kv("Headline", incident.headline || "--", "cyan"),
      kv("FW/DNSBL/IDS", `${incident.counts?.sources || 0}/${incident.counts?.dnsbl || 0}/${incident.ids?.high_signal || 0}`, "yellow"),
    ].join("")),
    card("Timeline", table(["Time", "Kind", "Severity", "Title", "Evidence"], (timeline.rows || []).map((r) => [r.time, r.kind, r.severity, r.title, r.evidence]))),
    card("Incident Memory", table(["Type", "Top Repeat", "Count"], [["Samples", "total", memory.count || memory.samples || 0], ["Source", memory.sources?.[0]?.name, memory.sources?.[0]?.count], ["Port", memory.ports?.[0]?.name, memory.ports?.[0]?.count], ["DNSBL", memory.dnsbl?.[0]?.name, memory.dnsbl?.[0]?.count], ["IDS High Samples", "samples", memory.ids_high_samples || 0]])),
    card("Ask About Incident Rows", `<div class="ask-list">${incidentAskRows.map((item) => `<div><span title="${esc(item.detail)}">${esc(item.label)}: ${esc(item.detail)}</span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No incident rows ready yet.</span>"}</div>`),
  ].join("");
  wireAskButtons();
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

function renderObservability(state) {
  title.textContent = "SOCX OBSERVABILITY";
  const obs = state.observability || {};
  const intel = state.metrics_intel || {};
  const latest = obs.latest || {};
  const measurementRows = (obs.measurements || []).slice(0, 24).map((m) => [m, (obs.core_measurements || []).includes(m) ? "core" : "extra"]);
  const sampleRows = [
    ["CPU", latest.cpu ? `${Number(latest.cpu.last || 0).toFixed(1)} user / ${Number(latest.cpu.last_1 || 0).toFixed(1)} system` : "--"],
    ["Memory", latest.mem?.last !== undefined ? `${Number(latest.mem.last || 0).toFixed(1)}% used` : "--"],
    ["PF", latest.pf?.last !== undefined ? `${Number(latest.pf.last || 0).toFixed(0)} states / ${Number(latest.pf.last_1 || 0).toFixed(0)} searches` : "--"],
    ["Ping", latest.ping?.last !== undefined ? `${Number(latest.ping.last || 0).toFixed(2)} ms` : "--"],
  ];
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Metrics Stack", [
      kv("State", obs.label || "--", obs.tone || "cyan"),
      kv("Host", obs.host || "--", "cyan"),
      kv("Grafana", obs.grafana_ok ? `OK ${obs.grafana_version || ""}` : "WARN", obs.grafana_ok ? "green" : "yellow"),
      kv("InfluxDB", obs.influx_ok ? "OK" : "WARN", obs.influx_ok ? "green" : "yellow"),
      kv("Telegraf", obs.telegraf_ok ? "target ok" : "target mismatch", obs.telegraf_ok ? "green" : "yellow"),
      kv("Core Series", `${(obs.core_measurements || []).length}/6`, (obs.core_measurements || []).length >= 4 ? "green" : "yellow"),
      `<div class="detail-links"><a href="${esc(obs.grafana_url || "#")}" target="_blank" rel="noreferrer">Open Grafana</a><a href="/api/observability" target="_blank">Raw JSON</a></div>`,
    ].join("")),
    card("Latest pfSense Samples", table(["Signal", "Latest"], sampleRows)),
    card("Metrics Intelligence", [
      kv("Severity", intel.severity || "--", intel.severity === "OK" ? "green" : intel.severity === "CRITICAL" ? "red" : "yellow"),
      kv("Summary", intel.summary || "--", intel.severity === "OK" ? "green" : "yellow"),
      table(["Signal", "State", "Value", "Why"], (intel.alerts || []).map((a) => [
        a.signal || "--",
        a.state || "--",
        `${a.value ?? "--"}${a.unit || ""}`,
        a.why || "--",
      ])),
    ].join("")),
    card("Recommended Next Steps", table(["#", "Action"], (intel.next_steps || []).map((step, idx) => [idx + 1, step]))),
    card("Measurements", table(["Series", "Type"], measurementRows)),
    card("Targets", [
      kv("Grafana URL", obs.grafana_url || "--", "cyan"),
      kv("Influx URL", obs.influx_url || "--", "cyan"),
      kv("Database", obs.database || "--", "cyan"),
      kv("Telegraf Target", obs.telegraf_target || "--", obs.telegraf_ok ? "green" : "yellow"),
      kv("Summary", obs.summary || "--", obs.tone || "cyan"),
      ...(obs.errors || []).map((err) => kv("Error", err, "red")),
    ].join("")),
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
  const story = page === "story"
    ? await fetch("/api/story", { cache: "no-store" }).then((r) => r.json())
    : null;
  const mission = page === "mission"
    ? await fetch("/api/mission", { cache: "no-store" }).then((r) => r.json())
    : null;
  const flowsData = page === "flows"
    ? await fetch("/api/flows", { cache: "no-store" }).then((r) => r.json())
    : null;
  const replay = page === "replay"
    ? await fetch(`/api/replay?window=${encodeURIComponent(replayWindow || 21600)}`, { cache: "no-store" }).then((r) => r.json())
    : null;
  const threatMap = page === "map"
    ? await fetch("/api/threat-map", { cache: "no-store" }).then((r) => r.json())
    : null;
  subtitle.textContent = `${state.hostname || "pfSense"} / ${state.mode || "live"} / ${new Date().toLocaleTimeString()}`;
  if (page === "flows") renderFlowsPage(state, flowsData || {});
  else if (page === "replay") renderReplay(state, replay || {});
  else if (page === "map") renderThreatMap(state, threatMap || {});
  else if (page === "devices") renderDevices(state);
  else if (page === "incidents") renderIncidents(state);
  else if (page === "ai") renderAi(state);
  else if (page === "health") renderHealth(state, health || {});
  else if (page === "why") renderWhy(state, why || {});
  else if (page === "story") renderStory(state, story || {});
  else if (page === "mission") renderMission(state, mission || state.mission || {});
  else if (page === "observability") renderObservability(state);
  else if (page === "guide") renderGuide(state);
  else if (page === "chat") renderChatPage(state);
  else renderSpeed(state);
}

refresh().catch((err) => {
  root.innerHTML = card("Load Error", `<pre>${esc(err)}</pre>`, "red");
});
setInterval(refresh, 3000);
