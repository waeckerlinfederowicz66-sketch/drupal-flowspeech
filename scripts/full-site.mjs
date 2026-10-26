import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const mode = process.argv[2];
if (!['fresh', 'upgrade'].includes(mode)) throw new Error('Usage: node scripts/full-site.mjs fresh|upgrade');
const state = path.join(root, '.tools/full-site', mode);
if (fs.existsSync(state)) throw new Error('Refusing to overwrite an existing fixture: ' + state);
const logs = path.join(state, 'logs');
const site = path.join(state, 'site');
const web = path.join(site, 'web');
fs.mkdirSync(logs, { recursive: true });
const php = process.env.PHP_BINARY || 'php';
const composer = process.env.COMPOSER_BINARY || 'composer';
const env = { ...process.env, FLOWSPEECH_SITE_MODE: mode, FLOWSPEECH_SITE_LOG: logs,
  COMPOSER_HOME: path.join(state, 'composer-home'), COMPOSER_CACHE_DIR: path.join(state, 'composer-cache'),
  COMPOSER_AUTH: '{}', COMPOSER_NO_INTERACTION: '1', TMPDIR: path.join(state, 'tmp'),
  LC_ALL: 'C', COPYFILE_DISABLE: '1' };
for (const name of ['FLOWSPEECH_API_KEY', 'GITHUB_TOKEN', 'GH_TOKEN', 'COMPOSER', 'COMPOSER_VENDOR_DIR']) delete env[name];
fs.mkdirSync(env.TMPDIR, { recursive: true });
let sequence = 0;
function run(label, command, args, cwd = root, extraEnv = {}) {
  console.log('[' + mode + '] ' + label);
  const result = spawnSync(command, args, { cwd, env: { ...env, ...extraEnv }, encoding: 'utf8',
    timeout: 8 * 60 * 1000, maxBuffer: 32 * 1024 * 1024 });
  const output = (result.stdout || '') + (result.stderr || '');
  fs.writeFileSync(path.join(logs, String(++sequence).padStart(2, '0') + '-' + label + '.log'), output);
  process.stdout.write(output);
  if (result.error || result.status !== 0) throw new Error(label + ' failed: ' + (result.error?.message || result.status));
  return result.stdout || '';
}
const drush = (label, args, extraEnv = {}) => run(label, php,
  [path.join(site, 'vendor/bin/drush'), '--root=' + web, '--uri=http://localhost', '--no-interaction', ...args], web, extraEnv);
const resultFile = path.join(state, 'result.json');
const report = { mode, status: 'pending', full_site_executed: false };
try {
  const preflight = run('runtime-preflight', php, [path.join(root, 'tests/full-site/preflight.php')]);
  fs.writeFileSync(path.join(state, 'runtime.json'), preflight);
  const commit = run('candidate-commit', 'git', ['rev-parse', 'HEAD']).trim();
  const legacy = '91145d2be61188a723b248d956916a810a627ea5';
  run('official-ancestry', 'git', ['merge-base', '--is-ancestor', legacy, commit]);
  fs.writeFileSync(path.join(state, 'commit.json'), JSON.stringify({ commit, legacy, mode }, null, 2) + '\n');
  fs.mkdirSync(site);
  fs.copyFileSync(path.join(root, 'tests/full-site/project.composer.json'), path.join(site, 'composer.json'));
  run('composer-install', composer, ['install', '--no-interaction', '--prefer-dist', '--no-progress'], site);
  run('platform-requirements', composer, ['check-platform-reqs'], site);
  const custom = path.join(web, 'modules/custom');
  fs.mkdirSync(custom, { recursive: true });
  const modulePath = path.join(custom, 'flowspeech');
  const installModule = (ref, label) => {
    const archive = path.join(state, label + '.tar');
    run(label + '-archive', 'git', ['archive', '--format=tar', '--output=' + archive, ref]);
    // This path exists only inside the newly-created disposable site.
    fs.rmSync(modulePath, { recursive: true, force: true });
    fs.mkdirSync(modulePath);
    run(label + '-extract', 'tar', ['-xf', archive, '-C', modulePath]);
  };
  installModule(mode === 'upgrade' ? legacy : commit, 'initial-module');
  fs.cpSync(path.join(root, 'tests/full-site/fixtures/flowspeech_ci'), path.join(custom, 'flowspeech_ci'), { recursive: true });
  const settings = path.join(web, 'sites/default/settings.php');
  fs.copyFileSync(path.join(web, 'sites/default/default.settings.php'), settings);
  fs.chmodSync(settings, 0o600);
  fs.appendFileSync(settings, `
$settings['trusted_host_patterns'] = ['^localhost$', '^127\\.0\\.0\\.1$'];
$config['system.mail']['interface']['default'] = 'test_mail_collector';
$config['automated_cron.settings']['interval'] = 0;
$settings['http_client_config']['headers']['Authorization'] = 'must-not-leak';
$settings['http_client_config']['headers']['Cookie'] = 'must-not-leak';
`);
  drush('site-install', ['site:install', 'minimal', '--yes',
    '--db-url=sqlite://sites/default/files/.ht.sqlite?module=sqlite',
    '--account-name=ciadmin', '--account-pass=fixture-admin-not-secret',
    '--account-mail=ci@example.invalid', '--site-mail=ci@example.invalid', '--site-name=FlowSpeech CI']);
  report.full_site_executed = true;
  drush('install-deny-network-fixture', ['pm:install', 'flowspeech_ci', '--yes']);
  drush('install-module-and-dependencies', ['pm:install', 'flowspeech', '--yes']);
  drush('seed-real-key-and-config', ['php:script', path.join(root, 'tests/full-site/seed.php')]);
  if (mode === 'upgrade') {
    installModule(commit, 'upgrade-candidate');
    drush('database-updates', ['updatedb', '--yes']);
  }
  drush('cache-rebuild-before-smoke', ['cache:rebuild']);
  drush('initial-smoke', ['php:script', path.join(root, 'tests/full-site/smoke.php')], { FLOWSPEECH_SITE_PHASE: 'initial' });
  drush('cache-rebuild-after-form-save', ['cache:rebuild']);
  drush('persisted-smoke', ['php:script', path.join(root, 'tests/full-site/smoke.php')], { FLOWSPEECH_SITE_PHASE: 'persisted' });
  report.status = 'passed';
  report.commit = commit;
  report.phases = ['initial', 'persisted'].map((phase) => JSON.parse(fs.readFileSync(path.join(logs, 'smoke-' + phase + '.json'), 'utf8')));
} catch (error) {
  report.status = 'failed';
  report.error = error.message;
  process.exitCode = 1;
  console.error(error.message);
} finally {
  fs.writeFileSync(resultFile, JSON.stringify(report, null, 2) + '\n');
}
