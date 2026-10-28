import assert from 'node:assert/strict';
import { test, after } from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { assertPhpEntrypoint, assertDrushVersion, readEvidence, readSmokeEvidence,
  writeCandidateModuleManifest, readCandidateModuleManifest, writeInstalledModuleEvidence,
  readInstalledModuleEvidence } from '../../scripts/full-site-evidence.mjs';

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

const pinnedModule = JSON.parse(fs.readFileSync(path.join(root, 'tests/full-site/module-manifest.json'), 'utf8'));
const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');
const clone = (value) => JSON.parse(JSON.stringify(value));
function moduleFixture(mode = 'fresh') {
  const directory = path.join(temp, 'module-' + ++sequence);
  const web = path.join(directory, mode, 'site/web');
  const exported = path.join(directory, 'export');
  const installed = path.join(web, 'modules/custom/flowspeech');
  const approved = { ...pinnedModule, files: pinnedModule.files.map(({ path: relative }) => {
    const bytes = Buffer.from(relative === 'VERSION' ? '1.1.1\n' : 'synthetic-file:' + relative);
    const file = path.join(exported, relative);
    fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, bytes);
    return { path: relative, bytes: bytes.length, sha256: sha256(bytes) };
  }) };
  fs.mkdirSync(path.dirname(installed), { recursive: true }); fs.cpSync(exported, installed, { recursive: true });
  const identity = { mode, nonce: '11111111-1111-4111-8111-111111111111', commit: 'a'.repeat(40), root: web, drupal: '11.4.11' };
  const candidateFile = path.join(directory, 'candidate.json');
  const candidate = writeCandidateModuleManifest(candidateFile, exported, approved, identity);
  return { directory, web, exported, installed, approved, identity, candidate, candidateFile,
    initialFile: path.join(directory, 'installed-initial.json') };
}

test('approved 21-file fixture still matches current public module bytes', () => {
  assert.equal(pinnedModule.commit, '783abe7daf71141a5cc3a89c06b1477a7c802c15');
  assert.equal(pinnedModule.files.length, 21);
  for (const file of pinnedModule.files) {
    const bytes = fs.readFileSync(path.join(root, file.path));
    assert.equal(bytes.length, file.bytes); assert.equal(sha256(bytes), file.sha256);
  }
  const attributes = fs.readFileSync(path.join(root, '.gitattributes'), 'utf8');
  for (const excluded of ['/tests', '/scripts', '/.github']) assert.ok(attributes.includes(excluded + ' export-ignore'));
});

test('fresh and post-upgrade inventories bind both actual installed phases to the exact approved export', () => {
  for (const mode of ['fresh', 'upgrade']) {
    const fixture = moduleFixture(mode);
    for (const phase of ['initial', 'persisted']) {
      const file = path.join(fixture.directory, phase + '.json');
      const evidence = writeInstalledModuleEvidence(file, fixture.installed, fixture.identity, phase, fixture.candidate);
      assert.equal(evidence.file_count, 21); assert.equal(evidence.phase, phase);
      assert.equal(evidence.module_root, fixture.installed);
      assert.equal(evidence.manifest_sha256, fixture.candidate.manifest_sha256);
      assert.deepEqual(readInstalledModuleEvidence(file, fixture.identity, phase, fixture.candidate), evidence);
      assert.deepEqual(readCandidateModuleManifest(fixture.candidateFile, fixture.identity, fixture.approved), fixture.candidate);
    }
  }
});

test('candidate proof rejects a changed export, missing file or wrong version before recording success', () => {
  for (const mutation of ['changed', 'missing', 'version']) {
    const fixture = moduleFixture();
    if (mutation === 'changed') fs.writeFileSync(path.join(fixture.exported, 'README.md'), 'changed bytes');
    if (mutation === 'missing') fs.rmSync(path.join(fixture.exported, 'README.md'));
    if (mutation === 'version') fixture.approved.version = '9.9.9';
    assert.throws(() => writeCandidateModuleManifest(path.join(fixture.directory, 'bad.json'), fixture.exported, fixture.approved, fixture.identity));
    assert.equal(fs.existsSync(path.join(fixture.directory, 'bad.json')), false);
  }
});

test('installed proof rejects missing and same-length changed bytes', () => {
  for (const mutation of ['missing', 'changed']) {
    const fixture = moduleFixture(); const file = path.join(fixture.installed, 'README.md');
    if (mutation === 'missing') fs.rmSync(file);
    else { const bytes = fs.readFileSync(file); bytes[0] ^= 1; fs.writeFileSync(file, bytes); }
    assert.throws(() => writeInstalledModuleEvidence(fixture.initialFile, fixture.installed, fixture.identity, 'initial', fixture.candidate));
    assert.equal(fs.existsSync(fixture.initialFile), false);
  }
});

test('installed proof rejects extra secret files and unexpected empty directories', () => {
  for (const extra of ['settings.php', '.env', 'unexpected-empty']) {
    const fixture = moduleFixture(); const file = path.join(fixture.installed, extra);
    if (extra === 'unexpected-empty') fs.mkdirSync(file); else fs.writeFileSync(file, 'synthetic-only');
    assert.throws(() => writeInstalledModuleEvidence(fixture.initialFile, fixture.installed, fixture.identity, 'initial', fixture.candidate));
  }
});

test('installed proof rejects file/root symlinks and hardlinks', () => {
  for (const kind of ['file-symlink', 'root-symlink', 'hardlink']) {
    const fixture = moduleFixture();
    if (kind === 'root-symlink') { fs.rmSync(fixture.installed, { recursive: true }); fs.symlinkSync(fixture.exported, fixture.installed); }
    else {
      const file = path.join(fixture.installed, 'README.md'); fs.rmSync(file);
      if (kind === 'file-symlink') fs.symlinkSync(path.join(fixture.exported, 'README.md'), file);
      else fs.linkSync(path.join(fixture.exported, 'README.md'), file);
    }
    assert.throws(() => writeInstalledModuleEvidence(fixture.initialFile, fixture.installed, fixture.identity, 'initial', fixture.candidate));
  }
});

test('manifest paths, hashes, counts and unknown fields fail closed', () => {
  const fixture = moduleFixture();
  for (const mutation of ['count', 'duplicate', 'traversal', 'absolute', 'backslash', 'private', 'hash', 'size', 'extra']) {
    const approved = clone(fixture.approved);
    if (mutation === 'count') approved.files.pop();
    if (mutation === 'duplicate') approved.files[0] = approved.files[1];
    if (mutation === 'traversal') approved.files[0].path = '../outside';
    if (mutation === 'absolute') approved.files[0].path = '/outside';
    if (mutation === 'backslash') approved.files[0].path = 'src\\outside.php';
    if (mutation === 'private') approved.files[0].path = '.env';
    if (mutation === 'hash') approved.files[0].sha256 = 'not-a-hash';
    if (mutation === 'size') approved.files[0].bytes = -1;
    if (mutation === 'extra') approved.files[0].contents = 'must never be emitted';
    assert.throws(() => writeCandidateModuleManifest(path.join(fixture.directory, 'rejected.json'), fixture.exported, approved, fixture.identity));
  }
});

test('candidate JSON cannot replace approved source hashes with self-consistent fabricated hashes', () => {
  const fixture = moduleFixture(), forged = clone(fixture.candidate);
  forged.files[0].sha256 = 'b'.repeat(64);
  forged.manifest_sha256 = sha256(JSON.stringify([...forged.files].sort((a, b) => a.path < b.path ? -1 : 1)));
  fs.writeFileSync(fixture.candidateFile, JSON.stringify(forged));
  assert.throws(() => readCandidateModuleManifest(fixture.candidateFile, fixture.identity, fixture.approved), /approved module manifest/);
});

test('installed JSON rejects stale identity, phase, root, expected export and file-list data', () => {
  const fixture = moduleFixture();
  const good = writeInstalledModuleEvidence(fixture.initialFile, fixture.installed, fixture.identity, 'initial', fixture.candidate);
  for (const key of ['mode', 'nonce', 'commit', 'root', 'drupal', 'phase', 'module_root', 'approved_commit',
    'candidate_manifest_sha256', 'manifest_sha256', 'file_count', 'status']) {
    fs.writeFileSync(fixture.initialFile, JSON.stringify({ ...good, [key]: 'stale' }));
    assert.throws(() => readInstalledModuleEvidence(fixture.initialFile, fixture.identity, 'initial', fixture.candidate));
  }
  for (const bad of [{ ...good, files: [] }, { ...good, contents: 'not allowed' }, null]) {
    fs.writeFileSync(fixture.initialFile, JSON.stringify(bad));
    assert.throws(() => readInstalledModuleEvidence(fixture.initialFile, fixture.identity, 'initial', fixture.candidate));
  }
  for (const key of ['mode', 'nonce', 'commit', 'root', 'drupal', 'phase', 'manifest_sha256']) {
    fs.writeFileSync(fixture.initialFile, JSON.stringify(good));
    assert.throws(() => readInstalledModuleEvidence(fixture.initialFile, fixture.identity, 'initial', { ...fixture.candidate, [key]: 'stale' }));
  }
});

test('zero exit or forged status without installed proof files cannot pass either phase', () => {
  const fixture = moduleFixture();
  assert.throws(() => readInstalledModuleEvidence(fixture.initialFile, fixture.identity, 'initial', fixture.candidate), /Missing or invalid/);
  fs.writeFileSync(fixture.initialFile, '{"status":"passed"}');
  assert.throws(() => readInstalledModuleEvidence(fixture.initialFile, fixture.identity, 'initial', fixture.candidate));
  writeInstalledModuleEvidence(fixture.initialFile, fixture.installed, fixture.identity, 'initial', fixture.candidate);
  assert.throws(() => readInstalledModuleEvidence(fixture.initialFile, fixture.identity, 'persisted', fixture.candidate));
});

test('a module mutation after initial smoke prevents persisted evidence without replacing initial proof', () => {
  const fixture = moduleFixture();
  writeInstalledModuleEvidence(fixture.initialFile, fixture.installed, fixture.identity, 'initial', fixture.candidate);
  const before = fs.readFileSync(fixture.initialFile);
  fs.appendFileSync(path.join(fixture.installed, 'README.md'), 'changed after initial');
  const persisted = path.join(fixture.directory, 'persisted.json');
  assert.throws(() => writeInstalledModuleEvidence(persisted, fixture.installed, fixture.identity, 'persisted', fixture.candidate));
  assert.equal(fs.existsSync(persisted), false); assert.deepEqual(fs.readFileSync(fixture.initialFile), before);
});

test('inventory context rejects unknown fields, malformed identity and a different mode site root', () => {
  const fixture = moduleFixture();
  for (const bad of [{ ...fixture.identity, extra: 'not allowed' }, { ...fixture.identity, nonce: 'not-a-run-nonce' },
    { ...fixture.identity, commit: 'not-a-commit' }, { ...fixture.identity, mode: 'upgrade' },
    { ...fixture.identity, root: fixture.web + '/../web' }]) {
    assert.throws(() => writeCandidateModuleManifest(path.join(fixture.directory, 'rejected-context.json'), fixture.exported, fixture.approved, bad), /identity/);
  }
});

test('workflow uploads only explicit inventory JSONs, never an installed module or site tree', () => {
  const workflow = fs.readFileSync(path.join(root, '.github/workflows/drupal-full-site.yml'), 'utf8');
  for (const file of ['candidate-module-manifest.json', 'installed-module-initial.json', 'installed-module-persisted.json']) {
    assert.ok(workflow.includes('.tools/full-site/${{ matrix.mode }}/' + file));
  }
  assert.ok(!workflow.includes('/site/web')); assert.ok(!workflow.includes('/site/\n'));
  assert.ok(workflow.includes('/site/composer.lock'));
});
