// SPDX-License-Identifier: AGPL-3.0-only
// Modified integration/build by Gesoft, 2026-11-08. Files under upstream/ stay
// byte-identical; gesoft-patches.mjs is applied while bundling and declared in
// the published provenance.
import { build } from './upstream/node_modules/esbuild/lib/main.js';
import { createRequire } from 'node:module';
import { mkdir, cp, readFile, writeFile, rm } from 'node:fs/promises';
import { patches, gesoftPatchPlugin } from './gesoft-patches.mjs';
const require = createRequire(new URL('./upstream/package.json', import.meta.url));
const patcher = gesoftPatchPlugin();
await rm('build/assets', { recursive: true, force: true });
await mkdir('build/assets', { recursive: true });
const options = { bundle: true, format: 'esm', target: ['chrome110', 'edge110'],
  platform: 'browser', minify: true, legalComments: 'linked',
  loader: { '.wasm': 'binary', '.css': 'css' },
  alias: { 'libsodium-wrappers': require.resolve('libsodium-wrappers'),
    stream: new URL('./upstream/src/shims/node-stream.ts', import.meta.url).pathname },
  define: { 'process.env.NODE_ENV': '"production"' } };
await build({ ...options, entryPoints: { app: 'upstream/src/ui/app.ts' },
  outdir: 'build/assets', splitting: true, chunkNames: 'chunks/[name]-[hash]',
  plugins: [patcher.plugin] });
await build({ ...options, entryPoints: { 'session.worker': 'upstream/src/worker/session.worker.ts' }, outdir: 'build/assets',
  plugins: [patcher.plugin] });
const missing = patches.filter((p) => !patcher.applied.has(p));
if (missing.length) throw new Error('Gesoft patches not applied: ' + missing.map((p) => p.finding).join(', '));
await build({ entryPoints: ['gateway.mjs'], outfile: 'build/gateway.cjs', bundle: true,
  platform: 'node', format: 'cjs', target: 'node24', legalComments: 'linked' });
await build({ entryPoints: ['trust.test.mjs'], outfile: 'build/trust.test.cjs', bundle: true,
  platform: 'node', format: 'cjs', target: 'node24', plugins: [gesoftPatchPlugin().plugin],
  alias: { 'libsodium-wrappers': require.resolve('libsodium-wrappers') } });
await cp('upstream/LICENSE', 'build/assets/LICENSE.txt');
await cp('upstream/NOTICE', 'build/assets/NOTICE.txt');
await cp('upstream/logo.png', 'build/assets/logo.png');
const provenance = JSON.parse(await readFile('upstream/PROVENANCE.json', 'utf8'));
provenance.modified_upstream_files = [...new Set(patches.map((p) => p.file))].sort();
provenance.modifications = { by: 'Gesoft', date: '2026-11-08', source: 'WebViewer/gesoft-patches.mjs',
  summary: patches.map((p) => `${p.file}: ${p.finding}`) };
await writeFile('build/assets/provenance.json', JSON.stringify(provenance, null, 2) + '\n');
