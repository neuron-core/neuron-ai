import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export function releaseTag(tag, version) {
  if (tag !== `frontend-v${version}`) throw new Error(`Expected tag frontend-v${version}; received ${tag}`);
  if (!/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/.test(version)) {
    throw new Error(`Unsupported release version: ${version}`);
  }
  return version.includes('-') ? 'next' : 'latest';
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const { version } = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8'));
  console.log(`dist-tag=${releaseTag(process.env.GITHUB_REF_NAME, version)}`);
}
