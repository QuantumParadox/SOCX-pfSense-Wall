const root = document.getElementById("detail-grid");
const title = document.getElementById("detail-title");
const subtitle = document.getElementById("detail-subtitle");
let whyTarget = new URLSearchParams(location.search).get("target") || "";
let deviceTarget = new URLSearchParams(location.search).get("asset") || new URLSearchParams(location.search).get("target") || "";
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
  if (path === "device") return "device";
  if (path.includes("device")) return "devices";
  if (path.includes("incident-report")) return "incident-report";
  if (path.includes("coverage")) return "coverage";
  if (path.includes("hunts")) return "hunts";
  if (path.includes("rules-lab")) return "rules-lab";
  if (path.includes("soc-score")) return "soc-score";
  if (path.includes("model-tournament") || path.includes("research-soc")) return "model-tournament";
  if (path.includes("evidence")) return "evidence";
  if (path.includes("notebook")) return "notebook";
  if (path.includes("actions")) return "actions";
  if (path.includes("mission-mode")) return "mission-mode";
  if (path.includes("memory")) return "memory";
  if (path.includes("twin")) return "twin";
  if (path.includes("automation")) return "automation";
  if (path.includes("timeline")) return "timeline";
  if (path.includes("movie")) return "movie";
  if (path.includes("project")) return "projects";
  if (path.includes("glitch")) return "glitches";
  if (path.includes("wall-health")) return "wall-health";
  if (path.includes("review-queue")) return "review-queue";
  if (path.includes("owner-editor")) return "owner-editor";
  if (path.includes("owner-map")) return "owner-map";
  if (path.includes("packet-noise")) return "packet-noise";
  if (path.includes("confidence")) return "confidence";
  if (path.includes("incident-focus")) return "incident-focus";
  if (path.includes("maintenance")) return "maintenance";
  if (path.includes("mission-console")) return "mission-console";
  if (path.includes("flight-recorder")) return "flight-recorder";
  if (path.includes("config-sim")) return "config-sim";
  if (path.includes("baseline")) return "baseline";
  if (path.includes("since-yesterday")) return "since-yesterday";
  if (path.includes("daily-brief")) return "daily-brief";
  if (path.includes("incident")) return "incidents";
  if (path.includes("why")) return "why";
  if (path.includes("story")) return "story";
  if (path.includes("mission")) return "mission";
  if (path.includes("cockpit")) return "cockpit";
  if (path.includes("threat-story")) return "threat-story";
  if (path.includes("replay")) return "replay";
  if (path.includes("map")) return "map";
  if (path.includes("observability") || path.includes("metrics")) return "observability";
  if (path.includes("guide")) return "guide";
  if (path.includes("chat")) return "chat";
  if (path.includes("ai")) return "ai";
  if (path.includes("doctor")) return "doctor";
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

function wireResearchCommands() {
  const out = document.getElementById("research-command-output");
  document.querySelectorAll("[data-command-action]").forEach((button) => {
    button.addEventListener("click", async () => {
      const action = button.getAttribute("data-command-action") || "";
      if (out) out.textContent = `Running ${action} through read-only Commander...`;
      try {
        const result = await fetch("/api/commander", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action }),
        }).then((r) => r.json());
        if (out) out.textContent = `${result.title || action} ${result.ok ? "OK" : "WARN"}\n${result.output || "No output returned."}`;
      } catch (err) {
        if (out) out.textContent = `Command failed: ${err}`;
      }
    });
  });
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
      <div class="device-actions">
        <a href="/device?asset=${encodeURIComponent(d.asset || d.friendly_name || "")}">Open Detail</a>
        ${askButton(question, "Explain Device")}
      </div>
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

function renderDoctor(state, doctor) {
  title.textContent = "SOCX PFSENSE DOCTOR";
  subtitle.textContent = `${state.hostname || "pfSense"} / read-only diagnostics / ${doctor.cache_age_sec !== undefined ? `cached ${doctor.cache_age_sec}s` : new Date().toLocaleTimeString()}`;
  const rows = doctor.rows || [];
  const checkRows = rows.map((r) => [
    r.group || "--",
    r.label || "--",
    r.status || "--",
    `${r.elapsed_ms ?? "--"}ms`,
    r.summary || "--",
  ]);
  const askRows = rows
    .filter((r) => String(r.status || "").toUpperCase() !== "OK")
    .slice(0, 8)
    .map((r) => ({
      label: `${r.status || "--"} ${r.label || "--"}`,
      detail: r.summary || r.why || "--",
      question: `Explain this SOCX pfSense Doctor result. Check: ${r.label || "--"}. Status: ${r.status || "--"}. Why it exists: ${r.why || "--"}. Summary: ${r.summary || "--"}. Command: ${r.command || "--"}. Tell me what it means, whether it is urgent, and what safe next step I should take.`,
    }));
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Doctor Summary", [
      `<div class="detail-score ${doctor.status === "OK" ? "green" : doctor.status === "FAIL" ? "red" : "yellow"}">${esc(doctor.status || "UNKNOWN")} ${esc(doctor.ok || 0)} OK / ${esc(doctor.warn || 0)} WARN / ${esc(doctor.fail || 0)} FAIL</div>`,
      `<div class="detail-reason">${esc(doctor.summary || "waiting for read-only diagnostics")}</div>`,
      table(["#", "Safe Next Step"], (doctor.next || []).map((step, idx) => [idx + 1, step])),
    ].join("")),
    card("Read-Only Checks", table(["Group", "Check", "State", "Time", "Summary"], checkRows)),
    card("Check Output", `<div class="doctor-output-list">${rows.map((r) => `
      <details class="doctor-output ${String(r.status || "").toLowerCase()}">
        <summary><b>${esc(r.status || "--")}</b> ${esc(r.label || "--")} <span>${esc(r.command || "--")}</span></summary>
        <div class="detail-reason">${esc(r.why || "--")}</div>
        <pre class="why-output">${esc(r.output || "No output returned.")}</pre>
      </details>`).join("")}</div>`),
    card("Ask About Warnings", `<div class="ask-list">${askRows.map((item) => `<div><span title="${esc(item.detail)}">${esc(item.label)}: ${esc(item.detail)}</span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No WARN/FAIL doctor rows right now.</span>"}</div>`),
  ].join("");
  wireAskButtons();
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
    ["72h", 259200],
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

function renderFlightRecorder(state, recorder) {
  title.textContent = "SOCX FLIGHT RECORDER";
  subtitle.textContent = `${state.hostname || "pfSense"} / evidence continuity / read-only`;
  const tone = recorder.label === "CONTINUOUS" ? "green" : recorder.label === "LEARNING" ? "cyan" : "yellow";
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Recorder Confidence", [
      `<div class="detail-score ${tone}">${esc(recorder.label || "UNKNOWN")} ${esc(recorder.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(recorder.summary || "Recorder state is warming up.")}</div>`,
      kv("Retained", `${recorder.samples || 0} samples / ${recorder.retention_h || "--"}`, "cyan"),
      kv("Latest", `${recorder.latest_age_h || "--"} ago`, Number(recorder.latest_age_seconds || 0) > 1800 ? "yellow" : "green"),
      kv("Cadence", recorder.median_interval_h || "--", "cyan"),
      kv("Gaps", `${recorder.gap_count || 0} / largest ${recorder.largest_gap_h || "--"}`, Number(recorder.gap_count || 0) ? "yellow" : "green"),
      kv("Evidence Markers", recorder.markers || 0, "purple"),
    ].join("")),
    card("Integrity Checks", table(["Check", "State", "Detail"], (recorder.integrity || []).map((row) => [row.check || "--", row.state || "--", row.detail || "--"]))),
    card("How To Use It", table(["#", "Operator Guidance"], (recorder.next || []).map((item, index) => [index + 1, item]))),
    card("Recorder Links", `<div class="story-actions"><a href="/replay?window=21600">Open 6h Replay</a><a href="/replay?window=259200">Open 72h Replay</a><a href="/evidence">Open Evidence</a><a href="/timeline">Open Timeline</a></div>`),
    card("Ask SOCX", `<div class="ask-list"><div><span>Ask whether the retained data is strong enough for an investigation.</span>${askButton("Is SOCX Flight Recorder continuity good enough to investigate the last 72 hours? Explain any gaps, uncertainty, and the safest evidence-preservation step.")}</div></div>`),
  ].join("");
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
      ["Doctor", "Read-only pfSense service, package, WAN, VPN, DNSBL, IDS, Speedtest, and Pi health checks."],
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

function evidenceCard(item = {}) {
  return `<div class="story-evidence-card ${esc(item.tone || "cyan")}">
    <b>${esc(item.label || "Signal")}</b>
    <strong>${esc(item.value || "--")}</strong>
    <span title="${esc(item.detail || "")}">${esc(item.detail || "--")}</span>
  </div>`;
}

function renderThreatStory(state, story) {
  title.textContent = "SOCX THREAT STORY";
  subtitle.textContent = `${state.hostname || "pfSense"} / plain-English incident narrative / ${new Date().toLocaleTimeString()}`;
  const repeated = story.repeated || {};
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Threat Story", [
      `<div class="story-title"><span>${esc(story.headline || "SOCX is watching current evidence")}</span><b class="${String(story.mode || "").includes("INCIDENT") ? "red" : "yellow"}">${esc(story.mode || "WATCH")} ${esc(story.score ?? "--")}</b></div>`,
      `<div class="story-evidence-grid">${(story.evidence_cards || []).map(evidenceCard).join("")}</div>`,
    ].join("")),
    card("What SOCX Thinks It Is Seeing", table(["#", "Narrative"], (story.story || []).map((line, idx) => [idx + 1, line]))),
    card("What To Do Next", table(["#", "Safe Action"], (story.next_steps || []).map((line, idx) => [idx + 1, line]))),
    card("Recent Incident Timeline", table(["Time", "Kind", "Severity", "Signal", "Evidence"], (story.timeline || []).map((r) => [
      r.time || "--",
      r.kind || "--",
      r.severity || "--",
      r.title || "--",
      r.evidence || r.detail || "--",
    ]))),
    card("Repeated Patterns", table(["Type", "Name", "Count"], [
      ...(repeated.sources || []).map((x) => ["source", x.name, x.count]),
      ...(repeated.ports || []).map((x) => ["port", x.name, x.count]),
      ...(repeated.dnsbl || []).map((x) => ["dnsbl", x.name, x.count]),
    ])),
    card("Ask SOCX", `<div class="why-targets">${(story.questions || []).map((q) => `<button class="why-target-button" data-ask="${esc(q)}">${esc(q)}</button>`).join("")}</div>`),
  ].join("");
  wireAskButtons();
}

function renderCockpit(state, cockpit) {
  title.textContent = "SOCX OPERATOR COCKPIT";
  subtitle.textContent = `${state.hostname || "pfSense"} / mobile and tablet command view / ${new Date().toLocaleTimeString()}`;
  const threat = cockpit.threat_story || {};
  const mission = cockpit.mission || {};
  const devices = cockpit.devices || {};
  const ai = cockpit.ai || {};
  const metrics = cockpit.metrics || {};
  root.innerHTML = [
    card("Now", [
      `<div class="cockpit-hero"><span>${esc(cockpit.headline || "SOCX is watching")}</span><b class="${String(cockpit.mode || "").includes("NORMAL") ? "green" : "yellow"}">${esc(cockpit.mode || "WATCH")} ${esc(cockpit.score ?? "--")}</b></div>`,
      `<div class="detail-reason">${esc(threat.story?.[0] || "SOCX is collecting live pfSense evidence.")}</div>`,
    ].join("")),
    card("Threat Story", [
      `<div class="story-evidence-grid">${(threat.evidence_cards || []).map(evidenceCard).join("")}</div>`,
      table(["#", "What SOCX Saw"], (threat.story || []).slice(0, 4).map((line, idx) => [idx + 1, line])),
    ].join("")),
    card("Next Actions", table(["Lane", "Action", "Open"], (cockpit.next_actions || []).map((item) => [
      item.label || "--",
      item.action || "--",
      item.href || "--",
    ]))),
    card("Mission", [
      `<div class="detail-reason">${esc(mission.headline || "Mission layer warming up")}</div>`,
      table(["Matter", "Check", "Noise"], [0, 1, 2, 3].map((idx) => [
        mission.what_matters?.[idx] || "--",
        mission.what_to_check?.[idx] || "--",
        mission.probably_noise?.[idx] || "--",
      ])),
    ].join("")),
    card("Devices And Apps", [
      kv("LAN", `${devices.known ?? "--"} known / ${devices.unknown ?? "--"} unknown`, Number(devices.unknown || 0) ? "yellow" : "green"),
      kv("Now", devices.headline || "--", "cyan"),
      deviceCards(devices.cards || []),
    ].join("")),
    card("AI / Pi / Metrics", [
      kv("Pi", ai.pi || "--", "green"),
      kv("Roles", (ai.roles || []).join(" | ") || "--", "purple"),
      kv("Metrics", `${metrics.severity || "--"} ${metrics.summary || ""}`, metrics.severity === "OK" ? "green" : "yellow"),
      kv("Flow", metrics.flow || "--", "cyan"),
      table(["Source", "Severity", "Reason"], (ai.timeline || []).map((r) => [
        r.source || "--",
        r.severity || "--",
        r.reason || "--",
      ])),
    ].join("")),
    card("Ask From Here", `<div class="why-targets">${(cockpit.quick_questions || []).map((q) => `<button class="why-target-button" data-ask="${esc(q)}">${esc(q)}</button>`).join("")}</div>`),
  ].join("");
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
    "Run a pfSense Doctor style review and explain the WARN items.",
    "What unknown devices, apps, or services should I fix next?",
    "Build the current threat story and tell me what is probably noise.",
    "What should I do next on SOCX today?",
    "Give me a mobile cockpit summary.",
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
    const confidence = result.confidence ? `\n\nConfidence: ${result.confidence.label || "--"} ${result.confidence.score ?? "--"}/100\n- ${(result.confidence.reasons || []).join("\n- ")}` : "";
    const evidence = Array.isArray(result.evidence_used) && result.evidence_used.length ? `\n\nEvidence used:\n- ${result.evidence_used.map((e) => `${e.source}: ${e.strength} - ${e.detail}`).join("\n- ")}` : "";
    const helps = Array.isArray(result.what_would_help) && result.what_would_help.length ? `\n\nWhat would make this more certain:\n- ${result.what_would_help.join("\n- ")}` : "";
    const queue = Array.isArray(result.safe_action_queue) && result.safe_action_queue.length ? `\n\nSafe action queue:\n- ${result.safe_action_queue.map((a) => `${a.priority} ${a.action}: ${a.command}`).join("\n- ")}` : "";
    const commands = Array.isArray(result.safe_commands) && result.safe_commands.length ? `\n\nUseful commands:\n- ${result.safe_commands.join("\n- ")}` : "";
    output.textContent = `${result.mode || "ANSWER"}${result.approval_required ? " / APPROVAL REQUIRED" : ""}\n\n${result.answer || "No answer returned."}${confidence}${evidence}${helps}${queue}${phases}${commands}`;
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

function renderDeviceDetail(state, detail) {
  title.textContent = "SOCX DEVICE DETAIL";
  subtitle.textContent = `${state.hostname || "pfSense"} / ${detail.asset || "device"} / read-only profile`;
  const apps = (detail.apps || []).map((x) => [x.name || x, x.count || "--"]);
  const services = (detail.services || []).map((x) => [x.name || x, x.count || "--"]);
  const flowRows = (detail.flows || []).map((r) => [
    r.display_path || `${r.asset || "--"} -> ${r.peer || "--"}`,
    r.app || r.service || "--",
    r.bytes_h || r.count || "--",
    r.baseline_state || r.state || "--",
    r.why || r.direction || "--",
  ]);
  const packetRows = (detail.packets || []).map((p) => [
    p.time || "--",
    p.action || "--",
    p.proto || "--",
    p.src_label || p.src || "--",
    p.dst_label || p.dst || "--",
    p.service || "--",
  ]);
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Device Readout", [
      `<div class="story-title"><span>${esc(detail.friendly_name || detail.asset || "Device")}</span><b class="${(detail.unusual || []).length ? "yellow" : "green"}">${esc(detail.profile || "device")} ${esc(detail.trust_score ?? "--")}</b></div>`,
      `<div class="detail-reason">${esc(detail.verdict || detail.summary || "SOCX is learning this device.")}</div>`,
      kv("Identity", detail.identity_confidence || "--", "cyan"),
      kv("Summary", detail.summary || "--", "cyan"),
    ].join("")),
    card("Likely Apps", table(["App", "Count"], apps)),
    card("Services", table(["Service", "Count"], services)),
    card("Unusual / Learned-Normal", table(["#", "Observation"], (detail.unusual || []).map((x, idx) => [idx + 1, x]))),
    card("Current Flow Evidence", table(["Path", "App", "Bytes/Count", "State", "Why"], flowRows)),
    card("Packet Evidence", table(["Time", "Action", "Proto", "Source", "Destination", "Service"], packetRows)),
    card("Safe Questions", `<div class="why-targets">${(detail.safe_questions || []).map((q) => `<button class="why-target-button" data-ask="${esc(q)}">${esc(q)}</button>`).join("")}</div>`),
    card("Safe Commands", table(["#", "Command"], (detail.safe_commands || []).map((cmd, idx) => [idx + 1, cmd]))),
  ].join("");
  wireAskButtons();
}

function renderIncidentReport(state, report) {
  title.textContent = "SOCX INCIDENT REPORT";
  subtitle.textContent = `${state.hostname || "pfSense"} / generated report / read-only`;
  const story = report.story || {};
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Report Summary", [
      `<div class="story-title"><span>${esc(story.headline || "SOCX incident report")}</span><b class="${String(story.mode || "").includes("INCIDENT") ? "red" : "yellow"}">${esc(story.mode || "WATCH")} ${esc(story.score ?? "--")}</b></div>`,
      `<div class="story-actions"><a href="/threat-story">Threat Story</a><a href="/incidents">Incidents</a><a href="/why">Why</a></div>`,
    ].join("")),
    card("Markdown Report", `<pre class="why-output incident-report-md">${esc(report.markdown || "Report not ready.")}</pre>`),
    card("Evidence Cards", `<div class="story-evidence-grid">${(story.evidence_cards || []).map(evidenceCard).join("")}</div>`),
    card("Safe Next Steps", table(["#", "Action"], (story.next_steps || []).map((line, idx) => [idx + 1, line]))),
  ].join("");
}

function renderCoverage(state, coverage) {
  title.textContent = "SOCX ATT&CK COVERAGE";
  subtitle.textContent = `${state.hostname || "pfSense"} / visibility map / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Coverage Score", [
      `<div class="detail-score ${coverage.score >= 75 ? "green" : coverage.score >= 55 ? "yellow" : "red"}">${esc(coverage.label || "WATCH")} ${esc(coverage.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(coverage.summary || "Coverage map waiting.")}</div>`,
    ].join("")),
    card("Technique Visibility", table(["ATT&CK", "Technique", "Tactic", "Visibility", "Score", "Signal"], (coverage.rows || []).map((r) => [
      r.technique,
      r.name,
      r.tactic,
      r.visibility,
      r.score,
      r.signal,
    ]))),
    card("D3FEND / Gaps", table(["Technique", "Defense", "Gap"], (coverage.rows || []).map((r) => [r.technique, r.defense, r.gap]))),
    card("Blind Spots", table(["ATT&CK", "Name", "Gap"], (coverage.blind_spots || []).map((r) => [r.technique, r.name, r.gap]))),
    card("Safe Next Steps", table(["#", "Action"], (coverage.next || []).map((x, idx) => [idx + 1, x]))),
    card("Ask SOCX", `<div class="why-targets">${[
      "Which ATT&CK blind spot matters most for my home lab?",
      "How do I improve SOCX detection coverage without making pfSense unstable?",
      "Explain the ATT&CK and D3FEND rows in plain English.",
    ].map((q) => `<button class="why-target-button" data-ask="${esc(q)}">${esc(q)}</button>`).join("")}</div>`),
  ].join("");
  wireAskButtons();
}

function renderHunts(state, hunts) {
  title.textContent = "SOCX THREAT HUNTS";
  subtitle.textContent = `${state.hostname || "pfSense"} / guided hypotheses / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Hunt Mode", [
      `<div class="detail-score ${hunts.mode === "HUNT" ? "yellow" : "green"}">${esc(hunts.mode || "OBSERVE")}</div>`,
      `<div class="detail-reason">${esc(hunts.summary || "No hunt state yet.")}</div>`,
    ].join("")),
    card("Hypotheses", table(["Hunt", "Status", "Hypothesis", "Evidence", "Next"], (hunts.rows || []).map((h) => [
      h.hunt,
      h.status,
      h.hypothesis,
      h.evidence,
      h.next,
    ]))),
    card("Ask SOCX", `<div class="why-targets">${(hunts.questions || []).map((q) => `<button class="why-target-button" data-ask="${esc(q)}">${esc(q)}</button>`).join("")}</div>`),
  ].join("");
  wireAskButtons();
}

function renderRulesLab(state, lab) {
  title.textContent = "SOCX RULES LAB";
  subtitle.textContent = `${state.hostname || "pfSense"} / draft-only detections / approval required`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Rules Lab", [
      `<div class="detail-reason">${esc(lab.summary || "Draft lab waiting.")}</div>`,
      table(["Guardrail", "Meaning"], (lab.guardrails || []).map((g, idx) => [`${idx + 1}`, g])),
    ].join("")),
    ...(lab.drafts || []).map((d) => card(`${d.type}: ${d.name}`, [
      kv("Status", d.status || "draft-only", d.status === "approval-required" ? "yellow" : "cyan"),
      kv("Confidence", d.confidence || "low", d.confidence === "medium" ? "yellow" : "cyan"),
      `<pre class="why-output incident-report-md">${esc(d.rule || "--")}</pre>`,
      `<div class="detail-reason">${esc(d.risk || "Review before use.")}</div>`,
      askButton(`Review this draft-only ${d.type} SOCX rule. Explain what it does, false-positive risk, and what evidence I should preserve before using it. Rule: ${d.rule || ""}`, "Ask About Draft"),
    ].join(""))),
    card("ATT&CK Context", table(["Signal", "Priority", "ATT&CK", "D3FEND", "Evidence"], (lab.intel_rows || []).map((r) => [
      r.signal,
      r.priority,
      `${r.attack?.id || "--"} ${r.attack?.name || ""}`,
      r.d3fend?.name || "--",
      r.evidence || "--",
    ]))),
  ].join("");
  wireAskButtons();
}

function renderSocScore(state, score) {
  title.textContent = "SOCX SOC SCORE";
  subtitle.textContent = `${state.hostname || "pfSense"} / CISA and NIST inspired lab score / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Maturity Score", [
      `<div class="detail-score ${score.score >= 80 ? "green" : score.score >= 65 ? "yellow" : "red"}">${esc(score.label || "WATCH")} ${esc(score.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(score.summary || "SOC score waiting.")}</div>`,
    ].join("")),
    card("Functions", table(["Function", "Score", "Why"], (score.rows || []).map((r) => [r.function, r.score, r.why]))),
    card("Top Gaps", table(["Function", "Score", "Why"], (score.top_gaps || []).map((r) => [r.function, r.score, r.why]))),
    card("Safe Next Steps", table(["#", "Action"], (score.next || []).map((x, idx) => [idx + 1, x]))),
  ].join("");
  wireAskButtons();
}

function renderModelTournament(state, tournament) {
  title.textContent = "SOCX MODEL TOURNAMENT";
  subtitle.textContent = `${state.hostname || "pfSense"} / Pi 5 AI route tests / read-only`;
  const exp = tournament.experiment || {};
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Tournament", [
      `<div class="detail-reason">${esc(tournament.summary || "Model tournament waiting.")}</div>`,
      table(["Experiment", "State", "Progress", "Result"], [[exp.kind || "--", exp.active ? "running" : (exp.status || "--"), exp.progress ?? "--", exp.result || "--"]]),
      `<div class="story-actions"><button class="why-target-button" data-command-action="model-tournament">Run Compare</button><button class="why-target-button" data-command-action="pi-bench">Run Bench</button><button class="why-target-button" data-command-action="pi-explain">Explain Pulse</button></div>`,
      `<pre id="research-command-output" class="why-output">No Pi lab command has run from this page yet.</pre>`,
    ].join("")),
    card("Model Routes", table(["Node", "Role", "Model", "Backend", "State", "Summary"], (tournament.rows || []).map((r) => [
      r.node,
      r.role,
      r.model,
      r.backend,
      r.state,
      r.summary,
    ]))),
    card("Recommended Tests", table(["Test", "Preferred", "Why"], (tournament.benchmarks || []).map((b) => [b.test, b.preferred, b.why]))),
    card("Commands", table(["#", "Command"], (tournament.commands || []).map((cmd, idx) => [idx + 1, cmd]))),
  ].join("");
  wireAskButtons();
  wireResearchCommands();
}

function renderEvidenceDrawer(state, evidence) {
  title.textContent = "SOCX EVIDENCE DRAWER";
  subtitle.textContent = `${state.hostname || "pfSense"} / answer evidence / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Answer Context", [
      `<div class="detail-score ${evidence.confidence?.score >= 78 ? "green" : "yellow"}">${esc(evidence.confidence?.label || "medium")} ${esc(evidence.confidence?.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(evidence.summary || "SOCX evidence is being collected.")}</div>`,
    ].join("")),
    card("Evidence Used", table(["Source", "Strength", "Detail"], (evidence.evidence_used || []).map((r) => [r.source, r.strength, r.detail]))),
    card("Evidence Cards", `<div class="story-evidence-grid">${(evidence.cards || []).map(evidenceCard).join("")}</div>`),
    card("Timeline", table(["Time", "Kind", "Severity", "Title", "Evidence"], (evidence.timeline || []).map((r) => [r.time, r.kind, r.severity, r.title, r.evidence || r.detail]))),
    card("Flow Evidence", table(["Asset", "Peer", "Proto", "Service", "State"], (evidence.flows || []).map((r) => [r.asset, r.peer, r.proto, r.service || r.app, r.state]))),
    card("Packet Evidence", table(["Time", "Action", "Proto", "Source", "Destination", "Service"], (evidence.packets || []).map((r) => [r.time, r.action, r.proto, r.src_label || r.src, r.dst_label || r.dst, r.service]))),
    card("Safe Action Queue", table(["Priority", "Action", "Why", "Command", "Approval"], (evidence.safe_action_queue || []).map((r) => [r.priority, r.action, r.why, r.command, r.approval]))),
  ].join("");
  wireAskButtons();
}

function renderNotebook(state, notebook) {
  title.textContent = "SOCX ANALYST NOTEBOOK";
  subtitle.textContent = `${state.hostname || "pfSense"} / changes, hunts, evidence / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Notebook", [
      `<div class="detail-reason">${esc(notebook.summary || "Notebook waiting.")}</div>`,
      `<div class="story-actions"><a href="/incident-report">Report</a><a href="/threat-story">Threat Story</a><a href="/evidence">Evidence Drawer</a></div>`,
    ].join("")),
    card("Current Notes", table(["Time", "Type", "Severity", "Title", "Detail", "Page"], (notebook.rows || []).map((r) => [r.time, r.type, r.severity, r.title, r.detail, r.page]))),
    card("Ask SOCX", `<div class="why-targets">${(notebook.questions || []).map((q) => `<button class="why-target-button" data-ask="${esc(q)}">${esc(q)}</button>`).join("")}</div>`),
  ].join("");
  wireAskButtons();
}

function renderActions(state, actions) {
  title.textContent = "SOCX SAFE ACTION QUEUE";
  subtitle.textContent = `${state.hostname || "pfSense"} / approval states / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Queue", `<div class="detail-reason">These are suggested operator steps. SOCX does not apply pfSense policy changes from this queue.</div>`),
    card("Safe Actions", table(["Priority", "Action", "Why", "Command", "Approval"], (actions.rows || []).map((r) => [r.priority, r.action, r.why, r.command, r.approval]))),
  ].join("");
  wireAskButtons();
}

function renderMissionMode(state, mode) {
  title.textContent = "SOCX MISSION MODE";
  subtitle.textContent = `${state.hostname || "pfSense"} / wall focus signal / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Mission Focus", [
      `<div class="detail-score ${mode.tone || "green"}">${esc(mode.mode || "NORMAL WATCH")}</div>`,
      `<div class="detail-reason">${esc(mode.headline || "SOCX watching current evidence.")}</div>`,
      kv("Active", mode.active ? "yes" : "no", mode.active ? "yellow" : "green"),
    ].join("")),
    card("Affected / Matters", table(["#", "Signal"], (mode.affected || []).map((x, idx) => [idx + 1, x]))),
    card("Next Checks", table(["#", "Action"], (mode.next || []).map((x, idx) => [idx + 1, x]))),
  ].join("");
}

function renderMemorySystem(state, memory) {
  title.textContent = "SOCX MEMORY";
  subtitle.textContent = `${state.hostname || "pfSense"} / learned normal / local passive`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Memory State", [
      `<div class="detail-score ${memory.score >= 80 ? "green" : memory.score >= 60 ? "yellow" : "red"}">${esc(memory.label || "LEARNING")} ${esc(memory.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(memory.summary || "SOCX memory is learning.")}</div>`,
      kv("Privacy", memory.privacy || "--", "cyan"),
    ].join("")),
    card("Device Baselines", table(["Device", "Profile", "Seen", "Normal Apps", "Current Apps", "Unusual"], (memory.devices || []).map((d) => [
      d.friendly_name || d.asset,
      d.profile,
      d.seen,
      (d.normal_apps || []).join(", "),
      (d.current_apps || []).join(", "),
      (d.unusual || []).join(", ") || "--",
    ]))),
    card("Now Watching", table(["Group", "Apps"], (memory.now_watching || []).map((g) => [g.group, (g.apps || []).join(", ")]))),
    card("Profiles", table(["Profile", "Devices"], (memory.profiles || []).map((p) => [p.name, p.count]))),
    card("Flow Memory", [
      `<div class="detail-reason">${esc(memory.flow_memory?.summary || "Flow memory waiting.")}</div>`,
      table(["Path", "App", "Bytes", "State", "Why"], (memory.flow_memory?.watch_rows || []).map((r) => [
        r.display_path || `${r.asset || "--"} -> ${r.peer || "--"}`,
        r.app || r.service || "--",
        r.bytes_h || "--",
        r.baseline_state || "--",
        r.why || "--",
      ])),
    ].join("")),
    card("Safe Next Steps", table(["#", "Action"], (memory.next || []).map((x, idx) => [idx + 1, x]))),
  ].join("");
  wireAskButtons();
}

function renderTwin(state, twin) {
  title.textContent = "SOCX AI NETWORK TWIN";
  subtitle.textContent = `${state.hostname || "pfSense"} / live assets, Pi AI, flows / read-only`;
  const nodeHtml = (twin.nodes || []).map((n) => `
    <div class="twin-node ${esc(n.tone || "cyan")} ${esc(n.type || "device")}" style="left:${Number(n.x || 50)}%;top:${Number(n.y || 50)}%" title="${esc(n.detail || "")}">
      <b>${esc(n.label || n.id)}</b>
      <span>${esc(n.type || "node")}</span>
    </div>`).join("");
  const linkHtml = (twin.links || []).slice(0, 18).map((l, idx) => `
    <div class="twin-link-row ${esc(l.tone || "cyan")}">
      <span>${String(idx + 1).padStart(2, "0")}</span>
      <b>${esc(l.source)} -> ${esc(l.target)}</b>
      <em>${esc(l.label || "flow")} x${esc(l.weight || 1)}</em>
    </div>`).join("");
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Twin Map", [
      `<div class="detail-reason">${esc(twin.summary || "Network twin waiting.")}</div>`,
      `<div class="twin-map"><div class="twin-rings"></div>${nodeHtml}</div>`,
    ].join("")),
    card("Live Links", `<div class="twin-links">${linkHtml || "<span class=\"muted\">No links ready.</span>"}</div>`),
    card("Flow Stories", table(["#", "Story"], (twin.stories || []).map((x, idx) => [idx + 1, x]))),
    card("Top Apps", table(["App", "Count"], (twin.top_apps || []).map((x) => [x.name, x.count]))),
    card("Memory Summary", [
      kv("State", `${twin.memory?.label || "--"} ${twin.memory?.score ?? "--"}/100`, "cyan"),
      kv("Summary", twin.memory?.summary || "--", "cyan"),
      `<div class="story-actions"><a href="/memory">Open Memory</a><a href="/flows">Open Flows</a><a href="/devices">Open Devices</a></div>`,
    ].join("")),
  ].join("");
  wireAskButtons();
}

function renderAutomation(state, automation) {
  title.textContent = "SOCX AUTOMATION CENTER";
  subtitle.textContent = `${state.hostname || "pfSense"} / schedules, caches, safe commands / read-only`;
  const rows = automation.rows || [];
  const ready = rows.filter((r) => ["READY", "LIVE", "OK"].includes(String(r.state || "").toUpperCase())).length;
  const watch = rows.filter((r) => ["WAITING", "STALE", "WATCH"].includes(String(r.state || "").toUpperCase())).length;
  const missing = rows.filter((r) => ["MISSING", "ERROR", "FAIL"].includes(String(r.state || "").toUpperCase())).length;
  const jobRows = rows.map((r) => [
    r.name || "--",
    r.state || "--",
    r.schedule || "--",
    r.last_run || "--",
    r.next_run || "--",
    r.result || "--",
  ]);
  const commandRows = rows.map((r) => [
    r.name || "--",
    r.command || "--",
    r.safety || "read-only",
    r.evidence || "--",
  ]);
  const askRows = rows.slice(0, 8).map((r) => ({
    label: r.name || "automation",
    question: `Explain this SOCX automation in plain English. Job: ${r.name || "--"}. State: ${r.state || "--"}. Schedule: ${r.schedule || "--"}. Last run: ${r.last_run || "--"}. Result: ${r.result || "--"}. Command: ${r.command || "--"}. Tell me what it does, whether it is healthy, and what safe thing to check next.`,
  }));
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Automation State", [
      `<div class="detail-score ${automation.score >= 80 ? "green" : automation.score >= 55 ? "yellow" : "red"}">${esc(automation.label || "WATCH")} ${esc(automation.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(automation.summary || "SOCX automation evidence is warming up.")}</div>`,
      kv("Ready", ready, "green"),
      kv("Watch", watch, watch ? "yellow" : "cyan"),
      kv("Needs Review", missing, missing ? "red" : "green"),
    ].join("")),
    card("Scheduled Jobs", table(["Job", "State", "Schedule", "Last Run", "Next", "Result"], jobRows)),
    card("Safe Commands", table(["Job", "Command", "Safety", "Evidence"], commandRows)),
    card("Operating Notes", table(["#", "Note"], (automation.next_focus || []).map((line, idx) => [idx + 1, line]))),
    card("Ask About Automation", `<div class="ask-list">${askRows.map((item) => `<div><span>${esc(item.label)}</span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No automation rows ready yet.</span>"}</div>`),
  ].join("");
  wireAskButtons();
}

function renderTimeline(state, timeline) {
  title.textContent = "SOCX UNIFIED TIMELINE";
  subtitle.textContent = `${state.hostname || "pfSense"} / changes, speed, IDS, AI, notes, actions / read-only`;
  const rows = timeline.rows || [];
  const rowTable = rows.map((r) => [
    r.time || "--",
    r.lane || "--",
    r.severity || "--",
    r.title || "--",
    r.detail || "--",
    r.source || "--",
  ]);
  const askRows = rows.slice(0, 10).map((r) => ({
    label: `${r.lane || "row"} ${r.time || ""}`,
    detail: `${r.title || "--"} ${r.detail || ""}`,
    question: `Explain this SOCX timeline row in plain English. Time: ${r.time || "--"}. Lane: ${r.lane || "--"}. Severity: ${r.severity || "--"}. Title: ${r.title || "--"}. Detail: ${r.detail || "--"}. Source: ${r.source || "--"}. Tell me what probably happened before/after it and the safest next check.`,
  }));
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Timeline State", [
      `<div class="detail-score cyan">${esc(timeline.summary || "Timeline warming up")}</div>`,
      kv("Rows", rows.length, "cyan"),
      kv("Lanes", (timeline.lanes || []).length, "cyan"),
      kv("Safety", timeline.read_only ? "read-only" : "unknown", timeline.read_only ? "green" : "yellow"),
    ].join("")),
    card("By Lane", table(["Lane", "Events"], (timeline.lanes || []).map((r) => [r.name, r.count]))),
    card("By Severity", table(["Severity", "Events"], (timeline.severities || []).map((r) => [r.name, r.count]))),
    card("What Happened", table(["Time", "Lane", "Severity", "Title", "Detail", "Source"], rowTable)),
    card("Safe Next Steps", table(["#", "Action"], (timeline.next || []).map((line, idx) => [idx + 1, line]))),
    card("Ask About Timeline", `<div class="ask-list">${askRows.map((item) => `<div><span title="${esc(item.detail)}">${esc(item.label)}: ${esc(item.detail)}</span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No timeline rows ready yet.</span>"}</div>`),
  ].join("");
  wireAskButtons();
}

function acknowledgedReviews() {
  try {
    return new Set(JSON.parse(localStorage.getItem("socx_review_ack") || "[]"));
  } catch {
    return new Set();
  }
}

function setReviewAcknowledged(key) {
  const ack = acknowledgedReviews();
  ack.add(key);
  localStorage.setItem("socx_review_ack", JSON.stringify([...ack].slice(-80)));
  refresh();
}

function reviewKey(row) {
  return `${row.priority || ""}|${row.item || ""}|${row.observation || ""}`;
}

function wireReviewQueue() {
  document.querySelectorAll("[data-review-ack]").forEach((button) => {
    button.addEventListener("click", () => setReviewAcknowledged(button.getAttribute("data-review-ack") || ""));
  });
  wireAskButtons();
}

function renderReviewQueue(state, review) {
  title.textContent = "SOCX AUTOPILOT REVIEW";
  subtitle.textContent = `${state.hostname || "pfSense"} / human approval queue / draft-only`;
  const ack = acknowledgedReviews();
  const rows = (review.rows || []).map((r) => ({ ...r, ack: ack.has(reviewKey(r)) }));
  const pending = rows.filter((r) => !r.ack);
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Review State", [
      `<div class="detail-score ${review.label === "CLEAR" ? "green" : "yellow"}">${esc(review.label || "REVIEW")} ${esc(review.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(review.summary || "SOCX review queue is warming up.")}</div>`,
      kv("Pending", pending.length, pending.length ? "yellow" : "green"),
      kv("Safety", review.read_only ? "no pfSense change applied" : "unknown", review.read_only ? "green" : "yellow"),
    ].join("")),
    card("Autopilot Items", table(["Pri", "Item", "Disposition", "Confidence", "Why It Matters", "Recommendation", "Approval"], rows.map((r) => [
      r.ack ? "ACK" : `${r.priority || "--"}${Number(r.count || 1) > 1 ? ` x${r.count}` : ""}`,
      r.item || "--",
      r.disposition || "--",
      r.confidence || "--",
      r.why_matters || r.observation || "--",
      r.recommendation || "--",
      r.approval || "--",
    ]))),
    card("Operator Controls", `<div class="ask-list">${rows.map((r) => {
      const key = reviewKey(r);
      return `<div class="${r.ack ? "muted" : ""}"><span title="${esc(r.recommendation)}">${esc(r.priority)} ${esc(r.item)}: ${esc(r.command || "--")}</span><a href="${esc(r.page || "/mission")}">Open</a><button data-review-ack="${esc(key)}">${r.ack ? "Acknowledged" : "Acknowledge"}</button>${askButton(r.question || `Explain review item ${r.item || ""}`)}</div>`;
    }).join("") || "<span class=\"muted\">No review rows ready.</span>"}</div>`),
    card("Queue Actions", table(["Action", "Meaning"], (review.actions || []).map((a) => [a, a === "Acknowledge is browser-local" ? "hide it for this browser only" : "opens or drafts evidence; no automatic firewall changes"]))),
  ].join("");
  wireReviewQueue();
}

function renderOwnerMap(state, owner) {
  title.textContent = "SOCX DEVICE OWNER MAP";
  subtitle.textContent = `${state.hostname || "pfSense"} / friendly names, roles, apps / read-only`;
  const rows = owner.rows || [];
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Owner Map State", [
      `<div class="detail-score ${owner.label === "MAPPED" ? "green" : "yellow"}">${esc(owner.label || "LEARNING")} ${esc(owner.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(owner.summary || "Device owner map is learning.")}</div>`,
      kv("Owners", (owner.owners || []).join(", ") || "--", "cyan"),
      kv("Safety", owner.read_only ? "passive labels only" : "unknown", owner.read_only ? "green" : "yellow"),
    ].join("")),
    card("Assets", table(["Asset", "Friendly", "Owner", "Profile", "Apps", "Traffic", "Trust", "State", "Source"], rows.map((r) => [
      r.asset || "--",
      r.friendly || "--",
      r.owner || "--",
      r.profile || "--",
      (r.apps || []).join(", ") || (r.services || []).join(", ") || "--",
      r.traffic || "--",
      r.trust ?? "--",
      r.state || "--",
      r.label_source || "--",
    ]))),
    card("What To Confirm", table(["Device", "Current Guess", "Next"], rows.filter((r) => r.confidence !== "confirmed" || r.state === "WATCH").slice(0, 10).map((r) => [
      r.friendly || r.asset || "--",
      `${r.owner || "--"} / ${r.profile || "--"} / ${r.confidence || "--"}`,
      r.next || "--",
    ]))),
    card("Ask About Devices", `<div class="ask-list">${rows.slice(0, 10).map((r) => `<div><span title="${esc(r.next)}">${esc(r.friendly || r.asset)}: ${esc(r.owner)} ${esc(r.state)}</span>${askButton(r.question || `Explain device ${r.asset || ""}`)}</div>`).join("") || "<span class=\"muted\">No device rows ready.</span>"}</div>`),
  ].join("");
  wireAskButtons();
}

function renderOwnerEditor(state, owner) {
  title.textContent = "SOCX OWNER EDITOR";
  subtitle.textContent = `${state.hostname || "pfSense"} / SOCX labels only / approval-safe`;
  const rows = owner.rows || [];
  const editorRows = rows.map((r, idx) => `
    <div class="owner-edit-row">
      <input data-owner-field="asset" data-owner-idx="${idx}" value="${esc(r.asset || "")}" readonly>
      <input data-owner-field="friendly" data-owner-idx="${idx}" value="${esc(r.friendly || r.asset || "")}" placeholder="Friendly name">
      <input data-owner-field="owner" data-owner-idx="${idx}" value="${esc(r.owner || "Home / Lab")}" placeholder="Owner">
      <input data-owner-field="role" data-owner-idx="${idx}" value="${esc(r.profile || "device")}" placeholder="Role">
    </div>`).join("");
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Label Editor", [
      `<div class="detail-reason">Saves SOCX display labels only. No pfSense firewall, DNSBL, IDS, VPN, or DHCP setting is changed.</div>`,
      `<div class="owner-editor">${editorRows || "<span class=\"muted\">No learned devices ready yet.</span>"}</div>`,
      `<div class="story-actions"><button id="owner-save">Save SOCX Labels</button><a href="/owner-map">Open Owner Map</a></div>`,
      `<pre class="why-output" id="owner-save-output">Overrides file: ${esc(owner.override_file || "/usr/local/etc/socx_owner_overrides.json")}</pre>`,
    ].join("")),
    card("Current Guess", table(["Asset", "Friendly", "Owner", "Role", "Source"], rows.map((r) => [r.asset || "--", r.friendly || "--", r.owner || "--", r.profile || "--", r.label_source || "--"]))),
  ].join("");
  document.getElementById("owner-save")?.addEventListener("click", async () => {
    const grouped = {};
    document.querySelectorAll("[data-owner-idx]").forEach((input) => {
      const idx = input.getAttribute("data-owner-idx");
      const field = input.getAttribute("data-owner-field");
      grouped[idx] = grouped[idx] || {};
      grouped[idx][field] = input.value.trim();
    });
    const out = document.getElementById("owner-save-output");
    out.textContent = "Saving SOCX label overrides...";
    try {
      const result = await fetch("/api/owner-overrides", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ rows: Object.values(grouped) }) }).then((r) => r.json());
      out.textContent = result.ok ? `Saved ${result.count} SOCX label override(s) to ${result.path}` : `Save failed: ${result.error || "unknown"}`;
    } catch (err) {
      out.textContent = `Save failed: ${err}`;
    }
  });
}

function renderPacketNoise(state, noise) {
  title.textContent = "SOCX PACKET NOISE";
  subtitle.textContent = `${state.hostname || "pfSense"} / hide from wall, keep evidence`;
  const rows = noise.rows || [];
  const selected = new Set(noise.suppress_on_wall || []);
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Noise Reducer", [
      `<div class="detail-score ${noise.label === "ACTIVE" ? "green" : "cyan"}">${esc(noise.label || "OFF")}</div>`,
      `<div class="detail-reason">${esc(noise.summary || "Packet noise reducer is waiting.")}</div>`,
      `<div class="noise-list">${rows.map((r) => `<label><input type="checkbox" data-noise-category="${esc(r.category)}" ${selected.has(r.category) ? "checked" : ""}> <b>${esc(r.category)}</b> <span>${esc(r.count)} row(s), ${esc(r.wall)}</span></label>`).join("") || "<span class=\"muted\">No packet categories yet.</span>"}</div>`,
      `<div class="story-actions"><button id="noise-save">Save Wall Filter</button><a href="/incidents">Open Incidents</a></div>`,
      `<pre class="why-output" id="noise-output">${esc(noise.safety || "")}\n${esc(noise.config_file || "")}</pre>`,
    ].join("")),
    card("Current Categories", table(["Category", "Count", "Wall", "Evidence"], rows.map((r) => [r.category || "--", r.count || 0, r.wall || "--", r.evidence || "--"]))),
  ].join("");
  document.getElementById("noise-save")?.addEventListener("click", async () => {
    const suppress = [...document.querySelectorAll("[data-noise-category]:checked")].map((x) => x.getAttribute("data-noise-category"));
    const out = document.getElementById("noise-output");
    out.textContent = "Saving SOCX wall packet filter...";
    try {
      const result = await fetch("/api/packet-noise", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ suppress_on_wall: suppress }) }).then((r) => r.json());
      out.textContent = result.ok ? `Saved wall filter: ${(result.config?.suppress_on_wall || []).join(", ") || "none"}` : `Save failed: ${result.error || "unknown"}`;
    } catch (err) {
      out.textContent = `Save failed: ${err}`;
    }
  });
}

function renderConfidence(state, confidence) {
  title.textContent = "SOCX CONFIDENCE";
  subtitle.textContent = `${state.hostname || "pfSense"} / source truth meter`;
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Confidence Meter", [
      `<div class="detail-score ${confidence.label === "HIGH" ? "green" : "yellow"}">${esc(confidence.label || "UNKNOWN")} ${esc(confidence.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(confidence.summary || "Confidence evidence is warming up.")}</div>`,
    ].join("")),
    card("Evidence Sources", table(["Source", "Kind", "Confidence", "Detail"], (confidence.rows || []).map((r) => [r.source || "--", r.kind || "--", r.confidence || "--", r.detail || "--"]))),
  ].join("");
}

function renderIncidentFocus(state, focus) {
  title.textContent = "SOCX INCIDENT FOCUS";
  subtitle.textContent = `${state.hostname || "pfSense"} / temporary operator mode`;
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Focus Mode", [
      `<div class="detail-score ${focus.active ? "yellow" : "green"}">${esc(focus.mode || "NORMAL")}</div>`,
      `<div class="detail-reason">${esc(focus.summary || "Normal wall mode")}</div>`,
      kv("Remaining", `${Math.ceil(Number(focus.remaining_sec || 0) / 60)}m`, focus.active ? "yellow" : "green"),
      `<div class="story-actions"><button id="focus-on">Start 10m Focus</button><button id="focus-off">Stop Focus</button><a href="/review-queue">Review Queue</a></div>`,
      `<pre class="why-output" id="focus-output">Focus mode changes SOCX display behavior only. pfSense policy is unchanged.</pre>`,
    ].join("")),
  ].join("");
  const setFocus = async (active) => {
    const out = document.getElementById("focus-output");
    out.textContent = active ? "Starting incident focus..." : "Stopping incident focus...";
    const result = await fetch("/api/incident-focus", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ active, minutes: 10 }) }).then((r) => r.json());
    out.textContent = `${result.mode || "--"} ${result.remaining_sec || 0}s`;
    refresh();
  };
  document.getElementById("focus-on")?.addEventListener("click", () => setFocus(true));
  document.getElementById("focus-off")?.addEventListener("click", () => setFocus(false));
}

function renderMaintenance(state, maint) {
  title.textContent = "SOCX MAINTENANCE";
  subtitle.textContent = `${state.hostname || "pfSense"} / services, files, freshness`;
  root.innerHTML = [
    ...renderTruthCards(state),
    card("Maintenance State", [
      `<div class="detail-score ${maint.label === "OK" ? "green" : "yellow"}">${esc(maint.label || "WATCH")}</div>`,
      `<div class="detail-reason">${esc(maint.summary || "Maintenance center warming up.")}</div>`,
    ].join("")),
    card("Checks", table(["Check", "State", "Detail"], (maint.checks || []).map((r) => [r.name || "--", r.state || "--", r.detail || "--"]))),
    card("Files", table(["File", "State", "Age", "Size", "Path"], (maint.files || []).map((r) => [r.name || "--", r.state || "--", r.age || "--", r.size || 0, r.path || "--"]))),
    card("Commands", table(["#", "Command"], (maint.commands || []).map((cmd, idx) => [idx + 1, cmd]))),
  ].join("");
}

function renderMissionConsole(state, consoleData) {
  title.textContent = "SOCX AI SOC CONSOLE";
  subtitle.textContent = `${state.hostname || "pfSense"} / mission assurance / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Mission Console", [
      `<div class="story-title"><span>${esc(consoleData.headline || "SOCX mission console warming up")}</span><b class="${Number(consoleData.score || 0) >= 80 ? "green" : "yellow"}">${esc(consoleData.label || "WATCH")} ${esc(consoleData.score ?? "--")}</b></div>`,
      `<div class="detail-reason">This page is the slow-thinking operator view: what matters, what is uncertain, and what evidence would make the next decision better.</div>`,
    ].join("")),
    card("What Matters Now", table(["#", "Signal"], (consoleData.what_matters || []).map((x, idx) => [idx + 1, x]))),
    card("What Changed", table(["Time", "Severity", "Change", "Detail"], (consoleData.what_changed || []).slice(0, 8).map((r) => [r.time || "--", r.severity || "--", r.title || "--", r.detail || r.to || "--"]))),
    card("Probably Noise", table(["#", "Reason"], (consoleData.probably_noise || []).map((x, idx) => [idx + 1, x]))),
    card("Uncertainty", table(["#", "Unknown / Stale Signal"], (consoleData.uncertainty || []).map((x, idx) => [idx + 1, x]))),
    card("Better Evidence", table(["#", "How To Improve Confidence"], (consoleData.better_evidence || []).map((x, idx) => [idx + 1, x]))),
    card("ATT&CK Context", table(["Signal", "Priority", "Disposition", "ATT&CK", "D3FEND"], (consoleData.attack || []).map((r) => [
      r.signal || "--",
      r.priority || "--",
      r.disposition || "--",
      r.attack?.id || "--",
      r.d3fend?.name || "--",
    ]))),
    card("Next Actions", table(["#", "Command"], (consoleData.next_actions || []).map((x, idx) => [idx + 1, x]))),
  ].join("");
}

function renderConfigSim(state, sim) {
  title.textContent = "SOCX CONFIG SIMULATOR";
  subtitle.textContent = `${state.hostname || "pfSense"} / blast radius checks / no changes applied`;
  const plans = sim.plans || [];
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Simulator State", [
      `<div class="detail-score yellow">${esc(sim.label || "DRAFT ONLY")} ${esc(sim.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(sim.summary || "Config simulator is warming up.")}</div>`,
      kv("Safety", sim.read_only ? "read-only; approval required" : "unknown", sim.read_only ? "green" : "yellow"),
    ].join("")),
    card("Draft Plans", table(["Plan", "DNS", "VPN", "Streaming", "Security", "Evidence", "Rollback"], plans.map((p) => [
      p.plan || "--",
      p.risk_dns || "--",
      p.risk_vpn || "--",
      p.risk_streaming || "--",
      p.risk_security || "--",
      p.evidence_needed || "--",
      p.rollback || "--",
    ]))),
    card("Commands", table(["Plan", "Command", "Approval"], plans.map((p) => [p.plan || "--", p.command || "--", p.approval_required ? "required" : "read-only"]))),
    card("Guardrails", table(["#", "Rule"], (sim.guardrails || []).map((x, idx) => [idx + 1, x]))),
    card("Ask About Plans", `<div class="ask-list">${plans.map((p) => `<div><span title="${esc(p.evidence_needed)}">${esc(p.plan)}: ${esc(p.command)}</span>${askButton(`Explain this SOCX config simulator plan in plain English. Plan: ${p.plan || "--"}. DNS risk: ${p.risk_dns || "--"}. VPN risk: ${p.risk_vpn || "--"}. Streaming risk: ${p.risk_streaming || "--"}. Security risk: ${p.risk_security || "--"}. Evidence needed: ${p.evidence_needed || "--"}. Rollback: ${p.rollback || "--"}.`)}</div>`).join("")}</div>`),
  ].join("");
  wireAskButtons();
}

function renderBaseline(state, baseline) {
  title.textContent = "SOCX BASELINE LEARNING";
  subtitle.textContent = `${state.hostname || "pfSense"} / learned normal / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Baseline State", [
      `<div class="detail-score ${baseline.label === "WATCH" ? "yellow" : "green"}">${esc(baseline.label || "LEARNING")} ${esc(baseline.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(baseline.summary || "Baseline learning is warming up.")}</div>`,
      kv("Safety", baseline.read_only ? "passive learning only" : "unknown", baseline.read_only ? "green" : "yellow"),
    ].join("")),
    card("Baseline Groups", table(["Category", "Normal", "Current", "State", "Confidence", "Next"], (baseline.rows || []).map((r) => [
      r.category || "--",
      r.normal || "--",
      r.current || "--",
      r.state || "--",
      r.confidence || "--",
      r.next || "--",
    ]))),
    card("Top Apps", table(["App", "Count"], (baseline.top_apps || []).map((r) => [r.name || "--", r.count || 0]))),
    card("Watch Flows", table(["Path", "App", "Bytes", "State", "Why"], (baseline.watch_rows || []).slice(0, 10).map((r) => [
      flowPath(r),
      r.app || r.service || "--",
      r.bytes_h || "--",
      r.baseline_state || "--",
      r.why || "--",
    ]))),
  ].join("");
}

function renderSinceYesterday(state, since) {
  title.textContent = "SOCX SINCE YESTERDAY";
  subtitle.textContent = `${state.hostname || "pfSense"} / baseline movement / read-only`;
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Change Readout", [
      `<div class="detail-score ${since.label === "STABLE" ? "green" : "yellow"}">${esc(since.label || "LEARNING")} ${esc(since.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(since.summary || "SOCX retained history is warming up.")}</div>`,
    ].join("")),
    card("Important Movement", table(["Signal", "State", "Delta", "Detail"], (since.important || []).map((r) => [
      r.signal || "--",
      r.state || "--",
      r.delta ?? "--",
      r.detail || "--",
    ]))),
    card("All Signals", table(["Signal", "State", "Delta", "Detail"], (since.rows || []).map((r) => [
      r.signal || "--",
      r.state || "--",
      r.delta ?? "--",
      r.detail || "--",
    ]))),
    card("Ask About Changes", `<div class="ask-list">${(since.important || since.rows || []).slice(0, 8).map((r) => `<div><span>${esc(r.signal)} ${esc(r.state)}: ${esc(r.detail)}</span>${askButton(`Explain this SOCX since-yesterday change. Signal: ${r.signal || "--"}. State: ${r.state || "--"}. Delta: ${r.delta ?? "--"}. Detail: ${r.detail || "--"}. Tell me if it matters and what safe thing to check next.`)}</div>`).join("") || "<span class=\"muted\">No change rows ready.</span>"}</div>`),
  ].join("");
  wireAskButtons();
}

function renderDailyBrief(state, brief) {
  title.textContent = "SOCX DAILY BRIEF";
  subtitle.textContent = `${state.hostname || "pfSense"} / morning readout / read-only`;
  const since = brief.since_yesterday || {};
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Brief", [
      `<div class="story-title"><span>${esc(brief.headline || "SOCX brief warming up")}</span><b class="${brief.status === "normal" ? "green" : "yellow"}">${esc(brief.status || "watch")}</b></div>`,
      `<div class="detail-reason">${esc(brief.generated || "--")}</div>`,
    ].join("")),
    card("Summary", table(["#", "Signal"], (brief.summary || []).map((x, idx) => [idx + 1, x]))),
    card("Sections", table(["Section", "Readout"], (brief.sections || []).map((r) => [r.label || "--", r.value || "--"]))),
    card("Since Yesterday", [
      `<div class="detail-reason">${esc(since.summary || "History warming up.")}</div>`,
      table(["Signal", "State", "Delta", "Detail"], (since.important || since.rows || []).slice(0, 8).map((r) => [r.signal || "--", r.state || "--", r.delta ?? "--", r.detail || "--"])),
    ].join("")),
    card("Next", table(["#", "Command"], (brief.next || []).map((x, idx) => [idx + 1, x]))),
    card("Ask About Brief", `<div class="why-targets">${[
      "Explain the SOCX daily brief in plain English.",
      "What changed since yesterday and what should I check first?",
      "What in this brief is probably noise?",
      "What evidence would make this brief more certain?",
    ].map((q) => `<button class="why-target-button" data-ask="${esc(q)}">${esc(q)}</button>`).join("")}</div>`),
  ].join("");
  wireAskButtons();
}

function renderMovie(state, movie) {
  title.textContent = "SOCX NETWORK MOVIE";
  subtitle.textContent = `${state.hostname || "pfSense"} / slow readable replay / ${movie.hero?.window || "6h"} window`;
  const scenes = movie.scenes || [];
  const pulses = movie.pulses || [];
  const laneStory = movie.lane_story || [];
  const laneEvents = movie.lane_events || [];
  const laneHtml = laneStory.map((lane, idx) => {
    const events = laneEvents.filter((e) => e.target === lane.name || e.source === lane.name).slice(0, 4);
    return `<div class="movie-lane" style="left:${Number(lane.x || 10)}%;top:${Number(lane.y || 10)}%">
      <b>${esc(lane.name)}</b>
      <span>${esc(lane.summary || "")}</span>
      ${events.map((e) => `<i class="${String(e.severity || "LOW").toLowerCase()}" title="${esc(e.detail)}">${esc(e.label).slice(0, 24)}</i>`).join("")}
    </div>`;
  }).join("");
  const sceneDots = scenes.slice(0, 14).map((r, idx) => {
    const sev = String(r.severity || "LOW").toUpperCase();
    const tone = sev === "HIGH" || sev === "CRITICAL" ? "red" : sev === "MED" || sev === "WARN" ? "yellow" : r.lane === "AI" || r.lane === "DNSBL" ? "purple" : "green";
    return `<div class="movie-dot ${tone}" style="left:${Number(r.x || 50)}%;top:${Number(r.y || 50)}%" title="${esc(r.time)} ${esc(r.lane)} ${esc(r.scene)}">
      <span>${esc(r.lane).slice(0, 4)}</span>
    </div>`;
  }).join("");
  const sceneRows = scenes.map((r) => [
    r.time || "--",
    r.lane || "--",
    r.severity || "--",
    r.scene || "--",
    r.detail || "--",
  ]);
  const pulseRows = pulses.map((r) => [
    r.kind || "--",
    r.path || "--",
    r.app || "--",
    r.traffic || "--",
    r.why || "--",
  ]);
  const askRows = scenes.slice(0, 8).map((r) => ({
    label: `${r.lane || "scene"} ${r.time || ""}`,
    detail: `${r.scene || "--"} ${r.detail || ""}`,
    question: `Explain this SOCX Network Movie scene. Time: ${r.time || "--"}. Lane: ${r.lane || "--"}. Severity: ${r.severity || "--"}. Scene: ${r.scene || "--"}. Detail: ${r.detail || "--"}. Source: ${r.source || "--"}. Tell me what it means and what safe page or command to check next.`,
  }));
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Movie Readout", [
      `<div class="detail-score ${movie.hero?.state === "LIVE" ? "green" : "yellow"}">${esc(movie.hero?.state || "WATCH")} ${esc(movie.hero?.score || "--")}/100</div>`,
      `<div class="detail-reason">${esc(movie.summary || "SOCX movie warming up.")}</div>`,
      kv("Twin", `${movie.hero?.nodes || 0} nodes / ${movie.hero?.links || 0} links`, "cyan"),
      kv("Threat", movie.hero?.threat || "--", String(movie.hero?.threat || "").toUpperCase() === "QUIET" ? "green" : "yellow"),
    ].join("")),
    card("Live Scene", `<div class="movie-stage movie-stage-lanes"><div class="movie-rings"></div><div class="movie-core">SOCX<br>LIVE</div>${laneHtml || sceneDots || "<span class=\"muted\">No scenes yet.</span>"}</div>`),
    card("Scene Timeline", table(["Time", "Lane", "Severity", "Scene", "Detail"], sceneRows)),
    card("Live Pulses", table(["Kind", "Path", "App", "Traffic", "Why"], pulseRows)),
    card("Open Related Views", table(["Action", "Open", "Why"], (movie.commands || []).map((cmd) => [cmd.label || "--", cmd.href || "--", cmd.why || "--"]))),
    card("Ask About Movie", `<div class="ask-list">${askRows.map((item) => `<div><span title="${esc(item.detail)}">${esc(item.label)}: ${esc(item.detail)}</span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No movie scenes ready yet.</span>"}</div>`),
  ].join("");
  wireAskButtons();
}

function renderProjects(state, lab) {
  title.textContent = "SOCX PROJECT LAB";
  subtitle.textContent = `${state.hostname || "pfSense"} / experimental modules / read-only roadmap`;
  const rows = lab.projects || [];
  const projectRows = rows.map((r) => [
    r.name || "--",
    r.state || "--",
    r.page || "--",
    r.command || "--",
    r.summary || "--",
  ]);
  const askRows = rows.map((r) => ({
    label: r.name || "project",
    question: `Explain this SOCX project module in plain English. Project: ${r.name || "--"}. State: ${r.state || "--"}. Page: ${r.page || "--"}. Command: ${r.command || "--"}. Summary: ${r.summary || "--"}. Tell me how I should use it and what to improve next.`,
  }));
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Lab State", [
      `<div class="detail-score ${Number(lab.score || 0) >= 80 ? "green" : "yellow"}">${esc(lab.label || "WATCH")} ${esc(lab.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(lab.summary || "Project lab warming up.")}</div>`,
      kv("Safety", lab.read_only ? "read-only dashboard; changes stay approval-gated" : "unknown", lab.read_only ? "green" : "yellow"),
    ].join("")),
    card("Projects", table(["Project", "State", "Page", "Command", "What It Does"], projectRows)),
    card("Next Builds", table(["#", "Recommendation"], (lab.next_builds || []).map((line, idx) => [idx + 1, line]))),
    card("Ask About Projects", `<div class="ask-list">${askRows.map((item) => `<div><span>${esc(item.label)}</span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No projects ready.</span>"}</div>`),
  ].join("");
  wireAskButtons();
}

function renderGlitches(state, glitches) {
  title.textContent = "SOCX GLITCH TIMELINE";
  subtitle.textContent = `${state.hostname || "pfSense"} / wall frame samples / read-only`;
  const latest = glitches.latest || {};
  const rows = glitches.rows || [];
  const askRows = rows.slice(0, 8).map((r) => ({
    label: `${r.time || "--"} ${r.status || "--"}`,
    detail: r.summary || "--",
    question: `Explain this SOCX wall glitch sample. Time: ${r.time || "--"}. Status: ${r.status || "--"}. Pane: ${r.pane || "--"}. PIDs: ${r.pids || "--"}. Error bytes: ${r.err || 0}. Slowest loop: ${r.loop || 0}ms. Summary: ${r.summary || "--"}. Tell me what it likely means and what safe check to run next.`,
  }));
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Glitch State", [
      `<div class="detail-score ${glitches.label === "STABLE" ? "green" : "yellow"}">${esc(glitches.label || "WAITING")} ${esc(glitches.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(glitches.summary || "Glitch watcher is warming up.")}</div>`,
      kv("Latest", latest.status || "--", String(latest.status || "").toUpperCase() === "OK" ? "green" : "yellow"),
      kv("Pane", latest.pane_size || "--", "cyan"),
      kv("Error Bytes", latest.wall_error_bytes ?? "--", Number(latest.wall_error_bytes || 0) ? "yellow" : "green"),
      kv("Slowest Loop", `${latest.slowest_loop_ms || 0}ms`, Number(latest.slowest_loop_ms || 0) > 900 ? "yellow" : "green"),
    ].join("")),
    card("Samples", table(["Time", "Status", "Pane", "PIDs", "Err", "Loop", "Summary"], rows.map((r) => [
      r.time || "--",
      r.status || "--",
      r.pane || "--",
      r.pids ?? "--",
      r.err ?? 0,
      `${r.loop || 0}ms`,
      r.summary || "--",
    ]))),
    card("Safe Next Steps", table(["#", "Step"], (glitches.next || []).map((line, idx) => [idx + 1, line]))),
    card("Ask About Samples", `<div class="ask-list">${askRows.map((item) => `<div><span title="${esc(item.detail)}">${esc(item.label)}: ${esc(item.detail)}</span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No glitch samples ready yet.</span>"}</div>`),
  ].join("");
  wireAskButtons();
}

function renderWallHealth(state, health) {
  title.textContent = "SOCX WALL HEALTH";
  subtitle.textContent = `${state.hostname || "pfSense"} / renderer, tmux, web API, logs / read-only`;
  const checks = health.checks || [];
  const askRows = checks.map((r) => ({
    label: r.name || "check",
    detail: `${r.state || "--"} ${r.detail || ""}`,
    question: `Explain this SOCX Wall Health check. Check: ${r.name || "--"}. State: ${r.state || "--"}. Detail: ${r.detail || "--"}. Tell me whether this could cause flicker or display glitches and what safe command to run next.`,
  }));
  root.innerHTML = [
    ...renderTruthCards(state),
    askDock(),
    card("Guard State", [
      `<div class="detail-score ${health.label === "OK" ? "green" : "yellow"}">${esc(health.label || "WATCH")} ${esc(health.score ?? "--")}/100</div>`,
      `<div class="detail-reason">${esc(health.summary || "Wall health guard is warming up.")}</div>`,
      kv("Safety", health.read_only ? "read-only checks only" : "unknown", health.read_only ? "green" : "yellow"),
    ].join("")),
    card("Checks", table(["Check", "State", "Detail"], checks.map((r) => [r.name || "--", r.state || "--", r.detail || "--"]))),
    card("Commands", table(["#", "Command"], (health.commands || []).map((cmd, idx) => [idx + 1, cmd]))),
    card("Glitch Summary", [
      kv("Glitch State", health.glitch?.label || "--", health.glitch?.label === "STABLE" ? "green" : "yellow"),
      kv("Samples", (health.glitch?.rows || []).length, "cyan"),
      kv("Summary", health.glitch?.summary || "--", "cyan"),
      `<div class="story-actions"><a href="/glitches">Open Glitch Timeline</a><a href="/health">Open Health</a><a href="/doctor">Open Doctor</a></div>`,
    ].join("")),
    card("Ask About Wall Health", `<div class="ask-list">${askRows.map((item) => `<div><span title="${esc(item.detail)}">${esc(item.label)}: ${esc(item.detail)}</span>${askButton(item.question)}</div>`).join("") || "<span class=\"muted\">No health checks ready yet.</span>"}</div>`),
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
      `<div class="story-actions"><a href="/incident-report">Open Report</a><a href="/threat-story">Threat Story</a><a href="/why">Why</a></div>`,
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
  const doctor = page === "doctor"
    ? await fetch("/api/doctor", { cache: "no-store" }).then((r) => r.json())
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
  const flightRecorder = page === "flight-recorder"
    ? await fetch("/api/flight-recorder", { cache: "no-store" }).then((r) => r.json())
    : null;
  const threatMap = page === "map"
    ? await fetch("/api/threat-map", { cache: "no-store" }).then((r) => r.json())
    : null;
  const threatStory = page === "threat-story"
    ? await fetch("/api/threat-story", { cache: "no-store" }).then((r) => r.json())
    : null;
  const cockpit = page === "cockpit"
    ? await fetch("/api/cockpit", { cache: "no-store" }).then((r) => r.json())
    : null;
  const deviceDetail = page === "device"
    ? await fetch(`/api/device-detail${deviceTarget ? `?asset=${encodeURIComponent(deviceTarget)}` : ""}`, { cache: "no-store" }).then((r) => r.json())
    : null;
  const incidentReport = page === "incident-report"
    ? await fetch("/api/incident-report", { cache: "no-store" }).then((r) => r.json())
    : null;
  const coverage = page === "coverage"
    ? await fetch("/api/coverage", { cache: "no-store" }).then((r) => r.json())
    : null;
  const hunts = page === "hunts"
    ? await fetch("/api/hunts", { cache: "no-store" }).then((r) => r.json())
    : null;
  const rulesLab = page === "rules-lab"
    ? await fetch("/api/rules-lab", { cache: "no-store" }).then((r) => r.json())
    : null;
  const socScore = page === "soc-score"
    ? await fetch("/api/soc-score", { cache: "no-store" }).then((r) => r.json())
    : null;
  const modelTournament = page === "model-tournament"
    ? await fetch("/api/model-tournament", { cache: "no-store" }).then((r) => r.json())
    : null;
  const evidence = page === "evidence"
    ? await fetch("/api/evidence", { cache: "no-store" }).then((r) => r.json())
    : null;
  const notebook = page === "notebook"
    ? await fetch("/api/notebook", { cache: "no-store" }).then((r) => r.json())
    : null;
  const actions = page === "actions"
    ? await fetch("/api/actions", { cache: "no-store" }).then((r) => r.json())
    : null;
  const missionMode = page === "mission-mode"
    ? await fetch("/api/mission-mode", { cache: "no-store" }).then((r) => r.json())
    : null;
  const memorySystem = page === "memory"
    ? await fetch("/api/memory-system", { cache: "no-store" }).then((r) => r.json())
    : null;
  const twin = page === "twin"
    ? await fetch("/api/twin", { cache: "no-store" }).then((r) => r.json())
    : null;
  const automation = page === "automation"
    ? await fetch("/api/automation", { cache: "no-store" }).then((r) => r.json())
    : null;
  const timeline = page === "timeline"
    ? await fetch("/api/timeline", { cache: "no-store" }).then((r) => r.json())
    : null;
  const movie = page === "movie"
    ? await fetch("/api/movie", { cache: "no-store" }).then((r) => r.json())
    : null;
  const projects = page === "projects"
    ? await fetch("/api/projects", { cache: "no-store" }).then((r) => r.json())
    : null;
  const glitches = page === "glitches"
    ? await fetch("/api/glitches", { cache: "no-store" }).then((r) => r.json())
    : null;
  const wallHealth = page === "wall-health"
    ? await fetch("/api/wall-health", { cache: "no-store" }).then((r) => r.json())
    : null;
  const reviewQueue = page === "review-queue"
    ? await fetch("/api/review-queue", { cache: "no-store" }).then((r) => r.json())
    : null;
  const ownerMap = page === "owner-map"
    ? await fetch("/api/owner-map", { cache: "no-store" }).then((r) => r.json())
    : null;
  const ownerEditor = page === "owner-editor"
    ? await fetch("/api/owner-map", { cache: "no-store" }).then((r) => r.json())
    : null;
  const packetNoise = page === "packet-noise"
    ? await fetch("/api/packet-noise", { cache: "no-store" }).then((r) => r.json())
    : null;
  const confidence = page === "confidence"
    ? await fetch("/api/confidence", { cache: "no-store" }).then((r) => r.json())
    : null;
  const incidentFocus = page === "incident-focus"
    ? await fetch("/api/incident-focus", { cache: "no-store" }).then((r) => r.json())
    : null;
  const maintenance = page === "maintenance"
    ? await fetch("/api/maintenance", { cache: "no-store" }).then((r) => r.json())
    : null;
  const missionConsole = page === "mission-console"
    ? await fetch("/api/mission-console", { cache: "no-store" }).then((r) => r.json())
    : null;
  const configSim = page === "config-sim"
    ? await fetch("/api/config-sim", { cache: "no-store" }).then((r) => r.json())
    : null;
  const baseline = page === "baseline"
    ? await fetch("/api/baseline", { cache: "no-store" }).then((r) => r.json())
    : null;
  const sinceYesterday = page === "since-yesterday"
    ? await fetch("/api/since-yesterday", { cache: "no-store" }).then((r) => r.json())
    : null;
  const dailyBrief = page === "daily-brief"
    ? await fetch("/api/daily-brief", { cache: "no-store" }).then((r) => r.json())
    : null;
  subtitle.textContent = `${state.hostname || "pfSense"} / ${state.mode || "live"} / ${new Date().toLocaleTimeString()}`;
  if (page === "cockpit") renderCockpit(state, cockpit || {});
  else if (page === "threat-story") renderThreatStory(state, threatStory || {});
  else if (page === "device") renderDeviceDetail(state, deviceDetail || {});
  else if (page === "incident-report") renderIncidentReport(state, incidentReport || {});
  else if (page === "coverage") renderCoverage(state, coverage || {});
  else if (page === "hunts") renderHunts(state, hunts || {});
  else if (page === "rules-lab") renderRulesLab(state, rulesLab || {});
  else if (page === "soc-score") renderSocScore(state, socScore || {});
  else if (page === "model-tournament") renderModelTournament(state, modelTournament || {});
  else if (page === "evidence") renderEvidenceDrawer(state, evidence || {});
  else if (page === "notebook") renderNotebook(state, notebook || {});
  else if (page === "actions") renderActions(state, actions || {});
  else if (page === "mission-mode") renderMissionMode(state, missionMode || {});
  else if (page === "memory") renderMemorySystem(state, memorySystem || {});
  else if (page === "twin") renderTwin(state, twin || {});
  else if (page === "automation") renderAutomation(state, automation || {});
  else if (page === "timeline") renderTimeline(state, timeline || {});
  else if (page === "movie") renderMovie(state, movie || {});
  else if (page === "projects") renderProjects(state, projects || {});
  else if (page === "glitches") renderGlitches(state, glitches || {});
  else if (page === "wall-health") renderWallHealth(state, wallHealth || {});
  else if (page === "review-queue") renderReviewQueue(state, reviewQueue || {});
  else if (page === "owner-map") renderOwnerMap(state, ownerMap || {});
  else if (page === "owner-editor") renderOwnerEditor(state, ownerEditor || {});
  else if (page === "packet-noise") renderPacketNoise(state, packetNoise || {});
  else if (page === "confidence") renderConfidence(state, confidence || {});
  else if (page === "incident-focus") renderIncidentFocus(state, incidentFocus || {});
  else if (page === "maintenance") renderMaintenance(state, maintenance || {});
  else if (page === "mission-console") renderMissionConsole(state, missionConsole || {});
  else if (page === "config-sim") renderConfigSim(state, configSim || {});
  else if (page === "baseline") renderBaseline(state, baseline || {});
  else if (page === "since-yesterday") renderSinceYesterday(state, sinceYesterday || {});
  else if (page === "daily-brief") renderDailyBrief(state, dailyBrief || {});
  else if (page === "flows") renderFlowsPage(state, flowsData || {});
  else if (page === "replay") renderReplay(state, replay || {});
  else if (page === "flight-recorder") renderFlightRecorder(state, flightRecorder || {});
  else if (page === "map") renderThreatMap(state, threatMap || {});
  else if (page === "devices") renderDevices(state);
  else if (page === "incidents") renderIncidents(state);
  else if (page === "ai") renderAi(state);
  else if (page === "health") renderHealth(state, health || {});
  else if (page === "doctor") renderDoctor(state, doctor || {});
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
