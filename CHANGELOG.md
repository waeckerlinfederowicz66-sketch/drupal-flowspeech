# Changelog

## 1.1.1 - 2026-10-27 (Candidate)

- Preserve received HTTP status and Retry-After when response bodies are
  interrupted; never retry permanent HTTP failures or shorten server cooldowns.
- Prepare a new allowlisted package. Historical installed-site results apply
  only to their original artifacts; fresh/upgrade validation remains required.

- Stop reflecting remote error messages, unsafe code/trace tokens and nested
  error metadata. Preserve null errors, safe error DTO fields and recovery IDs.
- Keep the Drupal AI exception type and structured cause, but omit call
  arguments from exception strings so signed download URLs are not rendered.
- Verify HTTP/terminal/storage failures through real Guzzle TLS and Drupal AI
  exception classes with virtual credentials only; no production API calls.

- Require ext-curl in Composer and Drupal installation/status requirements.
  Use an isolated Guzzle cURL handler instead of a site-customizable handler,
  retaining an explicit offline mock injection point. Use a
  bounded response sink, so slow headers and body share one hard timeout.
- Add verified loopback TLS regressions for slow headers/body, recovery IDs
  after timeout, and transport reuse. These changes are not in published 1.1.0.

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
