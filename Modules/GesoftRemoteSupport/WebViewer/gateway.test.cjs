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
async function fixture(t, options = {}) {
    const state = { revoked: false, fail: false, files: options.files === true, idMessages: [], relayMessages: [], audit: [] };
    const auth = http.createServer((req, res) => {
        if (state.fail) { res.writeHead(503); return res.end(); }
        if (state.revoked || req.headers.cookie !== 'session=good' || req.headers['x-viewer-attempt'] !== ATTEMPT) {
            res.writeHead(403); return res.end();
        }
        res.setHeader('Content-Type', 'application/json');
        res.end(JSON.stringify({ peer: '12345678', actor: 4, session: 9, key: KEY,
            expires: Math.floor(Date.now() / 1000) + 60, ...(options.noFilesField ? {} : { files: state.files }) }));
    });
    const ids = new WebSocketServer({ port: 0, host: '127.0.0.1' });
    // A relay that accepts TCP but never answers the upgrade keeps the
    // gateway's upstream in CONNECTING, so client frames queue.
    const relays = options.stallRelay
        ? Object.assign(require('node:net').createServer((socket) => { stalled.push(socket); }).listen(0, '127.0.0.1'), { clients: new Set() })
        : new WebSocketServer({ port: 0, host: '127.0.0.1' });
    const stalled = [];
    await Promise.all([once(ids, 'listening'), once(relays, 'listening'),
        new Promise((r) => auth.listen(0, '127.0.0.1', r))]);
    ids.on('connection', (ws) => ws.on('message', (bytes) => {
        state.idMessages.push(bytes);
        const file = RendezvousMessage.decode(bytes).union?.punch_hole_request?.conn_type === 1;
        ws.send(encode('relay_response', { uuid: options.uuid || (file ? 'file-uuid' : 'authorized-uuid'),
            relay_server: 'not-trusted.example:21117' }));
    }));
    // Each relay connection echoes everything after its own RequestRelay.
    if (!options.stallRelay) relays.on('connection', (ws) => { let seen = 0; ws.on('message', (bytes) => {
        state.relayMessages.push(bytes); seen++;
        if (bytes.toString() === 'FLOOD') {
            for (let i = 0; i < 24; i++) ws.send(Buffer.alloc(1024 * 1024, i));
        } else if (seen > 1) ws.send(bytes);
    }); });
    const gateway = createGateway({ origin: ORIGIN, pollMs: 40, authTimeoutMs: 200,
        authUrl: `http://127.0.0.1:${auth.address().port}/authorize`,
        idUrl: `ws://127.0.0.1:${ids.address().port}`,
        relayUrl: `ws://127.0.0.1:${relays.address().port}`, log: (e) => state.audit.push(e) });
    await new Promise((r) => gateway.server.listen(0, '127.0.0.1', r));
    state.gateway = gateway;
    const sockets = [];
    state.open = async (kind, options = {}) => {
        const ws = new WebSocket(`ws://127.0.0.1:${gateway.server.address().port}/gesoft-viewer/ws/${options.attempt || ATTEMPT}/${kind}`, {
            origin: options.origin || ORIGIN, headers: { Cookie: options.cookie || 'session=good' },
        });
        sockets.push(ws);
        await once(ws, 'open');
        return ws;
    };
    state.filePair = async () => {
        const id = await state.open('id');
        const response = once(id, 'message');
        id.send(punch({ conn_type: 1 })); await response;
        const data = await state.open('relay');
        data.send(relay({ uuid: 'file-uuid', conn_type: 1 }));
        return { id, data };
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
        for (const socket of stalled) socket.destroy();
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
    ['a valid request with a trailing zero tag', () => Buffer.concat([punch(), Buffer.from([0, 255])])],
    ['a valid request with a trailing end-group tag', () => Buffer.concat([punch(), Buffer.from([4, 255])])],
    ['a valid request with an unknown field', () => Buffer.concat([punch(), Buffer.from([0xf8, 0x3f, 1])])],
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
test('OWASP: relay request with trailing data is refused', async (t) => {
    const f = await fixture(t); const id = await f.open('id');
    const response = once(id, 'message'); id.send(punch()); await response;
    const ws = await f.open('relay'); const closed = once(ws, 'close');
    ws.send(Buffer.concat([relay(), Buffer.from([0, 255])])); await closed;
    assert.equal(f.relayMessages.length, 0);
});
test('OWASP: pre-open queue is bounded by message count, not only bytes', async (t) => {
    const f = await fixture(t, { stallRelay: true }); const id = await f.open('id');
    const response = once(id, 'message'); id.send(punch()); await response;
    const ws = await f.open('relay'); const closed = once(ws, 'close');
    ws.send(relay());
    for (let i = 0; i < 300; i++) ws.send(Buffer.alloc(0));
    await closed;
    assert.ok(f.audit.some((e) => e.reason === 'queue limit'));
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
test('OWASP: an authorized but malformed upgrade holds no pair slot', async (t) => {
    const f = await fixture(t);
    // Valid Origin, cookie and attempt, but no Sec-WebSocket-Key: ws refuses
    // the upgrade itself (400) after authorization has passed.
    const req = http.request({ port: f.gateway.server.address().port, host: '127.0.0.1',
        path: `/gesoft-viewer/ws/${ATTEMPT}/id`, headers: { Connection: 'Upgrade', Upgrade: 'websocket',
            'Sec-WebSocket-Version': '13', Origin: ORIGIN, Cookie: 'session=good' } });
    req.end();
    const [res] = await once(req, 'response'); res.resume();
    assert.equal(res.statusCode, 400);
    assert.equal(f.gateway.pairCount(), 0);
    const ws = await f.open('id');
    assert.equal(f.gateway.pairCount(), 1);
    ws.close();
});

// File transfer: a second RustDesk connection (FILE_TRANSFER) of the same
// viewer page, only while the desktop relay is up and the grant allows files.
const echo = async (ws, bytes) => {
    const reply = once(ws, 'message'); ws.send(bytes);
    return (await reply)[0];
};
const until = async (condition) => {
    for (let i = 0; i < 100 && !condition(); i++) await new Promise((r) => setTimeout(r, 20));
    assert.ok(condition());
};
test('files: refused when the grant does not allow them', async (t) => {
    const f = await fixture(t); await f.pair();
    await assert.rejects(f.open('id'), /403/);
});
test('files: refused by a helpdesk that sends no files field', async (t) => {
    const f = await fixture(t, { noFilesField: true }); await f.pair();
    await assert.rejects(f.open('id'), /403/);
});
test('files: refused before the desktop relay is up', async (t) => {
    const f = await fixture(t, { files: true }); const id = await f.open('id');
    const response = once(id, 'message'); id.send(punch()); await response;
    await assert.rejects(f.open('id'), /403/);
});
test('files: a file-transfer connection runs beside the desktop', async (t) => {
    const f = await fixture(t, { files: true }); const desktop = await f.pair();
    await echo(desktop.data, Buffer.from([1]));
    const file = await f.filePair();
    assert.deepEqual(await echo(file.data, Buffer.from([7, 7])), Buffer.from([7, 7]));
    assert.deepEqual(await echo(desktop.data, Buffer.from([2])), Buffer.from([2]));
    const forwarded = RendezvousMessage.decode(f.idMessages[1]).union.punch_hole_request;
    assert.equal(forwarded.conn_type, 1);
    const requests = f.relayMessages.map((m) => {
        try { return RendezvousMessage.decode(m).union?.request_relay; } catch { return undefined; }
    }).filter((r) => r?.uuid === 'file-uuid');
    assert.equal(requests.length, 1); assert.equal(requests[0].conn_type, 1);
    assert.ok(f.audit.some((e) => e.event === 'viewer.file.opened'));
});
test('files: only one file-transfer connection at a time', async (t) => {
    const f = await fixture(t, { files: true }); await f.pair(); await f.filePair();
    await assert.rejects(f.open('id'), /403/);
});
for (const [name, mode] of [['port forward', 2], ['RDP', 3], ['camera', 4], ['terminal', 5]]) {
    test(`files: a ${name} connection is refused and ends the page's transport`, async (t) => {
        const f = await fixture(t, { files: true }); const desktop = await f.pair();
        await echo(desktop.data, Buffer.from([1]));
        const id = await f.open('id'); const closed = once(desktop.data, 'close');
        id.send(punch({ conn_type: mode })); await closed;
        assert.equal(f.idMessages.length, 1);
    });
}
test('files: the file relay cannot use the desktop uuid', async (t) => {
    const f = await fixture(t, { files: true }); const desktop = await f.pair();
    await echo(desktop.data, Buffer.from([1]));
    const id = await f.open('id'); const response = once(id, 'message');
    id.send(punch({ conn_type: 1 })); await response;
    const before = f.relayMessages.length;
    const data = await f.open('relay'); const closed = once(data, 'close');
    data.send(relay({ uuid: 'authorized-uuid', conn_type: 1 })); await closed;
    assert.equal(f.relayMessages.length, before);
});
test('files: the file relay must keep the file-transfer mode', async (t) => {
    const f = await fixture(t, { files: true }); const desktop = await f.pair();
    await echo(desktop.data, Buffer.from([1]));
    const id = await f.open('id'); const response = once(id, 'message');
    id.send(punch({ conn_type: 1 })); await response;
    const before = f.relayMessages.length;
    const data = await f.open('relay'); const closed = once(data, 'close');
    data.send(relay({ uuid: 'file-uuid', conn_type: 0 })); await closed;
    assert.equal(f.relayMessages.length, before);
});
test('files: a relay uuid handed out twice is refused', async (t) => {
    const f = await fixture(t, { files: true, uuid: 'same-uuid' });
    const id = await f.open('id'); const response = once(id, 'message');
    id.send(punch()); await response;
    const data = await f.open('relay'); data.send(relay({ uuid: 'same-uuid' }));
    await echo(data, Buffer.from([1]));
    const second = await f.open('id'); const closed = once(second, 'close');
    second.send(punch({ conn_type: 1 })); await closed;
    assert.ok(f.audit.some((e) => e.reason === 'invalid signaling response'));
    assert.deepEqual(await echo(data, Buffer.from([2])), Buffer.from([2]));
});
test('files: closing the file transfer keeps the desktop', async (t) => {
    const f = await fixture(t, { files: true }); const desktop = await f.pair();
    await echo(desktop.data, Buffer.from([1]));
    const file = await f.filePair(); await echo(file.data, Buffer.from([2]));
    file.data.close(); file.id.close();
    await until(() => f.audit.some((e) => e.event === 'viewer.file.closed'));
    assert.deepEqual(await echo(desktop.data, Buffer.from([3])), Buffer.from([3]));
    const again = await f.filePair();
    assert.deepEqual(await echo(again.data, Buffer.from([4])), Buffer.from([4]));
});
test('files: closing the desktop ends the file transfer', async (t) => {
    const f = await fixture(t, { files: true }); const desktop = await f.pair();
    await echo(desktop.data, Buffer.from([1]));
    const file = await f.filePair(); await echo(file.data, Buffer.from([2]));
    const closed = once(file.data, 'close'); desktop.data.close(); await closed;
    assert.equal(f.gateway.pairCount(), 0);
});
test('files: withdrawing files from the grant ends only the file transfer', async (t) => {
    const f = await fixture(t, { files: true }); const desktop = await f.pair();
    await echo(desktop.data, Buffer.from([1]));
    const file = await f.filePair(); await echo(file.data, Buffer.from([2]));
    const closed = once(file.data, 'close'); f.files = false; await closed;
    assert.ok(f.audit.some((e) => e.reason === 'file transfer no longer allowed'));
    assert.deepEqual(await echo(desktop.data, Buffer.from([3])), Buffer.from([3]));
    await assert.rejects(f.open('id'), /403/);
});
test('files: revoking the grant ends desktop and file transfer', async (t) => {
    const f = await fixture(t, { files: true }); const desktop = await f.pair();
    await echo(desktop.data, Buffer.from([1]));
    const file = await f.filePair(); await echo(file.data, Buffer.from([2]));
    const closed = Promise.all([once(file.data, 'close'), once(desktop.data, 'close')]);
    f.revoked = true; await closed;
});
test('files: a page may open file transfer a bounded number of times', async (t) => {
    const f = await fixture(t, { files: true }); const desktop = await f.pair();
    await echo(desktop.data, Buffer.from([1]));
    for (let i = 0; i < 20; i++) {
        const id = await f.open('id'); const response = once(id, 'message');
        id.send(punch({ conn_type: 1 })); await response;
        const closed = once(id, 'close'); id.close(); await closed;
        await until(() => f.audit.filter((e) => e.event === 'viewer.file.closed').length === i + 1);
    }
    await assert.rejects(f.open('id'), /403/);
});
test('backpressure: a slow browser pauses the relay instead of losing the session', async (t) => {
    const f = await fixture(t, { files: true }); const desktop = await f.pair();
    await echo(desktop.data, Buffer.from([1]));
    const file = await f.filePair(); await echo(file.data, Buffer.from([2]));
    let received = 0;
    file.data.on('message', (bytes) => { received += bytes.length; });
    file.data.pause();
    file.data.send(Buffer.from('FLOOD'));
    await new Promise((r) => setTimeout(r, 600));
    file.data.resume();
    await until(() => received === 24 * 1024 * 1024);
    assert.ok(!f.audit.some((e) => e.reason === 'backpressure limit'));
    assert.deepEqual(await echo(desktop.data, Buffer.from([3])), Buffer.from([3]));
});
