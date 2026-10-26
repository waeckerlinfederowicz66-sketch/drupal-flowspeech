# FlowSpeech for Drupal

Release candidate 1.1.0 integrates FlowSpeech public API v1 with Drupal AI.
It preserves textToSpeech(text, voice), returning binary WAV, mime_type and
response. response is now the completed v1 job, including usage, not a legacy
code/data envelope. Audio is downloaded WAV at 24 kHz, mono, 16 bit, not PCM
or API base64. The old WavEncoder utility remains available for compatibility.

## Requirements and Configuration

- Drupal core constraint: ^10.2 || ^11 || ^12, PHP 8.1+ (or the higher minimum
  required by Drupal). The upstream Drupal 12 declaration is retained, but full
  Drupal 12 installation and kernel compatibility are not verified by this release candidate.
- Drupal AI ^1.0.5 and Key ^1.18, with a working Guzzle HTTPS transport.
- An active paid FlowSpeech Basic, Pro or Scale subscription and developer API
  key from [API settings](https://flowspeech.io/settings/apikeys). Trials and
  browser-login/plugin-scoped tokens do not provide public API access.

After the coordinator publishes 1.1.0:

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
A fresh Guzzle client shares only Drupal's handler, not its default headers,
auth or cookies. Keys are never sent to storage hosts. Logs do not record
scripts, credentials or result URLs. Result links are link-accessible, so
treat them as private information. Timeout stops waiting, not the server job.

For long jobs use a Drupal queue/CLI worker with an appropriate execution limit,
or the nonblocking job methods. PHP/web-server timeouts may terminate a blocking
Drupal AI call before the configured client deadline.

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

The isolated dependency installation required ignoring missing ext-gd. A normal
Drupal install still needs GD, a correctly scaffolded document root and an
installed database/site. Drupal 11.4.11 requires SQLite 3.45 or newer; the private
test runtime has SQLite 3.39.5, so use a newer SQLite build or a supported database
server before running module/container/settings-form smoke tests. This is not a
full kernel or browser test. The bundled namespaced v1 client is maintained with the PHP SDK;
its MIT notice is in SDK-LICENSE.txt. Module license: GPL-2.0-or-later.

The candidate preserves the official 1.0.x branch's Drupal 12 compatibility
declaration instead of reverting the upstream update. Only the Drupal 11.4.11
framework integration has run here; full Drupal 12 bootstrap, container, kernel
and provider execution remain unverified. Git release archives use .gitattributes
export-ignore to exclude development tests, scripts, reports and private tools.

[API documentation](https://flowspeech.io/docs/public-api) |
[OpenAPI](https://flowspeech.io/openapi.json).
