<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http\Internal;

use Daktela\DaktelaV6\Auth\LoginSession;

/**
 * The secrets of an ApiCommunicator: its static access token, or its login session.
 * @internal
 */
final class Credentials
{
    /**
     * @var \WeakMap<\stdClass, LoginSession>|null login sessions, kept outside the objects' properties so that
     *     var_export(), serialize() and array casts cannot reveal their credentials and tokens. A clone shares
     *     the session of the original; an unserialized copy has none.
     */
    private static ?\WeakMap $loginSessions = null;
    /** @var string static access token; empty once a login session was started */
    private string $accessToken;
    /** @var \stdClass|null key of the login session of this object in $loginSessions */
    private ?\stdClass $sessionKey = null;
    /** @var bool whether a login request is being sent: its error response may echo the password, which is redacted */
    public bool $loginInProgress = false;

    public function __construct(#[\SensitiveParameter] string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * Replaces the static access token and any previous session with a login session.
     * @param LoginSession $session session holding the user name, the password and the token store
     */
    public function startSession(LoginSession $session): void
    {
        // A new key: a previous session must not survive a failed login
        $this->sessionKey = new \stdClass();
        self::$loginSessions ??= new \WeakMap();
        self::$loginSessions[$this->sessionKey] = $session;
        $this->accessToken = '';
    }

    /**
     * @return LoginSession|null login session, or null when a static access token is used
     */
    public function session(): ?LoginSession
    {
        return $this->sessionKey === null ? null : (self::$loginSessions[$this->sessionKey] ?? null);
    }

    /**
     * @return string access token to authenticate requests with: the one of the login session, or the static one
     */
    public function accessToken(): string
    {
        $session = $this->session();

        return $session === null ? $this->accessToken : ($session->tokens?->getAccessToken() ?? '');
    }

    /**
     * @return string[] every secret that must not reach an exception or a log: the access token, also as the
     *     server receives it, the tokens of the login session, and its password while a login is sent
     */
    public function secrets(): array
    {
        $accessToken = $this->accessToken();
        // Guzzle trims blanks from header values, so the server receives, and may echo, the trimmed token
        $secrets = [$accessToken, trim($accessToken, " \t"), $this->accessToken];
        $session = $this->session();
        if ($session !== null) {
            if ($session->tokens !== null) {
                $secrets[] = $session->tokens->getRefreshToken();
            }
            array_push($secrets, ...$session->retiredTokens);
            if ($this->loginInProgress) {
                // Only while a login is sent: in other responses a short password would mangle the text
                $secrets[] = $session->password;
            }
        }

        return array_values(array_unique($secrets));
    }

    /**
     * Hides the access token from var_dump() and print_r(). The credentials and tokens of a login session
     * are not properties of this object at all.
     * @return array properties of the object with the access token replaced
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => $this->accessToken === '' ? '' : '***',
            'session' => $this->sessionKey !== null,
        ];
    }
}
