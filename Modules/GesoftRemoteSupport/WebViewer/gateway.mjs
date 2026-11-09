// SPDX-License-Identifier: AGPL-3.0-only
// Gesoft integration, 2026-11-08. The encrypted desktop payload stays opaque.
import http from 'node:http';
import { WebSocket, WebSocketServer } from './upstream/node_modules/ws/wrapper.mjs';
import { RendezvousMessage, PunchHoleRequest, RequestRelay, ConnType, NatType } from './upstream/src/gen/rendezvous.ts';

export { WebSocket, WebSocketServer, RendezvousMessage };
const PATH = /^\/gesoft-viewer\/ws\/([a-f0-9]{48})\/(id|relay)$/;
const LIMIT = 8 * 1024 * 1024;
const QUEUE_BYTES = 1024 * 1024;
const QUEUE_MESSAGES = 256;

// Policy checks only trust a message that re-encodes to exactly the bytes
// received: trailing data, stray terminators or unknown fields are refused.
function strictRendezvous(input) {
  const message = RendezvousMessage.decode(input);
  const canonical = RendezvousMessage.encode(message).finish();
  if (canonical.length !== input.length || Buffer.compare(Buffer.from(canonical), input) !== 0) {
    throw new Error('non-canonical message');
  }
  return message.union;
}

export function createGateway(options) {
  const pairs = new Map();
  const log = options.log || ((entry) => process.stdout.write(JSON.stringify(entry) + '\n'));
  const authUrl = new URL(options.authUrl);
  if (!['127.0.0.1', '[::1]'].includes(authUrl.hostname) || authUrl.protocol !== 'http:') {
    throw new Error('Authorization endpoint must be HTTP on loopback');
  }
  for (const address of [options.idUrl, options.relayUrl]) {
    const url = new URL(address);
    if (url.protocol !== 'ws:' || url.username || url.password) throw new Error('Invalid upstream');
  }
  if (new URL(options.origin).origin !== options.origin) throw new Error('An exact Origin is required');
  const audit = (pair, event, reason) => log({ event, reason, attempt: pair.attempt,
    actor: pair.auth.actor, session: pair.auth.session, peer: pair.auth.peer });

  async function authorize(attempt, cookie) {
    const response = await fetch(authUrl, {
      headers: { Cookie: cookie, Host: new URL(options.origin).host,
        // FreeScout's web middleware checks CSP-capable browser signatures,
        // including on this internal service endpoint. ACL/session checks
        // still run on every request.
        'User-Agent': 'Mozilla/5.0 Chrome/152.0.0.0 Safari/537.36 GesoftViewerGateway',
        Accept: 'application/json', 'X-Viewer-Attempt': attempt },
      redirect: 'manual', signal: AbortSignal.timeout(options.authTimeoutMs || 2500),
    });
    if (response.status !== 200) throw new Error('authorization refused');
    const data = await response.json();
    if (!/^\d{1,20}$/.test(data.peer) || !Number.isSafeInteger(data.actor) ||
        !Number.isSafeInteger(data.session) || !Number.isSafeInteger(data.expires) ||
        data.expires <= Date.now() / 1000 || typeof data.key !== 'string' || data.key.length > 128) {
      throw new Error('invalid authorization');
    }
    return data;
  }

  function end(pair, reason) {
    if (pair.ended) return;
    pair.ended = true;
    clearTimeout(pair.timer);
    pairs.delete(pair.attempt);
    for (const channel of Object.values(pair.channels)) {
      clearTimeout(channel.deadline);
      for (const socket of [channel.client, channel.upstream]) {
        if (socket.readyState === WebSocket.OPEN) socket.close(1008, reason);
        const timeout = setTimeout(() => socket.terminate(), 500);
        timeout.unref();
      }
    }
    audit(pair, 'viewer.transport.closed', reason);
  }

  async function monitor(pair) {
    if (pair.ended) return;
    try {
      const current = await authorize(pair.attempt, pair.cookie);
      if (current.actor !== pair.auth.actor || current.session !== pair.auth.session ||
          current.peer !== pair.auth.peer || current.key !== pair.auth.key) throw new Error('changed');
    } catch {
      end(pair, 'authorization ended');
      return;
    }
    if (!pair.ended) pair.timer = setTimeout(() => monitor(pair), options.pollMs || 2000);
  }

  const server = http.createServer((request, response) => {
    response.writeHead(request.url === '/health' ? 200 : 404, { 'Content-Type': 'text/plain' });
    response.end(request.url === '/health' ? 'ok\n' : 'not found\n');
  });
  const wss = new WebSocketServer({ noServer: true, maxPayload: LIMIT, perMessageDeflate: false });
  const pending = new Set();

  server.on('upgrade', async (request, socket, head) => {
    socket.on('error', () => socket.destroy());
    const match = PATH.exec(request.url || '');
    const deny = () => { socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); };
    if (!match || request.headers.origin !== options.origin || !request.headers.cookie ||
        request.headers.cookie.length > 8192) return deny();
    const [, attempt, kind] = match;
    const reservation = attempt + '/' + kind;
    if (pending.size >= 32 || pending.has(reservation) || pairs.get(attempt)?.channels[kind] || pairs.size >= 100) return deny();
    pending.add(reservation);
    let auth;
    try { auth = await authorize(attempt, request.headers.cookie); }
    catch { pending.delete(reservation); return deny(); }
    pending.delete(reservation);
    if (socket.destroyed) return;
    const existing = pairs.get(attempt);
    if (kind === 'relay' && (!existing || !existing.uuid || existing.ended)) return deny();
    if (existing && (existing.auth.actor !== auth.actor || existing.auth.session !== auth.session ||
        existing.auth.peer !== auth.peer || existing.auth.key !== auth.key)) return deny();
    // The pair is registered only once the WebSocket upgrade has succeeded: ws
    // answers a malformed upgrade itself and never calls back, and a pair
    // registered before that would hold one of the 100 slots, re-authorizing,
    // until the grant expired.
    wss.handleUpgrade(request, socket, head, (client) => {
      let pair = pairs.get(attempt);
      if (pair !== existing || pair?.ended || pair?.channels[kind] || (!pair && pairs.size >= 100)) {
        client.close(1008, 'attempt changed');
        return;
      }
      if (!pair) {
        pair = { attempt, auth, cookie: request.headers.cookie, channels: {}, uuid: '', ended: false };
        pairs.set(attempt, pair);
        pair.timer = setTimeout(() => monitor(pair), options.pollMs || 2000);
      }
      connect(pair, kind, client);
    });
  });

  function connect(pair, kind, client) {
    const upstream = new WebSocket(kind === 'id' ? options.idUrl : options.relayUrl,
      { maxPayload: LIMIT, perMessageDeflate: false, handshakeTimeout: 5000 });
    const channel = { client, upstream, first: true, queue: [], bytes: 0 };
    pair.channels[kind] = channel;
    channel.deadline = setTimeout(() => end(pair, 'handshake timeout'), 20000);
    audit(pair, 'viewer.transport.opened', kind);

    function send(destination, bytes) {
      if (pair.ended) return;
      if (destination.bufferedAmount > LIMIT) return end(pair, 'backpressure limit');
      if (destination.readyState !== WebSocket.OPEN) return end(pair, 'transport unavailable');
      destination.send(bytes, { binary: true }, (error) => { if (error) end(pair, 'send failed'); });
    }
    upstream.on('open', () => {
      for (const bytes of channel.queue) send(upstream, bytes);
      channel.queue = [];
      channel.bytes = 0;
    });
    client.on('message', (input, binary) => {
      if (pair.ended) return;
      if (!binary) return end(pair, 'binary frames required');
      let bytes = input;
      try {
        if (kind === 'id') {
          if (!channel.first || input.length > 65536) throw new Error('signal');
          const msg = strictRendezvous(input);
          if (msg?.$case !== 'punch_hole_request') throw new Error('signal type');
          const p = msg.punch_hole_request;
          if (p.id !== pair.auth.peer || p.conn_type !== ConnType.DEFAULT_CONN ||
              p.licence_key !== pair.auth.key || p.webrtc_sdp_offer || !p.force_relay) throw new Error('target');
          bytes = RendezvousMessage.encode({ union: { $case: 'punch_hole_request',
            punch_hole_request: PunchHoleRequest.fromPartial({ id: pair.auth.peer,
              licence_key: pair.auth.key, nat_type: NatType.SYMMETRIC, force_relay: true,
              version: p.version.slice(0, 64), conn_type: ConnType.DEFAULT_CONN }) } }).finish();
          channel.first = false;
        } else if (channel.first) {
          if (input.length > 65536) throw new Error('relay size');
          const msg = strictRendezvous(input);
          if (msg?.$case !== 'request_relay') throw new Error('relay type');
          const p = msg.request_relay;
          if (p.id !== pair.auth.peer || p.uuid !== pair.uuid ||
              p.conn_type !== ConnType.DEFAULT_CONN || p.licence_key !== pair.auth.key) throw new Error('relay target');
          bytes = RendezvousMessage.encode({ union: { $case: 'request_relay',
            request_relay: RequestRelay.fromPartial({ id: pair.auth.peer, uuid: pair.uuid,
              licence_key: pair.auth.key, conn_type: ConnType.DEFAULT_CONN }) } }).finish();
          channel.first = false;
          clearTimeout(channel.deadline);
        }
      } catch { return end(pair, 'protocol policy refused'); }
      if (upstream.readyState === WebSocket.CONNECTING) {
        channel.bytes += bytes.length;
        if (channel.bytes > QUEUE_BYTES || channel.queue.length >= QUEUE_MESSAGES) return end(pair, 'queue limit');
        channel.queue.push(bytes);
      } else send(upstream, bytes);
    });
    upstream.on('message', (bytes, binary) => {
      if (pair.ended) return;
      if (!binary) return end(pair, 'invalid upstream frame');
      if (kind === 'id') {
        try {
          if (bytes.length > 65536) throw new Error('signal size');
          const msg = RendezvousMessage.decode(bytes).union;
          if (msg?.$case === 'relay_response') {
            const uuid = msg.relay_response.uuid;
            if (typeof uuid !== 'string' || !uuid || uuid.length > 128) throw new Error('relay id');
            if (pair.uuid && pair.uuid !== uuid) throw new Error('relay changed');
            pair.uuid = uuid;
            clearTimeout(channel.deadline);
          }
        } catch { return end(pair, 'invalid signaling response'); }
      }
      send(client, bytes);
    });
    for (const ws of [client, upstream]) {
      ws.on('error', () => end(pair, 'transport error'));
      ws.on('close', () => {
        if (kind === 'relay' || !pair.channels.relay) end(pair, 'disconnected');
        else {
          clearTimeout(channel.deadline);
          if (client.readyState === WebSocket.OPEN) client.close();
          if (upstream.readyState === WebSocket.OPEN) upstream.close();
        }
      });
    }
  }
  return {
    server,
    pairCount: () => pairs.size,
    close: async () => {
      for (const pair of pairs.values()) end(pair, 'gateway stopping');
      await new Promise((resolve) => wss.close(resolve));
      await new Promise((resolve) => server.close(resolve));
    },
  };
}

if (typeof require !== 'undefined' && require.main === module) {
  const gateway = createGateway({ origin: process.env.VIEWER_ORIGIN,
    authUrl: process.env.VIEWER_AUTH_URL, idUrl: process.env.VIEWER_ID_URL,
    relayUrl: process.env.VIEWER_RELAY_URL });
  gateway.server.listen(Number(process.env.VIEWER_PORT || 18089), '127.0.0.1');
  for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => gateway.close().then(() => process.exit(0)));
}
