# Changelog

All notable changes to this project are documented in this file. The project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

This will be version 3.0.0. Code that creates a `Client` with a static access token or `Client::login()` and executes requests needs no changes on Daktela 31.37.0, 32.36.0, 33.14.0, or later. Read the Changed and Removed sections before upgrading.

### Changed

- **Breaking:** made the standard `Authorization: Bearer` header the default authentication method instead of `X-AUTH-TOKEN`. Daktela versions older than 31.37.0, 32.36.0, or 33.14.0 do not read it and answer HTTP 401. For them, call `setAuthenticationMethod(ApiCommunicator::AUTHENTICATION_METHOD_HEADER)` on the client's `ApiCommunicator`.
- **Breaking:** PHP 8.1 is the lowest supported version.
- **Breaking:** `new Client($instance, $accessToken)` creates an `ApiCommunicator` of its own. Clients with the same instance URL and access token shared one, so a timeout, logger, or authentication method set through one client applied to the others. To share a transport, pass one `ApiCommunicator` to the constructors.
- **Breaking:** `Client::iterate()` and `PaginatedIterator` throw an `InvalidRequestException` for a page size below 1 instead of an `InvalidArgumentException`.
- `Client::getApiCommunicator()` is declared to return `ApiCommunicator` instead of `?ApiCommunicator`. It never returned `null`.
- `ApiCommunicator` no longer has a `__debugInfo()` method; `var_dump()` and `print_r()` still show `***` for the access token.
- `ApiCommunicator` delegates to internal classes in `Daktela\DaktelaV6\Http\Internal` for building requests, parsing responses, redaction, retries, and the login session. Its public methods and their behaviour are unchanged. The internal classes are not part of the public API and may change in any release.

### Added

- `InvalidRequestException`, which extends `RequestException`, for a request or configuration that is not valid and is rejected before anything is sent: an invalid model, object name, or relation, a relation without an object name, a `take` or page size below 1, an instance URL that cannot be parsed, an unknown authentication method, an access token with control characters, and a payload that cannot be encoded as JSON. Code that catches `RequestException` keeps working.

### Deprecated

- `Client::getInstance()` and `ApiCommunicator::getInstance()`. They keep one shared object, with its access token, in memory until the process ends. Create the object with `new` and pass it to the code that needs it.
- `ApiCommunicator::AUTHENTICATION_METHOD_QUERY`. The token becomes part of the request URL, which web servers and proxies log. Use a header method.

### Removed

- **Breaking:** `ARequest::setExecuted()`, `isExecuted()`, `setResponse()`, and `getResponse()`, deprecated in 2.10.0. `Client::execute()` always sends the request.
## [2.10.0] - 2026-11-17

This release fixes the findings of a review of the whole library. No public method signatures were changed. Read the Changed section before upgrading: a few calls that used to return wrong data or never finish now throw.

### Security

- Documented that `login()` with a token store does not check the password while the store holds a valid session of the user: it compares only the user name and the instance. The README said that one store "can be shared safely", which was wrong for an application that signs its own users in with their Daktela credentials and lets the user name select the stored session: any password was then accepted. To check a password, call `login()` without a store.
- Rebuilt the error response to a login from its decoded JSON, redacted value by value, and left out a body that is not JSON, also on a redirect response. A string that contains the password once its HTML entities or percent-encoding are decoded is replaced as a whole. The password was redacted only in its raw, URL-encoded, and default JSON-encoded forms, so a server or proxy that echoed it with other escapes, such as `&` for `&` or HTML entities, left it in `getResponseBody()`, the exception message, and the `API request failed` log entry.
- Built the payload of login and refresh requests where the request is created, so the password and the refresh token are no longer an argument of a connector frame. A logger or error tracker that records `debug_backtrace()` with arguments saw them while the request was sent.
- Stopped an instance URL whose path starts with `//`, such as `https://example.daktela.com//other.host` or `https://example.daktela.com//api/v6`, from sending requests to another host (`other.host`, or `api`). The path became a network-path request target, so the Bearer token, the query parameter token, and login credentials went to that host. This matters when the instance URL comes from configuration that only checks its host. A request whose target names a host of its own is refused with a `RequestException`.
- Wrapped an `\Error` thrown by a custom HTTP client or its middleware, such as a `TypeError`, in a `RequestException`. Only exceptions were wrapped, so an error reached the caller with the request, which carries the access token, in its trace.
- Rejected model names containing a literal `?` or `#`, or a NUL byte, also percent-encoded, with a `RequestException`. A model name taken from user input could add query parameters, such as a second `accessToken`, or cut off the query. A percent-encoded `?` or `#` stays part of the path and is accepted as before.
- Logged a warning when the default HTTP client is created for an `http://` instance or with SSL verification disabled.
- Marked the access token parameters of the `Client` and `ApiCommunicator` constructors and `getInstance()` with `#[\SensitiveParameter]`.
- CI: read-only token permissions, actions pinned to commits, and no credentials left in the checkout.

### Fixed

- `Response::getFirstError()` returned `null` for errors the API reports as a map, such as `{"error":{"title":"required"}}` for a form error, although `hasErrors()` was `true`. It returns the first entry whatever its key.
- A list response with `"data": null`, as on an error page, returned the whole `{data, total}` envelope from `getData()`, so `isEmpty()` was `false` and a loop over the data iterated the envelope. `getData()` is `null` now. A result whose `data` is `null` is treated as a list only when it also has a `total` field; a single record with a `data` field of its own is otherwise returned as before.
- `Client::iterate()` with a request that carries a stored response (`setResponse()` and `setExecuted(true)`) returned that response for every page, and could loop forever.
- An instance URL that Guzzle cannot parse, such as one with port 99999, threw Guzzle's `MalformedUriException` from requests of the default HTTP client, and `ping()` and `healthCheck()` threw instead of reporting an unhealthy API. A `RequestException` is thrown and the health check returns `healthy: false`.
- `ApiCommunicator::normalizeUrl()` treated the scheme as case-sensitive: `HTTPS://host` became `https://HTTPS://host`.
- The query string was built with the `arg_separator.output` ini setting, so a host that sets it to `&amp;` sent a broken query.
- `RetryConfig::getDelayForAttempt()` returned a negative delay for negative settings, which made the retry fail with a `ValueError`, and overflowed for very large attempt numbers. `RateLimitConfig::parseRetryAfter()` returned a negative `defaultWaitSeconds` as it was. Both are never negative now.
- `addFilterFromArray()` did not convert the `[field, operator, null]` shorthand, so it was sent as a list instead of a filter.

### Changed

- **Reading all records with a `take` below 1 throws a `RequestException`**, and `Client::iterate()` and `PaginatedIterator` throw an `InvalidArgumentException` for a page size below 1. The offset did not advance: the read returned duplicates or sent 999 requests, and the iterator could loop forever.
- **A read request with a relation but no object name throws a `RequestException`.** The relation was dropped silently and the request read the records of the model, e.g. all tickets instead of the activities of one ticket.
- The default Guzzle client is created once per `ApiCommunicator` and reused, instead of once per request, so connections are kept alive between requests and between the pages of a paginated read. It is created again after `setRequestTimeout()` or `setVerifySsl()`, and in a forked process, which must not share the open connections of its parent.
- `Response::getFirstError()` returns the first entry of an error map, which may be an array or an object rather than a string. It returned `null` for such errors.
- An `\Error` thrown by a custom HTTP client or its middleware reaches the caller as a `RequestException`; the `API request failed` log entry names its class.
- Properties are typed. An object created without its constructor, such as a mock with `disableOriginalConstructor()` or a subclass that does not call `parent::__construct()`, throws an `Error` from getters such as `Client::getApiCommunicator()` and `Response::getData()`, which returned `null`.
- The `Sending API request` log entry reports `has_body: true` for login and refresh requests.
- Reading all records appends each page to one array instead of copying all records read so far for every page.
- Declared `psr/http-message` as a dependency. Guzzle's PSR-7 package already required it.

### Deprecated

- `ARequest::setExecuted()`, `isExecuted()`, `setResponse()`, and `getResponse()`. The connector never sets them; they only let a caller make `Client::execute()` return a stored response. They will be removed in 3.0.0.

### Documentation

- Corrected the install constraint in the README, which still said `^2.5`.
- Replaced the realistic-looking example token in the README with a placeholder.
- Noted that `setSkipErrorRequests(true)` and `stopOnError: false` skip only error pages answered with HTTP 2xx: an HTTP 4xx or 5xx response throws, and the errors of skipped pages are not kept.

### Development

- Added `composer check`, which runs `composer validate`, the coding standard, PHPStan, and the tests, as CI does.
- Added a PSR-12 ruleset (`phpcs.xml.dist`) and a CI job for it; PHP_CodeSniffer was installed but never run.
- PHPUnit fails on warnings and risky tests.
- Moved the live API tests from `tests/DaktelaV6` to `tests/Integration`. They delete the records they create.

## [2.9.1] - 2026-11-15

### Security

- Redacted the password of a login session from the exception, its stored response body and API errors, and log entries when the server echoes the password in an error response to the login. Other responses are not redacted for the password, as a short password would mangle their text.

### Documentation

- Noted that Guzzle 7 does not retry a connection reset or a truncated body, which it reports as a plain `RequestException`, and that `getCode()` of a transfer that failed after the response headers is the status of the partial response while `getHttpStatus()` is `null`.

The trace checks of the test suite now also cover the connector's frames in the traces of exceptions passed as arguments. No public method signatures were changed in this release.

## [2.9.0] - 2026-11-15

### Added

- Supported Guzzle 8 with PSR-7 3 alongside Guzzle 7 with PSR-7 2: the constraints are `guzzlehttp/guzzle: ^7.15.2 || ^8.0.1` and `guzzlehttp/psr7: ^2.13 || ^3.0`, and Composer installs the version that fits the other dependencies of the project. With Guzzle 8:
  - Network failures without a response (`NetworkException`, such as a timeout or a connection reset before the response headers) and responses that broke off after their headers (`ResponseTransferException`, such as `ResponseTimeoutException`) are retried as connection errors: idempotent requests always, other requests only with `retryNonIdempotentRequests`. Guzzle 8 throws `ConnectException` only when the connection could not be established, so a `ConnectException`, including a connect timeout, is retried for `POST` requests as well, unless it happened on a redirect hop (see Fixed).
  - The previous exception of a `RequestException` is a copy of the Guzzle 8 exception of the same class without the access token, as with Guzzle 7. Guzzle 8 exceptions have no handler context, and Guzzle 8.1 and later leave the query out of the URL in their messages.
  - The redacted body summary also replaces the raw one in messages where Guzzle 8 escapes line breaks and other control characters, so a token cut at the 120-byte limit leaves no fragment.

### Changed

- Sent the HTTP method in upper case. Guzzle 7 already did; Guzzle 8 sends the method as given, so `sendRequest('post', ...)` would have sent `post`. This also avoids the deprecation notice PSR-7 2.11 and later trigger for a method that is not in upper case.
- Retried a network exception of a custom HTTP client (`Psr\Http\Client\NetworkExceptionInterface`) as a connection error: idempotent requests always, other requests only with `retryNonIdempotentRequests`, because only a Guzzle `ConnectException` shows that the request was not sent. Previously it was wrapped in a `RequestException` without a retry. A Guzzle transfer exception of such a client is chained as a copy without the access token, as Guzzle's own exceptions are.
- Encoded payloads and parsed responses with PHP's JSON functions instead of Guzzle's JSON helpers, which Guzzle 7.15 deprecated with a notice and Guzzle 8 removed. The messages of the resulting exceptions are unchanged.
- Declared `psr/http-client` and `ext-json` as dependencies. Guzzle already required them. Raised the `guzzlehttp/psr7` 2.x minimum to 2.13, which Guzzle 7.15.2 already required.

### Fixed

- Stopped repeating a create request (`POST`) when the connection failed on a redirect hop, for example when the server answered the request with 303 See Other after creating a record and the redirect target could not be reached. Only a connection failure of the request the connector sent counts as proof that the request was not sent. Previously, when the redirect target could not be resolved or connected to, or its TLS handshake failed, the request was sent again and could create a duplicate record. The connector tells the request from a redirect hop by its origin, method, path, and query: a redirect hop with the same method, path, and query on the instance itself, such as an upgrade to HTTPS, still counts as the request. A custom HTTP client therefore has to send the request to the instance and keep its method, path, and query; one that sends it elsewhere, e.g. through a gateway, or rewrites it, e.g. by signing the query, no longer gets a `POST` retried after its connection failed.

### Security

- Kept the access token out of the stack-trace arguments of the exceptions the connector throws, and of the token-free copy chained to them. Unless `zend.exception_ignore_args` is on, PHP records the current parameter values of every frame in a trace, and the frames that created these exceptions held the HTTP client's exception, whose request carries the token, or the raw response, which may echo it. `var_dump()`, `print_r()`, `var_export()`, and debug pages that print trace arguments showed the token, for a static token as well as for the access token of a login session. This affected connection errors, HTTP errors, responses that cannot be parsed, and HTTP 429 responses since 2.8.0, and requests rejected before they were sent with query parameter authentication.
- Stopped chaining an exception as the previous exception of the `RequestException` for a response that cannot be parsed as JSON. Its stack trace held the raw response body, which may contain the access token, as a function argument.

No public method signatures were changed in this release. With Guzzle 7, the connector behaves as in 2.8.0 except for the Changed, Fixed, and Security sections. Review the Added section if your code inspects `getPrevious()` of a `RequestException`, or relies on which network failures are retried, before your project upgrades to Guzzle 8.

## [2.8.0] - 2026-10-14

### Security

- Wrapped every other exception of the HTTP client, such as an exception of a custom client, in a `RequestException` with the access token redacted. A Guzzle request exception is chained as a copy without the token; any other exception is not chained, because its own previous exceptions may carry the token. Previously, it reached the caller unchanged, so its message could contain the token and `ping()` and `healthCheck()` threw it.
- Replaced the truncated body summary in messages of other Guzzle exceptions with the summary of the redacted body, so a token cut at the 120-byte limit no longer leaves a fragment.
- Redacted the access token in the keys of decoded API errors, not only in their values.

### Added

- `Client::login()` and `ApiCommunicator::login()` to start a session with a username and password instead of a static access token. The connector refreshes the short-lived access token before it expires and after the server rejects it, and logs in again when the session has ended. A `TokenStore` keeps the session across processes; `InMemoryTokenStore` and the `TokenSet` value object are included. Stored tokens record the user and instance they belong to and are not used for another one. The password and the tokens are kept out of the traces of the exceptions the connector throws, also on PHP 8.0 and 8.1, and out of `var_dump()`, `print_r()`, `var_export()`, and `serialize()` output. Login and refresh requests do not follow redirects, which would send the credentials to the redirect target. After the server rejects the credentials, no further login is sent until `login()` is called again, and requests fail with the HTTP status of the rejected login. Accounts with two-factor authentication cannot log in this way.

### Fixed

- Kept the class of a custom subclass of a Guzzle exception in the copy chained as the previous exception. A subclass whose constructor takes other arguments falls back to the nearest Guzzle class.
- Reported an HTTP error response whose body cannot be read as a `RequestException` with an empty body, instead of a `RuntimeException`.
- Used the `Retry-After` header of an HTTP 429 response without a `RateLimitConfig`. `RateLimitException::getRetryAfterSeconds()` previously reported the default 5 seconds.
- Reported a request payload that cannot be encoded as JSON, such as a string that is not valid UTF-8, as a `RequestException` instead of Guzzle's `InvalidArgumentException`.

No public method signatures were changed in this release. The login methods and the `Auth` classes are additive. Review the Security section if your code reads `getPrevious()` of a `RequestException` for an exception of a custom HTTP client, and the Fixed section if your code catches Guzzle's `InvalidArgumentException` for a payload that cannot be encoded as JSON.

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

[Unreleased]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.10.0...HEAD
[2.10.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.9.1...2.10.0
[2.9.1]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.9.0...2.9.1
[2.9.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.8.0...2.9.0
[2.8.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.7.1...2.8.0
[2.7.1]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.7.0...2.7.1
[2.7.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.6.0...2.7.0
[2.6.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.5.0...2.6.0
[2.5.0]: https://github.com/Daktela/daktela-v6-php-connector/compare/2.4.2...2.5.0
