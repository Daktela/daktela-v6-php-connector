<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Auth;

/**
 * State of the login session of an ApiCommunicator. The ApiCommunicator keeps it outside its own
 * properties, so that var_export(), serialize() and array casts of the ApiCommunicator cannot reveal
 * the credentials and the tokens.
 * @internal
 * @package Daktela\DaktelaV6\Auth
 */
final class LoginSession
{
    public string $username;
    public string $password;
    public ?TokenStore $tokenStore;
    public ?TokenSet $tokens = null;
    /** @var string[] tokens replaced by a refresh, which are still redacted */
    public array $retiredTokens = [];
    /** @var int|null HTTP status of a login the server rejected; no further login is sent */
    public ?int $rejectedStatus = null;
    /** @var bool whether the access token is refreshed before it expires */
    public bool $refreshBeforeExpiry = true;

    public function __construct(string $username, #[\SensitiveParameter] string $password, ?TokenStore $tokenStore)
    {
        $this->username = $username;
        $this->password = $password;
        $this->tokenStore = $tokenStore;
    }

    /**
     * Hides the password and the tokens from var_dump() and print_r().
     * @return array state of the session without its secrets
     */
    public function __debugInfo(): array
    {
        return [
            'username' => $this->username,
            'password' => '***',
            'tokens' => $this->tokens === null ? null : '***',
            'rejectedStatus' => $this->rejectedStatus,
        ];
    }
}
