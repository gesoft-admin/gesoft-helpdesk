// SPDX-License-Identifier: AGPL-3.0-only
// Gesoft integration, 2026-11-08; file-transfer leg 2026-11-10. The encrypted
// desktop and file payloads stay opaque.
import http from 'node:http';
import { WebSocket, WebSocketServer } from './upstream/node_modules/ws/wrapper.mjs';
import { RendezvousMessage, PunchHoleRequest, RequestRelay, ConnType, NatType } from './upstream/src/gen/rendezvous.ts';

export { WebSocket, WebSocketServer, RendezvousMessage };
const PATH = /^\/gesoft-viewer\/ws\/([a-f0-9]{48})\/(id|relay)$/;
const LIMIT = 8 * 1024 * 1024;
const QUEUE_BYTES = 1024 * 1024;
const QUEUE_MESSAGES = 256;
// Backpressure marks for a destination's unsent bytes; see send() below.
const HIGH_WATER = 4 * 1024 * 1024;
const LOW_WATER = 1024 * 1024;
// File-transfer connections a pair may open (a pair lives as long as its
// desktop leg; a reconnect of the page starts a new one).
const FILE_OPENS = 20;
// How long a destination may stay above the low mark once its source is paused.
const STALL_MS = 60000;

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
  // A pair is one viewer page (one attempt). It has a desktop leg, opened
  // first, and at most one file-transfer leg at a time, opened only while the
  // desktop leg's relay is up and the grant allows files. Each leg is one
  // RustDesk connection: its own signaling and relay sockets and relay uuid.
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
  const audit = (pair, event, reason, extra = {}) => log({ event, reason, attempt: pair.attempt,
    actor: pair.auth.actor, session: pair.auth.session, peer: pair.auth.peer, ...extra });

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
        data.expires <= Date.now() / 1000 || typeof data.key !== 'string' || data.key.length > 128 ||
        (data.files !== undefined && typeof data.files !== 'boolean')) {
      throw new Error('invalid authorization');
    }
    // A helpdesk without the file-transfer change sends no field: no files.
    data.files = data.files === true;
    return data;
  }

  const sameGrant = (a, b) => a.actor === b.actor && a.session === b.session &&
    a.peer === b.peer && a.key === b.key;
  const isOpen = (channel) => channel && !channel.closed;

  function closeChannel(channel) {
    if (!channel || channel.closed) return;
    channel.closed = true;
    for (const timer of Object.values(channel.stalls)) clearTimeout(timer);
    for (const socket of [channel.client, channel.upstream]) {
      if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
        try { socket.close(1008); } catch { /* CONNECTING upstream: terminate below */ }
      }
      const timeout = setTimeout(() => socket.terminate(), 500);
      timeout.unref();
    }
  }

  function endLeg(pair, leg, reason, side) {
    if (leg.ended) return;
    leg.ended = true;
    clearTimeout(leg.deadline);
    closeChannel(leg.id);
    closeChannel(leg.relay);
    if (pair.legs[leg.name] === leg) pair.legs[leg.name] = null;
    if (leg.name === 'file') audit(pair, 'viewer.file.closed', reason, { side });
  }

  function end(pair, reason, side) {
    if (pair.ended) return;
    pair.ended = true;
    clearTimeout(pair.timer);
    pairs.delete(pair.attempt);
    for (const leg of Object.values(pair.legs)) if (leg) endLeg(pair, leg, reason, side);
    audit(pair, 'viewer.transport.closed', reason, side ? { side } : {});
  }

  // A failure on the file leg ends only that leg; one on the desktop leg, a
  // policy refusal on any leg, or the end of the grant ends the whole pair.
  function fail(pair, leg, reason, side) {
    if (leg.name === 'file') endLeg(pair, leg, reason, side);
    else end(pair, reason, side);
  }

  async function monitor(pair) {
    if (pair.ended) return;
    try {
      const current = await authorize(pair.attempt, pair.cookie);
      if (!sameGrant(current, pair.auth)) throw new Error('changed');
      pair.files = current.files;
      if (!current.files && pair.legs.file) endLeg(pair, pair.legs.file, 'file transfer no longer allowed');
    } catch {
      end(pair, 'authorization ended');
      return;
    }
    if (!pair.ended) pair.timer = setTimeout(() => monitor(pair), options.pollMs || 2000);
  }

  // Which leg a new socket of this kind would join, or null if it is refused.
  // A first signaling socket starts the desktop leg; a later one starts the
  // file leg; a relay socket joins the one leg that has a relay uuid and no
  // relay yet (the file leg can only start once the desktop relay is up, so
  // there is never more than one).
  function admit(pair, kind, auth) {
    if (kind === 'id') {
      if (!pair) return pairs.size < 100 ? 'desktop' : null;
      const desktop = pair.legs.desktop;
      if (pair.ended || !desktop || !isOpen(desktop.relay) || pair.legs.file) return null;
      if (!pair.files || (auth && !auth.files) || pair.fileOpens >= FILE_OPENS) return null;
      return 'file';
    }
    if (!pair || pair.ended) return null;
    for (const leg of Object.values(pair.legs)) {
      if (leg && !leg.ended && leg.uuid && !leg.relay) return leg.name;
    }
    return null;
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
    if (pending.size >= 32 || pending.has(reservation) || !admit(pairs.get(attempt), kind)) return deny();
    pending.add(reservation);
    let auth;
    try { auth = await authorize(attempt, request.headers.cookie); }
    catch { pending.delete(reservation); return deny(); }
    pending.delete(reservation);
    if (socket.destroyed) return;
    const existing = pairs.get(attempt);
    if (existing && !sameGrant(existing.auth, auth)) return deny();
    const legName = admit(existing, kind, auth);
    if (!legName) return deny();
    // The pair is registered only once the WebSocket upgrade has succeeded: ws
    // answers a malformed upgrade itself and never calls back, and a pair
    // registered before that would hold one of the 100 slots, re-authorizing,
    // until the grant expired.
    wss.handleUpgrade(request, socket, head, (client) => {
      let pair = pairs.get(attempt);
      if (pair !== existing || admit(pair, kind, auth) !== legName) {
        client.close(1008, 'attempt changed');
        return;
      }
      if (!pair) {
        pair = { attempt, auth, cookie: request.headers.cookie, files: auth.files,
          legs: { desktop: null, file: null }, fileOpens: 0, ended: false };
        pairs.set(attempt, pair);
        pair.timer = setTimeout(() => monitor(pair), options.pollMs || 2000);
      }
      let leg = pair.legs[legName];
      if (kind === 'id') {
        leg = { name: legName, uuid: '', id: null, relay: null, ended: false,
          connType: legName === 'desktop' ? ConnType.DEFAULT_CONN : ConnType.FILE_TRANSFER };
        pair.legs[legName] = leg;
        leg.deadline = setTimeout(() => fail(pair, leg, 'handshake timeout'), 20000);
        if (legName === 'file') {
          pair.fileOpens++;
          audit(pair, 'viewer.file.opened', 'file transfer');
        }
      }
      connect(pair, leg, kind, client);
    });
  });

  function connect(pair, leg, kind, client) {
    const upstream = new WebSocket(kind === 'id' ? options.idUrl : options.relayUrl,
      { maxPayload: LIMIT, perMessageDeflate: false, handshakeTimeout: 5000 });
    const channel = { client, upstream, first: true, queue: [], bytes: 0, closed: false, stalls: {} };
    leg[kind] = channel;
    audit(pair, 'viewer.transport.opened', kind, { leg: leg.name });

    // Backpressure: a destination that is not draining pauses its source,
    // which stops reading from that socket (TCP then slows the sender), and
    // the source resumes once the destination is below the low mark. The
    // close at LIMIT stays as the last bound.
    function send(source, destination, bytes, side) {
      if (pair.ended || leg.ended) return;
      if (destination.bufferedAmount > LIMIT) return fail(pair, leg, 'backpressure limit', side);
      if (destination.readyState !== WebSocket.OPEN) return fail(pair, leg, 'transport unavailable', side);
      destination.send(bytes, { binary: true }, (error) => {
        if (error) return fail(pair, leg, 'send failed', side);
        if (source.isPaused && destination.bufferedAmount < LOW_WATER) {
          clearTimeout(channel.stalls[side]);
          source.resume();
        }
      });
      if (!source.isPaused && destination.bufferedAmount > HIGH_WATER) {
        source.pause();
        // A destination that never drains (a browser that stopped reading, a
        // half-open connection) would hold the leg paused for as long as the
        // grant lasts.
        clearTimeout(channel.stalls[side]);
        channel.stalls[side] = setTimeout(() => fail(pair, leg, 'stalled', side), options.stallMs || STALL_MS);
      }
    }
    upstream.on('open', () => {
      for (const bytes of channel.queue) send(client, upstream, bytes, 'browser');
      channel.queue = [];
      channel.bytes = 0;
    });
    client.on('message', (input, binary) => {
      if (pair.ended || leg.ended) return;
      if (!binary) return end(pair, 'binary frames required', 'browser');
      let bytes = input;
      try {
        if (kind === 'id') {
          if (!channel.first || input.length > 65536) throw new Error('signal');
          const msg = strictRendezvous(input);
          if (msg?.$case !== 'punch_hole_request') throw new Error('signal type');
          const p = msg.punch_hole_request;
          if (p.id !== pair.auth.peer || p.conn_type !== leg.connType ||
              p.licence_key !== pair.auth.key || p.webrtc_sdp_offer || !p.force_relay) throw new Error('target');
          bytes = RendezvousMessage.encode({ union: { $case: 'punch_hole_request',
            punch_hole_request: PunchHoleRequest.fromPartial({ id: pair.auth.peer,
              licence_key: pair.auth.key, nat_type: NatType.SYMMETRIC, force_relay: true,
              version: p.version.slice(0, 64), conn_type: leg.connType }) } }).finish();
          channel.first = false;
        } else if (channel.first) {
          if (input.length > 65536) throw new Error('relay size');
          const msg = strictRendezvous(input);
          if (msg?.$case !== 'request_relay') throw new Error('relay type');
          const p = msg.request_relay;
          if (p.id !== pair.auth.peer || p.uuid !== leg.uuid ||
              p.conn_type !== leg.connType || p.licence_key !== pair.auth.key) throw new Error('relay target');
          bytes = RendezvousMessage.encode({ union: { $case: 'request_relay',
            request_relay: RequestRelay.fromPartial({ id: pair.auth.peer, uuid: leg.uuid,
              licence_key: pair.auth.key, conn_type: leg.connType }) } }).finish();
          channel.first = false;
          clearTimeout(leg.deadline);
        }
      } catch { return end(pair, 'protocol policy refused', 'browser'); }
      if (upstream.readyState === WebSocket.CONNECTING) {
        channel.bytes += bytes.length;
        if (channel.bytes > QUEUE_BYTES || channel.queue.length >= QUEUE_MESSAGES) return end(pair, 'queue limit', 'browser');
        channel.queue.push(bytes);
      } else send(client, upstream, bytes, 'browser');
    });
    upstream.on('message', (bytes, binary) => {
      if (pair.ended || leg.ended) return;
      if (!binary) return fail(pair, leg, 'invalid upstream frame', 'upstream');
      if (kind === 'id') {
        try {
          if (bytes.length > 65536) throw new Error('signal size');
          const msg = RendezvousMessage.decode(bytes).union;
          if (msg?.$case === 'relay_response') {
            const uuid = msg.relay_response.uuid;
            if (typeof uuid !== 'string' || !uuid || uuid.length > 128) throw new Error('relay id');
            if (leg.uuid && leg.uuid !== uuid) throw new Error('relay changed');
            if (Object.values(pair.legs).some((other) => other && other !== leg && other.uuid === uuid)) {
              throw new Error('relay id reused');
            }
            leg.uuid = uuid;
          }
        } catch { return fail(pair, leg, 'invalid signaling response', 'upstream'); }
      }
      send(upstream, client, bytes, 'upstream');
    });
    for (const [ws, side] of [[client, 'browser'], [upstream, 'upstream']]) {
      ws.on('error', () => fail(pair, leg, 'transport error', side));
      ws.on('close', () => {
        if (channel.closed) return;
        // The signaling socket may go once the relay is up; anything else
        // closing ends the leg (and, for the desktop leg, the pair).
        if (kind === 'relay' || !isOpen(leg.relay)) fail(pair, leg, 'disconnected', side);
        else closeChannel(channel);
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
