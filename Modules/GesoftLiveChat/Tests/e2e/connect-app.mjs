// A whole application, as small as one can be, that connects itself to the
// helpdesk with a key and vouches for its users with signed assertions.
//
// It is the stand-in `connect-browser.mjs` drives, and it is meant to be read:
// everything an application on any platform has to do is in here, and nothing
// else is. Four pieces —
//
//   1. a settings page whose button opens the helpdesk's connect popup and
//      answers it with a name and a public key;
//   2. a key pair made on the server, the private half never sent anywhere;
//   3. a page that frames `/chat/embed` and answers its READY with AUTH;
//   4. an endpoint that signs a short JWT (EdDSA) for the signed-in user.
//
// A real application takes the user from its own session; this one takes it
// from `?user=`, because it is a test and has no sign-in of its own. That is
// the one shortcut, and it is the one a real application must not take.

import http from 'node:http';
import crypto from 'node:crypto';

const b64u = (buf) => Buffer.from(buf).toString('base64url');

export function startApp({ helpdesk, name }) {
  const HELPDESK = helpdesk.replace(/\/$/, '');
  const HELPDESK_ORIGIN = new URL(HELPDESK).origin;

  // What the application keeps once connected — in a real one, its own
  // settings table. The private key is the secret; the rest is not.
  const state = {
    privateKey: null,
    provider: null,
    audience: null,
    pending: null, // a connection that has been asked for and not yet approved
  };

  function assertion(user, extra = {}) {
    const now = Math.floor(Date.now() / 1000);
    const claims = Object.assign({
      iss: state.provider,
      sub: String(user),
      aud: state.audience,
      iat: now,
      exp: now + 60,
      jti: b64u(crypto.randomBytes(16)),
      name: `Utilizator ${user}`,
      email: `${user}@connect.test`,
    }, extra);
    const input = b64u(JSON.stringify({ alg: 'EdDSA', typ: 'JWT' })) + '.' + b64u(JSON.stringify(claims));

    return input + '.' + b64u(crypto.sign(null, Buffer.from(input), state.privateKey));
  }

  const settingsPage = () => `<!doctype html><meta charset="utf-8"><title>Setări</title>
<h1>Setări</h1>
<p>Helpdesk: <span id="provider">${state.provider || 'neconectat'}</span></p>
<button id="connect">Conectează la helpdesk</button>
<p id="status"></p>
<script>
const HELPDESK = ${JSON.stringify(HELPDESK)}, HELPDESK_ORIGIN = ${JSON.stringify(HELPDESK_ORIGIN)};
document.getElementById('connect').onclick = () => {
  // Opened first, inside the click: a popup opened after an await is blocked.
  const popup = window.open(HELPDESK + '/gesoft-live-chat/apps/connect', 'helpdesk-connect', 'popup,width=640,height=720');
  const started = fetch('/api/connect/start' + location.search, { method: 'POST' }).then((r) => r.json());
  const status = document.getElementById('status');
  status.textContent = 'waiting';

  window.addEventListener('message', async function on(e) {
    if (e.origin !== HELPDESK_ORIGIN || e.source !== popup || !e.data || typeof e.data.type !== 'string') return;
    const request = await started;
    if (e.data.type === 'gesoft-helpdesk/connect:ready') {
      popup.postMessage({ type: 'gesoft-helpdesk/connect:request', name: request.name,
        public_key: request.public_key, state: request.state, provider: request.provider }, HELPDESK_ORIGIN);
    } else if (e.data.type === 'gesoft-helpdesk/connect:done' && e.data.state === request.state) {
      window.removeEventListener('message', on);
      const r = await fetch('/api/connect/finish', { method: 'POST', body: JSON.stringify(e.data) }).then((r) => r.json());
      status.textContent = r.ok ? 'connected' : 'refused';
      document.getElementById('provider').textContent = r.provider || '';
    } else if (e.data.type === 'gesoft-helpdesk/connect:cancelled' && e.data.state === request.state) {
      window.removeEventListener('message', on);
      status.textContent = 'cancelled';
    }
  });
};
</script>`;

  const chatPage = (user) => `<!doctype html><meta charset="utf-8"><title>Aplicație</title>
<style>body{margin:0}iframe{position:fixed;right:16px;bottom:16px;width:400px;height:600px;border:0}</style>
<p>Aplicația, ca ${user}</p>
<iframe id="support" src="${HELPDESK}/chat/embed?o=${encodeURIComponent(location_origin())}&lang=ro"></iframe>
<script>
const HELPDESK_ORIGIN = ${JSON.stringify(HELPDESK_ORIGIN)};
const frame = document.getElementById('support');
window.addEventListener('message', async (e) => {
  if (e.origin !== HELPDESK_ORIGIN || e.source !== frame.contentWindow || !e.data) return;
  if (e.data.type === 'READY') {
    // Asked for afresh every time: an assertion is good once and for a minute.
    const r = await fetch('/api/assertion?user=${encodeURIComponent(user)}').then((r) => r.json());
    frame.contentWindow.postMessage({ type: 'AUTH', value: r.assertion }, HELPDESK_ORIGIN);
    frame.contentWindow.postMessage({ type: 'OPEN' }, HELPDESK_ORIGIN);
  }
});
</script>`;

  let origin = '';
  const location_origin = () => origin;

  const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, origin);
    const json = (code, body) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
    const html = (body) => { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(body); };
    const body = async () => { let b = ''; for await (const c of req) b += c; return b ? JSON.parse(b) : {}; };

    if (req.method === 'GET' && url.pathname === '/settings') return html(settingsPage());
    if (req.method === 'GET' && url.pathname === '/app') return html(chatPage(url.searchParams.get('user') || 'u1'));

    if (req.method === 'POST' && url.pathname === '/api/connect/start') {
      // A new key pair for every attempt; it replaces the old one only once
      // the helpdesk has approved it, so a cancelled attempt breaks nothing.
      const { publicKey, privateKey } = crypto.generateKeyPairSync('ed25519');
      state.pending = { privateKey, state: b64u(crypto.randomBytes(16)) };

      return json(200, {
        name,
        public_key: publicKey.export({ format: 'jwk' }).x,
        state: state.pending.state,
        // Reconnecting keeps the provider, so this application's users keep
        // their conversations.
        provider: url.searchParams.get('reconnect') ? state.provider : undefined,
      });
    }

    if (req.method === 'POST' && url.pathname === '/api/connect/finish') {
      const done = await body();
      if (!state.pending || done.state !== state.pending.state || typeof done.provider !== 'string') return json(400, { ok: false });
      state.privateKey = state.pending.privateKey;
      state.provider = done.provider;
      state.audience = done.audience;
      state.pending = null;
      return json(200, { ok: true, provider: state.provider });
    }

    if (req.method === 'GET' && url.pathname === '/api/assertion') {
      if (!state.privateKey) return json(409, { error: 'not connected' });
      return json(200, { assertion: assertion(url.searchParams.get('user') || 'u1') });
    }

    res.writeHead(404); res.end();
  });

  return new Promise((ok) => server.listen(0, '127.0.0.1', () => {
    origin = `http://127.0.0.1:${server.address().port}`;
    ok({ origin, state, assertion, close: () => server.close() });
  }));
}
