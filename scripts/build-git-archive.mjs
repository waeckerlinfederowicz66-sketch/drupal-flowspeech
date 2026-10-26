import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const run = (command, args) => execFileSync(command, args, {
  cwd: root, encoding: 'utf8', env: { ...process.env, LC_ALL: 'C', COPYFILE_DISABLE: '1' },
});
const commit = run('git', ['rev-parse', '--verify', (process.argv[2] || 'HEAD') + '^{commit}']).trim();
const version = fs.readFileSync(path.join(root, 'VERSION'), 'utf8').trim();
if (!/^\d+\.\d+\.\d+$/.test(version)) throw new Error('Invalid version');
const dist = path.join(root, 'dist');
const expected = JSON.parse(fs.readFileSync(path.join(dist, 'manifest.json'), 'utf8'));
if (expected.version !== version) throw new Error('Run the allowlist build for this version first');
const filename = 'flowspeech-' + version + '-git.tar.gz';
const archive = path.join(dist, filename);
run('git', ['archive', '--format=tar.gz', '--prefix=flowspeech/', '--output=' + archive, commit]);
const entries = run('tar', ['-tzf', archive]).trim().split('\n');
if (entries.some((entry) => !entry.startsWith('flowspeech/') || entry.split('/').includes('..'))) {
  throw new Error('Unsafe archive path');
}
// Replace only the generated Git-export verification directory.
const verify = path.join(dist, 'git-verify');
fs.rmSync(verify, { recursive: true, force: true });
fs.mkdirSync(verify, { recursive: true });
run('tar', ['-xzf', archive, '-C', verify]);
const packageRoot = path.join(verify, 'flowspeech');
const files = [];
const digest = (bytes) => crypto.createHash('sha256').update(bytes).digest('hex');
function inspect(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) throw new Error('Symlink in release: ' + absolute);
    if (entry.isDirectory()) { inspect(absolute); continue; }
    const relative = path.relative(packageRoot, absolute);
    if (/(^|\/)(?:\.tools|vendor|tests|scripts|reports|\.github|\.git|dist)(\/|$)|(^|\/)\.env|REPORT|HANDOFF/.test(relative)) {
      throw new Error('Development/private entry in Git archive: ' + relative);
    }
    const bytes = fs.readFileSync(absolute);
    if (/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|(?:sk|fs)_[A-Za-z0-9_-]{24,}/.test(bytes.toString('utf8'))) {
      throw new Error('Credential pattern in Git archive: ' + relative);
    }
    const wanted = expected.files.find((file) => file.path === relative);
    if (!wanted || digest(bytes) !== wanted.sha256) throw new Error('Git export differs from allowlist: ' + relative);
    files.push({ path: relative, bytes: bytes.length, sha256: digest(bytes) });
  }
}
inspect(packageRoot);
if (files.length !== expected.files.length) throw new Error('Missing release files');
files.sort((a, b) => a.path.localeCompare(b.path));
const sha256 = digest(fs.readFileSync(archive));
const manifest = { commit, version, archive: filename, sha256, files };
fs.writeFileSync(path.join(dist, 'git-manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
fs.writeFileSync(archive + '.sha256', sha256 + '  ' + filename + '\n');
console.log(JSON.stringify({ commit, archive, sha256, files: files.length, verifiedRoot: packageRoot }, null, 2));
