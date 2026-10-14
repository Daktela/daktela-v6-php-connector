<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Auth;

/**
 * Keeps the tokens of a login session between requests and processes, so that every process does not
 * log in again: each login starts a new session on the Daktela server. Implement it with your cache or
 * database, e.g. by saving TokenSet::toArray() as JSON and restoring it with TokenSet::fromArray().
 * Refresh tokens are long-lived credentials, so store them as securely as passwords.
 * @package Daktela\DaktelaV6\Auth
 */
interface TokenStore
{
    /**
     * @return TokenSet|null saved tokens, or null when none are saved
     */
    public function load(): ?TokenSet;

    /**
     * Saves new tokens after a login or a refresh.
     * @param TokenSet $tokens tokens to be saved
     */
    public function save(TokenSet $tokens): void;
}
