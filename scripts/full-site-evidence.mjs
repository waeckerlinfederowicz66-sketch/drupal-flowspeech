import fs from 'node:fs';
import path from 'node:path';

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
