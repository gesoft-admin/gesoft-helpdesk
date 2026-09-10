// End-to-end checks of the visitor bubble in a real browser.
//
//   google-chrome --headless=new --remote-debugging-port=9222 --user-data-dir=/tmp/glc-e2e about:blank &
//   GLC_BASE=http://freescout-test.local \
//   GLC_SQL="ssh test-vm 'cd /var/www/freescout && sudo -n mysql -N freescout'" \
//   node Modules/GesoftLiveChat/Tests/e2e/widget-browser.mjs
//
// Drives Chrome over the DevTools Protocol with no packages: Node 24 has a
// global WebSocket. Test instance only — it opens and ends real conversations,
// marks agents present or away, and closes what it opened.
//
// What only a browser can show: which storage the token lives in, that a
// reload keeps the conversation and a new tab does not, that Enter in the chat
// box sends (it did not, for a day), that closing a tab says goodbye, and which
// screen the visitor is offered when nobody is available.

import { execSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const BASE = process.env.GLC_BASE.replace(/\/$/, '');
const CDP = process.env.GLC_CDP || 'http://localhost:9222';
const SQL = process.env.GLC_SQL;
// Optional. Clears the start limit per address partway through, so the suite
// does not depend on how many chats earlier runs started.
const ARTISAN = process.env.GLC_ARTISAN;
const DEMO = BASE + '/gesoft-live-chat/demo';
const KEY = 'gesoft-live-chat-token';
const RUN = Math.random().toString(16).slice(2, 8);
const R = "document.querySelector('[data-gesoft-live-chat]').shadowRoot";

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sql = (q) => execSync(SQL, { input: q, encoding: 'utf8' }).trim().split('\n').filter(Boolean).map((l) => l.split('\t'));
const one = (q) => (sql(q)[0] || [])[0];
const sha = (t) => createHash('sha256').update(t).digest('hex');
const agentsPresent = () => sql("insert into gesoft_live_chat_agents (user_id, last_seen_at) select id, now() from users where role=2 on duplicate key update last_seen_at=now()");
const agentsAway = () => sql('update gesoft_live_chat_agents set last_seen_at = date_sub(now(), interval 1 hour)');

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

async function tab(query = '', page = DEMO) {
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
    await S('Page.navigate', { url: page + query });
    await sleep(300);
    await waitFor(ev, `!!document.querySelector('[data-gesoft-live-chat]')`, 15000);
  };
  await go();
  return { ev, go, close: () => send('Target.closeTarget', { targetId }) };
}

const mode = (t) => t.ev(`${R}.querySelector('.panel').getAttribute('data-mode')`);
const openBubble = async (t, expected) => {
  await t.ev(`${R}.querySelector('.launcher').click(); true`);
  await waitFor(t.ev, `${R}.querySelector('.panel').getAttribute('data-mode') === ${JSON.stringify(expected)}`, 8000);
};
const fill = (t, screen, name, email, message) => t.ev(`(() => {
  const s = ${R}.querySelector('.${screen}');
  s.querySelector('[name=name]').value = ${JSON.stringify(name)};
  s.querySelector('[name=email]').value = ${JSON.stringify(email)};
  s.querySelector('[name=message]').value = ${JSON.stringify(message)};
  s.querySelector('button[type=submit]').click();
  return true;
})()`);

// An agent's session over plain HTTP, for the checks that need the other side
// of the chat. Skipped without GLC_AGENT_EMAIL.
async function agentSession() {
  if (!process.env.GLC_AGENT_EMAIL) return null;
  const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
  const jar = new Map();
  const req = async (path, init = {}) => {
    const headers = { 'User-Agent': UA, Cookie: [...jar].map(([k, v]) => `${k}=${v}`).join('; '), ...(init.headers || {}) };
    const r = await fetch(BASE + path, { ...init, headers, redirect: init.redirect || 'manual' });
    for (const c of r.headers.getSetCookie()) {
      const pair = c.split(';')[0];
      const at = pair.indexOf('=');
      jar.set(pair.slice(0, at), pair.slice(at + 1));
    }
    return r;
  };
  const formHeaders = { 'Content-Type': 'application/x-www-form-urlencoded' };
  const loginPage = await (await req('/login')).text();
  const loginToken = (loginPage.match(/name="_token" value="([^"]+)"/) || [])[1];
  await req('/login', {
    method: 'POST', headers: formHeaders,
    body: new URLSearchParams({ _token: loginToken, email: process.env.GLC_AGENT_EMAIL, password: process.env.GLC_AGENT_PASSWORD }).toString(),
  });
  const home = await (await req('/', { redirect: 'follow' })).text();
  const csrf = (home.match(/<meta name="csrf-token" content="([^"]+)"/) || [])[1];
  if (!csrf) throw new Error('the agent could not sign in');
  return {
    typing: async (conv, on) => (await req(`/gesoft-live-chat/agent/${conv}/typing`, {
      method: 'POST', headers: formHeaders,
      body: new URLSearchParams({ _token: csrf, typing: on ? '1' : '0' }).toString(),
    })).json(),
  };
}

const token = (t) => t.ev(`sessionStorage.getItem(${JSON.stringify(KEY)})`);
const conversationOf = (tok) => one(`select conversation_id from gesoft_live_chat_sessions where token_hash='${sha(tok)}'`);

console.log('GesoftLiveChat — the bubble in a browser\n');
agentsPresent();

// ------------------------------------- first message, then Enter in the chat box
const a = await tab();
await a.ev(`localStorage.setItem(${JSON.stringify(KEY)}, 'deadbeef'.repeat(4)); true`);
await a.go();
check('a token left in localStorage by an old build is removed', await a.ev(`localStorage.getItem(${JSON.stringify(KEY)})`), null);

await openBubble(a, 'intro');
check('with an agent around, a new visitor is asked who they are', await mode(a), 'intro');
check('  the header says so', await a.ev(`${R}.querySelector('.status-text').textContent`), 'Suntem online');
check('  the page is Romanian, so the bubble is', await a.ev(`${R}.querySelector('.t-intro-title').textContent`), 'Începeți o conversație');
check('the bot trap field is out of sight',
  await a.ev(`${R}.querySelector('.intro .hp').getBoundingClientRect().right < 0`), true);

await fill(a, 'intro', `E2E browser ${RUN}`, `e2e-browser-${RUN}@gesoft.test`, `Primul mesaj ${RUN}`);
await waitFor(a.ev, `!!sessionStorage.getItem(${JSON.stringify(KEY)})`);
const tokenA = await token(a);
const convA = tokenA && conversationOf(tokenA);
if (convA) opened.push(convA);
check('the token is kept in the tab, 64 hex', /^[0-9a-f]{64}$/.test(tokenA || ''), true);
check('  and not in localStorage', await a.ev(`localStorage.getItem(${JSON.stringify(KEY)})`), null);
check('  and the server knows it only by its hash', one(`select count(*) from gesoft_live_chat_sessions where token_hash='${tokenA}'`), '0');
check('the chat screen is shown after the introduction', await mode(a), 'chat');
check('the End button is shown during a chat', await a.ev(`!${R}.querySelector('.end').hidden`), true);
check("the introduction's message box is emptied after sending", await a.ev(`${R}.querySelector('.intro [name=message]').value`), '');
check('the visitor sees their message with a time', await a.ev(`/\\d{2}:\\d{2}/.test(${R}.querySelector('.row.visitor .time').textContent)`), true);

await a.ev(`(() => {
  const t = ${R}.querySelector('.form textarea');
  t.value = 'Al doilea mesaj, cu Enter ${RUN}';
  t.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
  return true;
})()`);
await sleep(2500);
check('Enter in the chat box sends the second message', one(`select count(*) from threads where conversation_id=${convA} and type=1`), '2');
check('  and the first message was not sent again', one(`select count(*) from threads where conversation_id=${convA} and type=1 and body like 'Primul mesaj ${RUN}%'`), '1');

// ------------------------------------------------------------ typing, both ways
const agent = await agentSession();
if (agent) {
  const agentFirst = one(`select first_name from users where email='${process.env.GLC_AGENT_EMAIL}'`);
  const dotsShown = `!${R}.querySelector('.typing').hidden`;
  await agent.typing(convA, true);
  check('an agent writing a reply shows three dots in the bubble', await waitFor(a.ev, dotsShown, 8000), true);
  check("  with the agent's first name", await a.ev(`${R}.querySelector('.typing-text').textContent`), `${agentFirst} scrie…`);
  await agent.typing(convA, false);
  check('  and they go when the agent stops', await waitFor(a.ev, `!(${dotsShown})`, 8000), true);

  const typeInBubble = (text) => a.ev(`(() => {
    const t = ${R}.querySelector('.form textarea');
    t.value = ${JSON.stringify(text)};
    t.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  })()`);
  const agentHears = async (want) => {
    for (let i = 0; i < 16; i++) {
      if ((await agent.typing(convA, false)).visitor_typing === want) return true;
      await sleep(500);
    }
    return false;
  };
  await typeInBubble('scriu ceva');
  check('the visitor typing is told to the agent', await agentHears(true), true);
  await typeInBubble('');
  check('  and no longer once they clear the box', await agentHears(false), true);
}

// ----------------------------------------------- a visitor sending too fast
// Reported on 2026-09-10: nothing stopped a visitor sending as fast as they
// could. Five in a row go through, the sixth is refused and given back.
const sendReady = `!${R}.querySelector('.form .send').disabled`;
const sendInBubble = (text) => a.ev(`(() => {
  const t = ${R}.querySelector('.form textarea');
  t.value = ${JSON.stringify(text)};
  ${R}.querySelector('.form').dispatchEvent(new Event('submit', { cancelable: true }));
  return true;
})()`);
const visitorLines = () => Number(one(`select count(*) from threads where conversation_id=${convA} and type=1`));
const linesBefore = visitorLines();
await sleep(11000);  // the burst window is ten seconds; start it empty
for (let i = 1; i <= 6; i++) {
  await waitFor(a.ev, sendReady, 3000);
  await sendInBubble(`Rafala ${i} ${RUN}`);
  await sleep(400);
  await waitFor(a.ev, sendReady, 1500);
}
await sleep(800);
check('five messages in a row are sent, the sixth is not', visitorLines() - linesBefore, 5);
check('  the visitor is told to slow down', await a.ev(`${R}.querySelector('.log').innerText.includes('Trimiteți mesaje prea des')`), true);
check('  the refused message does not stay in the conversation looking sent',
  await a.ev(`![...${R}.querySelectorAll('.log .row.visitor')].some(r => r.innerText.includes('Rafala 6 ${RUN}'))`), true);
check('  its text is given back in the box', await a.ev(`${R}.querySelector('.form textarea').value`), `Rafala 6 ${RUN}`);
check('  and Send is paused', await a.ev(`${R}.querySelector('.form .send').disabled`), true);
check('  until the visitor may send again', await waitFor(a.ev, sendReady, 12000), true);
await a.ev(`${R}.querySelector('.form textarea').value = ''; true`);

// ---------------------------------------- polling follows the conversation
// While a chat is active and on screen the bubble asks every second and a
// half, one request at a time; measured from the requests the page made.
const pollStarts = `performance.getEntriesByType('resource').filter(e => e.name.includes('/gesoft-live-chat/poll')).map(e => e.startTime).sort((x, y) => x - y)`;
await a.ev(`performance.setResourceTimingBufferSize(2000); performance.clearResourceTimings(); true`);
await sleep(8000);
const gaps = await a.ev(`(() => { const t = ${pollStarts}; return t.slice(1).map((v, i) => Math.round(v - t[i])); })()`);
console.log('        gaps between polls (ms): ' + JSON.stringify(gaps));
const median = [...gaps].sort((x, y) => x - y)[Math.floor(gaps.length / 2)] || 0;
check('an active chat polls about every 1.5 s', median >= 1200 && median <= 2000, true);
check('  one request at a time', gaps.length > 0 && gaps.every((g) => g >= 900), true);
const pollsBefore = (await a.ev(pollStarts)).length;
await a.ev(`document.dispatchEvent(new Event('visibilitychange')); true`);
await sleep(500);
check('coming back to the tab polls at once', (await a.ev(pollStarts)).length > pollsBefore, true);

// --------------------------------------------------- a reload keeps the chat
await a.go();
check('after a reload the tab still has the same token', (await token(a)) === tokenA, true);
await openBubble(a, 'chat');
await waitFor(a.ev, `${R}.querySelector('.log').innerText.includes('Al doilea mesaj')`, 8000);
check('  and shows the conversation so far', await a.ev(`${R}.querySelector('.log').innerText.includes('Primul mesaj ${RUN}')`), true);
check('  without asking who they are again', await mode(a), 'chat');

// ---------------------------------------------------- a new tab is a new chat
const b = await tab();
check('a new tab has no token', await token(b), null);
await openBubble(b, 'intro');
check('  and asks who the visitor is', await mode(b), 'intro');
await b.close();

// ------------------------------------------------------------- English, on request
const en = await tab('?lang=en');
await openBubble(en, 'intro');
check('?lang=en gives an English bubble', await en.ev(`${R}.querySelector('.t-intro-title').textContent`), 'Start a conversation');
await en.close();

// ------------------------------------------------------------- the chat page
// helpdesk…/chat: the same bubble, open from the start and filling the window.
const pg = await tab('', BASE + '/chat');
check('the chat page opens the chat by itself', await waitFor(pg.ev, `${R}.querySelector('.panel').classList.contains('open')`, 8000), true);
check('  with no launcher and nothing to close',
  await pg.ev(`getComputedStyle(${R}.querySelector('.launcher')).display === 'none' && getComputedStyle(${R}.querySelector('.x')).display === 'none'`), true);
check('  and asks who the visitor is', await waitFor(pg.ev, `${R}.querySelector('.panel').getAttribute('data-mode') === 'intro'`, 8000), true);
check('  in a window that fits the screen',
  await pg.ev(`(() => { const r = ${R}.querySelector('.panel').getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight + 1 && r.width > 300; })()`), true);
await pg.ev(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })); ${R}.querySelector('.panel').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, composed: true })); true`);
check('  and Escape does not close it', await pg.ev(`${R}.querySelector('.panel').classList.contains('open')`), true);
await pg.close();

// ------------------------------------------------- closing a tab says goodbye
const c = await tab();
await openBubble(c, 'intro');
await fill(c, 'intro', `E2E inchidere ${RUN}`, `e2e-close-${RUN}@gesoft.test`, `Inchid tabul ${RUN}`);
await waitFor(c.ev, `!!sessionStorage.getItem(${JSON.stringify(KEY)})`);
const tokenC = await token(c);
const convC = tokenC && conversationOf(tokenC);
if (convC) opened.push(convC);
await c.close();
await sleep(2500);
check('closing the tab records a goodbye', one(`select left_at is not null from gesoft_live_chat_sessions where token_hash='${sha(tokenC)}'`), '1');
check('  but writes nothing into the conversation yet', one(`select count(*) from threads where conversation_id=${convC} and type=4`), '0');

// ------------------------------------------------------------- ending on purpose
await a.ev(`${R}.querySelector('.end').click(); true`);
check('End asks inside the bubble first', await a.ev(`!${R}.querySelector('.confirm').hidden`), true);
await a.ev(`${R}.querySelector('.confirm-no').click(); true`);
check('  and "No" keeps the chat', (await token(a)) === tokenA, true);
await a.ev(`${R}.querySelector('.end').click(); ${R}.querySelector('.confirm-yes').click(); true`);
await sleep(2000);
check('"Yes" removes the token from the tab', await token(a), null);
check('  tells the visitor', await a.ev(`${R}.querySelector('.log').innerText.includes('Ați încheiat conversația')`), true);
check('  hides the End button', await a.ev(`${R}.querySelector('.end').hidden`), true);
check('  writes one line for the operator', one(`select count(*) from threads where conversation_id=${convA} and type=4 and action_type=100`), '1');
check('  and leaves the conversation open', ['1', '2'].includes(one(`select status from conversations where id=${convA}`)), true);
const after = await (await fetch(`${BASE}/gesoft-live-chat/poll?token=${tokenA}&since=0`)).json();
check('the ended token opens nothing', after.closed, true);
await a.ev(`(() => { const t = ${R}.querySelector('.form textarea'); t.value = 'Inca ceva ${RUN}'; ${R}.querySelector('.form').dispatchEvent(new Event('submit', { cancelable: true })); return true; })()`);
check('writing again after ending goes back to the introduction', await mode(a), 'intro');
check('  with what they wrote carried over', await a.ev(`${R}.querySelector('.intro [name=message]').value`), `Inca ceva ${RUN}`);

// Reported on 2026-09-10: after ending, the next conversation's first message
// appeared under the old one's, so a new chat read as the old one carrying on.
if (ARTISAN) execSync(ARTISAN + ' cache:clear', { encoding: 'utf8' });
// This tab was reloaded earlier, which empties the form; a visitor who never
// reloaded finds their name and address still there.
await fill(a, 'intro', `E2E browser ${RUN}`, `e2e-browser-${RUN}@gesoft.test`, `Inca ceva ${RUN}`);
await waitFor(a.ev, `!!sessionStorage.getItem(${JSON.stringify(KEY)})`);
const tokenA2 = await token(a);
const convA2 = tokenA2 && conversationOf(tokenA2);
if (convA2) opened.push(convA2);
if (!tokenA2) console.log('        the introduction said: ' + JSON.stringify(await a.ev(`${R}.querySelector('.intro .error').textContent`)));
check('  sending it opens a new conversation, not the ended one', !!convA2 && convA2 !== convA, true);
check('  on an empty window: the ended chat is no longer shown',
  await a.ev(`!${R}.querySelector('.log').innerText.includes('Primul mesaj ${RUN}')`), true);
check('  only the new message is', await a.ev(`${R}.querySelectorAll('.log .row').length`), 1);
await sleep(3500);
check('  and polling does not bring the old messages back',
  await a.ev(`!${R}.querySelector('.log').innerText.includes('Al doilea mesaj')`), true);

// ------------------------------------------------------ nobody available: the form
// An agent signed in to the test instance in a real browser marks themselves
// present every ten seconds, and can do it between marking everybody away and
// the bubble asking. Try again rather than fail on somebody else's tab.
let d = null;
for (let attempt = 0; attempt < 4; attempt++) {
  agentsAway();
  d = await tab();
  await openBubble(d, 'offline');
  if ((await mode(d)) === 'offline' || attempt === 3) break;
  await d.close();
  await sleep(1500);
}
check('with nobody around, the bubble offers the message form', await mode(d), 'offline');
check('  and the header says so', await d.ev(`${R}.querySelector('.status-text').textContent`), 'Lăsați-ne un mesaj');
await fill(d, 'offline', `E2E offline ${RUN}`, `e2e-offline-${RUN}@gesoft.test`, `Mesaj offline ${RUN}`);
await waitFor(d.ev, `${R}.querySelector('.panel').getAttribute('data-mode') === 'done'`, 8000);
check('  sending it thanks the visitor', await mode(d), 'done');
const convD = one(`select conversation_id from threads where body like 'Mesaj offline ${RUN}%' order by id desc limit 1`);
if (convD) opened.push(convD);
check('  and it became an email conversation', one(`select type from conversations where id=${convD}`), '1');
check('  with no chat token left in the tab', await token(d), null);
await d.close();
agentsPresent();

check('no script errors in any page', errors, []);

for (const conv of new Set(opened)) sql(`update conversations set status=3, closed_at=now() where id=${conv}`);
await a.close();

console.log(`\n${pass} passed, ${fail} failed  (run ${RUN})`);
process.exit(fail ? 1 : 0);
