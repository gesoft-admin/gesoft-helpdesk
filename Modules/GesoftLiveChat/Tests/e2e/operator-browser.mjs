// End-to-end checks of what the agent sees, in a real browser.
//
//   google-chrome --headless=new --remote-debugging-port=9222 --user-data-dir=/tmp/glc-e2e about:blank &
//   GLC_BASE=http://freescout-test.local \
//   GLC_SQL="ssh test-vm 'cd /var/www/freescout && sudo -n mysql -N freescout'" \
//   GLC_AGENT_EMAIL=... GLC_AGENT_PASSWORD=... \
//   node Modules/GesoftLiveChat/Tests/e2e/operator-browser.mjs
//
// Test instance only: it signs in as an agent, opens real chats through the
// visitor endpoints, blocks and unblocks a visitor, and closes what it opened.
//
// These are the checks that a passing endpoint cannot stand in for. The chat
// count once answered curl perfectly while the interface showed nothing,
// because a script threw before it painted anything.

import { execSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const BASE = process.env.GLC_BASE.replace(/\/$/, '');
const CDP = process.env.GLC_CDP || 'http://localhost:9222';
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

async function visitor(name, message, email) {
  const r = await fetch(`${BASE}/gesoft-live-chat/start`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, email: email || `e2e-op-${RUN}-${opened.length}@gesoft.test`, message, lang: 'ro' }),
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
const pollOnce = async (v) => (await fetch(`${BASE}/gesoft-live-chat/poll?token=${v.token}&since=0`)).json();
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
// A browser profile that is still signed in is sent straight past the form.
await ev(`(() => {
  const email = document.querySelector('input[name=email]');
  if (!email) { return false; }
  email.value = ${JSON.stringify(process.env.GLC_AGENT_EMAIL)};
  document.querySelector('input[name=password]').value = ${JSON.stringify(process.env.GLC_AGENT_PASSWORD)};
  document.querySelector('input[name=password]').form.submit();
  return true;
})()`);
await sleep(1500);
await waitFor(`!location.pathname.startsWith('/login')`, 20000);
check('signed in as the agent', await ev(`!location.pathname.startsWith('/login')`), true);
check('Manage has a link to the blocked visitors page',
  await ev(`[...document.querySelectorAll('a')].some(a => a.href.endsWith('/gesoft-live-chat/blocks'))`), true);

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
const hoverTitle = await ev(`(document.querySelector('.chats li.chat-item[data-chat_id="${ended.conv}"] .folder-name') || {}).title || ''`);
check('  and the mark is explained in words on hover, in the agent\'s language',
  ['The customer ended the chat', 'Clientul a încheiat chatul'].includes(hoverTitle), true);

const lineText = await ev(`[...document.querySelectorAll('.thread-type-lineitem .thread-title')].map(e => e.innerText.trim()).join(' | ')`);
check('the conversation shows the "ended the chat" line', /ended the chat|a încheiat chatul/.test(lineText), true);
check('  signed with the customer, not "System"', lineText.includes(`E2E plecat ${RUN}`) && !/System/.test(lineText), true);
check('More Actions offers "ask if still there" and "block visitor"',
  await ev(`!!document.querySelector('.gesoft-chat-nudge') && !!document.querySelector('.gesoft-chat-block-open')`), true);

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

// ------------------------------------------------------------ typing, both ways
// Still on the chat the agent is reading.
const box = `document.querySelector('.gesoft-chat-typing')`;
check('the chat page carries the typing line, hidden until needed', await ev(`!!${box} && ${box}.hidden`), true);
await fetch(`${BASE}/gesoft-live-chat/poll?token=${here.token}&since=0&typing=1`);
check('a visitor typing shows the agent that they are', await waitFor(`!!${box} && !${box}.hidden`, 10000), true);
check('  in words, in the agent\'s language',
  ['The customer is typing…', 'Clientul scrie…'].includes(await ev(`(document.querySelector('.gesoft-typing-text') || {}).textContent || ''`)), true);
await say(here, `Am terminat de scris ${RUN}`);
check('  and it goes when their message arrives', await waitFor(`!${box} || ${box}.hidden`, 10000), true);

const typeInEditor = (text, asNote) => ev(`(() => {
  const field = document.querySelector(".form-reply input[name='is_note']");
  if (field) { field.value = ${asNote ? "'1'" : "''"}; }
  const editor = document.querySelector('.form-reply .note-editable');
  if (!editor) { return false; }
  editor.focus();
  document.execCommand('insertText', false, ${JSON.stringify(text)});
  return true;
})()`);
const clearEditor = () => ev(`(() => { $('#body').summernote('code', ''); return true; })()`);
const agentFirst = one(`select first_name from users where email='${process.env.GLC_AGENT_EMAIL}'`);

await waitFor(`!!document.querySelector('.form-reply .note-editable')`, 15000);
check('the reply editor is there to type in', await typeInEditor(`Un răspuns în lucru ${RUN}`, false), true);
// Polled the way a bubble would, not faster: the visitor endpoints have a
// ceiling per address, and a test that hammers them tests the ceiling.
let sign = null;
for (let i = 0; i < 12 && !sign; i++) { sign = (await pollOnce(here)).typing; if (!sign) await sleep(1500); }
check("an agent writing a reply shows the visitor dots, with the agent's first name", sign, { name: agentFirst });
await clearEditor();
await sleep(8000);
check('  and they go once the agent stops', (await pollOnce(here)).typing, null);

// A note is for colleagues. A customer must never watch dots while somebody
// writes about them.
await typeInEditor(`O notiță internă ${RUN}`, true);
let noteSign = null;
for (let i = 0; i < 6 && !noteSign; i++) { noteSign = (await pollOnce(here)).typing; if (!noteSign) await sleep(1500); }
check('writing a note shows the visitor nothing', noteSign, null);
await clearEditor();
await ev(`(() => { const f = document.querySelector(".form-reply input[name='is_note']"); if (f) { f.value = ''; } window.onbeforeunload = null; return true; })()`);

// ------------------------------------------------------------------- the bell
await sleep(20000);  // notifications go through the queue
check('customer chat messages added nothing under the bell', one(`select count(*) from notifications`), bellBefore);

// ------------------------------------------------ a chat reply stays on the page
// Reported on 2026-09-10: sending from the agent's console reloaded the whole
// conversation, disabling the editor on the way. Core sends on Enter only in
// chat mode, so the chat is opened in it on purpose.
//
// The note written above is remembered by core in the browser, and its draft
// in the database; either would come back into the editor, the note in note
// mode, where Enter sends nothing. Start from an empty reply.
await ev(`(() => { if (typeof forgetNote === 'function') { forgetNote(${Number(here.conv)}); } return true; })()`);
sql(`delete from threads where conversation_id=${here.conv} and state=1`);
await open(`/conversation/${here.conv}?chat_mode=1`);
await waitFor(`!!document.querySelector('.form-reply .note-editable')`, 15000);
await sleep(1000);
check('the chat is open in chat mode, with an empty reply',
  await ev(`document.body.classList.contains('chat-mode') && !$(".form-reply:first :input[name='is_note']").val() && !$('#body').val()`), true);
await ev(`window.__gesoftStay = 'still here'; true`);
const sendState = () => ev(`({
  focus: (document.activeElement || {}).className || '',
  body: ($('#body').val() || '').slice(0, 60),
  sending: window.fs_processing_send_reply,
  savingDraft: window.fs_processing_save_draft,
  modal: $('.modal:visible').length,
  button: $('div.conv-block:not(.conv-note-block) div.conv-reply-body:visible .btn-reply-submit:first').length,
})`);
const pressEnterIn = async (text) => {
  await typeInEditor(text, false);
  await sleep(500);
  console.log('        before Enter: ' + JSON.stringify(await sendState()));
  await S('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 });
  await S('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 });
};
const agentReplies = () => Number(one(`select count(*) from threads where conversation_id=${here.conv} and type=2 and state=2`));
const waitForReplies = async (n) => {
  for (let i = 0; i < 20; i++) { if (agentReplies() >= n) return true; await sleep(750); }
  return false;
};
const onScreen = (text) => `[...document.querySelectorAll('#conv-layout-main .thread')].some(t => t.innerText.includes(${JSON.stringify(text)}))`;
const repliesBefore = agentReplies();

await pressEnterIn(`Primul răspuns ${RUN}`);
check('an agent reply in a chat is sent', await waitForReplies(repliesBefore + 1), true);
check('  and appears in the conversation', await waitFor(onScreen(`Primul răspuns ${RUN}`), 10000), true);
check('  without reloading the page', await ev(`window.__gesoftStay || null`), 'still here');
check('  leaving the editor empty and usable',
  await waitFor(`(() => { const e = document.querySelector('.form-reply .note-editable'); return !!e && e.isContentEditable && e.innerText.trim() === ''; })()`, 5000), true);
check('  and no draft id behind', await ev(`document.querySelector(".form-reply input[name='thread_id']").value`), '');

await pressEnterIn(`Al doilea răspuns ${RUN}`);
check('a second reply straight after is sent too', await waitForReplies(repliesBefore + 2), true);
check('  and appears above the first', await waitFor(`(() => {
  const texts = [...document.querySelectorAll('#conv-layout-main .thread')].map(t => t.innerText);
  const second = texts.findIndex(t => t.includes(${JSON.stringify(`Al doilea răspuns ${RUN}`)}));
  const first = texts.findIndex(t => t.includes(${JSON.stringify(`Primul răspuns ${RUN}`)}));
  return second !== -1 && first !== -1 && second < first;
})()`, 10000), true);
check('  still without a reload', await ev(`window.__gesoftStay || null`), 'still here');
check('the status shown is the one the server set',
  await waitFor(`convGetStatus() === ${Number(one(`select status from conversations where id=${here.conv}`))}`, 5000), true);
const visitorSees = (await pollOnce(here)).messages.map((m) => m.body);
check('the visitor receives both replies',
  visitorSees.includes(`Primul răspuns ${RUN}`) && visitorSees.includes(`Al doilea răspuns ${RUN}`), true);

// ------------------------------------------------------------ blocking a visitor
const blockedEmail = `e2e-op-blocat-${RUN}@gesoft.test`;
const blocked = await visitor(`E2E de blocat ${RUN}`, `Deranjez ${RUN}`, blockedEmail);
try {
  await open(`/conversation/${blocked.conv}?chat_mode=1`);
  await ev(`document.querySelector('.gesoft-chat-block-open').click(); true`);
  check('"Block visitor…" opens the dialog', await waitFor(`!!document.querySelector('#gesoft-chat-block-modal.in')`, 5000), true);
  check('  offering four durations', await ev(`document.querySelectorAll('#gesoft-chat-block-modal select[name=days] option').length`), 4);
  // The address is this test machine's own; block the email only.
  await ev(`(() => {
    const m = document.querySelector('#gesoft-chat-block-modal');
    m.querySelector('input[name=block_ip]').checked = false;
    m.querySelector('input[name=reason]').value = 'e2e ${RUN}';
    m.querySelector('.gesoft-block-submit').click();
    return true;
  })()`);
  await sleep(2500);
  await waitFor(`document.readyState === 'complete' && !document.querySelector('#gesoft-chat-block-modal.in')`, 15000);
  const blockLine = await ev(`[...document.querySelectorAll('.thread-type-lineitem .thread-title')].map(e => e.innerText.trim()).join(' | ')`);
  check('the conversation shows who blocked the visitor', /blocked this visitor|a blocat acest vizitator/.test(blockLine), true);
  check('  signed by the agent, not the customer', !blockLine.includes(`E2E de blocat ${RUN} blocked`) && !blockLine.includes(`E2E de blocat ${RUN} a blocat`), true);
  check("the visitor's chat ended at once", (await pollOnce(blocked)).closed, true);

  await open('/gesoft-live-chat/blocks');
  check('the blocked visitors page lists the email', await ev(`document.body.innerText.includes(${JSON.stringify(blockedEmail)})`), true);
  check('  with the reason', await ev(`document.body.innerText.includes('e2e ${RUN}')`), true);
  const rowsBefore = await ev(`document.querySelectorAll('table.gesoft-blocks tbody tr').length`);
  await ev(`(() => {
    const row = [...document.querySelectorAll('table.gesoft-blocks tbody tr')].find(r => r.innerText.includes(${JSON.stringify(blockedEmail)}));
    row.querySelector('button[type=submit]').click();
    return true;
  })()`);
  await sleep(1500);
  await waitFor(`document.readyState === 'complete'`, 15000);
  check('Unblock removes it from the page', await ev(`!document.body.innerText.includes(${JSON.stringify(blockedEmail)})`), true);
  check('  and from the list', await ev(`document.querySelectorAll('table.gesoft-blocks tbody tr').length`), Math.max(rowsBefore - 1, 0));
} finally {
  sql(`delete from gesoft_live_chat_blocks where value = '${blockedEmail}' or reason = 'e2e ${RUN}'`);
}

check('no script errors from the module', moduleErrors, []);

for (const conv of new Set(opened)) sql(`update conversations set status=3, closed_at=now() where id=${conv}`);
await send('Target.closeTarget', { targetId });

console.log(`\n${pass} passed, ${fail} failed  (run ${RUN})`);
process.exit(fail ? 1 : 0);
