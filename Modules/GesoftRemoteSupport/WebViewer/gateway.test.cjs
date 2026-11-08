// SPDX-License-Identifier: AGPL-3.0-only
const { test } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { once } = require('node:events');
const { createGateway, WebSocket, WebSocketServer, RendezvousMessage } = require('./build/gateway.cjs');
const ATTEMPT = 'a'.repeat(48);
const ORIGIN = 'https://support.test';
const KEY = Buffer.alloc(32, 7).toString('base64');
const encode = (name, fields) => RendezvousMessage.encode(RendezvousMessage.fromPartial({
    union: { $case: name, [name]: fields },
})).finish();
const punch = (extra = {}) => encode('punch_hole_request', {
    id: '12345678', licence_key: KEY, force_relay: true, conn_type: 0, version: '1.5.0', ...extra,
});
const relay = (extra = {}) => encode('request_relay', {
    id: '12345678', licence_key: KEY, uuid: 'authorized-uuid', conn_type: 0, ...extra,
});
async function fixture(t) {
    const state = { revoked: false, fail: false, idMessages: [], relayMessages: [], audit: [] };
    const auth = http.createServer((req, res) => {
        if (state.fail) { res.writeHead(503); return res.end(); }
        if (state.revoked || req.headers.cookie !== 'session=good' || req.headers['x-viewer-attempt'] !== ATTEMPT) {
            res.writeHead(403); return res.end();
        }
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ peer: '12345678', actor: 4, session: 9, key: KEY,
            expires: Math.floor(Date.now() / 1000) + 60 }));
    });
    const ids = new WebSocketServer({ port: 0, host: '127.0.0.1' });
    const relays = new WebSocketServer({ port: 0, host: '127.0.0.1' });
    await Promise.all([once(ids, 'listening'), once(relays, 'listening'),
        new Promise((r) => auth.listen(0, '127.0.0.1', r))]);
    ids.on('connection', (ws) => ws.on('message', (bytes) => {
        state.idMessages.push(bytes);
        ws.send(encode('relay_response', { uuid: 'authorized-uuid', relay_server: 'not-trusted.example:21117' }));
    }));
    relays.on('connection', (ws) => ws.on('message', (bytes) => {
        state.relayMessages.push(bytes);
        if (state.relayMessages.length > 1) ws.send(bytes);
    }));
    const gateway = createGateway({ origin: ORIGIN, pollMs: 40, authTimeoutMs: 200,
        authUrl: `http://127.0.0.1:${auth.address().port}/authorize`,
        idUrl: `ws://127.0.0.1:${ids.address().port}`,
        relayUrl: `ws://127.0.0.1:${relays.address().port}`, log: (e) => state.audit.push(e) });
    await new Promise((r) => gateway.server.listen(0, '127.0.0.1', r));
    const sockets = [];
    state.open = async (kind, options = {}) => {
        const ws = new WebSocket(`ws://127.0.0.1:${gateway.server.address().port}/gesoft-viewer/ws/${options.attempt || ATTEMPT}/${kind}`, {
            origin: options.origin || ORIGIN, headers: { Cookie: options.cookie || 'session=good' },
        });
        sockets.push(ws);
        await once(ws, 'open');
        return ws;
    };
    state.pair = async () => {
        const id = await state.open('id');
        const response = once(id, 'message');
        id.send(punch()); await response;
        const data = await state.open('relay');
        data.send(relay());
        return { id, data };
    };
    t.after(async () => {
        for (const ws of sockets) { ws.on('error', () => {}); ws.terminate(); }
        await gateway.close();
        for (const wss of [ids, relays]) {
            for (const ws of wss.clients) ws.terminate();
            await new Promise((r) => wss.close(r));
        }
        await new Promise((r) => auth.close(r));
    });
    return state;
}

test('OWASP: refused session cookie cannot open WSS upstream', async (t) => {
    const f = await fixture(t);
    await assert.rejects(f.open('id', { cookie: 'session=other' }), /403/);
    assert.equal(f.idMessages.length, 0);
});
test('OWASP: foreign Origin is refused even with valid login', async (t) => {
    const f = await fixture(t);
    await assert.rejects(f.open('id', { origin: 'https://attacker.test' }), /403/);
});
test('OWASP: unknown attempt is refused', async (t) => {
    const f = await fixture(t);
    await assert.rejects(f.open('id', { attempt: 'b'.repeat(48) }), /403/);
});
test('OWASP: relay cannot be opened without the authorized rendezvous', async (t) => {
    const f = await fixture(t);
    await assert.rejects(f.open('relay'), /403/);
});
for (const [name, data] of [
    ['another target', () => punch({ id: '99999999' })],
    ['another connection mode', () => punch({ conn_type: 1 })],
    ['a WebRTC bypass', () => punch({ webrtc_sdp_offer: 'webrtc://untrusted' })],
    ['registration instead of connection', () => encode('register_peer', { id: '12345678' })],
    ['malformed protobuf', () => Buffer.from([0xff])],
]) test(`OWASP: refuses ${name} before forwarding`, async (t) => {
    const f = await fixture(t); const ws = await f.open('id');
    const closed = once(ws, 'close'); ws.send(data()); await closed;
    assert.equal(f.idMessages.length, 0);
});
test('OWASP: relay UUID cannot be exchanged for another session', async (t) => {
    const f = await fixture(t); const id = await f.open('id');
    const response = once(id, 'message'); id.send(punch()); await response;
    const ws = await f.open('relay'); const closed = once(ws, 'close');
    ws.send(relay({ uuid: 'other-uuid' })); await closed;
    assert.equal(f.relayMessages.length, 0);
});
test('authorized relay carries opaque payload without crypto termination', async (t) => {
    const f = await fixture(t); const { data } = await f.pair();
    const reply = once(data, 'message'); data.send(Buffer.from([3, 4, 5, 6]));
    assert.deepEqual((await reply)[0], Buffer.from([3, 4, 5, 6]));
    const first = RendezvousMessage.decode(f.idMessages[0]).union.punch_hole_request;
    assert.equal(first.id, '12345678'); assert.equal(first.webrtc_sdp_offer, '');
});
for (const scenario of ['revoked', 'fail']) test(`OWASP: ${scenario} authorization cuts existing channels`, async (t) => {
    const f = await fixture(t); const { data } = await f.pair();
    const closed = once(data, 'close'); f[scenario] = true; await closed;
    assert.ok(f.audit.some((e) => e.reason === 'authorization ended'));
});
test('OWASP: an attempt cannot acquire a second signaling connection', async (t) => {
    const f = await fixture(t); await f.open('id');
    await assert.rejects(f.open('id'), /403/);
});
