import assert from 'node:assert/strict';
import { test, after } from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { assertPhpEntrypoint, assertDrushVersion, readEvidence, readSmokeEvidence } from '../../scripts/full-site-evidence.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
fs.mkdirSync(path.join(root, '.tools'), { recursive: true });
const temp = fs.mkdtempSync(path.join(root, '.tools/evidence-regression-'));
after(() => fs.rmSync(temp, { recursive: true, force: true }));
let sequence = 0;
const write = (contents, extension = '.json') => {
  const filename = path.join(temp, String(++sequence) + extension);
  fs.writeFileSync(filename, typeof contents === 'string' ? contents : JSON.stringify(contents));
  return filename;
};
const identity = { mode: 'fresh', phase: 'post-install', nonce: 'current-test-run',
  commit: 'a'.repeat(40), root: '/isolated/site/web', drupal: '11.4.11' };
const evidence = () => ({ ...identity, status: 'passed', assertions: 1, checks: ['Actual executed check'] });

test('reproduces PHP accepting a shell proxy with exit 0, then rejects the entrypoint', () => {
  const filename = write('#!/bin/sh\necho "this is shell, not PHP"\n', '.sh');
  const result = spawnSync(process.env.PHP_BINARY || 'php', [filename], { encoding: 'utf8' });
  assert.ifError(result.error);
  assert.equal(result.status, 0);
  assert.match(result.stdout, /echo "this is shell, not PHP"/);
  assert.throws(() => assertPhpEntrypoint(filename), /entrypoint is not PHP/);
});

test('accepts and executes a real PHP entrypoint, with or without a shebang', () => {
  for (const prefix of ['', '#!/usr/bin/env php\n']) {
    const filename = write(prefix + '<?php echo "PHP really executed";\n', '.php');
    assert.equal(assertPhpEntrypoint(filename), filename);
    const result = spawnSync(process.env.PHP_BINARY || 'php', [filename], { encoding: 'utf8' });
    assert.ifError(result.error);
    assert.equal(result.status, 0);
    assert.equal(result.stdout, 'PHP really executed');
  }
});

test('rejects missing entries and PHP tags embedded inside shell comments', () => {
  assert.throws(() => assertPhpEntrypoint(path.join(temp, 'absent.php')), /ENOENT/);
  const filename = write('#!/bin/sh\n# <?php is not an entrypoint\n', '.sh');
  assert.throws(() => assertPhpEntrypoint(filename), /entrypoint is not PHP/);
});

test('version command must be actual exact-version Drush output', () => {
  assert.doesNotThrow(() => assertDrushVersion('Drush Commandline Tool 13.8.0\n', '13.8.0'));
  assert.doesNotThrow(() => assertDrushVersion('Drush Commandline Tool 13.8.0.0\n', '13.8.0'));
  for (const output of ['', 'Drush Commandline Tool 13.7.0', '#!/bin/sh\necho "Drush Commandline Tool 13.8.0"', 'success']) {
    assert.throws(() => assertDrushVersion(output, '13.8.0'), /did not execute/);
  }
});

test('zero exit without a probe file cannot pass the bootstrap gate', () => {
  assert.throws(() => readEvidence(path.join(temp, 'not-executed.json'), identity, []), /Missing executed-site evidence/);
});

test('malformed, null, failed and incomplete evidence fail closed', () => {
  for (const contents of ['not JSON', 'null', '{}', JSON.stringify({ ...evidence(), status: 'failed' })]) {
    assert.throws(() => readEvidence(write(contents), identity, ['Actual executed check']));
  }
});

test('rejects stale or mismatched mode, phase, nonce, commit, root and Drupal version', () => {
  for (const key of Object.keys(identity)) {
    assert.throws(() => readEvidence(write({ ...evidence(), [key]: 'stale' }), identity, ['Actual executed check']), /identity mismatch/);
  }
});

test('requires real check coverage, consistent counters and unique labels', () => {
  for (const overrides of [
    { checks: [], assertions: 0 }, { assertions: 0 }, { checks: ['wrong'], assertions: 1 },
    { checks: ['Actual executed check', 'Actual executed check'], assertions: 2 },
    { checks: [12], assertions: 1 },
  ]) {
    assert.throws(() => readEvidence(write({ ...evidence(), ...overrides }), identity, ['Actual executed check']), /required executed checks/);
  }
  assert.deepEqual(readEvidence(write(evidence()), identity, ['Actual executed check']), evidence());
});

test('full smoke requires both correct phases, including saved-form evidence', () => {
  const directory = path.join(temp, 'smoke');
  fs.mkdirSync(directory);
  const common = ['Real installed database bootstrapped', 'Enabled module: flowspeech',
    'Production service resolves from compiled container', 'AI plugin discovered from installed module',
    'AI manager constructs provider through container factory', 'Actual AI proxy returns fixture WAV bytes',
    'Settings form renders real HTML controls', 'Stored timeout matches install/upgrade phase',
    'Installed configuration schema declares integer timeout'];
  const { phase: unused, ...base } = identity;
  const save = (phase, checks) => fs.writeFileSync(path.join(directory, 'smoke-' + phase + '.json'), JSON.stringify({
    ...base, phase, status: 'passed', network: 'in-memory only', assertions: checks.length, checks,
  }));
  const initialChecks = [...common, 'Real Form API validation/submission succeeds', 'Settings submission saves deadline to database'];
  save('initial', initialChecks);
  assert.throws(() => readSmokeEvidence(directory, base), /Missing executed-site evidence/);
  save('persisted', common);
  assert.equal(readSmokeEvidence(directory, base).length, 2);
  save('initial', common);
  assert.throws(() => readSmokeEvidence(directory, base), /required executed checks/);
});
