// SPDX-License-Identifier: AGPL-3.0-only
// Regression checks for the vendored viewer's identity and cipher boundaries.
import { test, before } from 'node:test';
import assert from 'node:assert/strict';
import sodium from './upstream/node_modules/libsodium-wrappers/dist/modules/libsodium-wrappers.js';
import { sodiumReady, StreamCipher, sealSymmetricKey } from './upstream/src/core/crypto.ts';
import { verifyServerRelayPk, verifyPeerSignedId } from './upstream/src/core/handshake.ts';
import { IdPk, Message } from './upstream/src/gen/message.ts';
import { initZstd, zstdDecode, ZSTD_MAX_OUTPUT } from './upstream/src/input/clipboard-cursor.ts';
import zlib from 'node:zlib';
import { RendezvousMessage } from './upstream/src/gen/rendezvous.ts';
import { Session } from './upstream/src/core/session.ts';
before(sodiumReady);
function signed(key, id, peerKey) {
    return sodium.crypto_sign(IdPk.encode(IdPk.fromPartial({id, pk:peerKey})).finish(), key.privateKey);
}
function keys() { return {server:sodium.crypto_sign_keypair(), peer:sodium.crypto_sign_keypair()}; }
test('OWASP crypto: only the pinned server can attest the exact target', () => {
    const {server,peer}=keys(), attestation=signed(server,'12345678',peer.publicKey);
    assert.deepEqual(verifyServerRelayPk(attestation,server.publicKey,'12345678'),peer.publicKey);
    assert.throws(()=>verifyServerRelayPk(attestation,sodium.crypto_sign_keypair().publicKey,'12345678'));
    assert.throws(()=>verifyServerRelayPk(attestation,server.publicKey,'99999999'));
});
test('OWASP crypto: modified server signature is refused', () => {
    const {server,peer}=keys(), attestation=signed(server,'12345678',peer.publicKey);
    attestation[0]^=1;
    assert.throws(()=>verifyServerRelayPk(attestation,server.publicKey,'12345678'));
});
test('OWASP crypto: the session refuses unsigned relay responses', () => {
    const {server}=keys(), events=[];let relays=0;
    const session=new Session({peerId:'12345678',serverKeyB64:sodium.to_base64(server.publicKey,sodium.base64_variants.ORIGINAL),myId:'test',myName:'test',password:'',wsIdUrl:'',wsRelayUrl:''},
        {sendSignaling(){},sendRelay(){},relayBuffered(){return 0},emit(e){events.push(e)},onVideo(){},onAudioFormat(){},onAudioFrame(){},openRelay(){relays++},closeAll(){}});
    session.start();
    session.onSignalingBytes(RendezvousMessage.encode(RendezvousMessage.fromPartial({union:{$case:'relay_response',relay_response:{uuid:'test',relay_server:'untrusted'}}})).finish());
    assert.equal(session.currentState,'error');assert.equal(relays,0);
});
test('OWASP crypto: forged peer handshake is refused', () => {
    const peer=sodium.crypto_sign_keypair(),box=sodium.crypto_box_keypair();
    const identity=signed(peer,'12345678',box.publicKey);
    assert.equal(verifyPeerSignedId(identity,peer.publicKey).id,'12345678');
    assert.throws(()=>verifyPeerSignedId(identity,sodium.crypto_sign_keypair().publicKey));
    identity[identity.length-1]^=1;
    assert.throws(()=>verifyPeerSignedId(identity,peer.publicKey));
});
test('OWASP crypto: key exchange uses fresh ephemeral keys and secrets', () => {
    const peer=sodium.crypto_box_keypair(),a=sealSymmetricKey(peer.publicKey),b=sealSymmetricKey(peer.publicKey);
    assert.notDeepEqual(a.ourBoxPk,b.ourBoxPk);assert.notDeepEqual(a.key,b.key);
    assert.deepEqual(sodium.crypto_box_open_easy(a.sealed,new Uint8Array(24),a.ourBoxPk,peer.privateKey),a.key);
});
test('OWASP crypto: tampered encrypted desktop frames are refused', () => {
    const key=sodium.randombytes_buf(32),sender=new StreamCipher(key),receiver=new StreamCipher(key);
    const frame=sender.seal(new Uint8Array([1,2,3,4]));frame[0]^=1;
    assert.throws(()=>receiver.open(frame));
});
test('OWASP crypto: replayed encrypted desktop frames are refused', () => {
    const key=sodium.randombytes_buf(32),sender=new StreamCipher(key),receiver=new StreamCipher(key);
    const frame=sender.seal(new Uint8Array([1,2,3,4]));
    assert.deepEqual(receiver.open(frame),new Uint8Array([1,2,3,4]));
    assert.throws(()=>receiver.open(frame));
});

// Gesoft patches (gesoft-patches.mjs): resource and error bounds.
function sessionWithEvents(server) {
    const events=[];
    const session=new Session({peerId:'12345678',serverKeyB64:sodium.to_base64(server.publicKey,sodium.base64_variants.ORIGINAL),myId:'test',myName:'test',password:'',wsIdUrl:'',wsRelayUrl:''},
        {sendSignaling(){},sendRelay(){},relayBuffered(){return 0},emit(e){events.push(e)},onVideo(){},onAudioFormat(){},onAudioFrame(){},openRelay(){},closeAll(){}});
    session.start();
    return session;
}
for (const [name, boxKey] of [['short', new Uint8Array(31)], ['all-zero', new Uint8Array(32)]]) {
    test(`Gesoft patch #5: a signed ${name} box key fails the session instead of stalling`, async () => {
        const {server,peer}=keys(), session=sessionWithEvents(server);
        session.onSignalingBytes(RendezvousMessage.encode(RendezvousMessage.fromPartial({union:{$case:'relay_response',
            relay_response:{uuid:'u',relay_server:'relay',union:{$case:'pk',pk:signed(server,'12345678',peer.publicKey)}}}})).finish());
        assert.equal(session.currentState,'relay');
        await session.onRelayBytes(Message.encode(Message.fromPartial({union:{$case:'signed_id',
            signed_id:{id:signed(peer,'12345678',boxKey)}}})).finish());
        assert.equal(session.currentState,'error');
    });
}
test('Gesoft patch #2: zstd output is bounded before allocation', async () => {
    await initZstd();
    const plain=Buffer.alloc(200000,7);
    const small=zlib.zstdCompressSync(plain,{params:{[zlib.constants.ZSTD_c_contentSizeFlag]:1},pledgedSrcSize:plain.length});
    assert.deepEqual(Buffer.from(zstdDecode(small)),plain);
    const huge=ZSTD_MAX_OUTPUT+1, big=Buffer.alloc(huge,0);
    const bomb=zlib.zstdCompressSync(big,{pledgedSrcSize:huge});
    assert.ok(bomb.length<64*1024);
    assert.throws(()=>zstdDecode(bomb),/out of bounds/);
    // A forged header declaring 1 GiB is refused from the header alone.
    assert.throws(()=>zstdDecode(new Uint8Array([0x28,0xb5,0x2f,0xfd,0xa0,0,0,0,0x40,0])),/out of bounds/);
    // An explicit capacity smaller than the content is refused, not truncated silently.
    assert.throws(()=>zstdDecode(small,1000));
});
