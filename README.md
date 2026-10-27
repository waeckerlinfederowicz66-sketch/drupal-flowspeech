# FlowSpeech for Drupal

## Unreleased Security Follow-up

The isolated production cURL transport retains HTTP status and Retry-After even when the response body is truncated or times out. Permanent HTTP failures do not retry; transient retries respect the server cooldown and total deadline. This also applies to the bundled shared API client.

FlowSpeech 1.1.0 integrates public API v1 with Drupal AI.
It preserves textToSpeech(text, voice), returning binary WAV, mime_type and
response. response is now the completed v1 job, including usage, not a legacy
code/data envelope. Audio is downloaded WAV at 24 kHz, mono, 16 bit, not PCM
or API base64. The old WavEncoder utility remains available for compatibility.

## Requirements and Configuration

- Drupal core constraint: ^10.2 || ^11 || ^12, PHP 8.1+ (or the higher minimum
  required by Drupal). The upstream Drupal 12 declaration is retained, but full
  Drupal 12 installation and kernel compatibility remain unverified.
- Drupal AI ^1.0.5 and Key ^1.18, with a working Guzzle HTTPS transport.
- The unreleased timeout fix requires PHP ext-curl with curl_exec enabled.
  Composer and Drupal's installation/status requirements check this dependency.
  These local changes are not included in the published 1.1.0 package.
- An active paid FlowSpeech Basic, Pro or Scale subscription and developer API
  key from [API settings](https://flowspeech.io/settings/apikeys). Trials and
  browser-login/plugin-scoped tokens do not provide public API access.

Install the module and rebuild Drupal's caches:

```bash
composer require drupal/flowspeech:^1.1
drush en flowspeech
drush cr
```

For an existing 1.0.0 installation, update the package and rebuild caches.
Store the developer key through Drupal Key, then select it under
Configuration > AI > Providers > FlowSpeech. Configure the total generation
deadline there (default 120 seconds). Existing sites without this setting use
the same default; no config update hook is needed. Rotate old developer keys
in FlowSpeech API settings before their migration expiry.

Select FlowSpeech Text to Speech and a voice in your Drupal AI workflow.
The model selector is a Drupal-only identifier, not a field sent to the API.
Unsupported model selection, model/source/speed/format/speakers options are
rejected.

## Jobs, Recovery and Safety

The service's getQuota() returns top-level quota/subscription/limits; use
quota.available, not remaining, for new work. getVoices() returns voices.

textToSpeech(text, voice, requestId=null) submits, polls and downloads under one
deadline. Persist a UUID before submission when you need reliable recovery.
Exceptions expose requestId, jobId, errorCode, retryable and traceId.
The unreleased privacy fix replaces remote HTTP/terminal messages with fixed
diagnostics and only retains 1-128 character ASCII letter/digit/underscore/hyphen
code and trace tokens that do not contain the client's API key. Invalid trace
tokens become null. Job error objects retain a safe message and boolean
retryable; error: null remains null. Recovery IDs are preserved.
Drupal AI exception strings retain stack frame names but omit call arguments,
including signed download URLs. Do not dump raw traces or client objects;
use zend.exception_ignore_args=1 for PHP diagnostic trace collection.
These privacy changes are not included in published 1.1.0.
Use the raw v1 client for queued workflows and long recordings:

```php
$api = \Drupal::service('flowspeech.api')->getClient();
$requestId = \Drupal\flowspeech\Api\FlowSpeechClient::newRequestId();
// Persist requestId before sending.
$job = $api->createJob([
  'mode' => 'single', 'text' => 'Hello.', 'voiceName' => 'Kore',
], $requestId);
// Later: $api->getJob($job['id']) or $api->findJob($requestId).
```

The client also supports multi-speaker segments, cancellation and
getAudio(jobId) for retrying a read/download without another recording.
Retries keep the original UUID/body. Polling waits at least five seconds and
honors longer Retry-After values (also HTTP dates and 429). HTTP 200 alone
does not indicate generation success. Failed/cancelled jobs stop; deleted
results are explicitly reported as result_deleted.

WAV downloads are capped at 32 MiB. Both API and storage redirects are refused.
A fresh Guzzle client uses an isolated cURL handler, not Drupal's configurable
handler stack, default headers, auth, cookies or environment proxy. A site-level
StreamHandler override cannot weaken its total HTTP timeout. Keys are never sent
to storage hosts. Logs do not record
scripts, credentials or result URLs. Result links are link-accessible, so
treat them as private information. Timeout stops waiting, not the server job.

For long jobs use a Drupal queue/CLI worker with an appropriate execution limit,
or the nonblocking job methods. PHP/web-server timeouts may terminate a blocking
Drupal AI call before the configured client deadline.

The optional fifth FlowSpeechApiService constructor argument is an explicit
isolated ClientInterface override for offline transport tests. Its handler must
implement the transport contract itself; the cURL wall-clock guarantee does not
apply to a deliberately injected custom/mock handler. Normal Drupal service
configuration does not set this argument. TLS verification and response-size
limits remain enabled on both paths.

## Development

```bash
php tests/provider.php
composer install
vendor/bin/phpunit
node scripts/build.mjs
FLOWSPEECH_TEST_ROOT="$PWD/dist/verify/flowspeech" php tests/provider.php
# After committing the release tree, inspect the actual Git export as well.
node scripts/build-git-archive.mjs HEAD
FLOWSPEECH_TEST_ROOT="$PWD/dist/git-verify/flowspeech" php tests/provider.php
```

The standalone suite uses fake Guzzle/framework interfaces and is unit-test
evidence only. FrameworkIntegrationTest.php separately runs actual Drupal
11.4.11, AI 1.5.1, Key 1.22.0 and Guzzle 7.16.1 classes against loopback HTTPS.
It checks the real provider constructor/output types, isolated credentials,
redirect refusal, streamed truncation, byte limits and timeouts. Configuration,
Key repository and logger dependencies are PHPUnit doubles, not an installed
Drupal site. The old WAV tests also run under real PHPUnit.

## Verified Full-Site Integration

[Full-site CI](https://github.com/waeckerlinfederowicz66-sketch/drupal-flowspeech/actions/runs/40306545344)
passed on Drupal 11.4.11, AI 1.5.1, Key 1.22.0, PHP 8.4.27 with GD,
SQLite 3.45.1 and Drush 13.8.0. Both fresh installation and upgrade from the
official 1.0.0 release passed 68 checks each: 4 installed-site bootstrap checks,
33 initial smoke checks and 31 persistence checks after a cache rebuild.

These tests install real Drupal sites and verify the compiled container, AI
plugin discovery and proxy execution, Key entity/repository, settings route,
Form API construction, HTML rendering, validation and submission, configuration
schema, and persistence across separate Drush processes. The upgrade retains
the selected Key and uses the 120-second default when the old configuration
has no timeout setting, until the settings form saves an explicit value.

HTTP is mocked by a test-only in-memory handler with no network fallback. It
verifies v1 payloads, quota.subscription, WAV output and credential-free storage
downloads. No production keys, paid API calls or production generation are used.
These are installed-site smoke tests, not browser automation or live API tests.
Drupal 12 bootstrap, container, kernel and provider execution remain unverified;
the official core compatibility declaration is retained without claiming a
Drupal 12 test pass.

To run the fresh and upgrade fixtures, use PHP >=8.3 with GD, PDO SQLite linked
to SQLite >=3.45, Composer and Node >=20:

```bash
node --test tests/full-site/evidence.test.mjs
node scripts/full-site.mjs fresh
node scripts/full-site.mjs upgrade
```

Each fixture uses its own directory under .tools/full-site and refuses to
overwrite an existing site. Preflight enforces runtime requirements without
platform bypasses. Git release archives use .gitattributes export-ignore to
exclude development tests, scripts, reports and private tools. The bundled
namespaced v1 client is maintained with the PHP SDK; its MIT notice is in
SDK-LICENSE.txt. Module license: GPL-2.0-or-later.

[API documentation](https://flowspeech.io/docs/public-api) |
[OpenAPI](https://flowspeech.io/openapi.json).
