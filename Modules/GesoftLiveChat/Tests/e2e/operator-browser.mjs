// End-to-end checks of what the agent sees, in a real browser.
//
//   google-chrome --headless=new --remote-debugging-port=9222 --user-data-dir=/tmp/glc-e2e about:blank &
//   GLC_BASE=http://freescout-test.local \
//   GLC_SQL="ssh test-vm 'cd /var/www/freescout && sudo -n mysql -N freescout'" \
//   GLC_AGENT_EMAIL=... GLC_AGENT_PASSWORD=... \
//   node Modules/GesoftLiveChat/Tests/e2e/operator-browser.mjs
//
// Test instance only: it signs in as an agent, opens real chats through the
// visitor endpoints and closes them afterwards.
//
// These are the checks that a passing endpoint cannot stand in for. The chat
// count once answered curl perfectly while the interface showed nothing,
// because a script threw before it painted anything.

import { execSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const BASE = process.env.GLC_BASE.replace(/\/$/, '');
const CDP = process.env.GLC_CDP || 'http://127.0.0.1:9222';
const SQL = process.env.GLC_SQL;
const RUN = Math.random().toString(16).slice(2, 8);
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sql = (q) => execSync(SQL, { input: q, encoding: 'utf8' }).trim().split('\n').filter(Boolean).map((l) => l.split('\t'));
const one = (q) => (sql(q)[0] || [])[0];

let pass = 0, fail = 0;
const opened = [];
function check(label, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${label.padEnd(66)} ${ok ? JSON.stringify(got) : `got: ${JSON.stringify(got)}  wanted: ${JSON.stringify(want)}`}`);
}

// ------------------------------------------------------------ visitor side

async function visitor(name, message) {
  const r = await fetch(`${BASE}/gesoft-live-chat/start`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, email: `e2e-op-${RUN}-${opened.length}@gesoft.test`, message }),
  });
  const b = await r.json();
  if (!b.token) throw new Error('start failed: ' + JSON.stringify(b));
  const hash = createHash('sha256').update(b.token).digest('hex');
  const conv = one(`select conversation_id from gesoft_live_chat_sessions where token_hash='${hash}'`);
  opened.push(conv);
  return { token: b.token, conv };
}
const say = (v, message) => fetch(`${BASE}/gesoft-live-chat/send`, {
  method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: v.token, message }),
});
const pollOnce = (v) => fetch(`${BASE}/gesoft-live-chat/poll?token=${v.token}&since=0`);
const endChat = (v) => fetch(`${BASE}/gesoft-live-chat/end`, {
  method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: v.token }),
});

// ------------------------------------------------------------ CDP plumbing

const version = await (await fetch(CDP + '/json/version')).json();
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((ok, no) => { ws.onopen = ok; ws.onerror = no; });
let seq = 0;
const pending = new Map();
const moduleErrors = [];
ws.onmessage = (m) => {
  const msg = JSON.parse(m.data);
  if (msg.id && pending.has(msg.id)) {
    const { ok, no } = pending.get(msg.id);
    pending.delete(msg.id);
    msg.error ? no(new Error(JSON.stringify(msg.error))) : ok(msg.result);
  } else if (msg.method === 'Runtime.exceptionThrown') {
    // Only errors from this module's scripts. FreeScout's own pages are not
    // what is under test here.
    const d = msg.params.exceptionDetails;
    const where = (d.url || '') + ' ' + ((d.stackTrace && JSON.stringify(d.stackTrace)) || '');
    if (/gesoftlivechat/.test(where)) moduleErrors.push((d.exception && d.exception.description) || d.text);
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
await S('Network.enable');
await S('Network.setUserAgentOverride', { userAgent: UA });
await S('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });

const ev = async (expression) => {
  const r = await S('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
  if (r.exceptionDetails) throw new Error((r.exceptionDetails.exception && r.exceptionDetails.exception.description) || 'evaluate failed');
  return r.result.value;
};
async function waitFor(expr, ms = 15000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    try { if (await ev(expr)) return true; } catch (e) { /* navigating */ }
    await sleep(300);
  }
  return false;
}
async function open(path) {
  await S('Page.navigate', { url: BASE + path });
  await sleep(500);
  await waitFor(`document.readyState === 'complete'`, 20000);
}

console.log('GesoftLiveChat — the agent side in a browser\n');

// ------------------------------------------------------------------ sign in
await open('/login');
await ev(`(() => {
  document.querySelector('input[name=email]').value = ${JSON.stringify(process.env.GLC_AGENT_EMAIL)};
  document.querySelector('input[name=password]').value = ${JSON.stringify(process.env.GLC_AGENT_PASSWORD)};
  document.querySelector('input[name=password]').form.submit();
  return true;
})()`);
await sleep(1500);
await waitFor(`!location.pathname.startsWith('/login')`, 20000);
check('signed in as the agent', await ev(`!location.pathname.startsWith('/login')`), true);

// --------------------------------------------------- presence in the chat list
const here = await visitor(`E2E prezent ${RUN}`, `Sunt aici ${RUN}`);
await pollOnce(here);
const ended = await visitor(`E2E plecat ${RUN}`, `Plec imediat ${RUN}`);
await endChat(ended);

await open(`/conversation/${ended.conv}?chat_mode=1`);
check('a visitor who is here gets the "here" mark in the chat list',
  await waitFor(`!!document.querySelector('.chats li.chat-item[data-chat_id="${here.conv}"].gesoft-presence-here')`), true);
check('a visitor who ended the chat gets the "ended" mark',
  await waitFor(`!!document.querySelector('.chats li.chat-item[data-chat_id="${ended.conv}"].gesoft-presence-ended')`), true);
check('  and the mark is explained in words on hover',
  await ev(`(document.querySelector('.chats li.chat-item[data-chat_id="${ended.conv}"] .folder-name') || {}).title || ''`),
  'Clientul a încheiat chatul');

const lineText = await ev(`[...document.querySelectorAll('.thread-type-lineitem .thread-title')].map(e => e.innerText.trim()).join(' | ')`);
check('the conversation shows the "ended the chat" line', /ended the chat/.test(lineText), true);
check('  signed with the customer, not "System"', lineText.includes(`E2E plecat ${RUN}`) && !/System ended/.test(lineText), true);

// -------------------------------------------- the alert opens the chat it names
const bellBefore = one(`select count(*) from notifications`);
await open('/');
await sleep(4000);  // the first answer only records what is already there
await say(here, `Mesaj nou pentru alertă ${RUN}`);
const alerted = await waitFor(`!!document.querySelector('.alert-floating.gesoft-chat-alert')`, 25000);
check('a customer message raises the in-page alert on another page', alerted, true);
check('  naming the customer',
  alerted ? await ev(`document.querySelector('.alert-floating.gesoft-chat-alert').innerText.includes('E2E prezent ${RUN}')`) : false, true);
if (alerted) {
  await ev(`document.querySelector('.alert-floating.gesoft-chat-alert').click(); true`);
  await waitFor(`location.pathname === '/conversation/${here.conv}'`, 15000);
}
check('  and clicking it opens that chat', await ev(`location.pathname`), `/conversation/${here.conv}`);

// ------------------------------------------ no alert for the chat on screen
await sleep(4000);
await ev(`document.querySelectorAll('.alert-floating').forEach(e => e.remove()); true`);
await say(here, `Mesaj în chatul deschis ${RUN}`);
const alertedAgain = await waitFor(`!!document.querySelector('.alert-floating.gesoft-chat-alert')`, 15000);
check('no alert for a message in the chat the agent is reading', alertedAgain, false);

// ------------------------------------------------------------------- the bell
await sleep(20000);  // notifications go through the queue
check('customer chat messages added nothing under the bell', one(`select count(*) from notifications`), bellBefore);

check('no script errors from the module', moduleErrors, []);

for (const conv of new Set(opened)) sql(`update conversations set status=3, closed_at=now() where id=${conv}`);
await send('Target.closeTarget', { targetId });

console.log(`\n${pass} passed, ${fail} failed  (run ${RUN})`);
process.exit(fail ? 1 : 0);
