<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http\Internal;

/**
 * Removes the access token, the tokens of a login session and, while a login is sent, its password from text and
 * decoded values that may end up in exceptions or logs.
 * @internal
 */
final class Redactor
{
    /** @var int Deepest nesting of arrays kept in the handler context of a copied exception */
    private const CONTEXT_DEPTH_LIMIT = 3;

    public function __construct(private readonly Credentials $credentials)
    {
    }

    /**
     * @return bool whether a login request is being sent, whose error response may echo the password
     */
    public function loginInProgress(): bool
    {
        return $this->credentials->loginInProgress;
    }

    /**
     * Redacts the body of a response that is kept in an exception.
     * @param string $rawBody body of the response
     * @return string body without the secrets; the error response to a login is rebuilt or left out
     */
    public function responseBody(string $rawBody): string
    {
        return $this->credentials->loginInProgress ? $this->redactLoginErrorBody($rawBody) : $this->redact($rawBody);
    }

    /**
     * Removes the access token from text that may end up in exceptions or logs.
     * @param string $text text possibly containing the access token (e.g. a request URL)
     * @return string text with every form of the access token replaced
     */
    public function redact(string $text): string
    {
        $forms = [];
        foreach ($this->credentials->secrets() as $secret) {
            if ($secret !== '') {
                array_push(
                    $forms,
                    $secret,
                    urlencode($secret),
                    rawurlencode($secret),
                    substr((string)json_encode($secret), 1, -1)
                );
            }
        }

        return $forms === [] ? $text : str_replace(array_unique($forms), '***', $text);
    }

    /**
     * Redacts the error response to a login, which may echo the password. The server can spell the password
     * with escapes the redaction of the raw text cannot match, e.g. `\u0026` for `&`, so a JSON body is
     * decoded, redacted value by value, and encoded again, and any other body is left out.
     * @param string $rawBody body of the error response
     * @return string body without the password and the tokens, or an empty string
     */
    public function redactLoginErrorBody(string $rawBody): string
    {
        // The raw body is moved out of the parameters, which the trace of an exception created below records
        $raw = $rawBody;
        $rawBody = '';
        $decoded = json_decode($raw);
        $raw = '';
        if (!is_object($decoded) && !is_array($decoded)) {
            return '';
        }
        $redacted = $this->maskPassword($this->redactValues(is_object($decoded) ? get_object_vars($decoded) : $decoded));
        $encoded = json_encode(is_object($decoded) ? (object)$redacted : $redacted);

        return $encoded === false ? '' : $this->redact($encoded);
    }

    /**
     * Replaces every string, including map keys, that still contains the password of the login session once its
     * HTML entities or percent-encoding are decoded, e.g. `&amp;` for `&`. The whole string is replaced, because
     * the position of the password in the encoded text is not known.
     * @param array $values decoded values, possibly nested in lists, maps and objects
     * @return array values without such strings
     */
    public function maskPassword(array $values): array
    {
        $session = $this->credentials->session();
        if ($session === null || $session->password === '') {
            return $values;
        }
        $echoes = function (string $text) use ($session): bool {
            return str_contains(html_entity_decode($text, ENT_QUOTES | ENT_HTML5), $session->password)
                || str_contains(rawurldecode($text), $session->password);
        };
        $masked = [];
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $value = $echoes($value) ? '***' : $value;
            } elseif (is_array($value)) {
                $value = $this->maskPassword($value);
            } elseif (is_object($value)) {
                $value = (object)$this->maskPassword(get_object_vars($value));
            }
            $masked[is_string($key) && $echoes($key) ? '***' : $key] = $value;
        }

        return $masked;
    }

    /**
     * Redacts the access token from the handler context of an HTTP client exception, e.g. the request headers
     * cURL reports. Only strings and nested arrays are kept: objects, and arrays nested deeper than a few
     * levels, may reference themselves and are left out.
     * @param array $context handler context of the exception
     * @param int $depth nesting level of $context
     * @return array context with every string redacted
     */
    public function redactContext(array $context, int $depth = 0): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = $this->redact($value);
            } elseif (is_array($value) && $depth < self::CONTEXT_DEPTH_LIMIT) {
                $context[$key] = $this->redactContext($value, $depth + 1);
            } elseif (is_array($value) || is_object($value)) {
                unset($context[$key]);
            }
        }

        return $context;
    }

    /**
     * Redacts the access token from every string, including map keys, in decoded API errors, which may
     * spell the token with JSON escapes the raw body redaction cannot match.
     * @param array $values decoded values, possibly nested in lists, maps and objects
     * @return array values with every string redacted
     */
    public function redactValues(array $values): array
    {
        $redacted = [];
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $value = $this->redact($value);
            } elseif (is_array($value)) {
                $value = $this->redactValues($value);
            } elseif (is_object($value)) {
                $value = (object)$this->redactValues(get_object_vars($value));
            }
            $redacted[is_string($key) ? $this->redact($key) : $key] = $value;
        }

        return $redacted;
    }
}
