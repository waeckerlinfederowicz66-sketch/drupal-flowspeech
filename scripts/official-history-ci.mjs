import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const inputsDirectory = path.join(root, 'tests/full-site/official-history');
const identity = 'waeckerlinfederowicz66-sketch <waeckerlinfederowicz66@gmail.com>';
const officialUrl = 'https://git.drupalcode.org/project/flowspeech.git';
const branch = 'codex/official-drupal-ci-1.1.1';
export const sha256 = (bytes) => crypto.createHash('sha256').update(bytes).digest('hex');
const gitObject = (type, bytes) => crypto.createHash('sha1').update(type + ' ' + bytes.length + '\0').update(bytes).digest('hex');
const exactKeys = (value, keys) => assert.deepEqual(Object.keys(value).sort(), keys.slice().sort(), 'unexpected input fields');

export function verifyInputs(manifest, read = (name) => fs.readFileSync(path.join(inputsDirectory, name))) {
  exactKeys(manifest, ['schema_version', 'official_url', 'official_base', 'official_legacy', 'legacy_tree',
    'expected_candidate', 'expected_tree', 'approved_commit', 'approved_manifest_sha256', 'canonical_inventory_sha256',
    'historical_tags', 'commits']);
  assert.equal(manifest.schema_version, 1);
  assert.equal(manifest.official_url, officialUrl);
  for (const field of ['official_base', 'official_legacy', 'legacy_tree', 'expected_candidate', 'expected_tree', 'approved_commit']) {
    assert.match(manifest[field], /^[a-f0-9]{40}$/);
  }
  for (const field of ['approved_manifest_sha256', 'canonical_inventory_sha256']) assert.match(manifest[field], /^[a-f0-9]{64}$/);
  exactKeys(manifest.historical_tags, ['1.0.0', '1.1.0']);
  assert.equal(manifest.historical_tags['1.0.0'], manifest.official_legacy);
  assert.match(manifest.historical_tags['1.1.0'], /^[a-f0-9]{40}$/);
  assert.equal(manifest.commits.length, 3);
  let parent = manifest.official_base;
  const verified = manifest.commits.map((entry, index) => {
    exactKeys(entry, ['label', 'commit', 'parent', 'tree', 'patch', 'patch_sha256', 'metadata', 'metadata_sha256']);
    assert.equal(entry.label, ['I', 'J', 'K'][index]);
    assert.equal(entry.parent, parent);
    assert.match(entry.commit, /^[a-f0-9]{40}$/); assert.match(entry.tree, /^[a-f0-9]{40}$/);
    const prefix = String(index + 1).padStart(2, '0');
    assert.equal(entry.patch, prefix + '.patch'); assert.equal(entry.metadata, prefix + '.commit');
    const patch = read(entry.patch), metadata = read(entry.metadata);
    assert.equal(sha256(patch), entry.patch_sha256); assert.equal(sha256(metadata), entry.metadata_sha256);
    assert.equal(gitObject('commit', metadata), entry.commit);
    const lines = metadata.toString('utf8').split('\n');
    assert.equal(lines[0], 'tree ' + entry.tree); assert.equal(lines[1], 'parent ' + entry.parent);
    for (const [offset, field] of [[2, 'author'], [3, 'committer']]) {
      assert.ok(lines[offset].startsWith(field + ' ' + identity + ' '));
      assert.match(lines[offset].slice((field + ' ' + identity + ' ').length), /^\d{10} [+-]\d{4}$/);
    }
    assert.equal(lines[4], ''); assert.ok(lines[5]); assert.equal(lines[6], ''); assert.equal(lines.length, 7);
    parent = entry.commit;
    return { ...entry, patchBytes: patch, metadataBytes: metadata };
  });
  assert.equal(parent, manifest.expected_candidate);
  assert.equal(verified[2].tree, manifest.expected_tree);
  assert.equal(verified[1].commit, manifest.approved_commit);
  return verified;
}

export function gitRunner(home) {
  const env = { PATH: process.env.PATH, HOME: home, XDG_CONFIG_HOME: home, CURL_HOME: home,
    GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: '/dev/null', GIT_TERMINAL_PROMPT: '0',
    GIT_ASKPASS: '/usr/bin/false', GIT_NO_REPLACE_OBJECTS: '1', LC_ALL: 'C', COPYFILE_DISABLE: '1' };
  return (directory, args, input, network = false) => {
    for (let attempt = 1; attempt <= (network ? 3 : 1); attempt++) {
      const result = spawnSync('git', ['-c', 'credential.helper=', '-c', 'http.extraHeader=',
        '-c', 'http.sslVerify=true', '-c', 'http.version=HTTP/1.1', '-C', directory, ...args],
      { env, input, timeout: network ? 90000 : 30000, maxBuffer: 8 * 1024 * 1024 });
      if (result.status === 0) return result.stdout;
      const error = String(result.stderr || '') + String(result.error || '');
      const auth = /401|403|authentication|username|password|permission denied|login|2fa/i.test(error);
      const transient = /EOF|SSL_ERROR_SYSCALL|reset|timed? ?out|TLS.*terminated/i.test(error);
      if (network && !auth && transient && attempt < 3) {
        console.error(JSON.stringify({ event: 'anonymous_git_transport_retry', attempt, category: 'transient_transport_failure' }));
        continue;
      }
      throw new Error(auth ? 'human_auth_or_authority_required_stop' : 'git_failed_details_suppressed');
    }
  };
}

export function replay(directory, manifest, verified, git) {
  const outputs = [];
  for (const entry of verified) {
    assert.equal(git(directory, ['rev-parse', 'HEAD']).toString().trim(), entry.parent);
    git(directory, ['apply', '--unidiff-zero', '--check', '-'], entry.patchBytes);
    git(directory, ['apply', '--unidiff-zero', '--index', '-'], entry.patchBytes);
    const tree = git(directory, ['write-tree']).toString().trim();
    assert.equal(tree, entry.tree);
    const commit = git(directory, ['hash-object', '-t', 'commit', '-w', '--stdin'], entry.metadataBytes).toString().trim();
    assert.equal(commit, entry.commit);
    git(directory, ['checkout', '--detach', commit]);
    assert.equal(git(directory, ['rev-parse', 'HEAD^']).toString().trim(), entry.parent);
    outputs.push({ label: entry.label, commit, parent: entry.parent, tree, patch_sha256: entry.patch_sha256,
      metadata_sha256: entry.metadata_sha256, exact_objects_verified: true });
  }
  assert.equal(git(directory, ['rev-parse', 'HEAD']).toString().trim(), manifest.expected_candidate);
  assert.equal(git(directory, ['rev-parse', 'HEAD^{tree}']).toString().trim(), manifest.expected_tree);
  git(directory, ['merge-base', '--is-ancestor', manifest.official_base, manifest.expected_candidate]);
  git(directory, ['merge-base', '--is-ancestor', manifest.official_legacy, manifest.expected_candidate]);
  assert.equal(git(directory, ['rev-list', '--count', 'HEAD']).toString().trim(), '14');
  assert.equal(git(directory, ['remote']).toString().trim(), '');
  assert.equal(git(directory, ['status', '--porcelain']).toString().trim(), '');
  return outputs;
}

export function verifyPackage(directory, manifest, git) {
  const raw = fs.readFileSync(path.join(directory, 'tests/full-site/module-manifest.json'));
  assert.equal(sha256(raw), manifest.approved_manifest_sha256);
  const approved = JSON.parse(raw); assert.equal(approved.commit, manifest.approved_commit);
  assert.equal(approved.version, '1.1.1'); assert.equal(approved.files.length, 21);
  assert.equal(new Set(approved.files.map((file) => file.path)).size, 21);
  const rows = approved.files.map((file) => {
    exactKeys(file, ['path', 'bytes', 'sha256']);
    assert.ok(file.path && !path.posix.isAbsolute(file.path) && !/[\\:\x00-\x1f\x7f]/.test(file.path));
    assert.ok(file.path.split('/').every((part) => part && part !== '.' && part !== '..'));
    const absolute = path.join(directory, file.path);
    assert.ok(fs.lstatSync(absolute).isFile());
    const bytes = fs.readFileSync(absolute); assert.equal(bytes.length, file.bytes); assert.equal(sha256(bytes), file.sha256);
    assert.deepEqual(git(directory, ['show', manifest.approved_commit + ':' + file.path]), bytes);
    if (!['README.md', 'CHANGELOG.md'].includes(file.path)) {
      assert.deepEqual(git(directory, ['show', manifest.commits[0].commit + ':' + file.path]), bytes);
    }
    return { path: file.path, bytes: file.bytes, sha256: file.sha256 };
  }).sort((a, b) => a.path < b.path ? -1 : a.path > b.path ? 1 : 0);
  assert.equal(sha256(Buffer.from(JSON.stringify(rows))), manifest.canonical_inventory_sha256);
}

function main() {
  const mode = process.argv[2]; assert.ok(['fresh', 'upgrade'].includes(mode));
  assert.equal(process.env.GITHUB_EVENT_NAME, 'push'); assert.equal(process.env.GITHUB_REF_NAME, branch);
  assert.match(process.env.GITHUB_SHA || '', /^[a-f0-9]{40}$/);
  assert.match(process.env.GITHUB_RUN_ID || '', /^\d+$/); assert.match(process.env.GITHUB_RUN_ATTEMPT || '', /^\d+$/);
  const manifest = JSON.parse(fs.readFileSync(path.join(inputsDirectory, 'manifest.json')));
  const verified = verifyInputs(manifest);
  const tools = path.join(root, '.tools'); fs.mkdirSync(tools, { recursive: true });
  const directory = path.join(tools, 'official-history-candidate');
  const home = path.join(tools, 'official-history-empty-home');
  assert.ok(!fs.existsSync(directory) && !fs.existsSync(home), 'refuse reused CI state');
  fs.mkdirSync(directory); fs.mkdirSync(home);
  const git = gitRunner(home);
  const harness = git(root, ['rev-parse', 'HEAD']).toString().trim(); assert.equal(harness, process.env.GITHUB_SHA);
  assert.equal(git(root, ['status', '--porcelain']).toString().trim(), '');
  const observed = git(root, ['ls-remote', '--heads', '--tags', officialUrl], undefined, true).toString().trim().split('\n');
  const refs = Object.fromEntries(observed.map((line) => { const [id, ref] = line.split('\t'); return [ref, id]; }));
  assert.deepEqual(refs, { 'refs/heads/1.0.x': manifest.official_base, 'refs/tags/1.0.0': manifest.historical_tags['1.0.0'],
    'refs/tags/1.1.0': manifest.historical_tags['1.1.0'], 'refs/tags/1.1.0^{}': manifest.official_base });
  git(directory, ['init']);
  git(directory, ['fetch', '--no-tags', officialUrl, 'refs/heads/1.0.x:refs/remotes/official/1.0.x',
    'refs/tags/1.0.0:refs/tags/1.0.0', 'refs/tags/1.1.0:refs/tags/1.1.0'], undefined, true);
  assert.equal(git(directory, ['rev-parse', 'refs/remotes/official/1.0.x']).toString().trim(), manifest.official_base);
  assert.equal(git(directory, ['rev-parse', 'refs/tags/1.0.0']).toString().trim(), manifest.official_legacy);
  assert.equal(git(directory, ['rev-parse', 'refs/tags/1.1.0']).toString().trim(), manifest.historical_tags['1.1.0']);
  assert.equal(git(directory, ['rev-parse', manifest.official_legacy + '^{tree}']).toString().trim(), manifest.legacy_tree);
  git(directory, ['checkout', '--detach', manifest.official_base]);
  const commits = replay(directory, manifest, verified, git); verifyPackage(directory, manifest, git);
  git(directory, ['fsck', '--strict', '--no-reflogs']);
  const lineage = { schema_version: 1, status: 'exact_candidate_prepared_not_runtime_acceptance', mode,
    harness_commit: harness, executed_commit: manifest.expected_candidate, tree: manifest.expected_tree,
    official_base: manifest.official_base, legacy: manifest.official_legacy, approved_commit: manifest.approved_commit,
    approved_manifest_sha256: manifest.approved_manifest_sha256, canonical_inventory_sha256: manifest.canonical_inventory_sha256,
    run_id: process.env.GITHUB_RUN_ID, run_attempt: process.env.GITHUB_RUN_ATTEMPT,
    candidate_root: directory, expected_site_root: path.join(directory, '.tools/full-site', mode, 'site/web'),
    inputs_manifest_sha256: sha256(fs.readFileSync(path.join(inputsDirectory, 'manifest.json'))), commits,
    public_original_history_only_in_ephemeral_checkout: true, original_history_uploaded: false };
  fs.writeFileSync(path.join(tools, 'official-history-lineage.json'), JSON.stringify(lineage, null, 2) + '\n');
  console.log(JSON.stringify({ harness_commit: harness, executed_commit: lineage.executed_commit, mode, prepared: true }));
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try { main(); } catch (error) { console.error(error.message === 'human_auth_or_authority_required_stop'
    ? error.message : 'official_history_ci_preparation_failed_details_suppressed'); process.exitCode = 1; }
}
