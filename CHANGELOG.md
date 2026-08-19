# Changelog

All notable changes to this project are documented in this file. The project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [2.5.0] - 2026-08-19

### Security

- Raised the minimum `guzzlehttp/guzzle` version to 7.15.2 and added an explicit `guzzlehttp/psr7` 2.12.3 minimum so supported dependency resolutions include published security fixes.

### Fixed

- Made configured retries and HTTP 429 handling work with Guzzle's default exception behavior for 4xx and 5xx responses.
- Kept non-retryable HTTP failures wrapped in the connector's `RequestException` hierarchy.
- Avoided applying both a rate-limit wait and an exponential-backoff wait to the same retry.
- Stopped read-all and iterator pagination at the API-reported total, including exact page-size boundaries.
- Made a zero iterator item limit return immediately without an API request.
- Made update and delete requests without an object name throw `NotFoundException` instead of a PHP `TypeError`.
- Made read-all pagination handle malformed error pages consistently with `setSkipErrorRequests()`.

### Changed

- Enabled one automatic HTTP 429 retry when `RateLimitConfig` allows it, independently of the general retry configuration.
- Updated examples and public behavior documentation for authentication, custom clients, retries, rate limits, pagination, and response errors.
- Upgraded the development-only PHP_CodeSniffer constraint to a maintained 3.x release.

### Tests

- Added coverage for client dispatch, all request types, pagination boundaries, custom HTTP clients, retry and rate-limit behavior, and failure paths.
- Added a deterministic coverage gate of 90% and CI coverage reporting.
- Expanded the PHP compatibility matrix through PHP 8.5.

No public method signatures were changed in this release. The dependency minimums in the Security section are the only compatibility-sensitive upgrade requirement.

[Unreleased]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.5.0...HEAD
[2.5.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.4.2...2.5.0
