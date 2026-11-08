// SPDX-License-Identifier: AGPL-3.0-only
// Gesoft modifications to the vendored CortenDesk sources, 2026-11-08.
//
// The files under upstream/ stay byte-identical to the pinned commit. build.mjs
// applies these exact replacements while bundling; each `find` must occur
// exactly once, so an upstream change under a patch fails the build instead of
// silently skipping it. Reasons refer to docs/web-viewer-crypto-review-2026-11-08.md
// in helpdesk-rust. All are resource or error-handling bounds against a
// malicious connected peer; none changes the RustDesk wire protocol.
export const patches = [
  {
    file: 'src/input/clipboard-cursor.ts',
    finding: '#2 unbounded zstd output',
    find: `export function zstdDecode(data: Uint8Array, uncompressedSize = 0): Uint8Array {
  if (!decoder || !decoderReady) throw new Error('zstd decoder not initialized: await initZstd() first');
  return decoder.decode(data, uncompressedSize);
}`,
    replace: `// Gesoft patch: bound the output before the decoder allocates it.
export const ZSTD_MAX_OUTPUT = 32 * 1024 * 1024;
function zstdFrameContentSize(data: Uint8Array): number {
  if (data.length < 6 || data[0] !== 0x28 || data[1] !== 0xb5 || data[2] !== 0x2f || data[3] !== 0xfd) {
    throw new Error('zstd: not a zstd frame');
  }
  const fhd = data[4];
  const single = (fhd >> 5) & 1;
  const width = [single ? 1 : 0, 2, 4, 8][fhd >> 6];
  if (!width) throw new Error('zstd: content size not declared');
  const at = 5 + (single ? 0 : 1) + [0, 1, 2, 4][fhd & 3];
  if (data.length < at + width) throw new Error('zstd: truncated frame header');
  let size = 0;
  for (let i = width - 1; i >= 0; i--) size = size * 256 + data[at + i];
  return width === 2 ? size + 256 : size;
}
// uncompressedSize, when given, is the caller's capacity; otherwise the
// frame-declared size is used, and both are capped by ZSTD_MAX_OUTPUT. zstddec
// does not report decoder errors (on wasm32 they come back as an empty slice),
// so the output length is checked against the size the frame declares.
export function zstdDecode(data: Uint8Array, uncompressedSize = 0): Uint8Array {
  if (!decoder || !decoderReady) throw new Error('zstd decoder not initialized: await initZstd() first');
  let declared: number | undefined;
  if (uncompressedSize === 0) declared = zstdFrameContentSize(data);
  else {
    try { declared = zstdFrameContentSize(data); } catch { declared = undefined; }
  }
  const size = uncompressedSize || (declared as number);
  if (!Number.isSafeInteger(size) || size < 0 || size > ZSTD_MAX_OUTPUT ||
      (declared !== undefined && (!Number.isSafeInteger(declared) || declared > ZSTD_MAX_OUTPUT))) {
    throw new Error('zstd: output size out of bounds');
  }
  if (declared !== undefined && declared > size) throw new Error('zstd: content exceeds capacity');
  if (size === 0 || declared === 0) return new Uint8Array(0);
  const out = decoder.decode(data, size);
  if (declared !== undefined ? out.length !== declared : out.length > size) throw new Error('zstd: corrupt frame');
  return out;
}`,
  },
  {
    file: 'src/input/clipboard-cursor.ts',
    finding: '#2 cursor dimensions drive allocations',
    find: `  await initZstd();
  const expected = c.width * c.height * 4;`,
    replace: `  await initZstd();
  // Gesoft patch: cursor size is peer-controlled and drives three allocations.
  if (!Number.isInteger(c.width) || !Number.isInteger(c.height) ||
      c.width < 1 || c.height < 1 || c.width > 512 || c.height > 512) {
    throw new Error('cursor: dimensions out of bounds');
  }
  const expected = c.width * c.height * 4;`,
  },
  {
    file: 'src/media/video.ts',
    finding: '#3 key frames bypass the decode queue bound',
    find: `      if (!f.key && (this.decoder?.decodeQueueSize ?? 0) > MAX_DECODE_QUEUE) {`,
    replace: `      // Gesoft patch: key frames get a higher bound instead of none.
      if ((this.decoder?.decodeQueueSize ?? 0) > (f.key ? MAX_DECODE_QUEUE * 4 : MAX_DECODE_QUEUE)) {`,
  },
  {
    file: 'src/media/audio.ts',
    finding: '#3 audio decode queue unbounded',
    find: `    if (!dec || dec.state !== 'configured') return;
    try {
      dec.decode(new EncodedAudioChunk(`,
    replace: `    if (!dec || dec.state !== 'configured') return;
    // Gesoft patch: drop audio beyond about half a second of backlog.
    if (dec.decodeQueueSize > 50) return;
    try {
      dec.decode(new EncodedAudioChunk(`,
  },
  {
    file: 'src/ui/app.ts',
    finding: '#3 chat history unbounded',
    find: `    this.chatLog.push({ who: 'peer', text, at: Date.now() });`,
    replace: `    // Gesoft patch: bound peer message length and the history kept and re-rendered.
    if (text.length > 4000) text = text.slice(0, 4000) + '…';
    this.chatLog.push({ who: 'peer', text, at: Date.now() });
    if (this.chatLog.length > 200) this.chatLog.splice(0, this.chatLog.length - 200);`,
  },
  {
    file: 'src/worker/session.worker.ts',
    finding: '#3 cursor conversions without a concurrency bound',
    find: `  private static readonly MAX_CURSORS = 128;

  private async postCursor(c: CursorData): Promise<void> {
    let entry: { pngDataUrl: string; hotx: number; hoty: number };
    try {
      entry = await this.deps.cursorToPng(c);
    } catch {
      return; // cursor rendering is best-effort; position still flows via cursorPos
    }`,
    replace: `  private static readonly MAX_CURSORS = 128;
  // Gesoft patch: bound concurrent cursor conversions.
  private cursorsInFlight = 0;

  private async postCursor(c: CursorData): Promise<void> {
    let entry: { pngDataUrl: string; hotx: number; hoty: number };
    if (this.cursorsInFlight >= 4) return;
    this.cursorsInFlight++;
    try {
      entry = await this.deps.cursorToPng(c);
    } catch {
      return; // cursor rendering is best-effort; position still flows via cursorPos
    } finally {
      this.cursorsInFlight--;
    }`,
  },
  {
    file: 'src/core/session.ts',
    finding: '#5 invalid signed box key escapes handshake error handling',
    find: `    const { bytes, key } = buildPublicKeyMessage(boxPk);
    this.sinks.sendRelay(bytes);`,
    replace: `    // Gesoft patch: a signed but invalid box key fails the session.
    let bytes: Uint8Array;
    let key: Uint8Array;
    try {
      ({ bytes, key } = buildPublicKeyMessage(boxPk));
    } catch {
      this.fail('peer trust link failed: invalid box key');
      return;
    }
    this.sinks.sendRelay(bytes);`,
  },
];

// Upstream's logo markup assumes the CortenDesk site root; point it at the
// module's published assets.
const LOGO_NEEDLE = 'src="/rdclient/logo.png"';
const LOGO = 'src="${new URL(\'logo.png\', new URL((window as any).__RD__.workerUrl, location.href)).href}"';

export function gesoftPatchPlugin() {
  const applied = new Set();
  const plugin = {
    name: 'gesoft-patches',
    setup(builder) {
      builder.onLoad({ filter: /\/upstream\/src\/.*\.ts$/ }, async ({ path }) => {
        const { readFile } = await import('node:fs/promises');
        const relative = path.slice(path.indexOf('/upstream/') + '/upstream/'.length);
        const mine = patches.filter((p) => p.file === relative);
        const logo = /\/ui\/(app|connect-card)\.ts$/.test(path);
        if (!mine.length && !logo) return undefined;
        let source = await readFile(path, 'utf8');
        for (const patch of mine) {
          const count = source.split(patch.find).length - 1;
          if (count !== 1) throw new Error(`Gesoft patch ${patch.finding} matches ${count} times in ${relative}; review upstream`);
          source = source.replace(patch.find, () => patch.replace);
          applied.add(patch);
        }
        if (logo) {
          if (!source.includes(LOGO_NEEDLE)) throw new Error('Review upstream logo markup before updating');
          source = source.replaceAll(LOGO_NEEDLE, LOGO);
        }
        return { loader: 'ts', contents: source };
      });
    },
  };
  return { plugin, applied };
}
