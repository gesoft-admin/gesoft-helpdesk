// Connecting an application from inside itself, and chatting from it with
// signed assertions, in a real browser.
//
//   google-chrome --headless=new --remote-debugging-port=9222 --user-data-dir=/tmp/glc-e2e about:blank &
//   GLC_BASE=http://freescout-test.local \
//   GLC_AGENT_EMAIL=<an administrator> GLC_AGENT_PASSWORD=... \
//   GLC_SQL="ssh test-vm 'sudo -n mysql -N freescout'" \
//   node Modules/GesoftLiveChat/Tests/e2e/connect-browser.mjs
//
// Test instances only: it connects, revokes and reconnects applications and
// opens chats.
//
// The application is `connect-app.mjs`, served from this machine on
// http://127.0.0.1:<port> — a plain-http origin on a private address, which is
// exactly what an application installed on a customer's own network looks like.
// Its server never calls the helpdesk: everything goes through the browser.

import { execSync } from 'node:child_process';
import { startApp } from './connect-app.mjs';

const BASE = process.env.GLC_BASE.replace(/\/$/, '');
const CDP = process.env.GLC_CDP || 'http://localhost:9222';
const SQL = process.env.GLC_SQL;
const RUN = Math.random().toString(16).slice(2, 8);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sql = (q) => execSync(SQL, { input: q, encoding: 'utf8' }).trim().split('\n').filter(Boolean).map((l) => l.split('\t'));
const one = (q) => (sql(q)[0] || [])[0];

let pass = 0, fail = 0;
function check(label, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${label.padEnd(70)} ${ok ? JSON.stringify(got) : `got: ${JSON.stringify(got)}  wanted: ${JSON.stringify(want)}`}`);
}

console.log('GesoftLiveChat — connecting an application from inside it\n');

const NAME = `Aplicație test ${RUN}`;
const app = await startApp({ helpdesk: BASE, name: NAME });
const other = await startApp({ helpdesk: BASE, name: `Alta ${RUN}` });

const api = async (path, body, headers = {}) => {
  const res = await fetch(`${BASE}/gesoft-live-chat/${path}`, {
    method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers), body: JSON.stringify(body),
  });
  return { status: res.status, body: await res.json().catch(() => ({})) };
};
const embedStatus = async (origin) => (await fetch(`${BASE}/chat/embed?o=${encodeURIComponent(origin)}`)).status;

// ------------------------------------------------------------- CDP plumbing

const version = await (await fetch(CDP + '/json/version')).json();
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((ok, no) => { ws.onopen = ok; ws.onerror = no; });

let seq = 0;
const pending = new Map();
let frameSession = null;
let popup = null; // { targetId, sessionId }
const errors = [];

ws.onmessage = (m) => {
  const msg = JSON.parse(m.data);

  if (msg.id && pending.has(msg.id)) {
    const { ok, no } = pending.get(msg.id);
    pending.delete(msg.id);
    msg.error ? no(new Error(JSON.stringify(msg.error))) : ok(msg.result);
    return;
  }

  // The panel, in a process of its own because it is another origin.
  if (msg.method === 'Target.attachedToTarget' && msg.params.targetInfo.type === 'iframe') {
    frameSession = msg.params.sessionId;
    send('Runtime.enable', {}, frameSession).catch(() => {});
    send('Emulation.setFocusEmulationEnabled', { enabled: true }, frameSession).catch(() => {});
  }
  // The connect popup: a page with an opener, which is how it is told apart.
  if (msg.method === 'Target.targetCreated' && msg.params.targetInfo.type === 'page' && msg.params.targetInfo.openerId) {
    const targetId = msg.params.targetInfo.targetId;
    send('Target.attachToTarget', { targetId, flatten: true }).then(({ sessionId }) => {
      popup = { targetId, sessionId };
      send('Runtime.enable', {}, sessionId).catch(() => {});
    });
  }
  if (msg.method === 'Target.targetDestroyed' && popup && msg.params.targetId === popup.targetId) {
    popup = null;
  }
  if (msg.method === 'Runtime.exceptionThrown') {
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

await send('Target.setDiscoverTargets', { discover: true });
const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
const S = (m, p) => send(m, p, sessionId);
await S('Page.enable');
await S('Runtime.enable');
await S('Target.setAutoAttach', { autoAttach: true, waitForDebuggerOnStart: false, flatten: true });
await S('Emulation.setFocusEmulationEnabled', { enabled: true });

async function evaluate(expression, session) {
  const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true }, session);
  if (r.exceptionDetails) throw new Error((r.exceptionDetails.exception && r.exceptionDetails.exception.description) || 'evaluate failed');
  return r.result.value;
}
const ev = (e) => evaluate(e, sessionId);
const inPopup = (e) => evaluate(e, popup.sessionId);
const inFrame = (body) => evaluate(
  `(async () => { const R = document.querySelector('[data-gesoft-live-chat]').shadowRoot; ${body} })()`,
  frameSession
);

async function waitFor(fn, ms = 20000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    try { if (await fn()) return true; } catch (e) { /* navigating, or not there yet */ }
    await sleep(300);
  }
  return false;
}

async function go(url) {
  frameSession = null;
  await S('Page.navigate', { url });
  await waitFor(() => ev(`document.readyState === 'complete'`));
  await sleep(300);
}

const status = () => ev(`document.getElementById('status').textContent`);
const popupState = () => inPopup(`(document.querySelector('.gesoft-connect-state:not(.hidden)') || {}).getAttribute ? document.querySelector('.gesoft-connect-state:not(.hidden)').getAttribute('data-state') : ''`);

// Press "Connect" in the application and get the popup on screen, signing in
// to the helpdesk inside it when it asks.
async function openConnect(query = '') {
  await go(`${app.origin}/settings${query}`);
  popup = null;
  await ev(`document.getElementById('connect').click(), true`);
  await waitFor(async () => !!popup);
  await waitFor(() => inPopup(`document.readyState === 'complete'`));

  if (await inPopup(`!!document.querySelector('form input[name=password]')`)) {
    await inPopup(`(() => {
      document.querySelector('input[name=email]').value = ${JSON.stringify(process.env.GLC_AGENT_EMAIL)};
      document.querySelector('input[name=password]').value = ${JSON.stringify(process.env.GLC_AGENT_PASSWORD)};
      document.querySelector('input[name=password]').form.submit();
      return true;
    })()`);
    await sleep(1500);
    await waitFor(() => inPopup(`location.pathname.endsWith('/gesoft-live-chat/apps/connect') && document.readyState === 'complete'`));
  }
}

// ------------------------------------------------------------- before

check('an unknown origin may not frame the panel', await embedStatus(app.origin), 404);
const anon = await fetch(`${BASE}/gesoft-live-chat/apps/connect`, { redirect: 'manual' });
check('the connect page is for signed-in users only', [anon.status, /\/login/.test(anon.headers.get('location') || '')], [302, true]);

// ------------------------------------------------------------- connecting

console.log('-- connecting');
await openConnect();
check('the popup signs in, comes back and asks', await waitFor(async () => (await popupState()) === 'ask'), true);
check('  showing the address the browser vouches for', await inPopup(`document.querySelector('.gesoft-connect-origin').textContent`), app.origin);
check('  and the name the application gave', await inPopup(`document.querySelector('.gesoft-connect-name').textContent`), NAME);
check('  warning that it is plain http', await inPopup(`!document.querySelector('.gesoft-connect-http').classList.contains('hidden')`), true);
check('nothing is stored before approval', one(`select count(*) from gesoft_live_chat_connected_apps where name='${NAME}'`), '0');

await inPopup(`document.querySelector('.gesoft-connect-approve').click(), true`);
check('approving tells the application', await waitFor(async () => (await status()) === 'connected'), true);
const provider = app.state.provider;
check('  a provider of its own, from its name', /^aplicatie-test-[0-9a-f]{6}-[0-9a-f]{6}$/.test(provider || ''), true);
check('  and the address to make assertions out to', app.state.audience, BASE);
check('  stored with the origin and the key', one(`select concat(origin, ' ', length(public_key)) from gesoft_live_chat_connected_apps where provider='${provider}'`), `${app.origin} 43`);
check('  and who approved it', one(`select u.email from gesoft_live_chat_connected_apps a join users u on u.id=a.connected_by where a.provider='${provider}'`), process.env.GLC_AGENT_EMAIL);
check('the popup closes itself', await waitFor(async () => !popup, 5000), true);
check('the application may now frame the panel', await embedStatus(app.origin), 200);
const csp = (await fetch(`${BASE}/chat/embed?o=${encodeURIComponent(app.origin)}`)).headers.get('content-security-policy') || '';
check('  and only it', /frame-ancestors (\S+)/.exec(csp)?.[1], app.origin);
check('another origin still may not', await embedStatus(other.origin), 404);

// ------------------------------------------------------------- cancelling

console.log('-- cancelling');
const before = one(`select count(*) from gesoft_live_chat_connected_apps`);
await go(`${other.origin}/settings`);
popup = null;
await ev(`document.getElementById('connect').click(), true`);
await waitFor(async () => !!popup);
await waitFor(async () => (await popupState()) === 'ask');
await inPopup(`document.querySelector('.gesoft-connect-cancel').click(), true`);
check('cancelling tells the application', await waitFor(async () => (await status()) === 'cancelled'), true);
check('  and connects nothing', one(`select count(*) from gesoft_live_chat_connected_apps`), before);

// ------------------------------------------------------------- chatting

console.log('-- chatting with an assertion');
await go(`${app.origin}/app?user=u1-${RUN}`);
check('the panel takes the assertion and opens', await waitFor(() => inFrame(`return R.querySelector('.panel').getAttribute('data-mode') === 'chat' && !R.querySelector('.form').hidden;`)), true);
const said = `Salut din aplicatie ${RUN}`;
await inFrame(`const b = R.querySelector('.form textarea'); b.value = ${JSON.stringify(said)}; R.querySelector('.form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); return true;`);
check('a message opens a chat', await waitFor(() => !!one(`select conversation_id from threads where body='${said}'`)), true);
const conv = one(`select conversation_id from threads where body='${said}'`);
check('  filed under this person of this application',
  one(`select concat(i.provider, ' ', i.external_id) from gesoft_live_chat_app_identities i where i.conversation_id=${conv}`), `${provider} u1-${RUN}`);
check('  with the name the assertion carried',
  one(`select concat_ws(' ', first_name, last_name) from customers where id=(select customer_id from conversations where id=${conv})`), `Utilizator u1-${RUN}`);
check('  and the address the browser came from, not one it was told', one(`select count(*) from gesoft_live_chat_app_sessions where provider='${provider}' and ip is not null`) !== '0', true);

await go(`${app.origin}/app?user=u2-${RUN}`);
await waitFor(() => inFrame(`return R.querySelector('.panel').getAttribute('data-mode') === 'chat';`));
await sleep(1000);
check('another user of the same application sees none of it', await inFrame(`return [...R.querySelectorAll('.row .msg')].map(b => b.textContent).join('|');`), '');

// ------------------------------------------------------------- refusals

console.log('-- what an assertion cannot do');
const used = app.assertion(`u1-${RUN}`);
check('an assertion is exchanged once', (await api('app/assertion', { assertion: used })).status, 200);
check('  and refused the second time', (await api('app/assertion', { assertion: used })).status, 401);
check('one for another helpdesk is refused', (await api('app/assertion', { assertion: app.assertion('x', { aud: 'https://elsewhere.example' }) })).status, 401);
const now = Math.floor(Date.now() / 1000);
check('an expired one is refused', (await api('app/assertion', { assertion: app.assertion('x', { iat: now - 400, exp: now - 200 }) })).status, 401);
check('one valid for an hour is refused', (await api('app/assertion', { assertion: app.assertion('x', { exp: now + 3600 }) })).status, 401);
check('one claiming another provider is refused',
  (await api('app/assertion', { assertion: app.assertion('x', { iss: 'nobody-000000' }) })).status, 401);
const [h, p, s] = app.assertion(`u1-${RUN}`).split('.');
const forged = Buffer.from(JSON.stringify(Object.assign(JSON.parse(Buffer.from(p, 'base64url')), { sub: `u2-${RUN}` }))).toString('base64url');
check('one with a claim changed after signing is refused', (await api('app/assertion', { assertion: `${h}.${forged}.${s}` })).status, 401);
check('nor is a bare token in its place', (await api('app/assertion', { assertion: 'a'.repeat(64) })).status, 401);

const unread = await api('app/unread', { provider, external_user_id: `u1-${RUN}` }, { Authorization: `Bearer ${app.assertion(`u1-${RUN}`)}` });
check('a server may ask for the unread count with an assertion', unread.status, 200);
check('  about that person only',
  (await api('app/unread', { provider, external_user_id: `u2-${RUN}` }, { Authorization: `Bearer ${app.assertion(`u1-${RUN}`)}` })).status, 401);

// ------------------------------------------------------------- revoking

console.log('-- revoking');
const held = (await api('app/assertion', { assertion: app.assertion(`u1-${RUN}`) })).body.token;
check('a permission held before revoking works', (await api('app/resume', { app_token: held })).status, 200);
await go(`${BASE}/gesoft-live-chat/apps`);
check('the administrator sees the application in the list', await ev(`!!document.querySelector('tr[data-provider="${provider}"] .gesoft-app-revoke')`), true);
await ev(`window.confirm = () => true; document.querySelector('tr[data-provider="${provider}"] .gesoft-app-revoke').click(), true`);
await waitFor(async () => !!one(`select revoked_at from gesoft_live_chat_connected_apps where provider='${provider}' and revoked_at is not null`));
check('revoking is recorded', !!one(`select revoked_at from gesoft_live_chat_connected_apps where provider='${provider}'`), true);
check('  the panel is refused to it at once', await embedStatus(app.origin), 404);
check('  new assertions are refused', (await api('app/assertion', { assertion: app.assertion(`u1-${RUN}`) })).status, 401);
check('  and so is the permission already held', (await api('app/resume', { app_token: held })).status, 401);

// ------------------------------------------------------------- reconnecting

console.log('-- reconnecting');
const oldKey = one(`select public_key from gesoft_live_chat_connected_apps where provider='${provider}'`);
await openConnect('?reconnect=1');
check('reconnecting says whose key it replaces', await waitFor(async () => (await popupState()) === 'ask'
  && (await inPopup(`document.querySelector('.gesoft-connect-provider').textContent`)) === provider), true);
await inPopup(`document.querySelector('.gesoft-connect-approve').click(), true`);
check('  and keeps the provider', await waitFor(async () => (await status()) === 'connected') && app.state.provider, provider);
check('  with a new key', one(`select public_key from gesoft_live_chat_connected_apps where provider='${provider}'`) !== oldKey, true);
check('  no longer revoked', one(`select revoked_at is null from gesoft_live_chat_connected_apps where provider='${provider}'`), '1');
check('  and its people keep their conversations',
  ((await api('app/history', { app_token: (await api('app/assertion', { assertion: app.assertion(`u1-${RUN}`) })).body.token })).body.conversations || [])
    .some((c) => String(c.id) === conv), true);

// Another origin cannot take it over by naming it. The last popup first has
// to be gone: a popup opened under the same window name would be reused.
await waitFor(async () => !popup, 5000);
await go(`${other.origin}/settings`);
other.state.provider = provider;
popup = null;
await ev(`history.replaceState(null, '', '?reconnect=1'); document.getElementById('connect').click(), true`);
await waitFor(async () => !!popup);
await waitFor(async () => (await popupState()) === 'ask');
await inPopup(`document.querySelector('.gesoft-connect-approve').click(), true`);
check('another origin naming that provider is refused',
  await waitFor(() => inPopup(`!document.querySelector('.gesoft-connect-error').classList.contains('hidden')`)), true);
check('  and the key stays the application\'s', one(`select origin from gesoft_live_chat_connected_apps where provider='${provider}'`), app.origin);
await inPopup(`window.close(), true`).catch(() => {});

check('no script errors', errors, []);

// What this run made is taken down again.
sql(`update conversations set status=3, closed_at=now() where id=${conv}`);
sql(`update gesoft_live_chat_app_identities set conversation_id=null where provider='${provider}'`);
sql(`update gesoft_live_chat_connected_apps set revoked_at=now() where provider='${provider}'`);
await send('Target.closeTarget', { targetId });
app.close(); other.close();

console.log(`\n${pass} passed, ${fail} failed  (run ${RUN})\n`);
process.exit(fail ? 1 : 0);
