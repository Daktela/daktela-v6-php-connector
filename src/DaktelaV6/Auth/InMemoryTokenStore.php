<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Auth;

/**
 * Keeps the tokens in memory only, for the lifetime of the PHP process.
 * @package Daktela\DaktelaV6\Auth
 */
final class InMemoryTokenStore implements TokenStore
{
    private ?TokenSet $tokens = null;

    public function load(): ?TokenSet
    {
        return $this->tokens;
    }

    public function save(TokenSet $tokens): void
    {
        $this->tokens = $tokens;
    }
}
