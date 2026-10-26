# Changelog

## 1.1.0 - 2026-10-26

- Verify real Drupal 11.4.11 fresh installation and upgrade from official 1.0.0:
  4 bootstrap, 33 initial and 31 post-cache-rebuild persistence checks per mode.
- Verify installed container/plugin discovery, Key integration, settings form
  rendering/submission and timeout fallback using mocked HTTP only. No production
  generation or paid API calls are used.
- Require actual Drush PHP execution and run-bound bootstrap/smoke evidence;
  a zero exit code or missing/stale result file cannot pass the full-site gate.
- Preserve the official branch's Drupal 12 compatibility declaration. Full Drupal
  12 bootstrap/kernel/provider compatibility remains unverified.
- Verify actual Git release exports with development/private content excluded.
- Require valid normalized request UUIDs and stable job/request identity throughout
  polling; independent reads/cancellation no longer inherit stale request IDs.
- Preserve quota.subscription and test real Drupal AI/Guzzle framework contracts.
- Reject truncated Guzzle streams whose bytes do not match Content-Length.
- Replace synchronous legacy generation with public API v1 jobs and WAV download.
- Preserve Drupal AI output and Key integration; add configurable total deadline.
- Add quota/voices, stable UUID retries, recovery, cancellation and strict result validation.
- Isolate storage downloads from API/default credentials, cap bytes and refuse redirects.
- Add offline contract/adapter tests and allowlisted release packaging.
- Require an active paid API subscription and developer key; no PCM conversion is
  needed for v1. Raw response metadata now contains the completed v1 job.

## 1.0.0

- Initial Drupal AI text-to-speech provider.
- Added Drupal Key integration for API credentials.
- Added 30 FlowSpeech voices.
- Added PCM-to-WAV conversion and unit coverage.
