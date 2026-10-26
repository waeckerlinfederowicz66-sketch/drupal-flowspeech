# Drupal 11 Full-Site Gate

The `Drupal Full Site` GitHub Actions workflow provisions PHP 8.4 with GD and
PDO SQLite on Ubuntu 24.04. Preflight rejects PHP SQLite below 3.45 or missing
extensions; no platform requirement is ignored. Direct project versions are
Drupal 11.4.11, AI 1.5.1, Key 1.22.0 and Drush 13.8.0. The resolved Composer
lock is uploaded with each run for exact dependency provenance.

Two independent jobs install real minimal-profile sites with persistent SQLite:

- `fresh`: install the candidate from its actual Git archive.
- `upgrade`: install official 1.0.0 at commit
  `91145d2be61188a723b248d956916a810a627ea5`, save a real Key selection without
  the new timeout, replace module files with the candidate archive and run
  database updates/cache rebuild. Configuration/state must survive.

Each job checks the compiled service container, AI provider discovery and
factory/proxy construction, Key entity/repository, quota.subscription, WAV
output, credential-free downloads, route permission, real Form API construction,
HTML rendering/validation/submission, configuration schema and persistence after
another cache rebuild in a new Drush process. The legacy default deadline must
be 120 seconds until saved. There is no browser automation or Drupal 12 test.

The test-only `flowspeech_ci` module replaces Drupal's HTTP handler stack with
an in-memory responder. It has no real HTTP implementation or fallback. Only
the exact fixture quota/voices/job/audio requests are accepted. Real API keys
are never used; only `fixture-only-not-a-live-key` is stored. Unexpected hosts,
paths, credentials, cookies or payloads throw. Mail uses Drupal's test collector;
automatic cron is disabled. Site provisioning downloads public dependencies,
but synthesis does not use any network or paid API.

## Run

```sh
node scripts/full-site.mjs fresh
node scripts/full-site.mjs upgrade
```

Use an isolated PHP satisfying preflight, Composer and Node >=20. Optional
`PHP_BINARY`/`COMPOSER_BINARY` select existing executables. All generated site,
Composer, database and log state is under `.tools/full-site/{fresh,upgrade}`.
The runner refuses to overwrite an existing fixture. Start in a fresh checkout
for repeat runs. It never installs global tools or edits release files.

The workflow runs when a `codex/drupal-*` branch is pushed, on pull requests,
and by workflow_dispatch once registered in the default branch. A branch push
is sufficient for first execution; no dispatch/default-branch write is needed.
Actions use verified commit pins and a read-only token without persisted Git
credentials. Only logs, runtime/commit/results metadata and Composer lock are
uploaded, not settings.php, SQLite databases or the installed tree.

`FixtureTransportTest.php` can be checked locally with the existing real
framework dependencies, but that alone does not pass this gate:

```sh
php .tools/vendor/bin/phpunit --no-configuration \
  --bootstrap tests/framework-bootstrap.php tests/full-site/FixtureTransportTest.php
```

Full-site status is **pending** until both actual GitHub jobs pass and the
uploaded results identify the reviewed candidate commit. Failures must be fixed
and rerun; a prepared workflow or local fixture unit test is not app execution.

References: [setup-php](https://github.com/shivammathur/setup-php),
[Drush site:install](https://www.drush.org/13.x/commands/site_install/),
[Drush releases](https://github.com/drush-ops/drush/releases).
