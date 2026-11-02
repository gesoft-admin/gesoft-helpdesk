// The bubble on a public site, switched from the helpdesk's header.
//
//   GLC_SITE_BASE=http://192.168.99.110/~emil/crm/frontend/web  (from .env.testvm)
//
// Needs that site's helpdeskUrl pointing at this instance and its origin in the
// instance's GESOFT_LIVE_CHAT_ORIGINS. Test instance only: it rewrites agents'
// presence rows and opens one chat.

import { execSync } from 'node:child_process';

const BASE = process.env.GLC_BASE.replace(/\/$/, '');
const SITE = process.env.GLC_SITE_BASE.replace(/\/$/, '') + '/';
const CDP = process.env.GLC_CDP || 'http://localhost:9222';
const RUN = Math.random().toString(16).slice(2, 8);
const R = "document.querySelector('[data-gesoft-live-chat]').shadowRoot";
const SHOWN = `(() => { const e = document.querySelector('[data-gesoft-live-chat]'); return !!e && e.style.display !== 'none'; })()`;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sql = (q) => execSync(process.env.GLC_SQL, { input: q, encoding: 'utf8' }).trim();
let pass = 0, fail = 0;
function check(label, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${label.padEnd(66)} ${ok ? JSON.stringify(got) : `got: ${JSON.stringify(got)}  wanted: ${JSON.stringify(want)}`}`);
}
const siteOpen = async () => (await (await fetch(BASE + '/gesoft-live-chat/status')).json()).site;

const version = await (await fetch(CDP + '/json/version')).json();
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((ok, no) => { ws.onopen = ok; ws.onerror = no; });
let seq = 0;
const pending = new Map();
ws.onmessage = (m) => {
  const msg = JSON.parse(m.data);
  if (msg.id && pending.has(msg.id)) {
    const { ok, no } = pending.get(msg.id);
    pending.delete(msg.id);
    msg.error ? no(new Error(JSON.stringify(msg.error))) : ok(msg.result);
  }
};
const send = (method, params = {}, sessionId) => new Promise((ok, no) => {
  const m = { id: ++seq, method, params };
  if (sessionId) m.sessionId = sessionId;
  pending.set(m.id, { ok, no });
  ws.send(JSON.stringify(m));
});

// Each side in a browser context of its own: a visitor is not signed in.
async function tab(url) {
  const { browserContextId } = await send('Target.createBrowserContext');
  const { targetId } = await send('Target.createTarget', { url: 'about:blank', browserContextId });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const S = (m, p) => send(m, p, sessionId);
  await S('Page.enable');
  await S('Runtime.enable');
  await S('Page.bringToFront');
  const ev = async (expression) => {
    const r = await S('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.text);
    return r.result.value;
  };
  const until = async (expr, ms = 10000) => {
    const t0 = Date.now();
    while (Date.now() - t0 < ms) {
      try { if (await ev(expr)) return true; } catch (e) { /* loading */ }
      await sleep(250);
    }
    return false;
  };
  const go = async (u) => { await S('Page.navigate', { url: u }); await sleep(1500); await until(`document.readyState === 'complete'`); };
  if (url) await go(url);
  return { ev, until, go, S };
}

sql('update gesoft_live_chat_agents set site_bubble_until = null');
check('nobody has offered it: the status says closed', await siteOpen(), false);

const visitor = await tab(SITE);
await sleep(1000);
check('the site carries no bubble', await visitor.ev(SHOWN), false);
check('and has not loaded the widget', await visitor.ev(`!!document.querySelector('script[src*="gesoft-chat-widget"]')`), false);

const agent = await tab(BASE + '/login');
await agent.ev(`(() => {
  document.querySelector('input[name=email]').value = ${JSON.stringify(process.env.GLC_AGENT_EMAIL)};
  document.querySelector('input[name=password]').value = ${JSON.stringify(process.env.GLC_AGENT_PASSWORD)};
  document.querySelector('form').submit(); return true; })()`);
await agent.until(`!location.pathname.startsWith('/login')`, 20000);
const label = `(document.querySelector('.gesoft-site-bubble span') || {}).textContent`;
check('the header shows the switch, off', await agent.until(`${label} === 'Chat pe site: oprit' || ${label} === 'Chat on the site: off'`, 15000), true);

await agent.ev(`document.querySelector('.gesoft-site-bubble a').click(); true`);
check('pressing it turns it on', await agent.until(`document.querySelector('.gesoft-site-bubble').classList.contains('is-on')`), true);
check('the status says open', await siteOpen(), true);

await visitor.S('Page.bringToFront');
check('an open page gets the bubble with no reload', await visitor.until(SHOWN, 35000), true);
await sleep(800);
check('its launcher is on screen', await visitor.ev(`${R}.querySelector('.launcher').getBoundingClientRect().width > 0`), true);
check('the source is offered in the bubble', await visitor.ev(`!!${R}.querySelector('.src a')`), true);

await visitor.ev(`${R}.querySelector('.launcher').click(); true`);
check('it offers a chat', await visitor.until(`${R}.querySelector('.panel').getAttribute('data-mode') === 'intro'`), true);
const email = `site-${RUN}@example.com`;
await visitor.ev(`(() => { const s = ${R}.querySelector('.intro');
  s.querySelector('[name=name]').value = 'Site ${RUN}';
  s.querySelector('[name=email]').value = '${email}';
  s.querySelector('[name=message]').value = 'din site ${RUN}';
  s.querySelector('button[type=submit]').click(); return true; })()`);
check('the chat starts across origins', await visitor.until(`!!sessionStorage.getItem('gesoft-live-chat-token')`), true);
check('and reaches the helpdesk', sql(`select count(*) from conversations where customer_email='${email}' and type=3`), '1');

const away = () => sql('update gesoft_live_chat_agents set last_seen_at = date_sub(now(), interval 1 hour)');
const back = () => sql('update gesoft_live_chat_agents set last_seen_at = now()');
await agent.S('Page.navigate', { url: 'about:blank' });
await sleep(1000);
away();
check('the agent gone: closed, though the switch is still on', await siteOpen(), false);
back();
check('the same agent back within the limit: open', await siteOpen(), true);

sql("update gesoft_live_chat_agents set site_bubble_until = date_sub(now(), interval 1 minute) where site_bubble_until is not null");
check('the time limit run out: closed', await siteOpen(), false);

await visitor.go(SITE);
check('a visitor in a chat keeps the bubble across a reload', await visitor.until(SHOWN), true);

const stranger = await tab(SITE);
await sleep(1500);
check('a new visitor gets none', await stranger.ev(SHOWN), false);

const cors = async (o) => (await fetch(BASE + '/gesoft-live-chat/status', { headers: { Origin: o } })).headers.get('access-control-allow-origin');
const siteOrigin = new URL(SITE).origin;
check('the site is allowed to ask', await cors(siteOrigin), siteOrigin);
check('another site is not', await cors('https://evil.example'), null);
const anon = await fetch(BASE + '/gesoft-live-chat/agent/site-bubble', { method: 'POST', redirect: 'manual', body: new URLSearchParams({ on: '1' }) });
check('nobody can press it without a session', anon.status !== 200 && (await siteOpen()) === false, true);

// Off, then signing out.
await agent.go(BASE + '/');
await agent.until(`!!document.querySelector('.gesoft-site-bubble')`, 15000);
await agent.ev(`document.querySelector('.gesoft-site-bubble a').click(); true`);
await agent.until(`document.querySelector('.gesoft-site-bubble').classList.contains('is-on')`);
check('on again', await siteOpen(), true);
await sleep(500);
await agent.ev(`document.querySelector('.gesoft-site-bubble a').click(); true`);
await agent.until(`!document.querySelector('.gesoft-site-bubble').classList.contains('is-on')`);
check('pressing it again turns it off', await siteOpen(), false);
await sleep(500);
await agent.ev(`document.querySelector('.gesoft-site-bubble a').click(); true`);
await agent.until(`document.querySelector('.gesoft-site-bubble').classList.contains('is-on')`);
check('on once more', await siteOpen(), true);
await agent.ev(`(() => { const f = document.querySelector('form#logout-form') || document.querySelector('form[action*="logout"]'); f.submit(); return true; })()`);
await sleep(2500);
check('signing out closes it', await siteOpen(), false);

sql(`update conversations set status=3 where customer_email='${email}'`);
console.log(`\n  ${pass} passed, ${fail} failed`);
ws.close();
process.exit(fail ? 1 : 0);
