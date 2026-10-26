# Changelog

## 1.1.0 - Unreleased

- Preserve the official branch's Drupal 12 compatibility declaration. Full Drupal
  12 bootstrap/kernel/provider compatibility remains unverified in this candidate.
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
