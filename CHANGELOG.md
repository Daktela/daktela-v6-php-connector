# Changelog

All notable changes to this project are documented in this file. The project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Security

- Wrapped every other exception of the HTTP client, such as an invalid redirect URL or an exception of a custom client, in a `RequestException` with the access token redacted and no previous exception, because its own previous exceptions may carry the token. Previously, it reached the caller unchanged, so its message could contain the token and `ping()` and `healthCheck()` threw it.
- Replaced the truncated body summary in messages of other Guzzle exceptions with the summary of the redacted body, so a token cut at the 120-byte limit no longer leaves a fragment.
- Redacted the access token in the keys of decoded API errors, not only in their values.

### Added

- `Client::login()` and `ApiCommunicator::login()` to start a session with a username and password instead of a static access token. The connector refreshes the short-lived access token before it expires and after the server rejects it, and logs in again when the session has ended. A `TokenStore` keeps the session across processes; `InMemoryTokenStore` and the `TokenSet` value object are included. Stored tokens record the user and instance they belong to and are not used for another one. The password and the tokens are kept out of exception traces, also on PHP 8.0 and 8.1, and out of `var_dump()` and `print_r()` output. Login and refresh requests do not follow redirects, which would send the credentials to the redirect target. After the server rejects the credentials, no further login is sent until `login()` is called again. Accounts with two-factor authentication cannot log in this way.

### Fixed

- Kept the class of a custom subclass of a Guzzle exception in the copy chained as the previous exception. A subclass whose constructor takes other arguments falls back to the nearest Guzzle class.
- Reported an HTTP error response whose body cannot be read as a `RequestException` with an empty body, instead of a `RuntimeException`.
- Used the `Retry-After` header of an HTTP 429 response without a `RateLimitConfig`. `RateLimitException::getRetryAfterSeconds()` previously reported the default 5 seconds.
- Reported a request payload that cannot be encoded as JSON, such as a string that is not valid UTF-8, as a `RequestException` instead of Guzzle's `InvalidArgumentException`.

## [2.7.1] - 2026-10-14

### Security

- Chained a copy of the Guzzle exception without the access token as the previous exception with header authentication. Its request has no credential headers, and the token is replaced in its URL, message, response headers, body, and handler context. Objects in the handler context are left out. For a transfer error, a response body that is unreadable, of unknown size, or larger than 1 MiB is also left out. Previously, the chained exception held the `X-AUTH-TOKEN` or `Authorization` header and the raw response body, and 2.7.0 dropped it entirely when its message contained the token.
- Rebuilt HTTP error messages from the redacted response body. The Guzzle message summarizes the first 120 bytes of the raw body, so a token echoed across that limit left a fragment in exception messages, log entries, and `healthCheck()` errors.
- Rejected an access token that contains a line break or another control character, for example one read from a file without trimming, with a `RequestException` before sending it in a header. Any other invalid request header also raises a `RequestException` with the token redacted. Previously, Guzzle's `InvalidArgumentException` quoted the full token, and `ping()` and `healthCheck()` threw it instead of reporting the API as unhealthy.
- Made the default HTTP client drop the `X-AUTH-TOKEN` header from redirected requests that leave the instance: another host, another port, or plain HTTP from an HTTPS instance. An upgrade from HTTP to HTTPS on the same host stays on the instance. Once a redirect has left the instance, the header stays off for the rest of the redirect chain, also when a later redirect returns to the instance. Guzzle already removes the `Authorization` header on such redirects.
- Redacted the access token with blanks trimmed as well. Guzzle trims spaces and tabs from header values, so the server receives the trimmed token, and an echo of it was not redacted.
- Redacted the access token from decoded API errors in `RequestException::getApiErrors()` and `Response::getErrors()`. This covers a token written with JSON escapes and errors of responses received through a client with `http_errors` disabled.

No public method signatures were changed in this release. With header authentication, `RequestException::getPrevious()` is again a Guzzle exception of the same class for HTTP and connection errors, as before 2.7.0, but it is a copy, not the instance the HTTP client threw, so its stack trace points to the connector. Messages of HTTP error responses no longer use a body summarizer configured on a custom HTTP client. An invalid request header now raises `RequestException` instead of `InvalidArgumentException`.

## [2.7.0] - 2026-10-14

### Security

- Stopped chaining the Guzzle exception as the previous exception with header authentication (`X-AUTH-TOKEN` or `Authorization: Bearer`) when its message contains the access token, for example because the response echoes it. Query parameter authentication already did not chain it.

### Fixed

- Made `ping()` and `healthCheck()` report an invalid, expired, or revoked access token as unhealthy. The `whoim` endpoint answers such requests with HTTP 200 and no user, which the health check previously reported as healthy. It now requires the response to identify a user, so a successful response that is not a `whoim` result, for example from a wrong instance URL, is also reported as unhealthy.
- Made `RateLimitException::getHttpStatus()` return 429 instead of `null`, so `healthCheck()` reports `status_code` 429 for a rate-limited check.
- Made a response that is not valid JSON throw a `RequestException` that carries the HTTP status and the response body with the access token redacted. Previously, neither was available.

### Added

- `ApiCommunicator::AUTHENTICATION_METHOD_BEARER` to send the access token in the standard `Authorization: Bearer` header, which the Daktela API documentation recommends. It requires Daktela 31.37.0, 32.36.0, 33.14.0, or later. The default method is still the `X-AUTH-TOKEN` header.

### Changed

- Made `healthCheck()` report the first error message returned by the API (for example `New password should be set`) as `error`, and include `status_code` for HTTP error responses. Previously, `error` contained the HTTP client's message with a truncated response body, and `status_code` was missing.

No public method signatures were changed in this release. Review the entries above if your code parses the `error` key of `healthCheck()`, relies on `RequestException::getPrevious()` with header authentication, or expects `RateLimitException::getHttpStatus()` to be `null`.

## [2.6.0] - 2026-10-06

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
- Kept the path of an instance URL such as `https://example.com/daktela/` in request URLs. An instance URL that already ends with `/api/v6` still works.
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
- Object names and relations containing `/` or `\` now throw `RequestException` instead of being sent. Read relations with `RequestFactory::buildReadRelationRequest()` or `setRelation()` instead of embedding them in the object name.
- With query parameter authentication, `RequestException::getPrevious()` is now `null` for HTTP and connection errors. Use `getHttpStatus()`, `getResponseBody()`, and `getApiErrors()` instead of `getPrevious()->getResponse()`.
- `ARequest::getResponse()` now returns `?Response` and returns `null` before a response is stored.
- Raised the development-only PHPStan constraint from 0.12 to 2.x and added a level 5 static analysis job to CI.

Public methods only gained optional parameters, and `ARequest::getResponse()` widened its return type. Subclasses that override `ARequestWithAttributes::addAttributes()` must add the optional `bool $includeNull = false` parameter. Review the Changed section if you combine several OR filter groups, embed relations in object names, or read `getPrevious()` with query parameter authentication.

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

[Unreleased]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.7.1...HEAD
[2.7.1]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.7.0...2.7.1
[2.7.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.6.0...2.7.0
[2.6.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.5.0...2.6.0
[2.5.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.4.2...2.5.0
