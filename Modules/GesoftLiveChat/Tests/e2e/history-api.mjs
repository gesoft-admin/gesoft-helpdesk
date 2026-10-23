// Where a message written from an application user's history goes, checked
// against the helpdesk's own API with no application and no browser.
//
//   GLC_BASE=http://freescout-test.local \
//   GLC_APP_PROVIDER=<a registered provider> GLC_APP_TOKEN=<its shared secret> \
//   GLC_AGENT_EMAIL=... GLC_AGENT_PASSWORD=... \
//   GLC_SQL="ssh test-vm 'sudo -n mysql -N freescout'" \
//   node Modules/GesoftLiveChat/Tests/e2e/history-api.mjs
//
// Test instances only: it mints identities, opens and closes conversations and
// rewrites their closing times.
//
// The embedded suite covers the same ground through the panel, but it needs the
// application signed in as two real people. This one stands in for the
// application's server instead — the one call a browser never makes — so the
// rules in `History::replyGoes` are checked whenever the helpdesk is up, and a
// broken application on the other side cannot hide a regression here.

import { execSync } from 'node:child_process';

const BASE = process.env.GLC_BASE.replace(/\/$/, '');
const SQL = process.env.GLC_SQL;
const PROVIDER = process.env.GLC_APP_PROVIDER;
const APP_TOKEN = process.env.GLC_APP_TOKEN;
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

console.log('GesoftLiveChat — where a reply from the history goes\n');

if (!PROVIDER || !APP_TOKEN) {
  console.log('  --    needs GLC_APP_PROVIDER and GLC_APP_TOKEN; skipped');
  process.exit(0);
}

// ------------------------------------------------------- the agent's session

let agentCookies = '';
async function agentFetch(path, body) {
  const res = await fetch(BASE + path, {
    method: body ? 'POST' : 'GET',
    redirect: 'manual',
    headers: Object.assign(
      { Cookie: agentCookies, 'X-Requested-With': 'XMLHttpRequest' },
      body ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}
    ),
    body,
  });
  for (const c of (res.headers.getSetCookie ? res.headers.getSetCookie() : [])) {
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

async function agentClose(csrf, conversation) {
  const res = await agentFetch('/conversation/ajax', new URLSearchParams({
    _token: csrf, action: 'conversation_change_status',
    conversation_id: String(conversation), status: '3',
  }).toString());
  return JSON.parse(res.text).status;
}

// ------------------------------------------------- the application's server

const external = `history-${RUN}`;
const email = `history-${RUN}@gesoft.test`;

async function mint() {
  const res = await fetch(`${BASE}/gesoft-live-chat/app/session`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${APP_TOKEN}` },
    body: JSON.stringify({ provider: PROVIDER, external_user_id: external, name: `Istoric ${RUN}`, email }),
  });
  return (await res.json()).token;
}

async function call(path, body) {
  const res = await fetch(`${BASE}/gesoft-live-chat/${path}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ lang: 'ro' }, body)),
  });
  return { status: res.status, body: await res.json().catch(() => ({})) };
}

const convOf = (body) => one(`select conversation_id from threads where body='${body}'`);
const statusOf = (id) => one(`select status from conversations where id=${id}`);
// A line names the other conversation by the number an agent sees, which is
// the `number` column only with custom numbering on and the id otherwise
// (`Conversation::getNumberAttribute`). Either is accepted, matched exactly.
const line = (id, action, other) => one(`select count(*) from threads
  where conversation_id=${id} and type=4 and action_type=${action}
  and (meta like '%:${other}}' or meta like concat('%:', (select number from conversations where id=${other}), '}'))`);

const csrf = await agentSignIn();
const app = await mint();
check('the application\'s server is given a permission', typeof app === 'string' && app.length === 64, true);
const identity = one(`select id from gesoft_live_chat_app_identities where external_id='${external}'`);
const openChats = () => one(`select count(*) from conversations c
  join gesoft_live_chat_app_conversations m on m.conversation_id=c.id
  where m.identity_id=${identity} and c.status in (1,2)`);

// --------------------------------------------- 1: a closed one, another open

const firstBody = `Prima ${RUN}`;
check('a first chat opens', (await call('start', { app_token: app, message: firstBody })).body.status, 'success');
const convA = convOf(firstBody);
check('  and an agent closes it', await agentClose(csrf, convA), 'success');

const secondBody = `A doua ${RUN}`;
check('a second chat opens', (await call('start', { app_token: app, message: secondBody })).body.status, 'success');
const convB = convOf(secondBody);
check('  as a conversation of its own', !!convB && convB !== convA, true);

const again = `Sub prima, cu a doua deschisa ${RUN}`;
let res = await call('app/reply', { app_token: app, conversation_id: convA, message: again });
check('writing under the closed one, with another open, is accepted', res.body.status, 'success');
check('  and lands in the open one', convOf(again), convB);
check('  which the answer says', [res.body.moved, String(res.body.conversation_id)], ['current', convB]);
check('  with a token for the chat it landed in', typeof (res.body.chat || {}).token === 'string' && res.body.chat.token.length > 0, true);
check('the closed one stays closed', statusOf(convA), '3');
check('  so this person has one open chat, not two', openChats(), '1');
check('the open one names the closed one', line(convB, 104, convA), '1');
check('  and the closed one names where the message went', line(convA, 105, convB), '1');

// ------------------------------------------------------- 2: an open one

const onB = `Pe cea deschisa ${RUN}`;
res = await call('app/reply', { app_token: app, conversation_id: convB, message: onB });
check('an open conversation is answered where it is', convOf(onB), convB);
check('  without saying it moved', res.body.moved, undefined);

// --------------------------------------------- 3: closed a long time ago

check('an agent closes the second', await agentClose(csrf, convB), 'success');
sql(`update conversations set closed_at = now() - interval 30 day where id=${convA}`);
const monthLater = `Dupa o luna ${RUN}`;
res = await call('app/reply', { app_token: app, conversation_id: convA, message: monthLater });
const convC = convOf(monthLater);
check('writing under one closed a month ago starts a new conversation', !!convC && convC !== convA && convC !== convB, true);
check('  which the answer says', [res.body.moved, String(res.body.conversation_id)], ['new', convC]);
check('  an open chat', one(`select concat(type, '/', status) from conversations where id=${convC}`), '3/1');
check('  in this person\'s history', one(`select count(*) from gesoft_live_chat_app_conversations where conversation_id=${convC} and identity_id=${identity}`), '1');
check('  and the chat they are in now', one(`select conversation_id from gesoft_live_chat_app_identities where id=${identity}`), convC);
check('  naming the old one', line(convC, 104, convA), '1');
check('the old one stays closed', statusOf(convA), '3');

// ------------------------------------------------- 4: closed yesterday

check('an agent closes the new one', await agentClose(csrf, convC), 'success');
sql(`update conversations set closed_at = now() - interval 1 day where id=${convA}`);
const nextDay = `A doua zi ${RUN}`;
res = await call('app/reply', { app_token: app, conversation_id: convA, message: nextDay });
check('closed yesterday, nothing else open: written in where it was', convOf(nextDay), convA);
check('  and reopened', statusOf(convA), '1');
check('  without saying it moved', res.body.moved, undefined);
check('  still one open chat', openChats(), '1');

// ------------------------------------------- 5: refusals still come first

sql(`update conversations set status=4 where id=${convB}`);
res = await call('app/reply', { app_token: app, conversation_id: convB, message: `Spam ${RUN}` });
check('spam is still not replied into, nor moved elsewhere', [res.status, convOf(`Spam ${RUN}`)], [404, undefined]);

// ------------------------------------------ 6: a message left outside hours

const leftBody = `Lasat offline ${RUN}`;
check('a message left outside hours is accepted', (await call('offline', { app_token: app, message: leftBody })).body.status, 'success');
const leftConv = convOf(leftBody);
check('  as an email conversation', one(`select type from conversations where id=${leftConv}`), '1');
const history = (await call('app/history', { app_token: app })).body.conversations || [];
check('  in the history of the person who left it', history.some((c) => String(c.id) === leftConv), true);
check('  without becoming the chat they are in', one(`select conversation_id from gesoft_live_chat_app_identities where id=${identity}`), convA);

// What this run opened is closed again.
await sleep(200);
sql(`update conversations set status=3, closed_at=now() where id in (${[convA, convB, convC, leftConv].filter(Boolean).join(',')})`);
sql(`update gesoft_live_chat_app_identities set conversation_id=null where id=${identity}`);

console.log(`\n${pass} passed, ${fail} failed  (run ${RUN})\n`);
process.exit(fail ? 1 : 0);
