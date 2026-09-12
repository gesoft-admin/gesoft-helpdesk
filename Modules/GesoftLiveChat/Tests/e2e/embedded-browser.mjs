// End-to-end checks of the chat embedded in an application, in a real browser.
//
//   google-chrome --headless=new --remote-debugging-port=9222 --user-data-dir=/tmp/glc-e2e about:blank &
//   GLC_BASE=http://freescout-test.local \
//   GLC_APP_BASE=http://app-test.local/backend/web \
//   GLC_APP_USER_A=... GLC_APP_USER_B=... GLC_APP_PASS=... \
//   GLC_APP_PROVIDER=<the provider name that application is registered under> \
//   GLC_AGENT_EMAIL=... GLC_AGENT_PASSWORD=... \
//   GLC_SQL="ssh test-vm 'sudo -n mysql -N freescout'" \
//   node Modules/GesoftLiveChat/Tests/e2e/embedded-browser.mjs
//
// Test instances only, on both sides: it signs in to the application as two
// people, opens chats as them and replies as an agent.
//
// What is under test here is the *boundary*, because that is what is new. The
// chat itself is the same bubble the other suites already exercise; what has to
// be proved is that a page of another application can open it, that only the
// application's own server can say who is chatting, that the two windows will
// talk to nobody else, and that signing out leaves nothing for the next person
// to inherit. The last of those is the one that matters most: it is the check
// that fails loudly if identity ever starts coming from the browser.

import { execSync } from 'node:child_process';

const BASE = process.env.GLC_BASE.replace(/\/$/, '');
const APP = process.env.GLC_APP_BASE.replace(/\/$/, '');
const APP_ORIGIN = new URL(APP).origin;
const CDP = process.env.GLC_CDP || 'http://localhost:9222';
const SQL = process.env.GLC_SQL;
const PASS = process.env.GLC_APP_PASS;
const USER_A = process.env.GLC_APP_USER_A;
const USER_B = process.env.GLC_APP_USER_B;
// Only for the check below that a browser cannot ask on its own behalf. The
// answer is the same 401 for an unregistered application as for a missing
// secret, by design, so this works either way and is only sharper when set.
const PROVIDER = process.env.GLC_APP_PROVIDER || 'not-a-registered-application';
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

// ------------------------------------------------------- the agent's session

// Enough of a cookie jar to sign in and send one reply. The agent's own
// interface has a suite of its own; here a reply is a fixture, not the subject.
let agentCookies = '';
async function agentFetch(path, body, follow) {
  const res = await fetch(BASE + path, {
    method: body ? 'POST' : 'GET',
    // Manual by default so a redirect to the sign-in page is visible rather
    // than silently followed; the conversation page is the exception, because
    // core answers it with a redirect that only adds the folder to the address.
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
  return { status: res.status, text: await res.text() };
}

async function agentSignIn() {
  const form = await agentFetch('/login');
  const token = /name="_token" value="([^"]+)"/.exec(form.text)[1];
  await agentFetch('/login', new URLSearchParams({
    _token: token, email: process.env.GLC_AGENT_EMAIL, password: process.env.GLC_AGENT_PASSWORD,
  }).toString());
  const home = await agentFetch('/');
  return /<meta name="csrf-token" content="([^"]+)"/.exec(home.text)[1];
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

ws.onmessage = (m) => {
  const msg = JSON.parse(m.data);

  if (msg.id && pending.has(msg.id)) {
    const { ok, no } = pending.get(msg.id);
    pending.delete(msg.id);
    msg.error ? no(new Error(JSON.stringify(msg.error))) : ok(msg.result);
    return;
  }

  // The panel is served from another origin, so Chrome puts it in a process
  // and a target of its own. Attaching to it is the only way to look inside.
  // Matched on being a frame rather than on its address: the attachment happens
  // before the frame has navigated, so at this moment it has no address yet.
  if (msg.method === 'Target.attachedToTarget' && msg.params.targetInfo.type === 'iframe') {
    frameSession = msg.params.sessionId;
    send('Runtime.enable', {}, frameSession).catch(() => {});
    // The panel polls every thirty seconds while its page is hidden, and a tab
    // that nobody is looking at in a headless browser is hidden. Without this
    // an agent's reply takes half a minute to arrive and every wait here is
    // really a wait on `document.hidden`.
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
// Inside the panel, which lives in a shadow root so that neither stylesheet can
// reach the other. `R` is that root.
const inFrame = (body) => evaluate(
  `(async () => { const R = document.querySelector('[data-gesoft-live-chat]').shadowRoot; ${body} })()`,
  frameSession
);

async function waitFor(fn, ms = 20000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    try { if (await fn()) return true; } catch (e) { /* navigating, or no frame yet */ }
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

  // A profile that is still signed in is sent straight past the form, and a
  // suite that then carries on as the wrong person proves nothing.
  if (!(await ev(`!!document.querySelector('#loginform-username')`))) {
    await signOut();
  }
  await ev(`(() => {
    document.querySelector('#loginform-username').value = ${JSON.stringify(username)};
    document.querySelector('#loginform-password').value = ${JSON.stringify(PASS)};
    // Not remembered: the next person at this browser must arrive as a guest.
    document.querySelector('#loginform-rememberme').checked = false;
    document.querySelector('form#login-form').submit();
    return true;
  })()`);
  await waitFor(() => ev(`!location.pathname.endsWith('/site/login')`));
  await sleep(400);
}

const panelOpen = () => ev(`!!document.querySelector('.support-panel') && !document.querySelector('.support-panel').hidden`);
const frameSrc = () => ev(`(document.querySelector('.support-panel iframe') || {}).src || ''`);
const badge = () => ev(`(() => { const b = document.querySelector('#support-chat-toggle .support-badge'); return b && !b.hidden ? b.textContent : ''; })()`);
const clickSupport = () => ev(`document.getElementById('support-chat-toggle').click(), true`);
const authorised = () => inFrame(`return R.querySelector('.panel').getAttribute('data-mode') === 'chat' && !R.querySelector('.form').hidden;`);
const transcript = () => inFrame(`return [...R.querySelectorAll('.row .msg')].map(b => b.textContent).join(' | ');`);

async function say(text) {
  await inFrame(`
    const box = R.querySelector('.form textarea');
    box.value = ${JSON.stringify(text)};
    R.querySelector('.form').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    return true;`);
}

// An agent has to count as present, or the panel correctly offers to take a
// message by email instead and there is no chat to test. Presence is the
// agent's own heartbeat, so this suite makes it rather than assuming it.
const csrf = await agentSignIn();
const heartbeat = () => agentFetch('/gesoft-live-chat/agent/chats');
await heartbeat();

console.log('GesoftLiveChat — the panel embedded in an application\n');

// --------------------------------------------------- the button, and the frame

await signIn(USER_A);
check('the application shows a Support button in its own navbar', await ev(`!!document.getElementById('support-chat-toggle')`), true);
check('  and nothing of the helpdesk is loaded until it is pressed',
  await ev(`!document.querySelector('.support-panel iframe') && !document.querySelector('script[src*="${BASE}"]')`), true);

await clickSupport();
check('pressing it opens the panel', await waitFor(panelOpen), true);
check('  which is a frame on the helpdesk, told which origin it is inside',
  await waitFor(async () => (await frameSrc()).startsWith(`${BASE}/chat/embed?o=${encodeURIComponent(APP_ORIGIN)}`)), true);

check('the panel authorises itself and offers the chat, with no form to fill in',
  await waitFor(async () => frameSession && await authorised()), true);
check('  and never asks who this is', await inFrame(`return R.querySelector('.intro').hidden;`), true);

// -------------------------------------------------------- a chat, both ways

// Everything from here is found through this one message rather than through
// "the newest row", so the suite says nothing about whoever else has been using
// the test instance.
const first = `Buna ziua, e2e ${RUN}`;
await heartbeat();
await say(first);

// Found by the message rather than by the conversation, because this person may
// already have had a chat open from a previous run -- in which case the right
// answer is that the message joined it, not that a second one appeared.
check('a message from the panel reaches a chat',
  await waitFor(() => !!one(`select conversation_id from threads where body='${first}'`)), true);

const convA = one(`select conversation_id from threads where body='${first}'`);
const customerA = one(`select customer_id from conversations where id=${convA}`);
const externalA = one(`select external_id from gesoft_live_chat_app_identities where customer_id=${customerA}`);

check('  which is a chat, on the chat channel',
  sql(`select type, channel from conversations where id=${convA}`)[0], ['3', '100']);
check('the helpdesk filed this person under the application and its own user id, not their address',
  /^[0-9]+$/.test(externalA || ''), true);
check('  and that identity is the one holding the chat',
  one(`select conversation_id from gesoft_live_chat_app_identities where customer_id=${customerA}`), convA);

const reply = `Va raspund imediat ${RUN}`;
check('an agent replies from FreeScout', await agentReply(csrf, convA, reply), 'success');
check('  and the reply reaches the panel', await waitFor(async () => (await transcript()).includes(reply)), true);

// A chat from an application is a chat: the agent gets the same conversation
// page and the same tools on it, Remote Support among them.
const sidebar = await agentFetch(`/conversation/${convA}`, null, true);
check('Remote Support is offered on it like on any other conversation',
  sidebar.text.includes('id="gesoft-rs-panel"'), true);

// ------------------------------------------------------- minimise and navigate

await inFrame(`R.querySelector('.x').click(); return true;`);
check('closing the panel from inside asks the application to put it away, and it does',
  await waitFor(async () => !(await panelOpen())), true);
check('  and the button no longer reads as active', await ev(`document.getElementById('support-chat-toggle').getAttribute('aria-expanded')`), 'false');

const whileAway = `Inca ceva ${RUN}`;
await agentReply(csrf, convA, whileAway);
check('a reply while the panel is away is counted on the application\'s own button',
  await waitFor(async () => (await badge()) === '1'), true);

await clickSupport();
check('opening it again clears the count', await waitFor(async () => (await badge()) === ''), true);

await go('/site/index');
check('walking to another page of the application reopens the panel', await waitFor(panelOpen), true);
check('  on the same conversation, from the beginning',
  await waitFor(async () => frameSession && (await transcript()).includes(first) && (await transcript()).includes(reply)), true);
check('  and the helpdesk opened nothing new for this person on the way',
  one(`select count(*) from conversations where customer_id=${customerA} and type=3 and id > ${convA}`), '0');

// --------------------------------------------------------- the postMessage door

// The application's page ignores anything that did not come from the helpdesk,
// whatever it says. Posted to itself, so its origin is the application's own.
await ev(`window.postMessage({ type: 'UNREAD_COUNT', value: 99 }, '*'), true`);
await sleep(400);
check('the application ignores a message that did not come from the helpdesk', await badge(), '');

// And the panel ignores a permission that is not one, however well formed.
const forged = 'f'.repeat(64);
const poke = (message) => ev(`(() => {
  const f = document.querySelector('.support-panel iframe');
  if (!f) { return false; }
  f.contentWindow.postMessage(${JSON.stringify(message)}, '*');
  return true;
})()`);
check('the frame is still there to be poked at', await poke({ type: 'AUTH', value: forged }), true);
await poke({ type: 'NONSENSE', value: 1 });
await sleep(1200);
check('a made-up permission gets nowhere, and the conversation is untouched',
  (await transcript()).includes(first), true);

// ------------------------------------------------------------- the frame's door

const wrongOrigin = await fetch(`${BASE}/chat/embed?o=https://not-registered.example`);
check('an origin the helpdesk does not know gets no panel at all', wrongOrigin.status, 404);
const noOrigin = await fetch(`${BASE}/chat/embed`);
check('and neither does asking without one', noOrigin.status, 404);

const embed = await fetch(`${BASE}/chat/embed?o=${encodeURIComponent(APP_ORIGIN)}`);
check('the panel names exactly one site as allowed to frame it',
  (embed.headers.get('content-security-policy') || '').includes(`frame-ancestors ${APP_ORIGIN}`), true);
check('  and says nothing that contradicts it', embed.headers.get('x-frame-options'), null);

const admin = await fetch(`${BASE}/login`);
check('the helpdesk\'s own interface stays unframeable', admin.headers.get('x-frame-options'), 'SAMEORIGIN');

// ------------------------------------------------------------ the other person

// Signing out the way the navbar does it: a POST, because that is the only
// verb the application accepts for it.
await ev(`(() => { const f = document.createElement('form'); f.method = 'post'; f.action = ${JSON.stringify(APP)} + '/site/logout';
  const t = document.createElement('input'); t.name = document.querySelector('meta[name=csrf-param]').content;
  t.value = document.querySelector('meta[name=csrf-token]').content; f.appendChild(t); document.body.appendChild(f); f.submit(); return true; })()`);
await waitFor(() => ev(`location.pathname.endsWith('/site/login')`));

await signIn(USER_B);

// Through the public API rather than the button, because the button toggles:
// the panel had been left open, that preference is the tab's and survives the
// sign-in, so the panel is already up and pressing it would put it away.
await ev(`window.GesoftSupport.open(), true`);
check('the next person to sign in at this browser gets the panel', await waitFor(panelOpen), true);
check('  and the application can say so for itself', await ev(`window.GesoftSupport.isOpen()`), true);
check('  authorised as themselves', await waitFor(async () => frameSession && await authorised()), true);

const externalB = one(`select external_id from gesoft_live_chat_app_identities where external_id <> '${externalA}' order by id desc limit 1`);
const customerB = one(`select customer_id from gesoft_live_chat_app_identities where external_id='${externalB}'`);
check('  filed as a different person entirely', [externalB !== externalA, customerB !== customerA], [true, true]);

const seen = await transcript();
check('  and not one word of the first person\'s conversation is on their screen',
  [seen.includes(first), seen.includes(reply), seen.includes(whileAway)], [false, false, false]);

// ----------------------------------------------------------------- impersonation

// The only fields the panel can send are a permission and a message. Sending a
// name and an address alongside a permission changes nothing: the customer is
// the one the mapping names, and the mapping is written by a server.
const impersonation = await inFrame(`
  const r = await fetch('${BASE}/gesoft-live-chat/status');
  return r.ok;`);
check('the panel can reach the helpdesk it is served from', impersonation, true);

const asked = await fetch(`${BASE}/gesoft-live-chat/app/session`, {
  method: 'POST', headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ provider: PROVIDER, external_user_id: '1', name: 'Somebody Else' }),
});
check('and a browser asking the helpdesk who it is, without the secret, is refused', asked.status, 401);

// What this run opened is closed again, so the next one starts from nothing.
// A chat left open would be picked up by the next run's resume, which is
// correct behaviour and a confusing fixture.
sql(`update conversations set status=3 where id=${convA}`);
sql(`update gesoft_live_chat_app_identities set conversation_id=null where customer_id=${customerA}`);

check('no script on either side threw', errors, []);

console.log(`\n${pass} passed, ${fail} failed\n`);
ws.close();
process.exit(fail ? 1 : 0);
