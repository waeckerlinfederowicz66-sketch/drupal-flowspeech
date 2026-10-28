import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { inputsDirectory, verifyInputs, sha256 } from '../../scripts/official-history-ci.mjs';

const manifest = JSON.parse(fs.readFileSync(path.join(inputsDirectory, 'manifest.json')));
const clone = () => JSON.parse(JSON.stringify(manifest));
const read = (name) => fs.readFileSync(path.join(inputsDirectory, name));

test('exact I/J/K patches and canonical correct-identity commit bytes verify', () => {
  const verified = verifyInputs(manifest);
  assert.equal(verified.length, 3);
  assert.equal(verified[2].commit, manifest.expected_candidate);
  assert.notEqual(manifest.expected_candidate, manifest.approved_commit);
});

test('modified patch and commit bytes fail closed', () => {
  for (const name of ['01.patch', '02.commit', '03.patch', '03.commit']) {
    assert.throws(() => verifyInputs(manifest, (file) => file === name ? Buffer.concat([read(file), Buffer.from('changed')]) : read(file)));
  }
});

test('rewriting a metadata digest cannot hide a changed Git commit identity', () => {
  const changed = clone(); const metadata = Buffer.from(read('03.commit').toString().replace('committer ', 'committer wrong-'));
  changed.commits[2].metadata_sha256 = sha256(metadata);
  assert.throws(() => verifyInputs(changed, (file) => file === '03.commit' ? metadata : read(file)));
});

test('wrong parent, tree, order or candidate binding fails closed', () => {
  for (const mutate of [
    (m) => { m.commits[1].parent = 'a'.repeat(40); },
    (m) => { m.commits[2].tree = 'a'.repeat(40); },
    (m) => { m.commits.reverse(); },
    (m) => { m.expected_candidate = m.approved_commit; },
    (m) => { m.approved_commit = m.expected_candidate; },
  ]) { const changed = clone(); mutate(changed); assert.throws(() => verifyInputs(changed)); }
});

test('unknown fields, path traversal and another network source are rejected', () => {
  for (const mutate of [
    (m) => { m.extra = true; },
    (m) => { m.commits[0].patch = '../01.patch'; },
    (m) => { m.official_url = 'https://example.invalid/repository.git'; },
    (m) => { m.historical_tags['new-tag'] = 'a'.repeat(40); },
  ]) { const changed = clone(); mutate(changed); assert.throws(() => verifyInputs(changed)); }
});

test('one exact clean branch triggers the pinned read-only workflow without uploading original history', () => {
  const workflow = fs.readFileSync(new URL('../../.github/workflows/official-history-ci.yml', import.meta.url), 'utf8');
  assert.ok(workflow.includes("branches: ['codex/official-drupal-ci-1.1.1']"));
  assert.ok(!workflow.includes('workflow_dispatch'));
  assert.ok(workflow.includes('contents: read'));
  assert.ok(workflow.includes('persist-credentials: false'));
  assert.ok(workflow.includes('timeout-minutes: 20'));
  const uses = workflow.match(/uses: [^\n]+/g);
  assert.equal(uses.length, 3);
  for (const use of uses) assert.match(use, /@[a-f0-9]{40}(?:\s|$)/);
  const uploaded = workflow.split('path: |')[1].split('if-no-files-found:')[0];
  assert.ok(!/\.git\/|settings\.php|\.sqlite|empty-home|\.commit|\.patch/.test(uploaded));
  assert.ok(uploaded.includes('official-history-lineage.json'));
  assert.ok(uploaded.includes('installed-module-initial.json'));
  assert.ok(uploaded.includes('installed-module-persisted.json'));
});
