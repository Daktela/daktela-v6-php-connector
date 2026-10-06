# Changelog

All notable changes to this project are documented in this file. The project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Security

- Encoded object names and relation names as single URL path segments, and rejected values that could address a different endpoint: empty values, dot segments (also percent-encoded), and values containing `/`, `\`, or NUL. Model names containing dot segments are rejected as well. Previously, an object name such as `../users/admin` sent `DELETE /api/v6/users/admin.json` against a different endpoint.
- Redacted the access token from exception messages, response bodies and API errors stored on exceptions, log entries, and `healthCheck()` errors. With query parameter authentication, Guzzle error messages previously included the full URL with `accessToken`. In that mode, the Guzzle exception, whose request URL contains the token, is no longer chained as the previous exception.

### Fixed

- Stopped retrying create (`POST`) requests after timeouts and retryable 5xx responses, which could create duplicate records. They are still retried when the connection could not be established and on HTTP 429.
- Kept an OR filter group intact when it is combined with other filters. `addFilter()` followed by an OR group from `addFilterFromArray()` previously produced a single AND list, so the query returned the wrong records.
- Kept the `logic` key of a flat `addFilterFromArray()` list out of the filter conditions, and normalized `[field, operator, value]` shorthand inside nested groups.
- Stopped `PaginatedIterator` with `stopOnError: false` from requesting pages indefinitely when every page returns an error and no total is reported.
- Reported a truncation error in the read-all response when the 999-page limit is reached before all records are read, instead of silently returning a partial result.
- Kept API errors in the response when the `result` member is `null`; such responses previously reported no errors.
- Normalized an object-shaped or string `error` member into an array instead of failing with a PHP `TypeError`.
- Accepted `"0"` as an object name for read, update, and delete requests.
- Made `Client::execute()` send the request when a request is flagged as executed but has no stored response, instead of failing with a `TypeError`.
- Clamped a negative numeric `Retry-After` header to zero instead of passing it to `sleep()`.
- Kept the path of an instance URL such as `https://example.com/daktela/` in request URLs.
- Made `addAttributes()` keep object values and integer keys instead of dropping or rejecting them.
- Stripped common separators (`-`, `(`, `)`, `.`, `/`) in `FormatHelper::getNormalizedPhoneNumber()`, and returned an empty string unchanged instead of `00420`.
- Corrected the `CreateRequest` and `UpdateRequest` docblock examples, which overwrote the `number` attribute.

### Added

- `RequestException::getHttpStatus()`, `getResponseBody()`, and `getApiErrors()` for HTTP error responses.
- `NotFoundException` is now thrown for HTTP 404 responses. It extends `RequestException`, so existing handlers still catch it.
- `RetryConfig` option `retryNonIdempotentRequests` to opt back into retrying create requests in all retryable cases.
- An optional `ApiCommunicator` argument for the `Client` constructor, to configure a client independently of other clients that use the same credentials.
- `PaginatedIterator::hasStoppedOnError()` and `getErrorResponse()` to tell an error page from the end of the data.
- An `$includeNull` argument for `addAttributes()` to send `null` values, for example to clear a field. By default, `null` values are still skipped.

### Changed

- Each `addFilter()` and `addFilterFromArray()` call is now combined with the previous filters using AND logic, as documented. Two consecutive OR groups now produce `(A OR B) AND (C OR D)` instead of `A OR B OR C OR D`.
- Read-all requests and `PaginatedIterator` now start at the request's `setSkip()` offset instead of 0.
- `ARequest::getResponse()` now returns `?Response` and returns `null` before a response is stored.
- Raised the development-only PHPStan constraint from 0.12 to 2.x and added a level 5 static analysis job to CI.

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
