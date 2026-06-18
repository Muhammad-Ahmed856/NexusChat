<?php
require_once __DIR__ . '/config.php';
requireAuth();
$user = currentUser();
if ($user['username'] !== ADMIN_USERNAME) { header('Location: rooms.php'); exit; }
$avatarSrc = resolveAvatarSrc($user['avatar_url'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>NexusChat — Network Dashboard</title>
<link rel="stylesheet" href="style.css">
<style>
.dash-layout{min-height:100vh;display:flex;flex-direction:column;background:var(--bg)}
.dash-nav{display:flex;align-items:center;justify-content:space-between;padding:1rem 2rem;border-bottom:1px solid var(--border);background:var(--card);position:sticky;top:0;z-index:50}
.dash-main{flex:1;padding:2rem;max-width:1500px;margin:0 auto;width:100%}
.dash-header{margin-bottom:2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.dash-header h2{font-size:1.8rem;font-weight:800}
.dash-header p{color:var(--text-muted);margin-top:.3rem;font-size:.9rem}
.dash-tabs{display:flex;gap:.3rem;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:.4rem;margin-bottom:2rem;flex-wrap:wrap}
.dash-tab{padding:.55rem 1.2rem;border-radius:8px;border:none;background:transparent;color:var(--text-muted);font-size:.88rem;font-weight:600;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:.5rem}
.dash-tab:hover{background:rgba(255,255,255,.05);color:var(--text)}
.dash-tab.active{background:var(--accent);color:#fff}
.tab-panel{display:none}
.tab-panel.active{display:block}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:1rem;margin-bottom:2rem}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.4rem;position:relative;overflow:hidden}
.stat-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--accent-grad)}
.stat-label{font-size:.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;font-weight:600}
.stat-value{font-size:2rem;font-weight:900;margin:.4rem 0;font-variant-numeric:tabular-nums}
.stat-sub{font-size:.78rem;color:var(--text-muted)}
.dash-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem}
.panel{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.5rem}
.panel h3{font-size:1rem;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.panel-green::before{content:'';display:inline-block;width:8px;height:8px;border-radius:50%;background:#43e97b;animation:pulse 2s infinite}
.chart-wrap{height:140px;display:flex;align-items:flex-end;gap:3px}
.chart-bar{flex:1;background:var(--accent-grad);border-radius:4px 4px 0 0;min-height:2px;transition:height .4s ease;position:relative;cursor:default}
.chart-bar:hover::after{content:attr(title);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:var(--card);border:1px solid var(--border);padding:.2rem .5rem;border-radius:6px;font-size:.75rem;white-space:nowrap;z-index:10}
.chart-labels{display:flex;justify-content:space-between;font-size:.7rem;color:var(--text-muted);margin-top:.5rem}
.bw-chart-wrap{height:120px;display:flex;align-items:flex-end;gap:2px}
.bw-bar-group{display:flex;align-items:flex-end;gap:1px;flex:1}
.bw-bar-in{flex:1;background:#43e97b;border-radius:2px 2px 0 0;min-height:1px;transition:height .4s ease}
.bw-bar-out{flex:1;background:#4facfe;border-radius:2px 2px 0 0;min-height:1px;transition:height .4s ease}
.bw-legend{display:flex;gap:1.2rem;margin-top:.6rem;font-size:.72rem;color:var(--text-muted)}
.bw-legend span{display:flex;align-items:center;gap:.3rem}
.bw-dot{width:8px;height:8px;border-radius:2px;display:inline-block}
.conn-row{display:flex;justify-content:space-between;padding:.6rem 0;border-bottom:1px solid var(--border);font-size:.85rem}
.conn-row:last-child{border:none}
.conn-label{color:var(--text-muted)}
.conn-value{font-family:Consolas,'Cascadia Code',monospace;font-size:.8rem}
.status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:.4rem}
.status-online{background:#43e97b;box-shadow:0 0 6px #43e97b;animation:pulse 2s infinite}
@keyframes port-cell-flash{0%{background:rgba(0,212,170,.22);color:#00d4aa}100%{background:transparent;color:#4facfe}}
.port-flash{animation:port-cell-flash 1.8s ease-out forwards;border-radius:4px}
.port-change-badge{display:inline-flex;align-items:center;margin-left:.4rem;background:rgba(249,115,22,.15);color:#f97316;border:1px solid rgba(249,115,22,.3);border-radius:5px;font-size:.65rem;font-weight:700;padding:.05rem .3rem;cursor:help;vertical-align:middle}
.packet-viewer{background:#0d1117;border:1px solid #30363d;border-radius:12px;padding:1rem;font-family:Consolas,monospace;font-size:.78rem;height:280px;overflow-y:auto;line-height:1.6}
.packet-viewer::-webkit-scrollbar{width:6px}
.packet-viewer::-webkit-scrollbar-thumb{background:#444;border-radius:3px}
.packet{padding:.2rem 0;border-bottom:1px solid rgba(255,255,255,.04)}
.packet-in{color:#43e97b}.packet-out{color:#4facfe}
.packet-ts{color:#555;margin-right:.8rem}.packet-type{font-weight:700;margin-right:.8rem}.packet-payload{color:#8b949e}
.ban-form{background:rgba(255,107,107,.06);border:1px solid rgba(255,107,107,.2);border-radius:12px;padding:1.2rem;margin-bottom:1.5rem;display:grid;grid-template-columns:1fr 1fr auto auto auto;gap:.75rem;align-items:end}
.ban-form label{font-size:.75rem;color:var(--text-muted);display:block;margin-bottom:.3rem;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.ban-form input,.ban-form select{width:100%;background:var(--input-bg);border:1px solid var(--border);border-radius:8px;padding:.55rem .8rem;color:var(--text);font-size:.88rem}
.ban-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:20px;font-size:.72rem;font-weight:700}
.ban-active{background:rgba(255,107,107,.15);color:#ff6b6b;border:1px solid rgba(255,107,107,.25)}
.ban-expired{background:rgba(100,100,120,.15);color:var(--text-muted);border:1px solid var(--border)}
.ban-ip-badge{background:rgba(249,115,22,.12);color:#f97316;border:1px solid rgba(249,115,22,.2);font-size:.72rem;padding:.15rem .4rem;border-radius:4px}
.audit-viewer{background:#0d1117;border:1px solid #30363d;border-radius:12px;padding:1rem;font-size:.82rem;height:420px;overflow-y:auto;line-height:1.7}
.audit-viewer::-webkit-scrollbar{width:6px}
.audit-viewer::-webkit-scrollbar-thumb{background:#333;border-radius:3px}
.audit-row{display:grid;grid-template-columns:140px 130px 1fr;gap:.8rem;padding:.45rem .3rem;border-bottom:1px solid rgba(255,255,255,.04);align-items:start}
.audit-ts{color:#555;font-size:.74rem;font-family:monospace}
.audit-action{font-weight:700;font-size:.78rem}
.audit-detail{color:#8b949e;font-size:.8rem}
.action-ban_user{color:#ff6b6b}.action-unban_user{color:#43e97b}.action-kick_member{color:#f97316}.action-login{color:#4facfe}.action-delete_message{color:#c084fc}
.dash-table{width:100%;border-collapse:collapse;font-size:.85rem}
.dash-table th{text-align:left;padding:.4rem .6rem;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px}
.dash-table td{padding:.55rem .6rem;border-bottom:1px solid var(--border)}
.dash-table tr:last-child td{border:none}
.btn-danger{background:rgba(255,107,107,.15);color:#ff6b6b;border:1px solid rgba(255,107,107,.3);border-radius:7px;padding:.3rem .75rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .15s}
.btn-danger:hover{background:rgba(255,107,107,.3)}
.btn-success{background:rgba(67,233,123,.12);color:#43e97b;border:1px solid rgba(67,233,123,.25);border-radius:7px;padding:.3rem .75rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .15s}
.btn-success:hover{background:rgba(67,233,123,.25)}
.btn-sm-outline{background:transparent;color:var(--text-muted);border:1px solid var(--border);border-radius:7px;padding:.3rem .75rem;font-size:.8rem;cursor:pointer;transition:all .15s}
.btn-sm-outline:hover{border-color:var(--accent);color:var(--accent)}
.toast-area{position:fixed;bottom:1.5rem;right:1.5rem;display:flex;flex-direction:column;gap:.5rem;z-index:9999}
.toast{padding:.75rem 1.2rem;border-radius:10px;font-size:.88rem;font-weight:600;animation:slideUp .2s ease;max-width:320px}
.toast-ok{background:rgba(67,233,123,.15);color:#43e97b;border:1px solid rgba(67,233,123,.3)}
.toast-err{background:rgba(255,107,107,.15);color:#ff6b6b;border:1px solid rgba(255,107,107,.3)}
@keyframes slideUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
@media(max-width:900px){.dash-grid-2{grid-template-columns:1fr}.ban-form{grid-template-columns:1fr 1fr}.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.dash-main{padding:1rem}.ban-form{grid-template-columns:1fr}.audit-row{grid-template-columns:1fr;gap:.2rem}}
</style>
</head>
<body>
<div class="dash-layout">
  <nav class="dash-nav">
    <div style="font-size:1.3rem;font-weight:800">⚡ NexusChat</div>
    <div style="display:flex;gap:1rem;align-items:center">
      <div id="dashboard-user-avatar" style="display:inline-flex;position:relative">
        <?php if ($avatarSrc): ?>
          <img src="<?= escHtml($avatarSrc) ?>" class="avatar avatar-img" alt="<?= escHtml($user['username']) ?>" style="width:32px;height:32px;border-radius:9px">
        <?php else: ?>
          <div class="avatar" style="background:<?=escHtml($user['avatar_color'])?>;width:32px;height:32px;border-radius:9px;font-size:.75rem"><?=escHtml(strtoupper(substr($user['username'],0,2)))?></div>
        <?php endif; ?>
      </div>
    </div>
  </nav>
  <main class="dash-main">
    <div class="dash-header">
      <div><h2>📡 Network Dashboard</h2><p>Real-time stats, ban management, audit trail &amp; bandwidth meter</p></div>
      <div id="last-refresh" style="font-size:.75rem;color:var(--text-muted)"></div>
    </div>

    <div class="stats-grid">
      <div class="stat-card"><div class="stat-label">Active Users</div><div class="stat-value" id="stat-active">—</div><div class="stat-sub">last 5 min</div></div>
      <div class="stat-card"><div class="stat-label">Msgs / Hour</div><div class="stat-value" id="stat-hour">—</div><div class="stat-sub">this hour</div></div>
      <div class="stat-card"><div class="stat-label">Msgs / Day</div><div class="stat-value" id="stat-day">—</div><div class="stat-sub">last 24 h</div></div>
      <div class="stat-card"><div class="stat-label">Msgs / Min</div><div class="stat-value" id="stat-mpm">—</div><div class="stat-sub">10-min avg</div></div>
      <div class="stat-card"><div class="stat-label">Total Rooms</div><div class="stat-value" id="stat-rooms">—</div><div class="stat-sub">all types</div></div>
      <div class="stat-card"><div class="stat-label">Total Users</div><div class="stat-value" id="stat-users">—</div><div class="stat-sub">registered</div></div>
      <div class="stat-card"><div class="stat-label">Active Bans</div><div class="stat-value" id="stat-bans" style="color:#ff6b6b">—</div><div class="stat-sub">current</div></div>
      <div class="stat-card"><div class="stat-label">BW In (1 min)</div><div class="stat-value" id="stat-bw-in" style="color:#43e97b;font-size:1.4rem">—</div><div class="stat-sub">bytes received</div></div>
    </div>

    <div class="dash-tabs">
      <button class="dash-tab active" onclick="switchTab('overview')"  id="tab-overview">📈 Overview</button>
      <button class="dash-tab"        onclick="switchTab('users')"     id="tab-users">👥 Active Users</button>
      <button class="dash-tab"        onclick="switchTab('bandwidth')" id="tab-bandwidth">📶 Bandwidth</button>
      <button class="dash-tab"        onclick="switchTab('bans')"      id="tab-bans">🚫 Ban Manager</button>
      <button class="dash-tab"        onclick="switchTab('audit')"     id="tab-audit">📋 Audit Log</button>
      <button class="dash-tab"        onclick="switchTab('packets')"   id="tab-packets">🔌 Packet Log</button>
    </div>

    <!-- TAB: Overview -->
    <div class="tab-panel active" id="panel-overview">
      <div class="dash-grid-2">
        <div class="panel">
          <h3>📈 Message Activity (24h)</h3>
          <div class="chart-wrap" id="activity-chart"></div>
          <div class="chart-labels"><span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:59</span></div>
        </div>
        <div class="panel">
          <h3 class="panel-green"> Connection Status</h3>
          <div class="conn-row"><span class="conn-label">Transport</span><span id="transport-mode"><span class="status-dot" style="background:#555"></span>Checking…</span></div>
          <div class="conn-row"><span class="conn-label">API Server</span><span><span class="status-dot status-online"></span>HTTP — Online</span></div>
          <div class="conn-row"><span class="conn-label">WS Address</span><span class="conn-value" id="ws-server">—</span><span class="conn-value">:</span><span class="conn-value" id="ws-port">—</span></div>
          <div class="conn-row"><span class="conn-label">Your Address</span><span class="conn-value"><?=$_SERVER['REMOTE_ADDR']?>:<?=$_SERVER['REMOTE_PORT']?></span></div>
          <div class="conn-row"><span class="conn-label">Server Time</span><span class="conn-value" id="server-time">—</span></div>
          <div class="conn-row"><span class="conn-label">Logged in as</span><span class="conn-value"><?=escHtml($user['username'])?></span></div>
        </div>
      </div>
    </div>

    <!-- TAB: Active Users -->
    <div class="tab-panel" id="panel-users">
      <div class="panel">
        <h3 style="display:flex;align-items:center;justify-content:space-between">
          <span>👥 Active Users <span id="active-count" style="font-size:.8rem;color:var(--text-muted);font-weight:400;margin-left:.5rem"></span></span>
          <span style="font-size:.72rem;color:var(--text-muted);font-weight:400">Auto-refreshes every 3s · ↻N = port changes</span>
        </h3>
        <div style="overflow-x:auto">
          <table class="dash-table">
            <thead><tr><th>User</th><th>IP Address</th><th>Port</th><th>Full Address</th><th>Last Seen</th><th>Action</th></tr></thead>
            <tbody id="active-users-tbody"><tr><td colspan="6" style="padding:.8rem;color:var(--text-muted)">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB: Bandwidth -->
    <div class="tab-panel" id="panel-bandwidth">
      <div class="dash-grid-2">
        <div class="panel">
          <h3>📶 Bandwidth — Last 60 Minutes</h3>
          <div class="bw-chart-wrap" id="bw-chart"></div>
          <div class="bw-legend">
            <span><span class="bw-dot" style="background:#43e97b"></span>Inbound</span>
            <span><span class="bw-dot" style="background:#4facfe"></span>Outbound</span>
          </div>
          <div class="chart-labels" style="margin-top:.3rem"><span>60m ago</span><span>45m</span><span>30m</span><span>15m</span><span>now</span></div>
        </div>
        <div class="panel">
          <h3>📊 Totals (Last Hour)</h3>
          <div class="conn-row"><span class="conn-label">Total Received</span><span class="conn-value" id="bw-total-in" style="color:#43e97b">—</span></div>
          <div class="conn-row"><span class="conn-label">Total Sent</span><span class="conn-value" id="bw-total-out" style="color:#4facfe">—</span></div>
          <div class="conn-row"><span class="conn-label">Combined</span><span class="conn-value" id="bw-total-combined">—</span></div>
          <div class="conn-row"><span class="conn-label">Peak Min (In)</span><span class="conn-value" id="bw-peak-in" style="color:#43e97b">—</span></div>
          <div class="conn-row"><span class="conn-label">Peak Min (Out)</span><span class="conn-value" id="bw-peak-out" style="color:#4facfe">—</span></div>
          <div class="conn-row"><span class="conn-label">Source</span><span style="font-size:.75rem;color:var(--text-muted)">Message payloads + responses</span></div>
        </div>
      </div>
    </div>

    <!-- TAB: Ban Manager -->
    <div class="tab-panel" id="panel-bans">
      <div class="ban-form">
        <div><label>Username</label><input type="text" id="ban-username" placeholder="e.g. johndoe" autocomplete="off"></div>
        <div><label>Reason (optional)</label><input type="text" id="ban-reason" placeholder="e.g. Spam"></div>
        <div><label>Duration</label>
          <select id="ban-hours">
            <option value="0">Permanent</option><option value="1">1 hour</option>
            <option value="6">6 hours</option><option value="24">24 hours</option>
            <option value="72">3 days</option><option value="168">7 days</option>
            <option value="720">30 days</option>
          </select>
        </div>
        <div><label>Ban IP too</label>
          <select id="ban-ip"><option value="0">No</option><option value="1">Yes</option></select>
        </div>
        <div><label>&nbsp;</label><button class="btn-danger" onclick="banUser()" style="width:100%;padding:.56rem">🚫 Ban User</button></div>
      </div>
      <div class="panel">
        <h3 style="display:flex;align-items:center;justify-content:space-between">
          <span>🚫 Ban History</span>
          <button class="btn-sm-outline" onclick="loadBans()">↻ Refresh</button>
        </h3>
        <div style="overflow-x:auto">
          <table class="dash-table">
            <thead><tr><th>User</th><th>Reason</th><th>Banned IP</th><th>Banned By</th><th>Banned At</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead>
            <tbody id="bans-tbody"><tr><td colspan="8" style="padding:.8rem;color:var(--text-muted)">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB: Audit Log -->
    <div class="tab-panel" id="panel-audit">
      <div class="panel">
        <h3 style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
          <span>📋 Audit Log</span>
          <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <label style="font-size:.8rem;color:var(--text-muted)">Filter:</label>
            <select id="audit-filter" onchange="loadAudit()" style="background:var(--input-bg);border:1px solid var(--border);border-radius:8px;padding:.4rem .7rem;color:var(--text);font-size:.83rem">
              <option value="">All actions</option>
              <option value="ban_user">ban_user</option><option value="unban_user">unban_user</option>
              <option value="kick_member">kick_member</option><option value="login">login</option>
              <option value="delete_message">delete_message</option>
            </select>
            <button class="btn-sm-outline" onclick="loadAudit()">↻ Refresh</button>
          </div>
        </h3>
        <div class="audit-viewer" id="audit-viewer"><div style="color:#555">Loading…</div></div>
      </div>
    </div>

    <!-- TAB: Packet Log -->
    <div class="tab-panel" id="panel-packets">
      <div class="panel">
        <h3 style="display:flex;justify-content:space-between;align-items:center">
          <span>🔌 Live Packet Log</span>
          <div style="display:flex;gap:.8rem;align-items:center">
            <label style="font-size:.8rem;color:var(--text-muted);display:flex;align-items:center;gap:.4rem;cursor:pointer">
              <input type="checkbox" id="auto-scroll" checked> Auto-scroll
            </label>
            <button class="btn-sm-outline" onclick="clearViewer()">Clear</button>
            <button class="btn-sm-outline" onclick="loadPackets()">↻ Refresh</button>
          </div>
        </h3>
        <div class="packet-viewer" id="packet-viewer"><div style="color:#555">Waiting for packets…</div></div>
      </div>
    </div>
  </main>
</div>
<div class="toast-area" id="toast-area"></div>

<script>
const WS_HOST='<?=WS_PUBLIC_HOST?>';
const WS_PORT_NUM=<?=WS_PORT?>;
const ADMIN_USER='<?=escHtml($user['username'])?>';
const CSRF='<?=getCsrfToken()?>';
let packetViewer=document.getElementById('packet-viewer');
let lastUserData={};
let portHistory={};

function switchTab(name){
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.dash-tab').forEach(b=>b.classList.remove('active'));
  document.getElementById('panel-'+name).classList.add('active');
  document.getElementById('tab-'+name).classList.add('active');
  if(name==='bans')      loadBans();
  if(name==='audit')     loadAudit();
  if(name==='packets')   loadPackets();
  if(name==='bandwidth') loadBandwidth();
}

function toast(msg,ok=true){
  const el=document.createElement('div');
  el.className='toast '+(ok?'toast-ok':'toast-err');
  el.textContent=msg;
  document.getElementById('toast-area').appendChild(el);
  setTimeout(()=>el.remove(),3500);
}
function escHtml(s){const d=document.createElement('div');d.textContent=String(s??'');return d.innerHTML;}
function fmtBytes(b){b=+b;if(b<1024)return b+' B';if(b<1048576)return(b/1024).toFixed(1)+' KB';return(b/1048576).toFixed(2)+' MB';}
function setText(id,val){
  const el=document.getElementById(id);
  if(!el||el.textContent===String(val))return;
  el.textContent=String(val);
  if(el.classList.contains('stat-value')){el.classList.remove('bump');void el.offsetWidth;el.classList.add('bump');setTimeout(()=>el.classList.remove('bump'),400);}
}
function timeSince(ts){
  const sec=Math.floor((Date.now()-new Date(ts).getTime())/1000);
  if(sec<5)return'Just now';if(sec<60)return sec+'s ago';if(sec<3600)return Math.floor(sec/60)+'m ago';return Math.floor(sec/3600)+'h ago';
}
function postData(fd){return{method:'POST',credentials:'same-origin',body:fd};}
function makeForm(obj){const fd=new FormData();Object.entries(obj).forEach(([k,v])=>fd.append(k,v));fd.append('_csrf',CSRF);return fd;}

async function loadStats(){
  try{
    const d=await fetch('ajax_server.php?action=network_stats').then(r=>r.json());
    if(!d.success)return;
    setText('stat-active',d.active_users);
    setText('stat-hour',d.messages_last_hour);
    setText('stat-day',d.messages_last_day);
    setText('stat-mpm',d.messages_per_minute);
    setText('stat-rooms',d.room_count);
    setText('stat-users',d.user_count);
    setText('stat-bans',d.active_bans??'—');
    setText('stat-bw-in',fmtBytes(d.bw_in_last_min??0));
    setText('ws-server',d.ws_host);
    setText('ws-port',d.ws_port);
    setText('server-time',d.server_time);
    document.getElementById('last-refresh').textContent='Last refresh: '+new Date().toLocaleTimeString();
    renderChart(d.activity_chart);
    diffUpdateUsers(d.active_users_list||[]);
  }catch(e){}
}

function renderChart(data){
  const wrap=document.getElementById('activity-chart');
  if(!wrap)return;
  // Always build a full 24-hour series — hours with no messages stay at min height
  const buckets=Array.from({length:24},(_,i)=>({hour:i,count:0}));
  if(Array.isArray(data)) data.forEach(d=>{buckets[+d.hour].count=+d.count;});
  const max=Math.max(...buckets.map(b=>b.count),1);
  wrap.innerHTML=buckets.map(b=>{
    const pct=Math.max(b.count/max*100,2); // min 2% so zero bars are still visible
    const opacity=b.count===0?'0.2':'1';
    return`<div class="chart-bar" style="height:${pct}%;opacity:${opacity}" title="${b.hour}:00 — ${b.count} msgs"></div>`;
  }).join('');
}

async function loadBandwidth(){
  try{
    const d=await fetch('ajax_server.php?action=bandwidth_stats').then(r=>r.json());
    if(!d.success)return;
    const series=d.series||[];
    // When all values are zero, maxVal stays at 1 so bars render at min height (baseline)
    const maxVal=Math.max(...series.map(s=>Math.max(+s.bytes_in,+s.bytes_out)),1);
    const wrap=document.getElementById('bw-chart');
    if(!wrap)return;
    wrap.innerHTML=series.map(s=>{
      const inVal=+s.bytes_in, outVal=+s.bytes_out;
      // Minimum 2% height so bars are always visible even at zero
      const pIn =Math.max(inVal /maxVal*100,2);
      const pOut=Math.max(outVal/maxVal*100,2);
      const opIn =inVal ===0?'0.15':'1';
      const opOut=outVal===0?'0.15':'1';
      return`<div class="bw-bar-group">
        <div class="bw-bar-in"  style="height:${pIn}%;opacity:${opIn}"   title="${s.bucket}: ${fmtBytes(inVal)} in"></div>
        <div class="bw-bar-out" style="height:${pOut}%;opacity:${opOut}" title="${s.bucket}: ${fmtBytes(outVal)} out"></div>
      </div>`;
    }).join('');
    setText('bw-total-in',    fmtBytes(d.total_in  ||0));
    setText('bw-total-out',   fmtBytes(d.total_out ||0));
    setText('bw-total-combined',fmtBytes((d.total_in||0)+(d.total_out||0)));
    const peakIn =Math.max(...series.map(s=>+s.bytes_in),  0);
    const peakOut=Math.max(...series.map(s=>+s.bytes_out), 0);
    setText('bw-peak-in',  fmtBytes(peakIn));
    setText('bw-peak-out', fmtBytes(peakOut));
  }catch(e){ console.error('loadBandwidth error:',e); }
}

function diffUpdateUsers(users){
  const tbody=document.getElementById('active-users-tbody');
  const countEl=document.getElementById('active-count');
  if(!users.length){
    tbody.innerHTML='<tr><td colspan="6" style="padding:.8rem;color:var(--text-muted)">No active users</td></tr>';
    if(countEl)countEl.textContent='(0)';lastUserData={};return;
  }
  if(countEl)countEl.textContent='('+users.length+')';
  const incoming={};users.forEach(u=>{incoming[u.username]=u;});
  Object.keys(lastUserData).forEach(un=>{if(!incoming[un]){document.getElementById('urow-'+un)?.remove();delete lastUserData[un];}});
  users.forEach(u=>{
    const prev=lastUserData[u.username];
    const portStr=u.port?String(u.port):'—';
    const ipStr=u.ip?u.ip:'—';
    const fullStr=(u.ip&&u.port)?u.ip+':'+u.port:ipStr;
    const ago=timeSince(u.ts);
    const isMe=u.username===ADMIN_USER;
    if(!portHistory[u.username])portHistory[u.username]=[];
    const hist=portHistory[u.username];
    if(!prev||prev.port!==u.port){if(portStr!=='—'&&(!hist.length||hist[hist.length-1]!==portStr))hist.push(portStr);if(hist.length>5)hist.shift();}
    const changeCount=hist.length>1?hist.length-1:0;
    let row=document.getElementById('urow-'+u.username);
    if(!row){
      row=document.createElement('tr');row.id='urow-'+u.username;
      if(isMe)row.style.background='rgba(108,99,255,.08)';
      row.innerHTML=_userRowHtml(u.username,ipStr,portStr,fullStr,ago,changeCount,hist,isMe);
      tbody.appendChild(row);
    }else{
      if(!prev||prev.ip!==u.ip){_setCellText(row,'ip',ipStr);_setCellText(row,'full',fullStr);}
      if(!prev||prev.port!==u.port){_flashPortCell(row,portStr,changeCount,hist);_setCellText(row,'full',fullStr);}
      _setCellText(row,'ago',ago);
    }
    lastUserData[u.username]={ip:u.ip,port:u.port,ts:u.ts};
  });
}

function _userRowHtml(username,ip,port,full,ago,changeCount,hist,isMe){
  const initials=username.slice(0,2).toUpperCase();
  const badge=changeCount>0?`<span class="port-change-badge" title="Port changed ${changeCount}×. History: ${hist.join(' → ')}">↻${changeCount}</span>`:'';
  return`<td style="padding:.55rem .6rem;border-bottom:1px solid var(--border)">
      <div style="display:flex;align-items:center;gap:.6rem">
        <div style="width:26px;height:26px;border-radius:6px;background:#6c63ff;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:800;color:#fff;flex-shrink:0">${initials}</div>
        <span style="font-weight:600">${escHtml(username)}</span>
        ${isMe?'<span style="font-size:.68rem;color:#6c63ff;font-weight:700">YOU</span>':''}
      </div>
    </td>
    <td data-cell="ip"   style="padding:.55rem .6rem;border-bottom:1px solid var(--border);font-family:monospace;font-size:.82rem">${escHtml(ip)}</td>
    <td data-cell="port" style="padding:.55rem .6rem;border-bottom:1px solid var(--border);font-family:monospace;font-size:.82rem;color:#4facfe"><span class="port-val">${escHtml(port)}</span>${badge}</td>
    <td data-cell="full" style="padding:.55rem .6rem;border-bottom:1px solid var(--border);font-family:monospace;font-size:.82rem;color:#43e97b">${escHtml(full)}</td>
    <td data-cell="ago"  style="padding:.55rem .6rem;border-bottom:1px solid var(--border);color:var(--text-muted);font-size:.8rem">${ago}</td>
    <td style="padding:.55rem .6rem;border-bottom:1px solid var(--border)">
      ${!isMe?`<button class="btn-danger" onclick="quickBan('${escHtml(username)}')" style="padding:.25rem .6rem;font-size:.75rem">Ban</button>`:'—'}
    </td>`;
}

function _setCellText(row,cellName,value){
  const cell=row.querySelector(`[data-cell="${cellName}"]`);if(!cell)return;
  if(cellName==='port'){const span=cell.querySelector('.port-val');if(span)span.textContent=value;}
  else{if(cell.textContent!==value)cell.textContent=value;}
}
function _flashPortCell(row,newPort,changeCount,hist){
  const cell=row.querySelector('[data-cell="port"]');if(!cell)return;
  const span=cell.querySelector('.port-val');if(span)span.textContent=newPort;
  let badge=cell.querySelector('.port-change-badge');
  if(changeCount>0){
    if(!badge){badge=document.createElement('span');badge.className='port-change-badge';cell.appendChild(badge);}
    badge.textContent=`↻${changeCount}`;badge.title=`Port changed ${changeCount}×. History: ${hist.join(' → ')}`;
  }
  cell.classList.remove('port-flash');void cell.offsetWidth;cell.classList.add('port-flash');
  setTimeout(()=>cell.classList.remove('port-flash'),1800);
}

async function banUser(username=null){
  const un=username??document.getElementById('ban-username').value.trim();
  const reason=document.getElementById('ban-reason')?.value?.trim()??'';
  const hrs=+document.getElementById('ban-hours').value;
  const banIp=+document.getElementById('ban-ip')?.value??0;
  if(!un){toast('Enter a username to ban',false);return;}
  const d=await fetch('ajax_server.php',postData(makeForm({action:'ban_user',username:un,reason,hours:hrs,ban_ip:banIp}))).then(r=>r.json());
  if(d.success){toast(d.message);if(document.getElementById('ban-username'))document.getElementById('ban-username').value='';loadBans();loadStats();}
  else toast(d.error??'Ban failed',false);
}

function quickBan(username){
  switchTab('bans');
  document.getElementById('ban-username').value=username;
  document.getElementById('ban-username').focus();
}

async function unbanUser(username){
  if(!confirm(`Lift ban on "${username}"?`))return;
  const d=await fetch('ajax_server.php',postData(makeForm({action:'unban_user',username}))).then(r=>r.json());
  if(d.success){toast(d.message);loadBans();loadStats();}
  else toast(d.error??'Unban failed',false);
}

async function loadBans(){
  try{
    const d=await fetch('ajax_server.php?action=list_bans').then(r=>r.json());
    if(!d.success)return;
    const tbody=document.getElementById('bans-tbody');
    if(!d.bans.length){tbody.innerHTML='<tr><td colspan="8" style="padding:.8rem;color:var(--text-muted)">No bans on record</td></tr>';return;}
    tbody.innerHTML=d.bans.map(b=>{
      const statusBadge=b.active==1?'<span class="ban-badge ban-active">● Active</span>':'<span class="ban-badge ban-expired">Expired/Lifted</span>';
      const ipBadge=b.ip?`<span class="ban-ip-badge">${escHtml(b.ip)}</span>`:'<span style="color:var(--text-muted);font-size:.8rem">—</span>';
      const expires=b.expires_at?b.expires_at:'<span style="color:#ff6b6b;font-size:.8rem">Permanent</span>';
      const actions=b.active==1?`<button class="btn-success" onclick="unbanUser('${escHtml(b.username??'')}')">Unban</button>`:'—';
      return`<tr>
        <td style="font-weight:600">${escHtml(b.username??'—')}</td>
        <td style="color:var(--text-muted);font-size:.82rem">${escHtml(b.reason??'—')}</td>
        <td>${ipBadge}</td>
        <td style="color:var(--text-muted);font-size:.82rem">${escHtml(b.banned_by)}</td>
        <td style="color:var(--text-muted);font-size:.8rem">${b.banned_at}</td>
        <td style="font-size:.82rem">${expires}</td>
        <td>${statusBadge}</td>
        <td>${actions}</td>
      </tr>`;
    }).join('');
  }catch(e){}
}

async function loadAudit(){
  const filter=document.getElementById('audit-filter')?.value??'';
  try{
    const d=await fetch(`ajax_server.php?action=get_audit_log&limit=200&action_filter=${encodeURIComponent(filter)}`).then(r=>r.json());
    if(!d.success)return;
    const viewer=document.getElementById('audit-viewer');
    if(!d.entries.length){viewer.innerHTML='<div style="color:#555;padding:.5rem">No audit entries yet</div>';return;}
    viewer.innerHTML=d.entries.map(e=>{
      const ts=new Date(e.ts).toLocaleString();
      const cls='audit-action action-'+(e.action??'');
      const targets=[e.target_user&&`user: ${e.target_user}`,e.target_room&&`room: ${e.target_room}`].filter(Boolean).join(' · ');
      const detail=[targets,e.detail].filter(Boolean).join(' — ');
      return`<div class="audit-row">
        <span class="audit-ts">${ts}</span>
        <span class="${cls}">${escHtml(e.action)}</span>
        <span class="audit-detail">
          <span style="color:var(--text);font-weight:600">${escHtml(e.actor)}</span>
          ${detail?' · '+escHtml(detail):''}
          ${e.ip?`<span style="color:#555;font-size:.72rem"> [${escHtml(e.ip)}]</span>`:''}
        </span>
      </div>`;
    }).join('');
  }catch(e){}
}

async function loadPackets(){
  try{
    const d=await fetch('ajax_server.php?action=packet_log&limit=100').then(r=>r.json());
    if(!d.success)return;
    packetViewer.innerHTML='';
    d.packets.reverse().forEach(p=>{
      const el=document.createElement('div');
      el.className=`packet packet-${p.direction==='in'?'in':'out'}`;
      const ts=new Date(p.ts).toLocaleTimeString();
      const pay=p.payload?p.payload.substring(0,80)+(p.payload.length>80?'…':''):'';
      el.innerHTML=`<span class="packet-ts">${ts}</span><span class="packet-type">[${p.direction.toUpperCase()}]</span><span class="packet-type">${p.type}</span>${p.username?`<span style="color:#f093fb">${escHtml(p.username)}</span> `:''}<span class="packet-payload">${escHtml(pay)}</span>`;
      packetViewer.appendChild(el);
    });
    if(document.getElementById('auto-scroll')?.checked)packetViewer.scrollTop=packetViewer.scrollHeight;
  }catch(e){}
}
function clearViewer(){packetViewer.innerHTML='<div style="color:#555">Cleared</div>';}

function probeTransport(){
  const modeEl=document.getElementById('transport-mode');
  let t=setTimeout(()=>modeEl.innerHTML='<span class="status-dot status-online"></span>HTTP Polling',3000);
  try{
    const ws=new WebSocket('ws://'+WS_HOST+':'+WS_PORT_NUM);
    ws.onopen=()=>{clearTimeout(t);modeEl.innerHTML='<span class="status-dot status-online"></span>WebSocket — Real-time';ws.close();};
    ws.onerror=()=>{clearTimeout(t);modeEl.innerHTML='<span class="status-dot status-online"></span>HTTP Polling — Live';};
  }catch(e){clearTimeout(t);modeEl.innerHTML='<span class="status-dot status-online"></span>HTTP Polling — Live';}
}

loadStats();loadPackets();loadBandwidth();probeTransport();
setInterval(loadStats,3000);
setInterval(loadPackets,5000);
setInterval(loadBandwidth,15000);
setInterval(()=>{
  document.querySelectorAll('[data-cell="ago"]').forEach(cell=>{
    const un=cell.closest('tr')?.id?.replace('urow-','');
    const data=lastUserData[un];
    if(data?.ts)cell.textContent=timeSince(data.ts);
  });
},1000);
</script>
<?php include __DIR__ . '/_admin_bar.php'; ?>
<script>
(function () {
  const syncKey = 'nexuschat_avatar_sync';
  const channelName = 'nexuschat-avatar-sync';
  const me = {
    username: <?= json_encode($user['username']) ?>,
    color: <?= json_encode($user['avatar_color']) ?>,
  };

  function renderAvatar(target, avatarUrl) {
    if (!target) return;
    if (avatarUrl) {
      target.innerHTML = `<img src="${avatarUrl}?t=${Date.now()}" class="avatar avatar-img" alt="${me.username}" style="width:32px;height:32px;border-radius:9px">`;
    } else {
      target.innerHTML = `<div class="avatar" style="background:${me.color};width:32px;height:32px;border-radius:9px;font-size:.75rem">${me.username.slice(0,2).toUpperCase()}</div>`;
    }
  }

  function applySync(payload) {
    if (!payload || payload.username !== me.username) return;
    const avatarUrl = payload.avatar_url || null;
    renderAvatar(document.getElementById('dashboard-user-avatar'), avatarUrl);
    renderAvatar(document.getElementById('admin-bar-avatar'), avatarUrl);
  }

  window.addEventListener('storage', e => {
    if (e.key !== syncKey || !e.newValue) return;
    try { applySync(JSON.parse(e.newValue)); } catch (err) {}
  });

  if (typeof BroadcastChannel !== 'undefined') {
    try {
      const bc = new BroadcastChannel(channelName);
      bc.onmessage = e => applySync(e.data);
    } catch (err) {}
  }
})();
</script>
</body>
</html>
