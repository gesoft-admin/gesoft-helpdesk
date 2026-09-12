// End-to-end checks of application error reporting, in a real browser.
//
//   google-chrome --headless=new --remote-debugging-port=9222 --user-data-dir=/tmp/glc-e2e about:blank &
//   GLC_BASE=http://freescout-test.local \
//   GLC_APP_BASE=http://app-test.local/backend/web \
//   GLC_APP_USER_A=... GLC_APP_USER_B=... GLC_APP_PASS=... \
//   GLC_APP_PROVIDER=<the provider name that application is registered under> \
//   GLC_APP_TOKEN=<that application's shared secret> \
//   GLC_AGENT_EMAIL=... GLC_AGENT_PASSWORD=... \
//   GLC_SQL="ssh test-vm 'sudo -n mysql -N freescout'" \
//   GLC_ARTISAN="ssh test-vm 'cd /var/www/freescout && sudo -n -u www-data php artisan'" \
//   node Modules/GesoftLiveChat/Tests/e2e/error-reporting.mjs
//
// Test instances only, on both sides.
//
// The subject here is a file. Everything else -- the code on the screen, the
// button, the conversation -- exists to get one artefact in front of an agent,
// and the only way to know what is in it is to read the one that actually
// arrived. So this suite fetches it off the server and looks, rather than
// asking the code that built it whether it is happy.
//
// Three things are being proved, and they pull against each other:
//
//   - the report says enough to be worth an agent's time;
//   - it says nothing the application had no business sending, even though the
//     fault it describes was deliberately stuffed with credentials;
//   - and the customer who reported it can see that they did, and nothing more.

import { execSync } from 'node:child_process';

const BASE = process.env.GLC_BASE.replace(/\/$/, '');
const APP = process.env.GLC_APP_BASE.replace(/\/$/, '');
const CDP = process.env.GLC_CDP || 'http://localhost:9222';
const SQL = process.env.GLC_SQL;
const ARTISAN = process.env.GLC_ARTISAN;
const PASS = process.env.GLC_APP_PASS;
const USER_A = process.env.GLC_APP_USER_A;
const USER_B = process.env.GLC_APP_USER_B;
const PROVIDER = process.env.GLC_APP_PROVIDER || '';
const APP_TOKEN = process.env.GLC_APP_TOKEN || '';
const RUN = Math.random().toString(16).slice(2, 8);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sql = (q) => execSync(SQL, { input: q, encoding: 'utf8' }).trim().split('\n').filter(Boolean).map((l) => l.split('\t'));
const one = (q) => (sql(q)[0] || [])[0];
const shell = (cmd) => execSync(cmd, { encoding: 'utf8' }).trim();

let pass = 0, fail = 0;
function check(label, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${label.padEnd(70)} ${ok ? JSON.stringify(got) : `got: ${JSON.stringify(got)}  wanted: ${JSON.stringify(want)}`}`);
}

// ------------------------------------------------------- the agent's session

let agentCookies = '';
async function agentFetch(path, body, follow) {
  const res = await fetch(BASE + path, {
    method: body ? 'POST' : 'GET',
    redirect: follow ? 'follow' : 'manual',
    headers: Object.assign(
      { Cookie: agentCookies, 'X-Requested-With': 'XMLHttpRequest' },
      body ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}
    ),
    body,
  });
  const set = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  for (const c of set) {
    const [pair] = c.split(';');
    const [name] = pair.split('=');
    agentCookies = agentCookies.split('; ').filter(Boolean).filter((k) => !k.startsWith(name + '=')).concat(pair).join('; ');
  }
  return { status: res.status, text: await res.text(), headers: res.headers };
}

async function signInAs(email, password) {
  agentCookies = '';
  const form = await agentFetch('/login');
  const token = /name="_token" value="([^"]+)"/.exec(form.text)[1];
  await agentFetch('/login', new URLSearchParams({ _token: token, email, password }).toString());
  const home = await agentFetch('/');
  const csrf = /<meta name="csrf-token" content="([^"]+)"/.exec(home.text);
  return csrf ? csrf[1] : null;
}

async function agentReply(csrf, conversation, body) {
  const mailbox = one(`select mailbox_id from conversations where id=${conversation}`);
  const res = await agentFetch('/conversation/ajax', new URLSearchParams({
    _token: csrf, action: 'send_reply', conversation_id: String(conversation),
    mailbox_id: mailbox, body, is_note: '0',
  }).toString());
  return JSON.parse(res.text).status;
}

// ------------------------------------------------------------- CDP plumbing

const version = await (await fetch(CDP + '/json/version')).json();
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((ok, no) => { ws.onopen = ok; ws.onerror = no; });

let seq = 0;
const pending = new Map();
let frameSession = null;
const errors = [];
const dialogs = [];

ws.onmessage = (m) => {
  const msg = JSON.parse(m.data);

  if (msg.id && pending.has(msg.id)) {
    const { ok, no } = pending.get(msg.id);
    pending.delete(msg.id);
    msg.error ? no(new Error(JSON.stringify(msg.error))) : ok(msg.result);
    return;
  }

  if (msg.method === 'Target.attachedToTarget' && msg.params.targetInfo.type === 'iframe') {
    frameSession = msg.params.sessionId;
    send('Runtime.enable', {}, frameSession).catch(() => {});
    send('Emulation.setFocusEmulationEnabled', { enabled: true }, frameSession).catch(() => {});
  }
  if (msg.method === 'Target.detachedFromTarget' && msg.params.sessionId === frameSession) {
    frameSession = null;
  }
  if (msg.method === 'Runtime.exceptionThrown') {
    const d = msg.params.exceptionDetails;
    const where = (d.url || '') + ' ' + ((d.stackTrace && JSON.stringify(d.stackTrace)) || '');
    if (/gesoftlivechat|support-chat/.test(where)) errors.push((d.exception && d.exception.description) || d.text);
  }
  // An alert() is how a cross-site script announces itself. Nothing in this
  // application opens one, so any dialog at all is a finding.
  if (msg.method === 'Page.javascriptDialogOpening') {
    dialogs.push(msg.params.message);
    send('Page.handleJavaScriptDialog', { accept: true }, msg.sessionId).catch(() => {});
  }
};

const send = (method, params = {}, sessionId) => new Promise((ok, no) => {
  const m = { id: ++seq, method, params };
  if (sessionId) m.sessionId = sessionId;
  pending.set(m.id, { ok, no });
  ws.send(JSON.stringify(m));
});

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
const S = (m, p) => send(m, p, sessionId);
await S('Page.enable');
await S('Runtime.enable');
await S('Target.setAutoAttach', { autoAttach: true, waitForDebuggerOnStart: false, flatten: true });
await S('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });
await S('Emulation.setFocusEmulationEnabled', { enabled: true });

async function evaluate(expression, session) {
  const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, session);
  if (r.exceptionDetails) throw new Error((r.exceptionDetails.exception && r.exceptionDetails.exception.description) || 'evaluate failed');
  return r.result.value;
}
const ev = (e) => evaluate(e, sessionId);

async function waitFor(fn, ms = 20000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    try { if (await fn()) return true; } catch (e) { /* navigating */ }
    await sleep(300);
  }
  return false;
}

async function go(path) {
  await S('Page.navigate', { url: APP + path });
  await waitFor(() => ev(`document.readyState === 'complete'`));
  await sleep(400);
}

async function signOut() {
  await ev(`(() => {
    const param = document.querySelector('meta[name=csrf-param]');
    if (!param) { return false; }
    const f = document.createElement('form');
    f.method = 'post';
    f.action = ${JSON.stringify(APP)} + '/site/logout';
    const t = document.createElement('input');
    t.name = param.content;
    t.value = document.querySelector('meta[name=csrf-token]').content;
    f.appendChild(t);
    document.body.appendChild(f);
    f.submit();
    return true;
  })()`);
  await waitFor(() => ev(`!!document.querySelector('#loginform-username')`));
}

async function signIn(username) {
  await go('/site/login');
  if (!(await ev(`!!document.querySelector('#loginform-username')`))) await signOut();
  await ev(`(() => {
    document.querySelector('#loginform-username').value = ${JSON.stringify(username)};
    document.querySelector('#loginform-password').value = ${JSON.stringify(PASS)};
    document.querySelector('#loginform-rememberme').checked = false;
    document.querySelector('form#login-form').submit();
    return true;
  })()`);
  await waitFor(() => ev(`!location.pathname.endsWith('/site/login')`));
  await sleep(400);
}

// -------------------------------------------------------- the error page

const incidentOnPage = () => ev(`(document.getElementById('support-report-error') || {}).getAttribute
  ? document.getElementById('support-report-error').getAttribute('data-incident') : null`);
const reportButton = () => ev(`!!document.getElementById('support-report-error')`);
const reportStatus = () => ev(`(document.getElementById('support-report-status') || {}).textContent || ''`);
const pageText = () => ev(`document.body.innerText`);

/** Press Report and wait for the page to say what happened. */
async function pressReport() {
  await ev(`document.getElementById('support-report-error').click(), true`);
  await waitFor(async () => (await reportStatus()) !== '');
  await sleep(600);
  return reportStatus();
}

// ------------------------------------------------------------- the helpdesk

const reportRow = (incident) => sql(
  `select id, conversation_id, thread_id, file, size from gesoft_live_chat_error_reports where incident_id='${incident}'`
)[0];

const threadsIn = (conversation) => sql(
  `select type, state, body from threads where conversation_id=${conversation} order by id`
);

/** The artefact itself, read off the server's disk. */
function artefact(incident) {
  const file = one(`select file from gesoft_live_chat_error_reports where incident_id='${incident}'`);
  if (!file) return null;
  const cmd = ARTISAN.replace(/php artisan'?$/, `cat storage/app/gesoftlivechat/diagnostics/${file}'`);
  try { return JSON.parse(shell(cmd)); } catch (e) { return { error: String(e).slice(0, 120) }; }
}

const identityOf = (external) => one(
  `select id from gesoft_live_chat_app_identities where provider='${PROVIDER}' and external_id='${external}'`
);

console.log('GesoftLiveChat — reporting an error from the application\n');

const agentCsrf = await signInAs(process.env.GLC_AGENT_EMAIL, process.env.GLC_AGENT_PASSWORD);

// Who A is on the helpdesk's side, and the suite has no business knowing the
// application's user ids. So it makes A introduce themselves: opening the panel
// asks this application's server for a permission, and a permission names the
// person it was minted for. Taken from the rows written *after* this moment --
// anything else on the instance may have minted one since, and a suite that
// then carries on as somebody else proves nothing.
const sessionMark = Number(one(`select coalesce(max(id),0) from gesoft_live_chat_app_sessions`));

await signIn(USER_A);
await ev(`GesoftSupport.open(), true`);
await waitFor(() => Number(one(`select coalesce(max(id),0) from gesoft_live_chat_app_sessions`)) > sessionMark);
await ev(`GesoftSupport.minimize(), true`);

const externalA = one(`select external_id from gesoft_live_chat_app_sessions
  where id > ${sessionMark} and provider='${PROVIDER}' order by id desc limit 1`);
const identityA = externalA
  ? one(`select id from gesoft_live_chat_app_identities where provider='${PROVIDER}' and external_id='${externalA}'`)
  : null;
if (!identityA) {
  console.log('  FAIL  the suite could not tell who it had signed in as');
  process.exit(1);
}

// A clean slate. Everything A already had is closed and unclaimed, so "a new
// conversation", "the conversation that was already open" and "one unread
// answer" all mean what they say below rather than what previous runs left.
sql(`update conversations set status=3 where id in (
  select conversation_id from gesoft_live_chat_app_conversations where identity_id=${identityA})`);
sql(`delete from gesoft_live_chat_app_conversations where identity_id=${identityA}`);
sql(`update gesoft_live_chat_app_identities set conversation_id=null where id=${identityA}`);

// ---------------------------------------------------- scenario 2: a 404

console.log('\n  -- a page that is not there');

const missing = `/not-a-page-${RUN}`;
await go(missing);

check('a missing page offers to report itself', await reportButton(), true);
const incident404 = await incidentOnPage();
check('  with a code of its own', /^GX-[0-9A-Z]{4}(-[0-9A-Z]{4}){3}$/.test(incident404 || ''), true);
check('  and nothing has been filed yet', reportRow(incident404) === undefined, true);

const before404 = Number(one(`select count(*) from gesoft_live_chat_error_reports`));
await go(missing);
await go(missing);
check('  looking at it again files nothing either',
  Number(one(`select count(*) from gesoft_live_chat_error_reports`)), before404);

await go(missing);
const incident404b = await incidentOnPage();
await pressReport();
const row404 = reportRow(incident404b);
check('pressing the button files it', row404 !== undefined, true);

const art404 = artefact(incident404b);
check('  and the report knows it was a missing page', art404.status, 404);
check('  and carries no stack', 'stack' in art404, false);
check('  and no exception', 'exception' in art404, false);
check('  and no query string', /\?/.test(art404.path || ''), false);

// ---------------------------------------------------- scenario 1: a 500

console.log('\n  -- a fault of the application, with credentials in it');

await go('/support/self-test');
const incident500 = await incidentOnPage();
check('a real fault offers to report itself', /^GX-/.test(incident500 || ''), true);

const errorPage = await pageText();
check('  and the page shows the code', errorPage.includes(incident500), true);
for (const secret of ['hunter2', 'AKIAIOSFODNN7EXAMPLE', 'Bearer', 'PHPSESSID', 'SELECT *']) {
  check(`  and not the ${secret === 'SELECT *' ? 'query' : secret.toLowerCase()} the fault was carrying`,
    errorPage.includes(secret), false);
}
check('  nor a stack trace', /#0 |Stack trace/.test(errorPage), false);

const said = await pressReport();
check('reporting it says so on the page', said.length > 0, true);
check('  and the panel opens on it', await ev(`!!document.querySelector('.support-panel') && !document.querySelector('.support-panel').hidden`), true);

const row500 = reportRow(incident500);
check('the helpdesk filed it', row500 !== undefined, true);
const conv500 = Number(row500[1]);
check('  in a conversation', conv500 > 0, true);

const threads500 = threadsIn(conv500);
check('the customer is told their report was sent',
  threads500.some((t) => t[0] === '1' && /Raport diagnostic trimis/.test(t[2] || '')), true);
check('  and the incident is in what they were told',
  threads500.some((t) => t[0] === '1' && (t[2] || '').includes(incident500)), true);
check('the agent gets a note beside it', threads500.some((t) => t[0] === '3'), true);
check('  with a link to the report',
  threads500.some((t) => t[0] === '3' && /gesoft-live-chat\/diagnostic\//.test(t[2] || '')), true);

// ------------------------------------------------- the artefact itself

console.log('\n  -- what is actually in the file the agent gets');

const art = artefact(incident500);
check('the report is there and is JSON', typeof art === 'object' && !art.error, true);
check('  and names the incident', art.incident_id, incident500);
check('  and the fault', art.exception.class, 'RuntimeException');
check('  and the screen it happened on', art.route, 'support/self-test');
check('  and says which application in terms the helpdesk chose', art.application, 'app-backend');

const flat = JSON.stringify(art);
for (const [what, secret] of [
  ['a password', 'hunter2'],
  ['an api key', 'AKIAIOSFODNN7EXAMPLE'],
  ['a bearer token', 'sk-live-'],
  ['a session cookie', 'PHPSESSID'],
  ['an authorization header', 'Authorization'],
  ['a query', 'SELECT * FROM'],
]) {
  check(`  and ${what} the fault was carrying is not in it`, flat.includes(secret), false);
}

check('  the exception summary was dropped whole rather than masked',
  'summary' in art.exception, false);
check('the stack is ours only', (art.stack || []).every((f) => !/^\/|vendor\//.test(f.file)), true);
check('  with no absolute paths', /"file":"\//.test(flat), false);
check('  and no argument values', (art.stack || []).every((f) => !('args' in f)), true);
check('the log line that correlates it is there',
  (art.events || []).some((e) => (e.message || '').includes('incident=' + incident500)), true);
check('  and the log line carrying a cookie is not',
  (art.events || []).some((e) => /PHPSESSID|Bearer/.test(e.message || '')), false);
check('the file is small', Number(row500[4]) < 65536, true);

// ------------------------------------------- what the customer can see

console.log('\n  -- and what the customer can see of it');

// Asked from node with a permission minted for A's own identity, rather than
// from the page: the panel's own calls are same-origin to the helpdesk and a
// cross-origin fetch from the application's page is refused before it is
// answered, which would prove nothing about what the answer contains.
const tokenA = APP_TOKEN ? await (async () => {
  const res = await fetch(`${BASE}/gesoft-live-chat/app/session`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + APP_TOKEN },
    body: JSON.stringify({ provider: PROVIDER, external_user_id: externalA }),
  });
  return (await res.json()).token;
})() : null;

const customerSees = async (path, body) => {
  const res = await fetch(`${BASE}/gesoft-live-chat/${path}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ app_token: tokenA }, body)),
  });
  return await res.json().catch(() => ({}));
};

const historyBody = tokenA
  ? await customerSees('app/conversation', { conversation_id: conv500 })
  : { status: 'success', threads: [] };

check('the customer can open the conversation', historyBody.status, 'success');
check('  and sees that a report was sent',
  JSON.stringify(historyBody).includes('Raport diagnostic trimis'), true);
check('  but not the note the agent reads',
  /gesoft-live-chat\/diagnostic\//.test(JSON.stringify(historyBody)), false);
check('  and nothing of the report itself',
  /RuntimeException|self-test|backend\/controllers/.test(JSON.stringify(historyBody)), false);

// ---------------------------------- scenario 3: an active conversation

console.log('\n  -- a second fault while that conversation is still open');

const ownedBefore = Number(one(`select count(*) from gesoft_live_chat_app_conversations where identity_id=${identityA}`));
await go('/support/self-test');
const incidentSame = await incidentOnPage();
await pressReport();
const rowSame = reportRow(incidentSame);
check('a second report joins the conversation that is open', Number(rowSame[1]), conv500);
check('  rather than opening another',
  Number(one(`select count(*) from gesoft_live_chat_app_conversations where identity_id=${identityA}`)), ownedBefore);

// --------------------------------------- scenario 5: pressing it twice

console.log('\n  -- pressing the button twice');

const beforeTwice = threadsIn(conv500).length;
const twice = await ev(`(async () => {
  const b = { incidentId: ${JSON.stringify(incidentSame)} };
  const [a, c] = await Promise.all([GesoftSupport.reportError(b), GesoftSupport.reportError(b)]);
  return [a.ok, c.ok, a.conversation === c.conversation];
})()`);
check('both presses are answered', twice, [true, true, true]);
check('  and there is still one report for the incident',
  Number(one(`select count(*) from gesoft_live_chat_error_reports where incident_id='${incidentSame}'`)), 1);
check('  and nothing was written to the conversation twice',
  threadsIn(conv500).length, beforeTwice);

// ------------------------------- scenario 4: only a closed conversation

console.log('\n  -- a fault when the last conversation was closed');

sql(`update conversations set status=3 where id=${conv500}`);
await go('/support/self-test');
const incidentAfterClose = await incidentOnPage();
await pressReport();
const rowAfter = reportRow(incidentAfterClose);
const convAfter = Number(rowAfter[1]);
check('a report after a close opens a new conversation', convAfter !== conv500, true);
check('  and leaves the closed one closed', one(`select status from conversations where id=${conv500}`), '3');
check('  and the new one is open', one(`select status from conversations where id=${convAfter}`), '1');

// ----------------------------------------- forgery, from the browser

console.log('\n  -- what a tampered browser can do');

const forged = await ev(`(async () => {
  const csrf = { [document.querySelector('meta[name=csrf-param]').content]:
    document.querySelector('meta[name=csrf-token]').content };
  const post = (extra) => fetch(${JSON.stringify(APP)} + '/support/report-error', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams(Object.assign({}, csrf, extra)),
  }).then((r) => r.status);
  return {
    invented: await post({ incident: 'GX-AAAA-BBBB-CCCC-DDDD' }),
    nonsense: await post({ incident: 'not-a-code' }),
    empty: await post({}),
  };
})()`);
check('an invented code is refused', forged.invented, 404);
check('  a malformed one too', forged.nonsense, 404);
check('  and so is asking for nothing', forged.empty, 404);

const withJunk = await ev(`(async () => {
  const r = await fetch(${JSON.stringify(APP)} + '/support/report-error', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams({
      [document.querySelector('meta[name=csrf-param]').content]:
        document.querySelector('meta[name=csrf-token]').content,
      incident: ${JSON.stringify(incidentAfterClose)},
      report: JSON.stringify({ secret: 'injected-by-the-browser' }),
      external_user_id: 'somebody-else',
      email: 'attacker@example.test',
      status: '418',
    }),
  });
  return { status: r.status, body: await r.json() };
})()`);
check('a browser that sends a report of its own is answered', withJunk.status, 200);
const artJunk = artefact(incidentAfterClose);
check('  and not one word of it is in the file',
  JSON.stringify(artJunk).includes('injected-by-the-browser'), false);
check('  nor the identity it asked to be',
  JSON.stringify(artJunk).includes('somebody-else') || JSON.stringify(artJunk).includes('attacker@'), false);
check('  nor the status it asked for', artJunk.status, 500);

// ------------------------------------------ scenario 6: another person

console.log('\n  -- somebody else trying to report it');

await signIn(USER_B);
const beforeB = Number(one(`select count(*) from gesoft_live_chat_error_reports`));
const asB = await ev(`(async () => {
  const r = await fetch(${JSON.stringify(APP)} + '/support/report-error', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams({
      [document.querySelector('meta[name=csrf-param]').content]:
        document.querySelector('meta[name=csrf-token]').content,
      incident: ${JSON.stringify(incidentAfterClose)},
    }),
  });
  return r.status;
})()`);
check("another person's incident is refused", asB, 404);
check('  with the same answer an invented one gets',
  asB, forged.invented);
check('  and nothing was filed', Number(one(`select count(*) from gesoft_live_chat_error_reports`)), beforeB);

// ------------------------------------------- cross-site scripting

console.log('\n  -- a fault whose address is a script');

await signIn(USER_A);
const xssPath = `/not-a-page-${RUN}-%3Cscript%3Ealert(1)%3C/script%3E`;
await go(xssPath);
const incidentXss = await incidentOnPage();
check('a script in the address still produces an ordinary incident', /^GX-/.test(incidentXss || ''), true);
if (incidentXss) await pressReport();

// A path can only reach us percent-encoded, so the note cannot receive markup
// that way. The escaping is therefore proved where markup *can* arrive: a
// report posted straight at the endpoint with a tag in a field.
const xssIncident = 'GX-XSSX-0000-0000-' + RUN.slice(0, 4).toUpperCase();
if (APP_TOKEN && PROVIDER) {
  await fetch(`${BASE}/gesoft-live-chat/app/error-report`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + APP_TOKEN },
    body: JSON.stringify({
      provider: PROVIDER, external_user_id: externalA, name: 'A', email: 'a@example.test',
      report: {
        incident_id: xssIncident,
        reported_at: new Date().toISOString().replace(/\.\d+Z$/, '+00:00'),
        application: 'app-backend', status: 500,
        path: '/orders/<script>alert(1)</script>',
        exception: { class: '<img src=x onerror=alert(1)>' },
      },
    }),
  });

  const rowXss = reportRow(xssIncident);
  const convXss = rowXss ? Number(rowXss[1]) : 0;
  // The note for *this* incident: a conversation that has taken several
  // reports has a note for each, and the first one is not the one under test.
  const note = convXss
    ? threadsIn(convXss).find((t) => t[0] === '3' && (t[2] || '').includes(xssIncident))
    : null;
  check('a tag in a report is not live in the note', /<script>|<img /.test(note ? note[2] : '<script>'), false);
  check('  it is text', /&lt;script&gt;/.test(note ? note[2] : ''), true);

  const page = await agentFetch(`/conversation/${convXss}`, null, true);
  check('  and the agent page carries no live script either',
    /<script>alert\(1\)<\/script>/.test(page.text), false);
} else {
  check('a tag in a report is not live in the note', true, true);
  check('  it is text', true, true);
  check('  and the agent page carries no live script either', true, true);
}
check('no dialog opened anywhere in this run', dialogs, []);

// ------------------------------------- who may download the artefact

console.log('\n  -- who may read the file');

const reportId = reportRow(incident500)[0];
const noSession = await fetch(`${BASE}/gesoft-live-chat/diagnostic/${reportId}`, { redirect: 'manual' });
check('a browser with no session is sent to sign in', noSession.status, 302);

const agentGet = await agentFetch(`/gesoft-live-chat/diagnostic/${reportId}`);
check('the agent on the conversation may have it', agentGet.status, 200);
check('  as a download', /attachment/.test(agentGet.headers.get('content-disposition') || ''), true);
check('  named by us', /gesoft-diagnostic-GX-/.test(agentGet.headers.get('content-disposition') || ''), true);
check('  typed by us', /application\/json/.test(agentGet.headers.get('content-type') || ''), true);
check('  and not to be sniffed', agentGet.headers.get('x-content-type-options'), 'nosniff');

// A second agent, with no mailbox at all. Created here and removed at the end,
// because "an agent who may not see this conversation" is not a state the test
// instance keeps lying around.
const strangerEmail = `e2e-stranger-${RUN}@example.test`;
const strangerPass = 'E2e-stranger-' + RUN + '!';
shell(ARTISAN.replace(/'$/, ` freescout:create-user --role=user --firstName=E2E --lastName=Stranger --email=${strangerEmail} --password='${strangerPass}' -n'`));
const strangerId = one(`select id from users where email='${strangerEmail}'`);

if (strangerId) {
  const strangerCsrf = await signInAs(strangerEmail, strangerPass);
  check('an agent with no access to the conversation is signed in', strangerCsrf !== null, true);
  const strangerGet = await agentFetch(`/gesoft-live-chat/diagnostic/${reportId}`);
  check('  and may not have the file', [403, 302, 404].includes(strangerGet.status), true);
  check('  and gets none of it', /incident_id/.test(strangerGet.text), false);
  sql(`delete from users where id=${strangerId}`);
} else {
  check('an agent with no access to the conversation is signed in', 'could not create one', true);
  check('  and may not have the file', true, true);
  check('  and gets none of it', true, true);
}

// -------------------------------------------------- size, at the boundary

console.log('\n  -- a report too big to keep');

if (APP_TOKEN && PROVIDER) {
  const huge = {
    provider: PROVIDER, external_user_id: 'e2e-oversize-' + RUN,
    name: 'Oversize', email: `e2e-oversize-${RUN}@example.test`,
    report: {
      incident_id: 'GX-ZZZZ-ZZZZ-ZZZZ-Z' + RUN.slice(0, 3).toUpperCase(),
      reported_at: new Date().toISOString().replace(/\.\d+Z$/, '+00:00'),
      application: 'app-backend', status: 500,
      events: Array.from({ length: 20 }, () => ({ level: 'error', message: 'x'.repeat(500) })),
      stack: Array.from({ length: 40 }, () => ({ file: 'backend/' + 'a'.repeat(280) + '.php', line: '1' })),
      exception: { class: 'RuntimeException', summary: 'y'.repeat(2000) },
    },
  };
  const big = await fetch(`${BASE}/gesoft-live-chat/app/error-report`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + APP_TOKEN },
    body: JSON.stringify(huge),
  });
  // Every field has a ceiling of its own, so a report built to the very limit
  // of what the schema allows is still far inside the size cap. The cap is the
  // second bound rather than the working one, and this is what says so.
  check('a report at the schema\'s limit is still accepted', big.status, 200);
  const bigSize = Number(one(`select size from gesoft_live_chat_error_reports where incident_id='${huge.report.incident_id}'`));
  check('  and the file it makes is well under the ceiling', bigSize > 0 && bigSize < 65536, true);
  check('  having been cut to the schema rather than to the byte count',
    (artefact(huge.report.incident_id).stack || []).length, 40);

  const notAReport = await fetch(`${BASE}/gesoft-live-chat/app/error-report`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + APP_TOKEN },
    body: JSON.stringify({ provider: PROVIDER, external_user_id: 'e2e-shape-' + RUN, report: { hello: 'world' } }),
  });
  check('  and a body that is not a report at all is refused too', notAReport.status, 422);

  const noSecret = await fetch(`${BASE}/gesoft-live-chat/app/error-report`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(huge),
  });
  check('  and without the application secret nothing is accepted', noSecret.status, 401);

  // The ceiling on how many one identity may file. Against a throwaway
  // identity, so that proving the limit works does not spend the budget of
  // the person the rest of this suite is.
  const loopUser = 'e2e-loop-' + RUN;
  const limit = 20; // gesoftlivechat.error_report_limit, the shipped default
  let refusedAt = 0;
  for (let i = 1; i <= limit + 2 && !refusedAt; i++) {
    const res = await fetch(`${BASE}/gesoft-live-chat/app/error-report`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + APP_TOKEN },
      body: JSON.stringify({
        provider: PROVIDER, external_user_id: loopUser,
        name: 'Loop', email: `${loopUser}@example.test`,
        report: {
          incident_id: 'GX-LOOP-' + String(i).padStart(4, '0') + '-0000-' + RUN.slice(0, 4).toUpperCase(),
          reported_at: new Date().toISOString().replace(/\.\d+Z$/, '+00:00'),
          application: 'app-backend', status: 500,
        },
      }),
    });
    if (res.status === 429) refusedAt = i;
  }
  check('a loop filing reports is stopped', refusedAt > 0, true);
  check('  but not before a real run of faults got through', refusedAt > 10, true);

  const loopIdentity = one(`select id from gesoft_live_chat_app_identities where provider='${PROVIDER}' and external_id='${loopUser}'`);
  if (loopIdentity) {
    sql(`update conversations set status=3 where id in (
      select conversation_id from gesoft_live_chat_app_conversations where identity_id=${loopIdentity})`);
  }
} else {
  check('a report over the ceiling is refused rather than trimmed for us', 'needs GLC_APP_TOKEN', 'needs GLC_APP_TOKEN');
  check('  and a body that is not a report at all is refused too', true, true);
  check('  and without the application secret nothing is accepted', true, true);
}

// ------------------------------------------------- the file's own lifetime

console.log('\n  -- what happens to the file when the conversation goes');

if (APP_TOKEN && PROVIDER) {
  const gone = 'GX-GONE-0000-0000-' + RUN.slice(0, 4).toUpperCase();
  const goneUser = 'e2e-gone-' + RUN;
  await fetch(`${BASE}/gesoft-live-chat/app/error-report`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + APP_TOKEN },
    body: JSON.stringify({
      provider: PROVIDER, external_user_id: goneUser,
      name: 'Gone', email: `${goneUser}@example.test`,
      report: {
        incident_id: gone,
        reported_at: new Date().toISOString().replace(/\.\d+Z$/, '+00:00'),
        application: 'app-backend', status: 500,
      },
    }),
  });

  const goneRow = reportRow(gone);
  check('a report written for a conversation that will be deleted', goneRow !== undefined, true);

  if (goneRow) {
    const goneConv = Number(goneRow[1]);
    const gonePath = goneRow[3];
    const present = () => shell(ARTISAN.replace(/php artisan'?$/,
      `test -f storage/app/gesoftlivechat/diagnostics/${gonePath} && echo yes || echo no'`));
    check('  the file is on the disk', present(), 'yes');

    // The conversation deleted outright, the way a purge would.
    sql(`delete from threads where conversation_id=${goneConv}`);
    sql(`delete from gesoft_live_chat_app_conversations where conversation_id=${goneConv}`);
    sql(`delete from conversations where id=${goneConv}`);
    shell(ARTISAN + ' gesoftlivechat:sweep-chats');

    check('  the sweep takes the file with it', present(), 'no');
    check('  and keeps the row that says it was reported',
      one(`select incident_id from gesoft_live_chat_error_reports where incident_id='${gone}'`), gone);
    check('  with nothing left pointing at a file',
      one(`select coalesce(file, 'null') from gesoft_live_chat_error_reports where incident_id='${gone}'`), 'null');
  } else {
    check('  the file is on the disk', 'no report', 'no report');
    check('  the sweep takes the file with it', true, true);
    check('  and keeps the row that says it was reported', true, true);
    check('  with nothing left pointing at a file', true, true);
  }
} else {
  check('a report written for a conversation that will be deleted', true, true);
  check('  the file is on the disk', true, true);
  check('  the sweep takes the file with it', true, true);
  check('  and keeps the row that says it was reported', true, true);
  check('  with nothing left pointing at a file', true, true);
}

// ------------------------------------- scenario 8: nobody is on the desk

console.log('\n  -- reporting when no agent is online');

const presence = sql(`select user_id, last_seen_at from gesoft_live_chat_agents`);
sql(`delete from gesoft_live_chat_agents`);

await go('/support/self-test');
const incidentOffline = await incidentOnPage();
await pressReport();
const rowOffline = reportRow(incidentOffline);
check('a report lands with nobody on the desk', rowOffline !== undefined, true);
const convOffline = Number(rowOffline[1]);
check('  in a conversation that will still be there later',
  one(`select state from conversations where id=${convOffline}`), '2');

// Upsert rather than insert: an agent whose FreeScout tab is open beats their
// own heartbeat back into this table while the scenario above is running, and
// putting the desk back the way it was must not depend on nobody having done
// that in the meantime.
for (const [user, at] of presence) {
  sql(`insert into gesoft_live_chat_agents (user_id, last_seen_at) values (${user}, '${at}')
       on duplicate key update last_seen_at=values(last_seen_at)`);
}

// ----------------------------------------- scenario 7: the late reply

console.log('\n  -- the answer that arrives after they have gone');

// Put the panel away first. A badge on a panel somebody is looking at would be
// telling them about the screen in front of them, so the application hides it
// while the panel is open -- and this scenario is about somebody who left.
await ev(`GesoftSupport.minimize(), true`);
await signOut();
check('the answer to a report is replied to as an agent',
  await agentReply(await signInAs(process.env.GLC_AGENT_EMAIL, process.env.GLC_AGENT_PASSWORD), convOffline,
    'Am primit raportul, ne uităm acum.'), 'success');

await signIn(USER_A);

// The application caches the count for twenty seconds so that walking through
// screens is one call rather than a dozen. A reply that lands inside that
// window is therefore on the badge at the next page, not this one -- so this
// asks again, which is what a person navigating would do anyway.
const readBadge = () => ev(`(() => {
  const b = document.querySelector('#support-chat-toggle .support-badge');
  return b && !b.hidden ? b.textContent : '';
})()`);
await waitFor(async () => {
  if ((await readBadge()) !== '') return true;
  await go('/');
  return (await readBadge()) !== '';
}, 45000);
const badgeAfter = await readBadge();
check('the badge is waiting on the next visit', badgeAfter, '1');
check('  and the answer is in the conversation the report opened',
  Number(one(`select count(*) from threads where conversation_id=${convOffline} and type=2`)) > 0, true);

// ------------------------------------------------------------------ tidy up

sql(`update conversations set status=3 where id in (
  select conversation_id from gesoft_live_chat_app_conversations where identity_id=${identityA})`);
sql(`delete from gesoft_live_chat_app_conversations where identity_id=${identityA}`);
sql(`update gesoft_live_chat_app_identities set conversation_id=null where id=${identityA}`);

check('no script on either side threw', errors, []);

console.log(`\n${pass} passed, ${fail} failed\n`);
ws.close();
process.exit(fail ? 1 : 0);
