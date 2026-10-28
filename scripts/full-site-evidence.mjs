import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';

export function assertPhpEntrypoint(filename) {
  const source = fs.readFileSync(filename, 'utf8');
  if (!/^(?:#![^\r\n]*\r?\n)?<\?php(?:\s|$)/.test(source)) {
    throw new Error('Drush entrypoint is not PHP: ' + filename);
  }
  return filename;
}

export function assertDrushVersion(output, version) {
  const escaped = version.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  if (!new RegExp('^Drush Commandline Tool\\s+' + escaped + '(?:\\.0)?\\s*$').test(output.trim())) {
    throw new Error('Drush did not execute the expected version command.');
  }
}

export function readEvidence(filename, identity, requiredChecks) {
  if (!fs.existsSync(filename)) throw new Error('Missing executed-site evidence: ' + filename);
  let evidence;
  try { evidence = JSON.parse(fs.readFileSync(filename, 'utf8')); }
  catch { throw new Error('Invalid executed-site JSON: ' + filename); }
  if (!evidence || typeof evidence !== 'object' || evidence.status !== 'passed') {
    throw new Error('Site evidence does not report success: ' + filename);
  }
  for (const [key, expected] of Object.entries(identity)) {
    if (evidence[key] !== expected) throw new Error('Site evidence identity mismatch: ' + key);
  }
  if (!Array.isArray(evidence.checks) || evidence.checks.some((check) => typeof check !== 'string') ||
      evidence.checks.length === 0 || new Set(evidence.checks).size !== evidence.checks.length ||
      evidence.assertions !== evidence.checks.length ||
      requiredChecks.some((check) => !evidence.checks.includes(check))) {
    throw new Error('Site evidence lacks required executed checks: ' + filename);
  }
  return evidence;
}

export function readSmokeEvidence(logs, identity) {
  const common = ['Real installed database bootstrapped', 'Enabled module: flowspeech',
    'Production service resolves from compiled container', 'AI plugin discovered from installed module',
    'AI manager constructs provider through container factory', 'Actual AI proxy returns fixture WAV bytes',
    'Settings form renders real HTML controls', 'Stored timeout matches install/upgrade phase',
    'Installed configuration schema declares integer timeout'];
  return ['initial', 'persisted'].map((phase) => readEvidence(
    path.join(logs, 'smoke-' + phase + '.json'), { ...identity, phase, network: 'in-memory only' },
    phase === 'initial' ? [...common, 'Real Form API validation/submission succeeds',
      'Settings submission saves deadline to database'] : common));
}

const digest = (data) => createHash('sha256').update(data).digest('hex');
const moduleFileCount = 21;

function manifestFiles(files) {
  if (!Array.isArray(files) || files.length !== moduleFileCount) throw new Error('Expected exactly 21 module files.');
  const names = new Set();
  const normalized = files.map((file) => {
    if (!file || Object.keys(file).sort().join(',') !== 'bytes,path,sha256' ||
        typeof file.path !== 'string' || !file.path || path.posix.normalize(file.path) !== file.path ||
        path.posix.isAbsolute(file.path) || /[\\\x00-\x1f\x7f:]/.test(file.path) ||
        file.path.split('/').some((part) => !part || part === '.' || part === '..') ||
        /(^|\/)(?:\.env[^/]*|\.git|\.tools|vendor|tests|scripts|dist|settings\.php|auth\.json)(\/|$)/.test(file.path) ||
        /\.(?:sqlite|db|sql|pem|key)$/.test(file.path) || names.has(file.path) ||
        !Number.isSafeInteger(file.bytes) || file.bytes < 0 || !/^[a-f0-9]{64}$/.test(file.sha256)) {
      throw new Error('Invalid module manifest entry.');
    }
    names.add(file.path);
    return { path: file.path, bytes: file.bytes, sha256: file.sha256 };
  });
  return normalized.sort((a, b) => a.path < b.path ? -1 : a.path > b.path ? 1 : 0);
}

function assertInventoryIdentity(identity) {
  if (!identity || Object.keys(identity).sort().join(',') !== 'commit,drupal,mode,nonce,root' ||
      !['fresh', 'upgrade'].includes(identity.mode) || !/^[a-f0-9]{40}$/.test(identity.commit) ||
      !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(identity.nonce) ||
      !path.isAbsolute(identity.root) || path.resolve(identity.root) !== identity.root ||
      !identity.root.endsWith(path.sep + path.join(identity.mode, 'site/web')) ||
      /[\x00-\x1f\x7f]/.test(identity.root) || !/^\d+\.\d+\.\d+$/.test(identity.drupal)) {
    throw new Error('Invalid installed-module evidence identity.');
  }
}

function inventory(directory, expected) {
  if (fs.lstatSync(directory).isSymbolicLink() || fs.realpathSync(directory) !== path.resolve(directory)) {
    throw new Error('Module root must be a real isolated directory.');
  }
  const allowed = new Set(expected.map((file) => file.path));
  const directories = new Set(expected.flatMap((file) => {
    const parts = file.path.split('/');
    return parts.slice(0, -1).map((_, index) => parts.slice(0, index + 1).join('/'));
  }));
  const files = [];
  const walk = (current, prefix = '') => {
    for (const entry of fs.readdirSync(current, { withFileTypes: true })) {
      const relative = prefix ? prefix + '/' + entry.name : entry.name;
      const filename = path.join(current, entry.name);
      if (entry.isSymbolicLink()) throw new Error('Module inventory rejects symlinks.');
      if (entry.isDirectory()) {
        if (!directories.has(relative)) throw new Error('Unexpected module directory.');
        walk(filename, relative);
      } else {
        if (!entry.isFile() || !allowed.has(relative) || fs.statSync(filename).nlink !== 1) {
          throw new Error('Unexpected module file or hardlink.');
        }
        const bytes = fs.readFileSync(filename);
        files.push({ path: relative, bytes: bytes.length, sha256: digest(bytes) });
      }
    }
  };
  walk(directory);
  const actual = manifestFiles(files);
  if (JSON.stringify(actual) !== JSON.stringify(expected)) throw new Error('Installed module differs from approved candidate files.');
  return actual;
}

function candidateFiles(candidate, identity) {
  assertInventoryIdentity(identity);
  for (const [key, value] of Object.entries({ ...identity, schema_version: 1, module: 'flowspeech', phase: 'candidate-export', status: 'passed' })) {
    if (candidate?.[key] !== value) throw new Error('Candidate manifest identity mismatch: ' + key);
  }
  const files = manifestFiles(candidate.files);
  if (candidate.file_count !== moduleFileCount || candidate.manifest_sha256 !== digest(JSON.stringify(files)) ||
      !/^[a-f0-9]{40}$/.test(candidate.approved_commit) || !/^\d+\.\d+\.\d+$/.test(candidate.version) ||
      Object.keys(candidate).sort().join(',') !== [...Object.keys(identity), 'schema_version', 'module', 'phase', 'status',
        'approved_commit', 'version', 'file_count', 'manifest_sha256', 'files'].sort().join(',')) {
    throw new Error('Invalid candidate module manifest.');
  }
  return files;
}

export function writeCandidateModuleManifest(filename, directory, approved, identity) {
  assertInventoryIdentity(identity);
  if (!/^[a-f0-9]{40}$/.test(approved?.commit) || !/^\d+\.\d+\.\d+$/.test(approved?.version)) {
    throw new Error('Invalid approved module manifest.');
  }
  const expected = manifestFiles(approved.files);
  const files = inventory(directory, expected);
  if (fs.readFileSync(path.join(directory, 'VERSION'), 'utf8').trim() !== approved.version) {
    throw new Error('Candidate module version mismatch.');
  }
  const evidence = { schema_version: 1, module: 'flowspeech', version: approved.version, ...identity,
    phase: 'candidate-export', approved_commit: approved.commit, status: 'passed', file_count: files.length,
    manifest_sha256: digest(JSON.stringify(files)), files };
  fs.writeFileSync(filename, JSON.stringify(evidence, null, 2) + '\n');
  return readCandidateModuleManifest(filename, identity, approved);
}

export function readCandidateModuleManifest(filename, identity, approved) {
  let evidence;
  try { evidence = JSON.parse(fs.readFileSync(filename, 'utf8')); }
  catch { throw new Error('Missing or invalid candidate module manifest.'); }
  const files = candidateFiles(evidence, identity);
  if (evidence.approved_commit !== approved.commit || evidence.version !== approved.version ||
      JSON.stringify(files) !== JSON.stringify(manifestFiles(approved.files))) {
    throw new Error('Candidate export differs from the approved module manifest.');
  }
  return evidence;
}

export function readInstalledModuleEvidence(filename, identity, phase, candidate) {
  if (!['initial', 'persisted'].includes(phase)) throw new Error('Unknown installed-module phase.');
  const expected = candidateFiles(candidate, identity);
  let evidence;
  try { evidence = JSON.parse(fs.readFileSync(filename, 'utf8')); }
  catch { throw new Error('Missing or invalid installed-module evidence.'); }
  const binding = { ...identity, schema_version: 1, module: 'flowspeech', version: candidate.version, phase,
    approved_commit: candidate.approved_commit, status: 'passed', file_count: moduleFileCount,
    module_root: path.join(identity.root, 'modules/custom/flowspeech'),
    candidate_manifest_sha256: candidate.manifest_sha256, manifest_sha256: candidate.manifest_sha256 };
  for (const [key, value] of Object.entries(binding)) {
    if (evidence?.[key] !== value) throw new Error('Installed-module evidence identity mismatch: ' + key);
  }
  if (Object.keys(evidence).sort().join(',') !== [...Object.keys(binding), 'files'].sort().join(',') ||
      JSON.stringify(manifestFiles(evidence.files)) !== JSON.stringify(expected)) {
    throw new Error('Installed-module evidence file inventory mismatch.');
  }
  return evidence;
}

export function writeInstalledModuleEvidence(filename, directory, identity, phase, candidate) {
  const expected = candidateFiles(candidate, identity);
  if (!['initial', 'persisted'].includes(phase) || path.resolve(directory) !== path.join(identity.root, 'modules/custom/flowspeech')) {
    throw new Error('Installed-module phase or site root mismatch.');
  }
  const files = inventory(directory, expected);
  const evidence = { schema_version: 1, module: 'flowspeech', version: candidate.version, ...identity, phase,
    approved_commit: candidate.approved_commit, status: 'passed', file_count: files.length,
    module_root: path.resolve(directory), candidate_manifest_sha256: candidate.manifest_sha256,
    manifest_sha256: digest(JSON.stringify(files)), files };
  fs.writeFileSync(filename, JSON.stringify(evidence, null, 2) + '\n');
  return readInstalledModuleEvidence(filename, identity, phase, candidate);
}
