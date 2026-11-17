<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http\Internal;

use Psr\Http\Message\UriInterface;

/**
 * The URL of the Daktela instance: its normal form and the check that a request stays on it.
 * @internal
 */
final class InstanceUrl
{
    /**
     * Method for normalizing URL into one standard form the API transport class can use as part of the HTTP request.
     * @param string|null $url URL to be normalized
     * @return string|null normalized URL
     */
    public static function normalize(?string $url): ?string
    {
        if (is_null($url)) {
            return null;
        }

        // The scheme is not case-sensitive: `HTTPS://host` must not become `https://HTTPS://host`
        if (preg_match('#^https?://#i', $url) !== 1) {
            $url = "https://" . $url;
        }
        if (substr($url, -1) === "/") {
            $url = substr($url, 0, -1);
        }

        return $url;
    }

    /**
     * Checks whether a request URL leaves the Daktela instance: another host, another port, or plain HTTP
     * from an HTTPS instance. Only the upgrade from HTTP to HTTPS on the same host stays on the instance.
     * @param UriInterface $instance URL of the Daktela instance
     * @param UriInterface $target URL the request is sent to
     * @return bool true when the access token must not be sent to the URL
     */
    public static function leavesInstance(UriInterface $instance, UriInterface $target): bool
    {
        if (strcasecmp($target->getHost(), $instance->getHost()) !== 0) {
            return true;
        }
        if (strcasecmp($target->getScheme(), $instance->getScheme()) !== 0) {
            return strcasecmp($target->getScheme(), 'https') !== 0;
        }

        return self::effectivePort($target) !== self::effectivePort($instance);
    }

    /**
     * @param UriInterface $uri URL
     * @return int|null port of the URL, including the default port of its scheme
     */
    private static function effectivePort(UriInterface $uri): ?int
    {
        return $uri->getPort() ?? (['http' => 80, 'https' => 443][strtolower($uri->getScheme())] ?? null);
    }
}
