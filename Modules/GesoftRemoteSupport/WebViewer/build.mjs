// SPDX-License-Identifier: AGPL-3.0-only
// Modified integration/build by Gesoft, 2026-11-08; upstream sources are unchanged.
import { build } from './upstream/node_modules/esbuild/lib/main.js';
import { createRequire } from 'node:module';
import { mkdir, cp, readFile, writeFile, rm } from 'node:fs/promises';
const require = createRequire(new URL('./upstream/package.json', import.meta.url));
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
  plugins: [{ name: 'module-logo-path', setup(builder) {
    builder.onLoad({filter: /\/ui\/(app|connect-card)\.ts$/}, async ({path}) => {
      const source = await readFile(path, 'utf8');
      const needle = 'src="/rdclient/logo.png"';
      if (!source.includes(needle)) throw new Error('Review upstream logo markup before updating');
      return {loader:'ts', contents:source.replaceAll(needle,
        'src="${new URL(\'logo.png\', new URL((window as any).__RD__.workerUrl, location.href)).href}"')};
    });
  }}] });
await build({ ...options, entryPoints: { 'session.worker': 'upstream/src/worker/session.worker.ts' }, outdir: 'build/assets' });
await build({ entryPoints: ['gateway.mjs'], outfile: 'build/gateway.cjs', bundle: true,
  platform: 'node', format: 'cjs', target: 'node24', legalComments: 'linked' });
await build({ entryPoints: ['trust.test.mjs'], outfile: 'build/trust.test.cjs', bundle: true,
  platform: 'node', format: 'cjs', target: 'node24',
  alias: { 'libsodium-wrappers': require.resolve('libsodium-wrappers') } });
await cp('upstream/LICENSE', 'build/assets/LICENSE.txt');
await cp('upstream/NOTICE', 'build/assets/NOTICE.txt');
await cp('upstream/logo.png', 'build/assets/logo.png');
const provenance = JSON.parse(await readFile('upstream/PROVENANCE.json', 'utf8'));
await writeFile('build/assets/provenance.json', JSON.stringify(provenance, null, 2) + '\n');
