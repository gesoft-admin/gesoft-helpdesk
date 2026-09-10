// End-to-end checks of the visitor bubble in a real browser.
//
//   google-chrome --headless=new --remote-debugging-port=9222 --user-data-dir=/tmp/glc-e2e about:blank &
//   GLC_BASE=http://freescout-test.local \
//   GLC_SQL="ssh test-vm 'cd /var/www/freescout && sudo -n mysql -N freescout'" \
//   node Modules/GesoftLiveChat/Tests/e2e/widget-browser.mjs
//
// Drives Chrome over the DevTools Protocol with no packages: Node 24 has a
// global WebSocket. Test instance only — it opens and ends real conversations
// and closes them afterwards.
//
// What only a browser can show: which storage the token lives in, that a
// reload keeps the conversation and a new tab does not, that Enter in the chat
// box sends (it did not, for a day), and that closing a tab says goodbye.

import { execSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const BASE = process.env.GLC_BASE.replace(/\/$/, '');
const CDP = process.env.GLC_CDP || 'http://127.0.0.1:9222';
const SQL = process.env.GLC_SQL;
const DEMO = BASE + '/gesoft-live-chat/demo';
const KEY = 'gesoft-live-chat-token';
const RUN = Math.random().toString(16).slice(2, 8);
const R = "document.querySelector('[data-gesoft-live-chat]').shadowRoot";

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sql = (q) => execSync(SQL, { input: q, encoding: 'utf8' }).trim().split('\n').filter(Boolean).map((l) => l.split('\t'));
const one = (q) => (sql(q)[0] || [])[0];
const sha = (t) => createHash('sha256').update(t).digest('hex');

let pass = 0, fail = 0;
const opened = [];
function check(label, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${label.padEnd(66)} ${ok ? JSON.stringify(got) : `got: ${JSON.stringify(got)}  wanted: ${JSON.stringify(want)}`}`);
}

// ---------------------------------------------------------------- CDP plumbing

const version = await (await fetch(CDP + '/json/version')).json();
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((ok, no) => { ws.onopen = ok; ws.onerror = no; });
let seq = 0;
const pending = new Map();
const errors = [];
ws.onmessage = (m) => {
  const msg = JSON.parse(m.data);
  if (msg.id && pending.has(msg.id)) {
    const { ok, no } = pending.get(msg.id);
    pending.delete(msg.id);
    msg.error ? no(new Error(JSON.stringify(msg.error))) : ok(msg.result);
  } else if (msg.method === 'Runtime.exceptionThrown') {
    const d = msg.params.exceptionDetails;
    errors.push((d.exception && d.exception.description) || d.text);
  }
};
const send = (method, params = {}, sessionId) => new Promise((ok, no) => {
  const m = { id: ++seq, method, params };
  if (sessionId) m.sessionId = sessionId;
  pending.set(m.id, { ok, no });
  ws.send(JSON.stringify(m));
});

async function waitFor(ev, expr, ms = 10000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    try { if (await ev(expr)) return true; } catch (e) { /* page still loading */ }
    await sleep(250);
  }
  return false;
}

async function tab() {
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const S = (m, p) => send(m, p, sessionId);
  await S('Page.enable');
  await S('Runtime.enable');
  const ev = async (expression) => {
    const r = await S('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error((r.exceptionDetails.exception && r.exceptionDetails.exception.description) || 'evaluate failed');
    return r.result.value;
  };
  const go = async () => {
    await S('Page.navigate', { url: DEMO });
    await sleep(300);
    await waitFor(ev, `!!document.querySelector('[data-gesoft-live-chat]')`, 15000);
  };
  await go();
  return { ev, go, close: () => send('Target.closeTarget', { targetId }) };
}

const introduce = (t, name, email, message) => t.ev(`(() => {
  const r = ${R};
  r.querySelector('.launcher').click();
  r.querySelector('[name=name]').value = ${JSON.stringify(name)};
  r.querySelector('[name=email]').value = ${JSON.stringify(email)};
  r.querySelector('[name=message]').value = ${JSON.stringify(message)};
  r.querySelector('.intro button').click();
  return true;
})()`);

const token = (t) => t.ev(`sessionStorage.getItem(${JSON.stringify(KEY)})`);
const conversationOf = (tok) => one(`select conversation_id from gesoft_live_chat_sessions where token_hash='${sha(tok)}'`);

console.log('GesoftLiveChat — the bubble in a browser\n');

// ------------------------------------- first message, then Enter in the chat box
const a = await tab();
await a.ev(`localStorage.setItem(${JSON.stringify(KEY)}, 'deadbeef'.repeat(4)); true`);
await a.go();
check('a token left in localStorage by an old build is removed', await a.ev(`localStorage.getItem(${JSON.stringify(KEY)})`), null);

await a.ev(`${R}.querySelector('.launcher').click(); true`);
check('a new visitor is asked who they are', await a.ev(`${R}.querySelector('.panel').classList.contains('asking')`), true);
await a.ev(`${R}.querySelector('.launcher').click(); true`);

await introduce(a, `E2E browser ${RUN}`, `e2e-browser-${RUN}@gesoft.test`, `Primul mesaj ${RUN}`);
await waitFor(a.ev, `!!sessionStorage.getItem(${JSON.stringify(KEY)})`);
const tokenA = await token(a);
const convA = tokenA && conversationOf(tokenA);
if (convA) opened.push(convA);
check('the token is kept in the tab, 64 hex', /^[0-9a-f]{64}$/.test(tokenA || ''), true);
check('  and not in localStorage', await a.ev(`localStorage.getItem(${JSON.stringify(KEY)})`), null);
check('  and the server knows it only by its hash', one(`select count(*) from gesoft_live_chat_sessions where token_hash='${tokenA}'`), '0');
check('the End button is shown during a chat', await a.ev(`!${R}.querySelector('.end').hidden`), true);
check("the introduction's message box is emptied after sending", await a.ev(`${R}.querySelector('[name=message]').value`), '');

await a.ev(`(() => {
  const t = ${R}.querySelector('.form textarea');
  t.value = 'Al doilea mesaj, cu Enter ${RUN}';
  t.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
  return true;
})()`);
await sleep(2500);
check('Enter in the chat box sends the second message', one(`select count(*) from threads where conversation_id=${convA} and type=1`), '2');
check('  and the first message was not sent again', one(`select count(*) from threads where conversation_id=${convA} and type=1 and body like 'Primul mesaj ${RUN}%'`), '1');

// --------------------------------------------------- a reload keeps the chat
await a.go();
check('after a reload the tab still has the same token', (await token(a)) === tokenA, true);
await a.ev(`${R}.querySelector('.launcher').click(); true`);
await waitFor(a.ev, `${R}.querySelector('.log').innerText.includes('Al doilea mesaj')`, 8000);
check('  and shows the conversation so far', await a.ev(`${R}.querySelector('.log').innerText.includes('Primul mesaj ${RUN}')`), true);
check('  without asking who they are again', await a.ev(`${R}.querySelector('.panel').classList.contains('asking')`), false);

// ---------------------------------------------------- a new tab is a new chat
const b = await tab();
check('a new tab has no token', await token(b), null);
await b.ev(`${R}.querySelector('.launcher').click(); true`);
check('  and asks who the visitor is', await b.ev(`${R}.querySelector('.panel').classList.contains('asking')`), true);
await b.close();

// ------------------------------------------------- closing a tab says goodbye
const c = await tab();
await introduce(c, `E2E inchidere ${RUN}`, `e2e-close-${RUN}@gesoft.test`, `Inchid tabul ${RUN}`);
await waitFor(c.ev, `!!sessionStorage.getItem(${JSON.stringify(KEY)})`);
const tokenC = await token(c);
const convC = tokenC && conversationOf(tokenC);
if (convC) opened.push(convC);
await c.close();
await sleep(2500);
check('closing the tab records a goodbye', one(`select left_at is not null from gesoft_live_chat_sessions where token_hash='${sha(tokenC)}'`), '1');
check('  but writes nothing into the conversation yet', one(`select count(*) from threads where conversation_id=${convC} and type=4`), '0');

// ------------------------------------------------------------- ending on purpose
await a.ev(`window.confirm = () => true; ${R}.querySelector('.end').click(); true`);
await sleep(2000);
check('ending removes the token from the tab', await token(a), null);
check('  tells the visitor', await a.ev(`${R}.querySelector('.log').innerText.includes('Ați încheiat conversația')`), true);
check('  hides the End button', await a.ev(`${R}.querySelector('.end').hidden`), true);
check('  writes one line for the operator', one(`select count(*) from threads where conversation_id=${convA} and type=4 and action_type=100`), '1');
check('  and leaves the conversation open', ['1', '2'].includes(one(`select status from conversations where id=${convA}`)), true);
const after = await (await fetch(`${BASE}/gesoft-live-chat/poll?token=${tokenA}&since=0`)).json();
check('the ended token opens nothing', after.closed, true);

check('no script errors in any page', errors, []);

for (const conv of new Set(opened)) sql(`update conversations set status=3, closed_at=now() where id=${conv}`);
await a.close();

console.log(`\n${pass} passed, ${fail} failed  (run ${RUN})`);
process.exit(fail ? 1 : 0);
