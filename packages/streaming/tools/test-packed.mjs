import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const directory = mkdtempSync(join(tmpdir(), 'neuron-streaming-'));
const run = (command, args, options = {}) => execFileSync(command, args, { cwd: root, stdio: 'inherit', ...options });
try {
  writeFileSync(join(directory, 'package.json'), JSON.stringify({ private: true, type: 'module' }));
  // npm run exports local script policy as CLI config; the isolated install runs no scripts.
  const installEnv = { ...process.env };
  delete installEnv.npm_config_allow_scripts;
  delete installEnv.NPM_CONFIG_ALLOW_SCRIPTS;
  run('npm', ['install', resolve(root, 'artifacts/streaming.tgz'), '--ignore-scripts', '--no-audit', '--no-fund', '--package-lock=false'], { cwd: directory, env: installEnv });
  // Resolve through the installed package's public exports, with no workspace fallback.
  writeFileSync(join(directory, 'resolve.mjs'), "console.log(import.meta.resolve('@neuron-core/streaming'));\n");
  const entry = fileURLToPath(execFileSync(process.execPath, ['resolve.mjs'], { cwd: directory, encoding: 'utf8' }).trim());
  const unitTest = readFileSync(join(root, 'packages/streaming/test/consumer.test.mjs'), 'utf8')
    .replace("'../dist/index.js'", "'@neuron-core/streaming'");
  writeFileSync(join(directory, 'consumer.test.mjs'), unitTest);
  run(process.execPath, ['--test', 'consumer.test.mjs'], { cwd: directory });
  writeFileSync(join(directory, 'types.ts'), `
import { createChannelConsumer, createProtocolStream, subscribeToPusher, type ChannelEvent, type PusherChannel } from '@neuron-core/streaming';
const consume = (event: ChannelEvent): void => { console.log(event.streamId, event.sequence, event.type, event.data); };
const consumer = createChannelConsumer({ onEvent: consume, onGap: console.error }, 'a'.repeat(32));
consumer.accept({}); consumer.close();
declare const channel: PusherChannel;
subscribeToPusher(channel, { onEvent: consume, onGap: console.error }, 'a'.repeat(32)).close();
const stream: ReadableStream<string> = createProtocolStream(
  callbacks => createChannelConsumer(callbacks, 'a'.repeat(32)),
  async event => event.type,
);
void stream.cancel();
`);
  writeFileSync(join(directory, 'tsconfig.json'), JSON.stringify({ compilerOptions: {
    target: 'ES2022', module: 'NodeNext', moduleResolution: 'NodeNext', strict: true, noEmit: true, types: [],
  }, files: ['types.ts'] }));
  run(process.execPath, [join(root, 'node_modules/typescript/bin/tsc'), '-p', join(directory, 'tsconfig.json')]);
  const shipped = readdirSync(join(directory, 'node_modules/@neuron-core/streaming'));
  if (shipped.some(name => !['dist', 'package.json', 'README.md', 'LICENSE'].includes(name))) {
    throw new Error(`Unexpected files in npm package: ${shipped.join(', ')}`);
  }
  console.log(`Browser tests use installed archive: ${entry}`);
  run('npm', ['test', '--workspace', 'neuron-frontend-integration', '--', ...process.argv.slice(2)], {
    env: { ...process.env, NEURON_STREAMING_ENTRY: entry },
  });
} finally {
  rmSync(directory, { recursive: true, force: true });
}
