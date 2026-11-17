<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http\Internal;

use Daktela\DaktelaV6\Auth\TokenSet;
use Daktela\DaktelaV6\Exception\NotFoundException;
use Daktela\DaktelaV6\Exception\RateLimitException;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Response\Response;
use Psr\Log\LoggerInterface;

/**
 * Keeps a login session usable: logs in, refreshes the access token before it expires, and saves the tokens to
 * the token store of the session.
 * @internal
 */
final class SessionManager
{
    /** @var int Seconds before the expiry of a login session's access token when it is refreshed */
    private const TOKEN_REFRESH_MARGIN = 60;

    /**
     * @param Credentials $credentials credentials holding the login session
     * @param Redactor $redactor redaction of the secrets
     * @param string $baseUrl URL of the Daktela instance
     * @param LoggerInterface|null $logger logger, or null
     * @param \Closure(string): Response $send sends a login or refreshAccessToken request, whose payload the
     *     RequestBuilder takes from the session
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly Redactor $redactor,
        private readonly string $baseUrl,
        private readonly ?LoggerInterface $logger,
        private readonly \Closure $send
    ) {
    }

    /**
     * Starts the session after login(): resumes the one saved in the token store, or logs in.
     * @throws RequestException when the login fails
     */
    public function start(): void
    {
        $stored = $this->loadStoredTokens();
        if ($stored !== null) {
            $this->useTokens($stored, false);
            return;
        }
        $this->loginWithCredentials();
    }

    /**
     * Prepares a login session for a request: logs in when the session has no tokens, and refreshes an
     * access token that is about to expire. A refresh that fails, e.g. on a network error, leaves the
     * current access token in use until it expires.
     * @throws RequestException when the session cannot authenticate the request
     */
    public function prepareSession(): void
    {
        $session = $this->credentials->session();
        if ($session === null) {
            return;
        }
        if ($session->tokens === null) {
            $this->loginWithCredentials();
            return;
        }
        if (!$session->refreshBeforeExpiry || !$session->tokens->accessTokenExpiresWithin(self::TOKEN_REFRESH_MARGIN, time())) {
            return;
        }
        try {
            $this->refreshTokens();
        } catch (RequestException $ex) {
            if (!$this->hasValidAccessToken()) {
                throw $ex;
            }
            $this->logger?->warning('Refreshing the access token failed; the current one is used until it expires', [
                'error' => $ex->getMessage(),
            ]);
        }
    }

    /**
     * @return bool whether the login session has an access token that has not expired by the local clock
     */
    private function hasValidAccessToken(): bool
    {
        $tokens = $this->credentials->session()?->tokens;

        return $tokens !== null && !$tokens->accessTokenExpiresWithin(0, time());
    }

    /**
     * Logs in with the credentials of the session.
     * @throws RequestException when the login fails or was rejected before
     */
    public function loginWithCredentials(): void
    {
        $session = $this->credentials->session();
        if ($session === null) {
            throw new RequestException('There is no login session; call login() first.');
        }
        if ($session->rejectedStatus !== null) {
            throw new RequestException(
                'The server rejected the login; call login() again with valid credentials.',
                0,
                null,
                $session->rejectedStatus
            );
        }
        try {
            $this->useTokens($this->requestTokens('login'), true);
        } catch (RequestException $ex) {
            if ($ex->getHttpStatus() === 400 || $ex->getHttpStatus() === 401) {
                // Repeating a rejected login could lock the account
                $session->rejectedStatus = $ex->getHttpStatus();
                $session->tokens = null;
            }
            throw $ex;
        }
        $this->saveTokens();
        $this->logger?->info('Logged in to the Daktela API', ['username' => $session->username]);
    }

    /**
     * Replaces the access token of the session with a new one; logs in again when the refresh is rejected,
     * e.g. because the session has ended.
     * @throws RequestException when both the refresh and the login fail
     */
    public function refreshTokens(): void
    {
        $tokens = $this->credentials->session()?->tokens;
        if ($tokens === null || $tokens->isRefreshTokenExpired(time())) {
            $this->loginWithCredentials();
            return;
        }
        try {
            $this->useTokens($this->requestTokens('refreshAccessToken'), true);
        } catch (RequestException $ex) {
            if ($ex->getHttpStatus() !== 400 && $ex->getHttpStatus() !== 401) {
                throw $ex;
            }
            $this->loginWithCredentials();
            return;
        }
        $this->saveTokens();
        $this->logger?->info('Refreshed the access token of the Daktela API');
    }

    /**
     * Sends a login or token refresh request, which carries its credentials in the payload instead of the
     * access token. The payload is built from the session by the RequestBuilder, so that no frame of an exception
     * trace or of a backtrace taken by a logger has the credentials as an argument, and the exceptions are created again in this frame, without the
     * HTTP client's exception (its request contains the payload) and without the trace below.
     * @param string $apiEndpoint login or refreshAccessToken
     * @return TokenSet tokens returned by the API
     * @throws RequestException when the request fails or the response contains no tokens
     */
    private function requestTokens(string $apiEndpoint): TokenSet
    {
        $session = $this->credentials->session();
        $current = $session?->tokens;
        // An error response to a login may echo the password, so it is redacted from messages, the stored body,
        // and log entries while the login is sent
        $this->credentials->loginInProgress = $apiEndpoint === 'login';
        try {
            $response = ($this->send)($apiEndpoint);
        } catch (RateLimitException $ex) {
            throw new RateLimitException($ex->getRetryAfterSeconds());
        } catch (RequestException $ex) {
            $status = $ex->getHttpStatus();
            // A successful response that could not be parsed may contain the new tokens
            $body = $status !== null && $status >= 300 ? $ex->getResponseBody() : null;
            if ($body !== null && $apiEndpoint === 'login') {
                // Also a body that did not come with an HTTP error of the client, e.g. one that is not JSON
                // on a redirect response, is rebuilt or left out
                $body = $this->redactor->redactLoginErrorBody($body);
            }
            if ($ex instanceof NotFoundException) {
                throw new NotFoundException($ex->getMessage(), null, $body, $ex->getApiErrors());
            }
            throw new RequestException($ex->getMessage(), $ex->getCode(), null, $status, $body, $ex->getApiErrors());
        } catch (\Throwable $ex) {
            // Anything else, e.g. from a logger: its message and its own previous exceptions are not trusted
            throw new RequestException($this->redactor->redact($ex->getMessage()));
        } finally {
            $this->credentials->loginInProgress = false;
        }

        $result = $response->getData();
        $accessToken = is_object($result) ? ($result->accessToken ?? null) : null;
        $refreshToken = is_object($result) ? ($result->refreshToken ?? null) : null;
        if ($apiEndpoint !== 'login' && $current !== null && ($refreshToken === null || $refreshToken === '')) {
            // The server keeps the refresh token when it does not rotate it
            $refreshToken = $current->getRefreshToken();
        }
        if (!is_string($accessToken) || $accessToken === '' || !is_string($refreshToken) || $refreshToken === '') {
            throw new RequestException(
                sprintf(
                    'The %s response (HTTP %d) does not contain an access token and a refresh token.',
                    $apiEndpoint,
                    $response->getHttpStatus()
                ),
                0,
                null,
                $response->getHttpStatus()
            );
        }

        return TokenSet::fromTokens($accessToken, $refreshToken, $this->sessionOwner());
    }

    /**
     * @return string session the tokens of the login belong to, as `username@instance URL`
     */
    private function sessionOwner(): string
    {
        return ($this->credentials->session()->username ?? '') . '@' . InstanceUrl::normalize($this->baseUrl);
    }

    /**
     * Loads the tokens of this session from the token store. Tokens of another user or instance, expired
     * tokens, and a store that fails are ignored, and the connector logs in instead.
     * @return TokenSet|null usable stored tokens, or null
     */
    private function loadStoredTokens(): ?TokenSet
    {
        try {
            $stored = $this->credentials->session()?->tokenStore?->load();
        } catch (\Throwable $ex) {
            $this->logger?->warning('Loading the saved login session failed; logging in', [
                'error' => $this->redactor->redact($ex->getMessage()),
            ]);
            return null;
        }
        // A store shared by several users or instances must not resume the session of another one
        if ($stored === null || $stored->getOwner() !== $this->sessionOwner() || $stored->isRefreshTokenExpired(time())) {
            return null;
        }

        return $stored;
    }

    /**
     * Saves the tokens of the session to the token store. When the store fails, the session continues with
     * the tokens in memory. The tokens are read from the session rather than passed as an argument, so that
     * no frame of an exception escaping from here, e.g. from the logger, holds them.
     */
    private function saveTokens(): void
    {
        $session = $this->credentials->session();
        if ($session === null || $session->tokenStore === null || $session->tokens === null) {
            return;
        }
        try {
            $session->tokenStore->save($session->tokens);
        } catch (\Throwable $ex) {
            $this->logger?->warning('Saving the login session failed; it is kept in memory', [
                'error' => $this->redactor->redact($ex->getMessage()),
            ]);
        }
    }

    /**
     * @param TokenSet $tokens tokens of the session to authenticate the following requests with
     * @param bool $received whether the API has just issued the tokens
     */
    private function useTokens(TokenSet $tokens, bool $received): void
    {
        $session = $this->credentials->session();
        if ($session === null) {
            return;
        }
        if ($session->tokens !== null) {
            // Replaced tokens are still redacted, as later responses may repeat them
            $replaced = [$session->tokens->getAccessToken(), $session->tokens->getRefreshToken()];
            $session->retiredTokens = array_slice(array_values(array_unique(array_merge($replaced, $session->retiredTokens))), 0, 4);
        }
        $session->tokens = $tokens;
        // A token that looks expired as soon as it is issued means the local clock is ahead of the server:
        // refreshing it before every request would not help, so it is refreshed after a 401 instead
        $session->refreshBeforeExpiry = !$received || !$tokens->accessTokenExpiresWithin(self::TOKEN_REFRESH_MARGIN, time());
    }
}
