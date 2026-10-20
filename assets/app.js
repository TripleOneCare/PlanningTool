/* TOC Planning Tool - front end. Talks to api.php; checks for teammates' changes every few seconds. */
(() => {
"use strict";

const meta = n => document.querySelector(`meta[name="${n}"]`)?.content || "";
const CSRF = meta("csrf-token");
const POLL_MS = Math.max(5, Number(meta("poll-seconds")) || 15) * 1000;

const TEAMS = [
  {id:"QA", name:"Quality Assurance", c:"var(--qa)"},
  {id:"Training", name:"Training", c:"var(--training)"},
  {id:"Events", name:"Events", c:"var(--events)"},
  {id:"General", name:"General", c:"var(--general)"}
];
const PRI = [
  {id:1, name:"Critical", short:"P1", c:"var(--p1)"},
  {id:2, name:"High", short:"P2", c:"var(--p2)"},
  {id:3, name:"Medium", short:"P3", c:"var(--p3)"},
  {id:4, name:"Low", short:"P4", c:"var(--p4)"}
];
const STATUS = [
  {id:"backlog", name:"Backlog"},{id:"todo", name:"To do"},{id:"doing", name:"In progress"},
  {id:"review", name:"Review"},{id:"blocked", name:"Blocked"},{id:"done", name:"Done"}
];
const PSTATUS = [
  {id:"planning", name:"Planning"},{id:"active", name:"Active"},{id:"on_hold", name:"On hold"},
  {id:"done", name:"Complete"},{id:"archived", name:"Archived"}
];
const MEMBER_COLORS = ["#2a2769","#1f8a7a","#d1567a","#6a4bc4","#e07a1f","#2f6fd0","#7a5c2e","#4b5563"];
const PROJECT_COLORS = ["#2a2769","#f9c81d","#1f8a7a","#d1567a","#6a4bc4","#e07a1f","#2f6fd0","#c62c3b"];

const state = {
  tasks: [], members: [], projects: [], me: null, mail: {enabled:false}, unread: 0,
  loaded:false, revision:0, today: meta("local-today"),
  view: "priority", team: "all", project: "all", person: "all", q: "", showDone: false,
  sort: {key:"priority", dir:1}, editing: null, confirmDelete:false, busy:false
};
try {
  const v = localStorage.getItem("toc-ops-view"); if (["projects","priority","board","table"].includes(v)) state.view = v;
  const p = localStorage.getItem("toc-ops-person"); if (p) state.person = p;
} catch(e) {}

const $ = s => document.querySelector(s);
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const ymd = d => d.getFullYear()+"-"+String(d.getMonth()+1).padStart(2,"0")+"-"+String(d.getDate()).padStart(2,"0");
const todayStr = () => state.today || ymd(new Date());
const addDays = (s, n) => { const d = new Date(s+"T00:00:00"); d.setDate(d.getDate()+n); return ymd(d); };
const teamOf = id => TEAMS.find(t => t.id === id) || TEAMS[3];
const priOf = id => PRI.find(p => p.id === Number(id)) || PRI[2];
const statusOf = id => STATUS.find(s => s.id === id) || STATUS[1];
const pstatusOf = id => PSTATUS.find(s => s.id === id) || PSTATUS[1];
const sameId = (a, b) => a != null && b != null && a !== "" && b !== "" && String(a) === String(b);
const memberOf = id => state.members.find(m => sameId(m.id, id));
const projOf = id => state.projects.find(p => sameId(p.id, id));
const initials = n => (n||"?").split(/\s+/).filter(Boolean).slice(0,2).map(w=>w[0].toUpperCase()).join("");
const firstName = n => (n||"").trim().split(/\s+/)[0] || "Someone";
const fmtDate = (iso, opts) => iso ? new Date(iso).toLocaleString("en-NZ", opts) : "";
const shortDate = d => d ? new Date(d+"T00:00:00").toLocaleDateString("en-NZ",{day:"numeric",month:"short"}) : "";
const isMine = t => state.me && t.assignees.some(id => sameId(id, state.me.id));
function ago(iso){
  if (!iso) return "";
  const s = (Date.now() - new Date(iso).getTime())/1000;
  if (s < 60) return "just now";
  if (s < 3600) return Math.floor(s/60)+"m ago";
  if (s < 86400) return Math.floor(s/3600)+"h ago";
  if (s < 86400*7) return Math.floor(s/86400)+"d ago";
  return fmtDate(iso,{day:"numeric",month:"short"});
}

/* ---------- API ---------- */
async function api(action, body, query){
  const url = "api.php?action="+encodeURIComponent(action)+(query||"");
  const opts = body === undefined
    ? {method:"GET", headers:{"Accept":"application/json"}, credentials:"same-origin"}
    : {method:"POST", headers:{"Content-Type":"application/json","X-CSRF-Token":CSRF,"Accept":"application/json"}, credentials:"same-origin", body:JSON.stringify(body)};
  let res;
  try { res = await fetch(url, opts); }
  catch(e) { throw new Error("Couldn't reach the server. Check your connection and try again."); }
  let data = {};
  try { data = await res.json(); } catch(e) {}
  if (res.status === 401 || res.status === 419) { location.reload(); throw new Error(data.error || "Signed out"); }
  if (!res.ok) throw new Error(data.error || "Couldn't save. Try again.");
  return data;
}
function applyState(d){
  state.me = d.me; state.members = d.members; state.tasks = d.tasks; state.projects = d.projects || [];
  state.mail = d.mail || {enabled:false}; state.revision = d.revision; state.unread = d.unread || 0;
  if (d.today) state.today = d.today;
  state.loaded = true;
}
async function loadAll(){
  try {
    applyState(await api("state")); setStatus(""); render();
    const e = state.editing;
    if (e && e.kind==="task" && e.id) { const t = state.tasks.find(x=>x.id===e.id); if (t && t.comments !== e.seenComments) refreshTaskTimeline(e.id); }
    if (e && e.kind==="project" && e.id) { const p = projOf(e.id); if (p && p.notes !== e.seenNotes) refreshProjectTimeline(e.id); }
  } catch(e) { setStatus(e.message); }
}
function upsert(list, item){ const i = list.findIndex(x=>x.id===item.id); if (i>=0) list[i] = item; else list.push(item); }
function noteRevision(r){ if (typeof r === "number") state.revision = r; }

function dueInfo(t){
  if (!t.due) return {cls:"", label:"No date"};
  const today = todayStr(), d = new Date(t.due+"T00:00:00");
  const label = shortDate(t.due);
  if (t.status === "done") return {cls:"", label};
  if (t.due < today) { const days = Math.round((new Date(today+"T00:00:00") - d)/864e5); return {cls:"over", label: label+" · "+days+"d late"}; }
  if (t.due === today) return {cls:"soon", label:"Today"};
  if (t.due <= addDays(today,7)) return {cls:"soon", label};
  return {cls:"", label};
}
function progress(t){
  const s = Array.isArray(t.subtasks) ? t.subtasks : [];
  if (!s.length) return null;
  const d = s.filter(x=>x.done).length;
  return {d, n:s.length, pct: Math.round(d/s.length*100)};
}
function projectStats(pid){
  const list = state.tasks.filter(t=>sameId(t.project,pid));
  const done = list.filter(t=>t.status==="done").length;
  const today = todayStr();
  return {total:list.length, done, open:list.length-done, overdue:list.filter(t=>t.status!=="done"&&t.due&&t.due<today).length,
          pct: list.length ? Math.round(done/list.length*100) : 0};
}
function toast(msg){
  const el = document.createElement("div"); el.className="toast"; el.setAttribute("role","status"); el.textContent=msg;
  $("#toastHost").replaceChildren(el); setTimeout(()=>{ if (el.isConnected) el.remove(); }, 3200);
}
function setStatus(msg){ const s=$("#status"); s.textContent = msg||""; s.hidden = !msg; }

/* ---------- filtering ---------- */
function inScope(t){
  if (state.team !== "all" && t.team !== state.team) return false;
  if (state.project === "none" && t.project) return false;
  if (state.project !== "all" && state.project !== "none" && !sameId(t.project, state.project)) return false;
  if (state.person === "me" && !isMine(t)) return false;
  if (state.person === "none" && t.assignees.length) return false;
  if (!["all","me","none"].includes(state.person) && !t.assignees.some(id=>sameId(id,state.person))) return false;
  return true;
}
function filtered(includeDone){
  const q = state.q.trim().toLowerCase();
  return state.tasks.filter(t => {
    if (!inScope(t)) return false;
    if (!(includeDone ?? state.showDone) && t.status === "done") return false;
    if (q && !((t.title||"")+" "+(t.notes||"")+" "+(projOf(t.project)?.name||"")).toLowerCase().includes(q)) return false;
    return true;
  });
}
const byQueue = (a,b) => (a.priority-b.priority) || ((a.rank??0)-(b.rank??0)) || String(a.created||"").localeCompare(String(b.created||""));

/* ---------- summary ---------- */
function renderSummary(){
  const today = todayStr(), wk = addDays(today,7);
  const scope = state.tasks.filter(inScope);
  const open = scope.filter(t=>t.status!=="done");
  const over = open.filter(t=>t.due && t.due<today);
  const week = open.filter(t=>t.due && t.due>=today && t.due<=wk);
  const blocked = open.filter(t=>t.status==="blocked");
  const crit = open.filter(t=>Number(t.priority)===1);
  const monthStart = today.slice(0,8)+"01";
  const doneM = scope.filter(t=>t.status==="done" && t.doneAt && ymd(new Date(t.doneAt))>=monthStart);
  const scopeLabel = state.person==="me" ? "assigned to you" : state.project!=="all" && state.project!=="none" ? "in this project" : "across all teams";
  const toTasks = () => { if (state.view==="projects") state.view="priority"; };
  const tiles = [
    {k:"Open", v:open.length, s:scopeLabel, act:toTasks},
    {k:"Overdue", v:over.length, s:"past due date", cls: over.length?"alert":"", act:()=>{ state.view="table"; state.sort={key:"due",dir:1}; }},
    {k:"Due in 7 days", v:week.length, s:"coming up", cls: week.length?"warnc":"", act:()=>{ state.view="table"; state.sort={key:"due",dir:1}; }},
    {k:"Critical", v:crit.length, s:"P1 open", cls: crit.length?"alert":"", act:()=>{ state.view="priority"; }},
    {k:"Blocked", v:blocked.length, s:"need a decision", cls: blocked.length?"warnc":"", act:()=>{ state.view="board"; }},
    {k:"Done this month", v:doneM.length, s:new Date(today+"T00:00:00").toLocaleDateString("en-NZ",{month:"long"}), act:()=>{ state.showDone=true; $("#fDone").checked=true; state.view="board"; }}
  ];
  const host = $("#summary"); host.replaceChildren();
  tiles.forEach(t => {
    const b = document.createElement("button"); b.type="button"; b.className="stat "+(t.cls||"");
    b.innerHTML = `<span class="k">${esc(t.k)}</span><span class="v">${t.v}</span><span class="s">${esc(t.s)}</span>`;
    b.onclick = () => { t.act(); render(); };
    host.append(b);
  });
  const people = [...state.members.map(m=>({id:m.id,name:m.name})), {id:null, name:"Unassigned"}];
  const ownedBy = (list, id) => list.filter(t => id === null ? !t.assignees.length : t.assignees.some(a=>sameId(a,id)));
  const maxP = Math.max(1, ...people.map(p => ownedBy(open, p.id).length));
  const bar = (list, max) => PRI.map(pr => { const n = list.filter(t=>Number(t.priority)===pr.id).length; return n?`<span style="width:${n/max*100}%;background:${pr.c}" title="${pr.name}: ${n}"></span>`:""; }).join("");
  const pRows = people.map(p => {
    const mine = ownedBy(open, p.id);
    if (p.id===null && !mine.length) return "";
    const od = mine.filter(t=>t.due && t.due<today).length;
    return `<button type="button" class="lrow" data-person="${p.id===null?"none":p.id}"><span class="nm">${esc(p.name)}</span><span class="track">${bar(mine,maxP)}</span><span class="n mono">${mine.length}${od?` · <b style="color:var(--bad)">${od}!</b>`:""}</span></button>`;
  }).join("");
  const maxT = Math.max(1, ...TEAMS.map(tm => open.filter(t=>t.team===tm.id).length));
  const tRows = TEAMS.map(tm => {
    const mine = open.filter(t=>t.team===tm.id);
    if (tm.id==="General" && !mine.length) return "";
    return `<button type="button" class="lrow" data-teamrow="${tm.id}"><span class="nm"><span class="dot" style="background:${tm.c}"></span> ${esc(tm.name)}</span><span class="track">${bar(mine,maxT)}</span><span class="n mono">${mine.length}</span></button>`;
  }).join("");
  $("#load").innerHTML = `<div><h3>Workload by person</h3>${pRows}</div><div><h3>Open by team</h3>${tRows}</div>`;
}
$("#load").addEventListener("click", e => {
  const p = e.target.closest("[data-person]");
  if (p) { state.person = state.person===p.dataset.person ? "all" : p.dataset.person; if (state.view==="projects") state.view="priority"; render(); return; }
  const t = e.target.closest("[data-teamrow]");
  if (t) { state.team = state.team===t.dataset.teamrow ? "all" : t.dataset.teamrow; render(); }
});

/* ---------- controls ---------- */
function renderControls(){
  document.querySelectorAll(".tab").forEach(b => b.setAttribute("aria-selected", String(b.dataset.view===state.view)));
  const chips = $("#teamChips"); chips.replaceChildren();
  [{id:"all",name:"All teams",c:null}, ...TEAMS].forEach(t => {
    const b = document.createElement("button"); b.type="button"; b.className="chip";
    b.setAttribute("aria-pressed", String(state.team===t.id));
    b.innerHTML = (t.c?`<span class="dot" style="background:${t.c}"></span>`:"")+esc(t.id==="QA"?"QA":t.name);
    b.onclick = () => { state.team = t.id; render(); };
    chips.append(b);
  });
  if (!["all","none"].includes(state.project) && !projOf(state.project)) state.project = "all";
  const live = state.projects.filter(p => p.status!=="archived" || sameId(p.id,state.project));
  const fp = $("#fProject");
  fp.innerHTML = `<option value="all">All projects</option>` + live.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join("") + `<option value="none">No project</option>`;
  fp.value = String(state.project);
  fp.hidden = state.view==="projects";
  if (!["all","me","none"].includes(state.person) && !memberOf(state.person)) state.person = "all";
  const sel = $("#fOwner");
  sel.innerHTML = `<option value="all">Everyone</option><option value="me">My tasks</option>` + state.members.map(m=>`<option value="${m.id}">${esc(m.name)}</option>`).join("") + `<option value="none">Unassigned</option>`;
  sel.value = String(state.person);
  $("#fSearch").placeholder = state.view==="projects" ? "Search projects" : "Search tasks";
  if (state.me) $("#who").textContent = state.me.name;
  $("#btnTeam").textContent = state.me?.is_admin ? "Team" : "Team list";
  const b = $("#bellCount"); b.textContent = state.unread > 99 ? "99+" : String(state.unread); b.hidden = !state.unread;
  $("#btnBell").setAttribute("aria-label", state.unread ? `Notifications, ${state.unread} unread` : "Notifications");
  $("#btnNew").textContent = state.view==="projects" ? "+ New project" : "+ New task";
}

/* ---------- small renderers ---------- */
const priPill = p => { const x = priOf(p); return `<span class="pill pri-${x.id}">${x.short} ${x.name}</span>`; };
const teamPill = id => { const t = teamOf(id); return `<span class="pill team"><span class="dot" style="background:${t.c}"></span>${esc(t.id)}</span>`; };
const stPill = id => `<span class="pill st st-${esc(id)}">${esc(statusOf(id).name)}</span>`;
const pstPill = id => `<span class="pill pst pst-${esc(id)}">${esc(pstatusOf(id).name)}</span>`;
const projPill = id => { const p = projOf(id); return p ? `<span class="pill proj" title="Project"><span class="sq" style="background:${esc(p.color)}"></span>${esc(p.name)}</span>` : ""; };
const avatar = id => { const m = memberOf(id); if (!m) return `<span class="av av-empty" title="Unassigned">–</span>`; return `<span class="av" style="background:${esc(m.color)}" title="${esc(m.name)}">${esc(initials(m.name))}</span>`; };
function avatars(ids, max = 3){
  const list = (ids||[]).filter(id=>memberOf(id));
  if (!list.length) return `<span class="avs">${avatar(null)}</span>`;
  const shown = list.slice(0, max).map(avatar).join("");
  const more = list.length > max ? `<span class="av av-more" title="${esc(list.slice(max).map(id=>memberOf(id).name).join(", "))}">+${list.length-max}</span>` : "";
  return `<span class="avs" title="${esc(list.map(id=>memberOf(id).name).join(", "))}">${shown}${more}</span>`;
}
const dueHtml = t => { const d = dueInfo(t); return `<span class="due ${d.cls}">${esc(d.label)}</span>`; };
const progHtml = t => { const p = progress(t); return p?`<span class="prog"><i><b style="width:${p.pct}%"></b></i>${p.d}/${p.n}</span>`:""; };
const noteHtml = n => n ? `<span class="cmt" title="${n} note${n===1?"":"s"}"><svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><path fill="currentColor" d="M4 4h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H9l-5 4V5a1 1 0 0 1 1-1Z"/></svg>${n}</span>` : "";

function emptyHtml(){
  if (!state.loaded) return `<div class="empty"><h2>Loading the board…</h2><p>Pulling in the team's tasks.</p></div>`;
  if (!state.tasks.length) {
    return `<div class="empty"><h2>No tasks on the board yet</h2>
      <p>Start with a project for a bigger piece of work, or add a single task. Assign people, set a priority and due date, and the right people get an email.</p>
      <div class="row"><button class="btn primary" type="button" data-act="new">+ Add the first task</button>
      <button class="btn" type="button" data-act="newproject">+ New project</button>
      ${state.me?.is_admin && state.members.length<3?`<button class="btn" type="button" data-act="team">Add your team</button>`:""}</div></div>`;
  }
  return `<div class="empty"><h2>Nothing matches these filters</h2><p>Try another team, project or person, or turn on Show done.</p><div class="row"><button class="btn" type="button" data-act="clear">Clear filters</button></div></div>`;
}

/* ---------- views ---------- */
function renderProjects(){
  if (!state.loaded) return emptyHtml();
  const q = state.q.trim().toLowerCase();
  const list = state.projects.filter(p =>
    (state.team==="all" || p.team===state.team) &&
    (state.showDone || !["done","archived"].includes(p.status)) &&
    (state.person==="all" || (state.person==="me" ? (p.members.some(id=>sameId(id,state.me.id)) || sameId(p.lead,state.me.id)) : state.person==="none" || p.members.some(id=>sameId(id,state.person)))) &&
    (!q || (p.name+" "+p.description).toLowerCase().includes(q))
  ).sort((a,b) => (["done","archived"].includes(a.status)-["done","archived"].includes(b.status)) || (a.due||"9999").localeCompare(b.due||"9999") || a.name.localeCompare(b.name));
  if (!state.projects.length) {
    return `<div class="empty"><h2>No projects yet</h2><p>Projects group related tasks, like an event, a course rebuild or an audit cycle. Each has a lead, members, dates and an updates thread that emails the people involved.</p><div class="row"><button class="btn primary" type="button" data-act="newproject">+ Create the first project</button></div></div>`;
  }
  if (!list.length) return `<div class="empty"><h2>No projects match</h2><p>Try another team or turn on Show done to include completed projects.</p></div>`;
  const today = todayStr();
  return `<div class="pgrid">` + list.map(p => {
    const s = projectStats(p.id);
    const lead = memberOf(p.lead);
    const late = p.due && p.due < today && !["done","archived"].includes(p.status);
    return `<button type="button" class="pcard" data-proj="${p.id}" style="--pc:${esc(p.color)}">
      <span class="pc-top"><span class="pc-name">${esc(p.name)}</span>${pstPill(p.status)}</span>
      <span class="pc-meta">${teamPill(p.team)}${p.due?`<span class="due ${late?"over":""}">Due ${esc(shortDate(p.due))}</span>`:""}</span>
      <span class="pc-bar"><i style="width:${s.pct}%"></i></span>
      <span class="pc-nums"><span><b>${s.pct}%</b> done</span><span>${s.open} open</span>${s.overdue?`<span class="over">${s.overdue} overdue</span>`:""}${p.notes?`<span>${noteHtml(p.notes)}</span>`:""}</span>
      <span class="pc-people">${lead?`<span class="pc-lead">${avatar(lead.id)} ${esc(firstName(lead.name))} leads</span>`:`<span class="hint">No lead</span>`}${avatars(p.members.filter(id=>!sameId(id,p.lead)),4)}</span>
    </button>`;
  }).join("") + `<button type="button" class="pcard pcard-new" data-act="newproject"><span>+ New project</span></button></div>`;
}
function renderPriority(){
  const list = filtered().sort(byQueue);
  if (!list.length) return emptyHtml();
  let html = `<div class="panel">`, n = 0;
  PRI.forEach(p => {
    const g = list.filter(t=>Number(t.priority)===p.id);
    if (!g.length) return;
    html += `<div class="group-h"><span class="stripe" style="background:${p.c}"></span>${p.short} · ${p.name}<span class="c">${g.length}</span></div>`;
    g.forEach((t,i) => {
      n++;
      html += `<div class="q">
        <span class="rank">${n}</span>
        <div class="main" data-open="${t.id}">
          <div class="t">${esc(t.title)}</div>
          <div class="meta">${projPill(t.project)}${teamPill(t.team)}${stPill(t.status)}${dueHtml(t)}${progHtml(t)}${noteHtml(t.comments)}</div>
        </div>
        <div class="acts">
          ${avatars(t.assignees)}
          <button class="ib" type="button" data-mv="up" data-id="${t.id}" ${i===0?"disabled":""} aria-label="Move up">↑</button>
          <button class="ib" type="button" data-mv="down" data-id="${t.id}" ${i===g.length-1?"disabled":""} aria-label="Move down">↓</button>
          <button class="ib" type="button" data-mv="raise" data-id="${t.id}" ${p.id===1?"disabled":""} aria-label="Raise priority" title="Raise priority">⇡</button>
        </div>
      </div>`;
    });
  });
  return html + `</div>`;
}
function renderBoard(){
  if (!state.tasks.length || !state.loaded) return emptyHtml();
  const list = filtered(true).sort(byQueue);
  const cols = STATUS.filter(s => s.id!=="done" || state.showDone);
  return `<div class="board" style="grid-template-columns:repeat(${cols.length},minmax(240px,1fr))">` + cols.map(s => {
    const items = list.filter(t=>t.status===s.id);
    return `<div class="col" data-col="${s.id}">
      <div class="col-h"><span>${s.name}</span><span class="c">${items.length}</span></div>
      ${items.map(t=>`<div class="card p${Number(t.priority)}" draggable="true" data-card="${t.id}" data-open="${t.id}" tabindex="0">
        ${t.project?`<div class="row">${projPill(t.project)}</div>`:""}
        <div class="t">${esc(t.title)}</div>
        <div class="row">${priPill(t.priority)}${teamPill(t.team)}</div>
        <div class="row">${dueHtml(t)}${progHtml(t)}${noteHtml(t.comments)}<span class="r">${avatars(t.assignees)}</span></div>
      </div>`).join("") || `<div class="col-empty">Drop tasks here</div>`}
    </div>`;
  }).join("") + `</div>`;
}
function renderTable(){
  const list = filtered();
  if (!list.length) return emptyHtml();
  const k = state.sort.key, d = state.sort.dir;
  const names = t => t.assignees.map(id=>memberOf(id)?.name||"").sort().join(", ");
  const val = t => ({
    title:(t.title||"").toLowerCase(), project:(projOf(t.project)?.name||"zzz").toLowerCase(), team:t.team, owner:(names(t)||"zzz").toLowerCase(),
    priority: Number(t.priority)*1e6+(t.rank??0), status: STATUS.findIndex(s=>s.id===t.status),
    due: t.due||"9999", progress: progress(t)?.pct ?? -1, updated: t.updated||""
  })[k];
  list.sort((a,b)=>{ const x=val(a), y=val(b); return (x<y?-1:x>y?1:0)*d; });
  const cols = [["title","Task"],["project","Project"],["team","Team"],["owner","Assigned"],["priority","Priority"],["status","Status"],["due","Due"],["progress","Checklist"],["updated","Updated"]];
  return `<div class="tablewrap"><table><thead><tr>${cols.map(([key,lab])=>`<th data-sort="${key}" aria-sort="${k===key?(d===1?"ascending":"descending"):"none"}" tabindex="0">${lab}</th>`).join("")}</tr></thead><tbody>
  ${list.map(t=>`<tr data-open="${t.id}" tabindex="0">
    <td class="tt">${esc(t.title)} ${noteHtml(t.comments)}</td><td>${projPill(t.project)||'<span class="hint">–</span>'}</td><td>${teamPill(t.team)}</td>
    <td><span class="who-cell">${avatars(t.assignees)}<span>${esc(t.assignees.length===1?memberOf(t.assignees[0])?.name||"":t.assignees.length?t.assignees.length+" people":"Unassigned")}</span></span></td>
    <td>${priPill(t.priority)}</td><td>${stPill(t.status)}</td><td>${dueHtml(t)}</td><td>${progHtml(t)||'<span class="hint">–</span>'}</td>
    <td class="hint">${t.updated?fmtDate(t.updated,{day:"numeric",month:"short"}):""}${memberOf(t.updatedBy)?" · "+esc(initials(memberOf(t.updatedBy).name)):""}</td></tr>`).join("")}
  </tbody></table></div>`;
}
function render(){
  try { localStorage.setItem("toc-ops-view", state.view); localStorage.setItem("toc-ops-person", state.person); } catch(e) {}
  renderControls(); renderSummary();
  $("#view").innerHTML = state.view==="projects" ? renderProjects() : state.view==="board" ? renderBoard() : state.view==="table" ? renderTable() : renderPriority();
}

/* ---------- view events ---------- */
$("#view").addEventListener("click", e => {
  const act = e.target.closest("[data-act]");
  if (act) {
    const a = act.dataset.act;
    if (a==="new") openTask(null); else if (a==="newproject") openProject(null); else if (a==="team") openTeam();
    else if (a==="clear") { state.team="all"; state.project="all"; state.person="all"; state.q=""; $("#fSearch").value=""; render(); }
    return;
  }
  const pc = e.target.closest("[data-proj]"); if (pc) { openProject(Number(pc.dataset.proj)); return; }
  const mv = e.target.closest("[data-mv]");
  if (mv) { moveTask(Number(mv.dataset.id), mv.dataset.mv); return; }
  const th = e.target.closest("th[data-sort]");
  if (th) { const k = th.dataset.sort; state.sort = {key:k, dir: state.sort.key===k ? -state.sort.dir : 1}; render(); return; }
  const op = e.target.closest("[data-open]");
  if (op) openTask(Number(op.dataset.open));
});
$("#view").addEventListener("keydown", e => {
  if (e.key!=="Enter") return;
  const th = e.target.closest("th[data-sort]"); if (th) { th.click(); return; }
  const op = e.target.closest("tr[data-open],.card[data-open]"); if (op) openTask(Number(op.dataset.open));
});
let dragId = null;
$("#view").addEventListener("dragstart", e => { const c = e.target.closest("[data-card]"); if (!c) return; dragId = Number(c.dataset.card); e.dataTransfer.effectAllowed="move"; try{e.dataTransfer.setData("text/plain",String(dragId));}catch(_){} });
$("#view").addEventListener("dragover", e => { const col = e.target.closest("[data-col]"); if (!col || !dragId) return; e.preventDefault(); document.querySelectorAll(".col.over").forEach(c=>c!==col&&c.classList.remove("over")); col.classList.add("over"); });
$("#view").addEventListener("dragleave", e => { const col = e.target.closest("[data-col]"); if (col && !col.contains(e.relatedTarget)) col.classList.remove("over"); });
$("#view").addEventListener("drop", e => {
  const col = e.target.closest("[data-col]"); if (!col || !dragId) return; e.preventDefault();
  col.classList.remove("over"); const id = dragId; dragId = null;
  const t = state.tasks.find(x=>x.id===id); if (t && t.status!==col.dataset.col) patchTask(id, {status: col.dataset.col}, "Moved to "+statusOf(col.dataset.col).name);
});
$("#view").addEventListener("dragend", () => { dragId=null; document.querySelectorAll(".col.over").forEach(c=>c.classList.remove("over")); });

document.querySelectorAll(".tab").forEach(b => b.onclick = () => { state.view = b.dataset.view; render(); });
$("#fProject").onchange = e => { state.project = e.target.value; render(); };
$("#fOwner").onchange = e => { state.person = e.target.value; render(); };
let qT; $("#fSearch").oninput = e => { clearTimeout(qT); qT = setTimeout(()=>{ state.q = e.target.value; render(); }, 150); };
$("#fDone").onchange = e => { state.showDone = e.target.checked; render(); };
$("#btnNew").onclick = () => state.view==="projects" ? openProject(null) : openTask(null);
$("#btnTeam").onclick = () => openTeam();
$("#btnSettings").onclick = () => openSettings();
$("#btnBell").onclick = () => openNotifications();
document.addEventListener("keydown", e => { if (e.key==="Escape" && state.editing) closeDrawer(); });

/* ---------- task writes ---------- */
async function patchTask(id, fields, okMsg){
  const i = state.tasks.findIndex(t=>t.id===id); if (i<0) return false;
  state.tasks[i] = {...state.tasks[i], ...fields}; render();
  try {
    const r = await api("task_patch", {id, fields});
    upsert(state.tasks, r.task); noteRevision(r.revision); render();
    if (okMsg) toast(okMsg);
    return true;
  } catch(e) { toast(e.message); await loadAll(); return false; }
}
async function moveTask(id, dir){
  if (state.busy) return;
  const t = state.tasks.find(x=>x.id===id); if (!t) return;
  state.busy = true;
  try {
    if (dir==="raise") {
      const np = Math.max(1, Number(t.priority)-1);
      const g = state.tasks.filter(x=>x.priority===np && x.id!==id);
      const rank = g.length ? Math.max(...g.map(x=>x.rank??0)) + 1 : 1;
      await patchTask(id, {priority:np, rank}, "Raised to "+priOf(np).name);
      return;
    }
    const g = filtered().filter(x=>Number(x.priority)===Number(t.priority)).sort(byQueue);
    const i = g.findIndex(x=>x.id===id), j = dir==="up" ? i-1 : i+1;
    if (j<0 || j>=g.length) return;
    const other = g[j];
    let ra = t.rank??0, rb = other.rank??0;
    if (ra===rb) rb = ra + (dir==="up"?-1:1);
    await patchTask(id, {rank: rb});
    await patchTask(other.id, {rank: ra});
  } finally { state.busy = false; }
}

/* ---------- drawer plumbing ---------- */
function closeDrawer(){ state.editing = null; state.confirmDelete = false; const h = $("#drawerHost"); h.replaceChildren(); h.dataset.kind = ""; }
function drawerShell(label, title, headExtra, body, foot){
  return `<div class="scrim" data-close></div><aside class="drawer" role="dialog" aria-modal="true" aria-label="${esc(label)}">
    <div class="dr-h"><h2>${title}</h2>${headExtra||""}<button class="btn ghost" type="button" data-close aria-label="Close">✕</button></div>
    <div class="dr-b">${body}</div>
    ${foot?`<div class="dr-f">${foot}</div>`:""}
  </aside>`;
}
function keepFocus(fn){
  const a = document.activeElement, id = a?.id, pos = a && "selectionStart" in a ? a.selectionStart : null;
  const scroll = $(".dr-b")?.scrollTop;
  fn();
  if (scroll != null && $(".dr-b")) $(".dr-b").scrollTop = scroll;
  if (id) { const el = document.getElementById(id); if (el) { el.focus(); if (pos != null && el.setSelectionRange) { try { el.setSelectionRange(pos,pos); } catch(_){} } } }
}
function renderDrawer(){
  const e = state.editing; if (!e) return;
  keepFocus(() => {
    const host = $("#drawerHost");
    const sameDrawer = host.dataset.kind === e.kind + ":" + (e.id ?? "") && host.firstChild;
    if (e.kind==="task") host.innerHTML = taskDrawer(e);
    else if (e.kind==="project") host.innerHTML = projectDrawer(e);
    else if (e.kind==="team") host.innerHTML = teamDrawer(e);
    else if (e.kind==="settings") host.innerHTML = settingsDrawer(e);
    else if (e.kind==="notifications") host.innerHTML = notificationsDrawer(e);
    host.dataset.kind = e.kind + ":" + (e.id ?? "");
    if (sameDrawer) host.querySelector(".drawer")?.classList.add("still");   // only animate when a drawer first opens
  });
}
function memberPicker(selected, field, label){
  return `<div class="flabel">${label}
    <div class="picker" role="group" aria-label="${esc(label)}">${state.members.map(m=>{
      const on = selected.some(id=>sameId(id,m.id));
      return `<button type="button" class="pick" data-${field}="${m.id}" aria-pressed="${on}">${avatar(m.id)}<span>${esc(m.name)}${sameId(m.id,state.me.id)?" (you)":""}</span></button>`;
    }).join("")}</div></div>`;
}
function timelineHtml(items, loading){
  if (loading) return `<p class="hint">Loading notes…</p>`;
  if (!items || !items.length) return `<p class="hint">No notes yet.</p>`;
  return `<ol class="tl">${items.slice().reverse().map(it => {
    const m = memberOf(it.by), who = m ? m.name : "Former member";
    if (it.kind==="activity") return `<li class="tl-act"><span class="tl-dot"></span><span><b>${esc(firstName(who))}</b> ${esc(it.text)} <span class="tl-time" title="${esc(fmtDate(it.at,{dateStyle:"medium",timeStyle:"short"}))}">${esc(ago(it.at))}</span></span></li>`;
    const mine = state.me && sameId(it.by, state.me.id);
    return `<li class="tl-note">${avatar(it.by)}<div class="tl-body"><div class="tl-head"><b>${esc(who)}</b><span class="tl-time" title="${esc(fmtDate(it.at,{dateStyle:"medium",timeStyle:"short"}))}">${esc(ago(it.at))}</span>${(mine||state.me?.is_admin)?`<button type="button" class="linkbtn" data-delnote="${it.id}">Delete</button>`:""}</div><div class="tl-text">${esc(it.text)}</div></div></li>`;
  }).join("")}</ol>`;
}
function recipientsText(ids){
  const names = [...new Set(ids.map(String))].filter(id=>!sameId(id,state.me.id)).map(id=>memberOf(id)?.name).filter(Boolean);
  if (!names.length) return "Nobody else is on this yet, so no one will be emailed.";
  const how = state.mail.enabled ? "Notifies" : "Shows in the bell for";
  return `${how} ${names.join(", ")}.`;
}

/* ---------- task drawer ---------- */
function openTask(id){
  if (id) {
    const t = state.tasks.find(x=>x.id===id); if (!t) { toast("That task no longer exists"); return; }
    state.editing = {kind:"task", ...t, project: t.project ?? "", assignees:[...t.assignees], subtasks:(t.subtasks||[]).map(s=>({...s})), timeline:null, draft:"", seenComments:t.comments};
    refreshTaskTimeline(id);
  } else {
    const person = !["all","me","none"].includes(state.person) ? [Number(state.person)] : state.person==="none" ? [] : [state.me.id];
    const proj = !["all","none"].includes(state.project) ? Number(state.project) : "";
    const p = projOf(proj);
    state.editing = { kind:"task", id:null, title:"", project: proj, team: state.team!=="all"?state.team:(p?.team && p.team!=="General" ? p.team : "QA"), assignees: person, priority:3, status:"todo", start:"", due:"", notes:"", subtasks:[], timeline:[], draft:"" };
  }
  state.confirmDelete = false;
  renderDrawer();
  setTimeout(()=>{ if (!id) $("#tTitle")?.focus(); }, 30);
}
async function refreshTaskTimeline(id){
  try {
    const d = await api("task_detail", undefined, "&id="+id);
    const e = state.editing;
    upsert(state.tasks, d.task); state.unread = d.unread; renderControls();
    if (e && e.kind==="task" && e.id===id) { e.timeline = d.timeline; e.seenComments = d.task.comments; renderDrawer(); }
  } catch(err) { toast(err.message); }
}
function taskDrawer(e){
  const isNew = !e.id, p = progress(e);
  const projOpts = state.projects.filter(x=>x.status!=="archived" || sameId(x.id,e.project));
  const notify = [...e.assignees, ...(e.createdBy?[e.createdBy]:[])];
  const body = `
    <label class="f">Task<input class="field titlein" id="tTitle" data-f="title" maxlength="300" value="${esc(e.title)}" placeholder="e.g. Moderate STCW assessment pack"></label>
    <div class="grid2">
      <label class="f">Project<select class="field" id="tProject" data-f="project"><option value="">No project</option>${projOpts.map(x=>`<option value="${x.id}" ${sameId(e.project,x.id)?"selected":""}>${esc(x.name)}</option>`).join("")}</select></label>
      <label class="f">Team<select class="field" id="tTeam" data-f="team">${TEAMS.map(t=>`<option value="${t.id}" ${e.team===t.id?"selected":""}>${t.name}</option>`).join("")}</select></label>
    </div>
    ${memberPicker(e.assignees, "assign", "Assigned to")}
    <div class="flabel">Priority
      <div class="segs">${PRI.map(x=>`<button type="button" class="seg pri-${x.id}" data-pri="${x.id}" aria-pressed="${Number(e.priority)===x.id}">${x.short} ${x.name}</button>`).join("")}</div>
    </div>
    <div class="grid3">
      <label class="f">Status<select class="field" id="tStatus" data-f="status">${STATUS.map(s=>`<option value="${s.id}" ${e.status===s.id?"selected":""}>${s.name}</option>`).join("")}</select></label>
      <label class="f">Start<input class="field" type="date" id="tStart" data-f="start" value="${esc(e.start||"")}"></label>
      <label class="f">Due<input class="field" type="date" id="tDue" data-f="due" value="${esc(e.due||"")}"></label>
    </div>
    <label class="f">Description<textarea class="field" id="tNotes" data-f="notes" placeholder="What needs doing, links, who you're waiting on">${esc(e.notes||"")}</textarea></label>
    <div class="flabel">Checklist ${p?`<span class="hint">${p.d} of ${p.n} done</span>`:""}
      <div class="subs">${e.subtasks.map((s,i)=>`<div class="sub ${s.done?"done":""}">
        <input type="checkbox" id="sc${i}" data-sc="${i}" ${s.done?"checked":""} aria-label="Done">
        <input type="text" class="field" id="st${i}" data-st="${i}" value="${esc(s.t)}" placeholder="Step">
        <button class="ib" type="button" data-sdel="${i}" aria-label="Remove step">✕</button></div>`).join("")}
      </div>
      <div><button class="btn" type="button" id="addSub">+ Add step</button></div>
    </div>
    ${isNew ? `<p class="hint">${state.mail.enabled?"People you assign get an email when you add the task.":"People you assign will see it in their notifications."}</p>` : `
    <section class="notes-sec" aria-label="Notes and activity">
      <h3>Notes &amp; activity</h3>
      <div class="composer">
        <textarea class="field" id="noteDraft" data-draft placeholder="Add a note for the people on this task" rows="3">${esc(e.draft||"")}</textarea>
        <div class="composer-row"><span class="hint">${esc(recipientsText(notify))}</span><button class="btn primary" type="button" id="postNote" ${e.posting?"disabled":""}>${e.posting?"Posting…":"Post note"}</button></div>
      </div>
      ${timelineHtml(e.timeline, e.timeline===null)}
    </section>`}
    ${!isNew && e.created?`<p class="hint">Created ${fmtDate(e.created,{dateStyle:"medium",timeStyle:"short"})}${memberOf(e.createdBy)?" by "+esc(memberOf(e.createdBy).name):""}</p>`:""}`;
  const foot = state.confirmDelete
    ? `<div class="confirm"><span>Delete this task and its notes for everyone?</span><button class="btn danger" type="button" id="delYes">Delete</button><button class="btn" type="button" id="delNo">Keep</button></div>`
    : `<button class="btn primary" type="button" id="saveTask">${isNew?"Add task":"Save changes"}</button>
       ${!isNew && e.status!=="done"?`<button class="btn" type="button" id="markDone">Mark done</button>`:""}
       <span class="spacer"></span>${!isNew?`<button class="btn danger" type="button" id="delTask">Delete</button>`:""}`;
  return drawerShell("Task", isNew?"New task":"Task", !isNew?stPill(e.status):"", body, foot);
}

/* ---------- project drawer ---------- */
function openProject(id){
  if (id) {
    const p = projOf(id); if (!p) { toast("That project no longer exists"); return; }
    state.editing = {kind:"project", ...p, lead: p.lead ?? "", members:[...p.members], timeline:null, draft:"", widen:false, seenNotes:p.notes};
    refreshProjectTimeline(id);
  } else {
    state.editing = {kind:"project", id:null, name:"", team: state.team!=="all"?state.team:"General", status:"active", color: PROJECT_COLORS[state.projects.length % PROJECT_COLORS.length],
      description:"", lead: state.me.id, members:[state.me.id], start:"", due:"", timeline:[], draft:"", widen:false};
  }
  state.confirmDelete = false;
  renderDrawer();
  setTimeout(()=>{ if (!id) $("#pName")?.focus(); }, 30);
}
async function refreshProjectTimeline(id){
  try {
    const d = await api("project_detail", undefined, "&id="+id);
    upsert(state.projects, d.project); state.unread = d.unread; renderControls();
    const e = state.editing;
    if (e && e.kind==="project" && e.id===id) { e.timeline = d.timeline; e.seenNotes = d.project.notes; renderDrawer(); }
  } catch(err) { toast(err.message); }
}
function projectDrawer(e){
  const isNew = !e.id;
  const s = isNew ? null : projectStats(e.id);
  const canDelete = !isNew && (state.me.is_admin || sameId(e.lead,state.me.id) || sameId(e.createdBy,state.me.id));
  let notifyIds = [...e.members, e.lead].filter(Boolean);
  if (e.widen && !isNew) state.tasks.filter(t=>sameId(t.project,e.id)&&t.status!=="done").forEach(t=>notifyIds.push(...t.assignees));
  const body = `
    <label class="f">Project name<input class="field titlein" id="pName" data-pf="name" maxlength="200" value="${esc(e.name)}" placeholder="e.g. Hutt Valley Marathon 2027"></label>
    <div class="grid3">
      <label class="f">Team<select class="field" id="pTeam" data-pf="team">${TEAMS.map(t=>`<option value="${t.id}" ${e.team===t.id?"selected":""}>${t.name}</option>`).join("")}</select></label>
      <label class="f">Status<select class="field" id="pStatus" data-pf="status">${PSTATUS.map(x=>`<option value="${x.id}" ${e.status===x.id?"selected":""}>${x.name}</option>`).join("")}</select></label>
      <label class="f">Lead<select class="field" id="pLead" data-pf="lead"><option value="">No lead</option>${state.members.map(m=>`<option value="${m.id}" ${sameId(e.lead,m.id)?"selected":""}>${esc(m.name)}</option>`).join("")}</select></label>
    </div>
    <div class="grid2">
      <label class="f">Start<input class="field" type="date" id="pStart" data-pf="start" value="${esc(e.start||"")}"></label>
      <label class="f">Due<input class="field" type="date" id="pDue" data-pf="due" value="${esc(e.due||"")}"></label>
    </div>
    ${memberPicker(e.members, "pmember", "Project members")}
    <div class="flabel">Colour<div class="swatches">${PROJECT_COLORS.map(c=>`<button type="button" class="swatch" data-color="${c}" style="background:${c}" aria-pressed="${e.color===c}" aria-label="Colour ${c}"></button>`).join("")}</div></div>
    <label class="f">Description<textarea class="field" id="pDesc" data-pf="description" placeholder="Goal, scope, key dates, client contact">${esc(e.description||"")}</textarea></label>
    ${isNew ? `<p class="hint">${state.mail.enabled?"Members you add get an email with the project details.":"Members you add will see it in their notifications."}</p>` : `
    <div class="pstats">
      <div><span class="k">Progress</span><span class="v">${s.pct}%</span><span class="pc-bar"><i style="width:${s.pct}%;background:${esc(e.color)}"></i></span></div>
      <div><span class="k">Open tasks</span><span class="v">${s.open}</span></div>
      <div><span class="k">Overdue</span><span class="v ${s.overdue?"bad":""}">${s.overdue}</span></div>
      <div><span class="k">Done</span><span class="v">${s.done}</span></div>
    </div>
    <div class="row-btns"><button class="btn" type="button" id="viewTasks">View tasks</button><button class="btn" type="button" id="addProjTask">+ Add task to this project</button></div>
    <section class="notes-sec" aria-label="Project updates">
      <h3>Updates</h3>
      <div class="composer">
        <textarea class="field" id="noteDraft" data-draft placeholder="Post an update for the project team" rows="3">${esc(e.draft||"")}</textarea>
        <label class="toggle"><input type="checkbox" id="widen" ${e.widen?"checked":""}> Also include everyone with open tasks in this project</label>
        <div class="composer-row"><span class="hint">${esc(recipientsText(notifyIds))}</span><button class="btn primary" type="button" id="postNote" ${e.posting?"disabled":""}>${e.posting?"Posting…":"Post update"}</button></div>
      </div>
      ${timelineHtml(e.timeline, e.timeline===null)}
    </section>`}`;
  const foot = state.confirmDelete
    ? `<div class="confirm"><span>Delete this project? Its tasks stay on the board without a project.</span><button class="btn danger" type="button" id="delYes">Delete</button><button class="btn" type="button" id="delNo">Keep</button></div>`
    : `<button class="btn primary" type="button" id="saveProject">${isNew?"Create project":"Save changes"}</button><span class="spacer"></span>${canDelete?`<button class="btn danger" type="button" id="delProject">Delete</button>`:""}`;
  return drawerShell("Project", isNew?"New project":"Project", !isNew?pstPill(e.status):"", body, foot);
}

/* ---------- team drawer ---------- */
function openTeam(){
  state.editing = {kind:"team", members: state.members.map(m=>({...m})), links:{}};
  renderDrawer();
}
function teamDrawer(e){
  const admin = !!state.me?.is_admin;
  const body = `
    <p class="hint">${admin
      ? `Only people listed here can sign in. Save the list, then ${state.mail.enabled?"<b>Email invite</b> or ":""}<b>Copy invite link</b> for each new person. The link lets them choose a password and expires after a few days.`
      : "People who can use this board. A team admin manages this list."}</p>
    ${e.members.map((m,i)=>{
      const saved = m.id && state.members.some(x=>sameId(x.id,m.id));
      const isMe = sameId(m.id, state.me.id);
      const link = e.links[m.id];
      return `<div class="mem">
        <span class="av" style="background:${esc(m.color)}">${esc(initials(m.name)||"?")}</span>
        <input class="field" id="mn${i}" data-mi="${i}" data-mf="name" value="${esc(m.name)}" placeholder="Full name" aria-label="Name" ${admin?"":"disabled"}>
        <input class="field" id="me${i}" data-mi="${i}" data-mf="email" type="email" value="${esc(m.email)}" placeholder="work email" aria-label="Email" ${admin?"":"disabled"}>
        <select class="field" id="mr${i}" data-mi="${i}" data-mf="role" aria-label="Main area" ${admin?"":"disabled"}>
          <option value="">Main area</option>${TEAMS.slice(0,3).map(t=>`<option value="${t.id}" ${m.role===t.id?"selected":""}>${t.name}</option>`).join("")}<option value="Lead" ${m.role==="Lead"?"selected":""}>Team lead</option>
        </select>
        ${admin && !isMe ? `<button class="ib" type="button" data-mdel="${i}" aria-label="Remove ${esc(m.name||"person")}">✕</button>` : `<span></span>`}
        ${admin ? `<div class="mem-meta">
          ${saved ? (m.has_login ? `<span class="tag-ok">Has a password</span>` : `<span class="tag-wait">Invite not used yet</span>`) : `<span>Save the team to invite</span>`}
          ${m.last_login ? `<span>Last in ${esc(fmtDate(m.last_login,{day:"numeric",month:"short"}))}</span>` : ""}
          ${!isMe ? `<label><input type="checkbox" id="ma${i}" data-mi="${i}" data-mf="is_admin" ${m.is_admin?"checked":""}> Team admin</label>` : `<span>Team admin (you)</span>`}
          ${saved && !isMe && state.mail.enabled ? `<button class="btn" type="button" data-invmail="${m.id}">${m.has_login?"Email reset link":"Email invite"}</button>` : ""}
          ${saved && !isMe ? `<button class="btn" type="button" data-invite="${m.id}">${m.has_login?"Copy reset link":"Copy invite link"}</button>` : ""}
        </div>` : ""}
        ${link ? `<div class="linkbox"><input class="field" id="lk${i}" readonly value="${esc(link)}" aria-label="Invite link"><button class="btn" type="button" data-copy="lk${i}">Copy</button></div>` : ""}
        <div class="mem-sep"></div>
      </div>`;}).join("")}
    ${admin?`<div><button class="btn" type="button" id="addMem">+ Add person</button></div>`:""}`;
  const foot = `${admin?`<button class="btn primary" type="button" id="saveTeam">Save team</button>`:""}<button class="btn" type="button" data-close>Close</button>`;
  return drawerShell("Team", "Your team", "", body, foot);
}

/* ---------- notifications drawer ---------- */
async function openNotifications(){
  state.editing = {kind:"notifications", items:null};
  renderDrawer();
  try {
    const d = await api("notifications");
    if (state.editing?.kind==="notifications") { state.editing.items = d.items; state.unread = d.unread; renderControls(); renderDrawer(); }
  } catch(err) { toast(err.message); }
}
function notificationsDrawer(e){
  const icon = {assigned:"＋", comment:"✎", completed:"✓", project_note:"✎", project_added:"★"};
  const body = e.items===null ? `<p class="hint">Loading…</p>` : !e.items.length
    ? `<div class="empty" style="padding:28px 12px"><h2 style="font-size:18px">You're all caught up</h2><p>When someone assigns you a task, posts a note on your work or adds you to a project, it shows up here.</p></div>`
    : `<ul class="nlist">${e.items.map(n=>`<li><button type="button" class="nitem ${n.read?"":"unread"}" data-nid="${n.id}" data-ntask="${n.task??""}" data-nproj="${n.project??""}">
        <span class="nicon nt-${esc(n.type)}" aria-hidden="true">${icon[n.type]||"•"}</span>
        <span class="nbody"><span class="nsub">${esc(n.subject)}</span>${n.message?`<span class="nmsg">${esc(n.message)}</span>`:""}<span class="ntime">${esc(ago(n.at))}${n.email==="sent"?" · emailed":""}</span></span>
      </button></li>`).join("")}</ul>`;
  const foot = e.items && e.items.some(n=>!n.read) ? `<button class="btn" type="button" id="readAll">Mark all as read</button>` : "";
  return drawerShell("Notifications", "Notifications", "", body, foot);
}

/* ---------- settings drawer ---------- */
function openSettings(){
  const m = state.mail;
  state.editing = {kind:"settings", prefs:{...state.me.prefs}, pw:{current:"",new:"",confirm:""},
    mail: state.me.is_admin ? {driver:m.driver||"off", host:m.host||"smtp.hostinger.com", port:m.port||465, secure:m.secure||"ssl", user:m.user||"", pass:"", from:m.from||"", from_name:m.from_name||"TOC Planning Tool", digest_hour:m.digest_hour??7, digest_weekends:!!m.digest_weekends} : null};
  renderDrawer();
}
function settingsDrawer(e){
  const p = e.prefs, m = state.mail;
  const pref = (k, label, hint) => `<label class="pref"><input type="checkbox" data-pref="${k}" id="pref_${k}" ${p[k]?"checked":""}><span><b>${label}</b><span class="hint">${hint}</span></span></label>`;
  let body = `
    <section class="set-sec"><h3>Email me when</h3>
      ${!m.enabled?`<div class="notice">Email isn't set up yet${state.me.is_admin?". Set it up under Email server below.":", so these will only show in the bell for now."}</div>`:""}
      ${pref("assigned","I'm assigned a task","Includes the task details and due date.")}
      ${pref("comments","Someone adds a note to my task","Also when a task I created or am on is marked done.")}
      ${pref("projects","There's an update in my project","Updates posted to a project I'm a member or lead of, and when I'm added to one.")}
      ${pref("digest","Daily due-date reminder",`One email at ${hourLabel(m.digest_hour??7)} listing my tasks that are overdue, due today or due tomorrow. Skipped when nothing is due.`)}
      <div><button class="btn primary" type="button" id="savePrefs">Save email preferences</button></div>
    </section>
    <section class="set-sec"><h3>Change password</h3>
      <input type="email" value="${esc(state.me.email)}" autocomplete="username" hidden>
      <label class="f">Current password<input class="field" type="password" id="pwCur" data-pw="current" autocomplete="current-password" value="${esc(e.pw.current)}"></label>
      <div class="grid2">
        <label class="f">New password<input class="field" type="password" id="pwNew" data-pw="new" autocomplete="new-password" value="${esc(e.pw.new)}"></label>
        <label class="f">Confirm<input class="field" type="password" id="pwConf" data-pw="confirm" autocomplete="new-password" value="${esc(e.pw.confirm)}"></label>
      </div>
      <div><button class="btn" type="button" id="savePw">Update password</button></div>
    </section>`;
  if (e.mail) {
    const s = e.mail, smtp = s.driver==="smtp";
    body += `<section class="set-sec"><h3>Email server <span class="pill ${m.enabled?"st-done":"st"}">${m.enabled?"On":"Off"}</span></h3>
      <p class="hint">For Hostinger: create a mailbox (for example board@tripleonecare.online) under Emails in hPanel, then use server <b>smtp.hostinger.com</b>, port <b>465</b>, security <b>SSL</b>, and that mailbox's address and password.</p>
      <div class="grid2">
        <label class="f">Sending method<select class="field" id="mDriver" data-mail="driver">
          <option value="off" ${s.driver==="off"?"selected":""}>Off</option><option value="smtp" ${smtp?"selected":""}>SMTP (recommended)</option><option value="mail" ${s.driver==="mail"?"selected":""}>PHP mail()</option></select></label>
        <label class="f">Reminder time<select class="field" id="mHour" data-mail="digest_hour">${Array.from({length:24},(_,h)=>`<option value="${h}" ${Number(s.digest_hour)===h?"selected":""}>${hourLabel(h)}</option>`).join("")}</select></label>
      </div>
      <div class="grid2">
        <label class="f">From address<input class="field" type="email" id="mFrom" data-mail="from" value="${esc(s.from)}" placeholder="board@tripleonecare.online"></label>
        <label class="f">From name<input class="field" id="mFromName" data-mail="from_name" value="${esc(s.from_name)}"></label>
      </div>
      ${smtp?`<div class="grid3">
        <label class="f">SMTP server<input class="field" id="mHost" data-mail="host" value="${esc(s.host)}"></label>
        <label class="f">Port<input class="field" type="number" id="mPort" data-mail="port" value="${esc(s.port)}"></label>
        <label class="f">Security<select class="field" id="mSecure" data-mail="secure"><option value="ssl" ${s.secure==="ssl"?"selected":""}>SSL</option><option value="tls" ${s.secure==="tls"?"selected":""}>STARTTLS</option><option value="none" ${s.secure==="none"?"selected":""}>None</option></select></label>
      </div>
      <div class="grid2">
        <label class="f">Username<input class="field" id="mUser" data-mail="user" value="${esc(s.user)}" placeholder="board@tripleonecare.online" autocomplete="off"></label>
        <label class="f">Password<input class="field" type="password" id="mPass" data-mail="pass" value="${esc(s.pass)}" placeholder="${m.has_pass?"Saved. Leave blank to keep":""}" autocomplete="new-password"></label>
      </div>`:""}
      <label class="toggle"><input type="checkbox" id="mWeekend" data-mail="digest_weekends" ${s.digest_weekends?"checked":""}> Send reminders on weekends too</label>
      <div class="row-btns"><button class="btn primary" type="button" id="saveMail">Save email settings</button><button class="btn" type="button" id="testMail" ${m.enabled?"":"disabled"}>Send test email to me</button></div>
      <ul class="statuslist">
        <li class="${m.cron_ok?"ok":"warn"}">${m.cron_ok?`Scheduled job is running (last run ${esc(m.cron_seen)}).`:`Scheduled job hasn't run ${m.cron_seen?"since "+esc(m.cron_seen):"yet"}. Daily reminders need it. In hPanel go to Advanced → Cron Jobs and run this every 15 minutes:<code>${esc(m.cron_path||"cron.php")}</code>`}</li>
        ${m.pending?`<li>${m.pending} email${m.pending===1?"":"s"} waiting to send.</li>`:""}
        ${m.failed_7d?`<li class="warn">${m.failed_7d} email${m.failed_7d===1?"":"s"} failed in the last 7 days.</li>`:""}
        ${m.last_error?`<li class="warn">Last error: ${esc(m.last_error)}</li>`:""}
      </ul>
    </section>`;
  }
  body += `<section class="set-sec"><h3>Account</h3><p class="hint">Signed in as ${esc(state.me.name)} (${esc(state.me.email)}).</p><div><button class="btn danger" type="button" id="btnOut">Sign out</button></div></section>`;
  return drawerShell("Settings", "Settings", "", body, "");
}
function hourLabel(h){ h = Number(h); return h===0?"12 am":h<12?h+" am":h===12?"12 pm":(h-12)+" pm"; }

/* ---------- drawer events ---------- */
$("#drawerHost").addEventListener("click", async ev => {
  const e = state.editing; if (!e) return;
  const t = ev.target;
  if (t.closest("[data-close]")) { closeDrawer(); return; }
  const dn = t.closest("[data-delnote]"); if (dn) { deleteNote(Number(dn.dataset.delnote)); return; }

  if (e.kind==="notifications") {
    const it = t.closest("[data-nid]");
    if (it) {
      const tid = Number(it.dataset.ntask), pid = Number(it.dataset.nproj);
      api("notifications_read", {ids:[Number(it.dataset.nid)]}).then(r=>{ state.unread=r.unread; renderControls(); }).catch(()=>{});
      if (tid && state.tasks.some(x=>x.id===tid)) openTask(tid);
      else if (pid && projOf(pid)) openProject(pid);
      else toast("That item has been deleted");
      return;
    }
    if (t.id==="readAll") { try { const r = await api("notifications_read",{all:true}); state.unread=r.unread; e.items.forEach(n=>n.read=true); renderControls(); renderDrawer(); } catch(err){ toast(err.message); } }
    return;
  }
  if (e.kind==="team") {
    if (t.id==="addMem") { e.members.push({id:null, name:"", email:"", role:"", is_admin:false, has_login:false, color:MEMBER_COLORS[e.members.length % MEMBER_COLORS.length]}); renderDrawer(); document.getElementById("mn"+(e.members.length-1))?.focus(); }
    else if (t.dataset.mdel!==undefined) { e.members.splice(Number(t.dataset.mdel),1); renderDrawer(); }
    else if (t.id==="saveTeam") saveTeam();
    else if (t.dataset.invite) createInvite(Number(t.dataset.invite));
    else if (t.dataset.invmail) emailInvite(Number(t.dataset.invmail), t);
    else if (t.dataset.copy) copyField(t.dataset.copy, t);
    return;
  }
  if (e.kind==="settings") {
    if (t.id==="savePrefs") savePrefs();
    else if (t.id==="savePw") savePassword();
    else if (t.id==="saveMail") saveMail();
    else if (t.id==="testMail") testMail(t);
    else if (t.id==="btnOut") { try { await api("logout", {}); } catch(_) {} location.href = "index.php"; }
    return;
  }

  const pick = t.closest("[data-assign],[data-pmember]");
  if (pick) {
    const list = pick.dataset.assign ? e.assignees : e.members, id = Number(pick.dataset.assign || pick.dataset.pmember);
    const i = list.findIndex(x=>sameId(x,id)); if (i>=0) list.splice(i,1); else list.push(id);
    renderDrawer(); return;
  }
  const sw = t.closest("[data-color]"); if (sw) { e.color = sw.dataset.color; renderDrawer(); return; }
  const pri = t.closest("[data-pri]"); if (pri) { e.priority = Number(pri.dataset.pri); renderDrawer(); return; }
  if (t.id==="addSub") { e.subtasks.push({t:"",done:false}); renderDrawer(); document.getElementById("st"+(e.subtasks.length-1))?.focus(); return; }
  if (t.dataset.sdel!==undefined) { e.subtasks.splice(Number(t.dataset.sdel),1); renderDrawer(); return; }
  if (t.id==="postNote") { postNote(); return; }
  if (t.id==="saveTask") { saveTask(); return; }
  if (t.id==="markDone") { e.status="done"; saveTask(); return; }
  if (t.id==="saveProject") { saveProject(); return; }
  if (t.id==="viewTasks") { state.project = String(e.id); state.view = state.view==="projects" ? "priority" : state.view; closeDrawer(); render(); return; }
  if (t.id==="addProjTask") { const pid = e.id; state.project = String(pid); closeDrawer(); if (state.view==="projects") state.view="priority"; render(); openTask(null); return; }
  if (t.id==="delTask" || t.id==="delProject") { state.confirmDelete = true; renderDrawer(); return; }
  if (t.id==="delNo") { state.confirmDelete = false; renderDrawer(); return; }
  if (t.id==="delYes") { e.kind==="task" ? deleteTask() : deleteProject(); return; }
});
const onField = ev => {
  const e = state.editing; if (!e) return; const t = ev.target;
  if (t.dataset.f) e[t.dataset.f] = t.value;
  if (t.dataset.pf) e[t.dataset.pf] = t.value;
  if (t.dataset.draft !== undefined) e.draft = t.value;
  if (t.dataset.st!==undefined) e.subtasks[Number(t.dataset.st)].t = t.value;
  if (t.dataset.mi!==undefined) e.members[Number(t.dataset.mi)][t.dataset.mf] = t.type==="checkbox" ? t.checked : t.value;
  if (t.dataset.pw) e.pw[t.dataset.pw] = t.value;
  if (t.dataset.pref) e.prefs[t.dataset.pref] = t.checked;
  if (t.dataset.mail) e.mail[t.dataset.mail] = t.type==="checkbox" ? t.checked : t.value;
};
$("#drawerHost").addEventListener("input", onField);
$("#drawerHost").addEventListener("change", ev => {
  onField(ev);
  const e = state.editing; const t = ev.target; if (!e) return;
  if (t.dataset.sc!==undefined) { e.subtasks[Number(t.dataset.sc)].done = t.checked; renderDrawer(); }
  if (t.id==="widen") { e.widen = t.checked; renderDrawer(); }
  if (t.dataset.mail==="driver") renderDrawer();
  if (t.dataset.f==="project" && !e.id) { const p = projOf(t.value); if (p && p.team!=="General") { e.team = p.team; renderDrawer(); } }
  if (t.dataset.pf==="lead" && t.value && !e.members.some(x=>sameId(x,t.value))) { e.members.push(Number(t.value)); renderDrawer(); }
});
$("#drawerHost").addEventListener("keydown", ev => {
  if (ev.key==="Enter" && ev.target.dataset.st!==undefined) { ev.preventDefault(); $("#addSub")?.click(); }
  if (ev.key==="Enter" && (ev.metaKey||ev.ctrlKey) && ev.target.id==="noteDraft") { ev.preventDefault(); postNote(); }
});

/* ---------- drawer actions ---------- */
async function saveTask(){
  const e = state.editing;
  if (!(e.title||"").trim()) { toast("Give the task a name first"); $("#tTitle")?.focus(); return; }
  const btn = $("#saveTask"); if (btn) btn.disabled = true;
  const task = { title:e.title, project: e.project===""?null:Number(e.project), team:e.team, assignees:e.assignees.map(Number), priority:Number(e.priority), status:e.status,
    start:e.start||"", due:e.due||"", notes:e.notes||"", subtasks:e.subtasks };
  if (e.id) task.id = e.id;
  const before = e.id ? state.tasks.find(x=>x.id===e.id) : null;
  try {
    const r = await api("task_save", {task});
    upsert(state.tasks, r.task); noteRevision(r.revision);
    const newly = r.task.assignees.filter(id => !sameId(id,state.me.id) && !(before?.assignees||[]).some(x=>sameId(x,id))).length;
    const msg = !e.id ? "Task added" : (task.status==="done" && before?.status!=="done" ? "Marked done" : "Saved");
    toast(newly && state.mail.enabled ? `${msg}. ${newly} ${newly===1?"person":"people"} notified.` : msg);
    closeDrawer(); render();
  } catch(err) { if (btn) btn.disabled = false; toast(err.message); }
}
async function deleteTask(){
  const e = state.editing;
  try {
    const r = await api("task_delete", {id:e.id});
    state.tasks = state.tasks.filter(t=>t.id!==e.id); noteRevision(r.revision);
    closeDrawer(); render(); toast("Task deleted");
  } catch(err) { toast(err.message); }
}
async function saveProject(){
  const e = state.editing;
  if (!(e.name||"").trim()) { toast("Give the project a name"); $("#pName")?.focus(); return; }
  const btn = $("#saveProject"); if (btn) btn.disabled = true;
  const project = {id:e.id||undefined, name:e.name, team:e.team, status:e.status, color:e.color, description:e.description||"", lead: e.lead===""?null:Number(e.lead),
    members:e.members.map(Number), start:e.start||"", due:e.due||""};
  try {
    const r = await api("project_save", {project});
    upsert(state.projects, r.project); noteRevision(r.revision);
    toast(e.id ? "Project saved" : "Project created");
    if (!e.id) { closeDrawer(); state.view = "projects"; render(); openProject(r.project.id); }
    else { Object.assign(e, r.project, {lead: r.project.lead ?? "", members:[...r.project.members]}); renderDrawer(); render(); }
  } catch(err) { if (btn) btn.disabled = false; toast(err.message); }
}
async function deleteProject(){
  const e = state.editing;
  try {
    const r = await api("project_delete", {id:e.id});
    state.projects = state.projects.filter(p=>p.id!==e.id);
    state.tasks.forEach(t=>{ if (sameId(t.project,e.id)) t.project = null; });
    if (sameId(state.project, e.id)) state.project = "all";
    noteRevision(r.revision); closeDrawer(); render(); toast("Project deleted");
  } catch(err) { toast(err.message); }
}
async function postNote(){
  const e = state.editing; if (!e || !e.id || e.posting) return;
  const text = (e.draft||"").trim();
  if (!text) { toast("Write a note first"); $("#noteDraft")?.focus(); return; }
  e.posting = true; renderDrawer();
  try {
    const payload = e.kind==="task" ? {task_id:e.id, body:text} : {project_id:e.id, body:text, include_assignees:!!e.widen};
    const r = await api("comment_add", payload);
    e.timeline = r.timeline; e.draft = ""; noteRevision(r.revision);
    if (r.task) { upsert(state.tasks, r.task); e.seenComments = r.task.comments; }
    if (r.project) { upsert(state.projects, r.project); e.seenNotes = r.project.notes; }
    toast(r.notified ? `Posted. ${r.notified} ${r.notified===1?"person":"people"} notified.` : "Posted");
    render();
  } catch(err) { toast(err.message); }
  e.posting = false; renderDrawer();
}
async function deleteNote(id){
  const e = state.editing;
  try {
    await api("comment_delete", {id});
    e.timeline = (e.timeline||[]).filter(it => !(it.kind==="note" && it.id===id));
    if (e.kind==="task") { const t = state.tasks.find(x=>x.id===e.id); if (t) { t.comments = Math.max(0,t.comments-1); e.seenComments = t.comments; } }
    if (e.kind==="project") { const p = projOf(e.id); if (p) { p.notes = Math.max(0,p.notes-1); e.seenNotes = p.notes; } }
    renderDrawer(); render(); toast("Note deleted");
  } catch(err) { toast(err.message); }
}
async function saveTeam(){
  const e = state.editing;
  const btn = $("#saveTeam"); if (btn) btn.disabled = true;
  try {
    const d = await api("team_save", {members: e.members.map(m=>({id:m.id, name:m.name, email:m.email, role:m.role, color:m.color, is_admin:!!m.is_admin}))});
    applyState(d);
    state.editing = {kind:"team", members: state.members.map(m=>({...m})), links:e.links};
    renderDrawer(); render(); toast("Team saved");
  } catch(err) { if (btn) btn.disabled = false; toast(err.message); }
}
async function createInvite(id){
  try { const r = await api("invite_link", {id}); state.editing.links[id] = r.url; renderDrawer(); toast("Link created. Copy it and send it to them."); }
  catch(err) { toast(err.message); }
}
async function emailInvite(id, btn){
  btn.disabled = true; btn.textContent = "Sending…";
  try { await api("invite_email", {id}); toast("Invite emailed to "+(memberOf(id)?.email||"them")); btn.textContent = "Sent"; }
  catch(err) { toast(err.message); btn.disabled = false; btn.textContent = "Email invite"; }
}
async function copyField(inputId, btn){
  const el = document.getElementById(inputId); if (!el) return;
  try { await navigator.clipboard.writeText(el.value); btn.textContent = "Copied"; }
  catch(e) { el.focus(); el.select(); toast("Press Ctrl+C (or Cmd+C) to copy"); }
}
async function savePrefs(){
  try { const r = await api("prefs_save", {prefs: state.editing.prefs}); state.me = r.me; toast("Email preferences saved"); }
  catch(err) { toast(err.message); }
}
async function savePassword(){
  const p = state.editing.pw;
  if (p.new !== p.confirm) { toast("The new passwords don't match"); return; }
  try { await api("password_change", {current:p.current, new:p.new}); state.editing.pw = {current:"",new:"",confirm:""}; renderDrawer(); toast("Password updated"); }
  catch(err) { toast(err.message); }
}
async function saveMail(){
  const btn = $("#saveMail"); if (btn) btn.disabled = true;
  try {
    const r = await api("mail_settings_save", {settings: state.editing.mail});
    state.mail = r.mail; state.editing.mail.pass = ""; renderDrawer(); toast(r.mail.enabled ? "Email settings saved. Send a test to check." : "Email settings saved");
  } catch(err) { toast(err.message); }
  if ($("#saveMail")) $("#saveMail").disabled = false;
}
async function testMail(btn){
  btn.disabled = true; btn.textContent = "Sending…";
  try { const r = await api("mail_test", {}); toast("Test email sent to "+r.sent_to); state.mail.last_error = ""; }
  catch(err) { toast("Email failed: "+err.message); state.mail.last_error = err.message; }
  renderDrawer();
}

/* ---------- deep links: index.php#task-12 / #project-3 (used by email buttons) ---------- */
function openFromHash(){
  const m = /^#(task|project)-(\d+)$/.exec(location.hash || "");
  if (!m || !state.loaded) return;
  history.replaceState(null, "", location.pathname + location.search);
  const id = Number(m[2]);
  if (m[1]==="task") { if (state.tasks.some(t=>t.id===id)) openTask(id); else toast("That task has been deleted"); }
  else { if (projOf(id)) openProject(id); else toast("That project has been deleted"); }
}
window.addEventListener("hashchange", openFromHash);

/* ---------- live updates ---------- */
async function poll(){
  if (document.hidden) return;
  try {
    const r = await api("poll");
    if (r.unread !== state.unread) { state.unread = r.unread; renderControls(); }
    if (r.revision !== state.revision) await loadAll();
  } catch(e) { /* keep polling */ }
}
setInterval(poll, POLL_MS);
document.addEventListener("visibilitychange", () => { if (!document.hidden) poll(); });

render();
loadAll().then(openFromHash);
})();
