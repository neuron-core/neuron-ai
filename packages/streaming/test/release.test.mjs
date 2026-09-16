import { test } from 'node:test';
import assert from 'node:assert/strict';
import { releaseTag } from '../tools/release.mjs';

test('frontend tags must exactly match the package version', () => {
  assert.equal(releaseTag('frontend-v0.1.0', '0.1.0'), 'latest');
  assert.equal(releaseTag('frontend-v0.2.0-beta.1', '0.2.0-beta.1'), 'next');
  for (const tag of ['v0.1.0', 'frontend-v0.2.0', undefined]) {
    assert.throws(() => releaseTag(tag, '0.1.0'), /Expected tag/);
  }
  assert.throws(() => releaseTag('frontend-v01.0.0', '01.0.0'), /Unsupported/);
});
