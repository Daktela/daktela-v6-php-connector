<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http\Internal;

use Daktela\DaktelaV6\Exception\RequestException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates the Guzzle client the connector uses when no custom HTTP client is set.
 * @internal
 */
final class DefaultClientFactory
{
    /** @var string Request option shared by the hops of a redirected request; records that one left the instance */
    private const LEFT_INSTANCE_OPTION = 'daktela_left_instance';

    /**
     * Creates the default Guzzle HTTP client with configured settings.
     *
     * @param string $baseUrl URL of the Daktela instance
     * @param float $requestTimeout timeout of a request in seconds
     * @param bool $verifySsl whether SSL certificates are verified
     * @param LoggerInterface|null $logger logger warned about an insecure transport
     * @return ClientInterface
     * @throws RequestException when the instance URL cannot be parsed
     */
    public static function create(string $baseUrl, float $requestTimeout, bool $verifySsl, ?LoggerInterface $logger): ClientInterface
    {
        try {
            $instance = new Uri((string)InstanceUrl::normalize($baseUrl));
        } catch (\InvalidArgumentException $invalidUrl) {
            // The message would quote the URL, which is not needed to find the mistake
            throw new RequestException('The instance URL is not a valid URL.');
        }
        if (!$verifySsl || strcasecmp($instance->getScheme(), 'https') !== 0) {
            $logger?->warning('The connection to the Daktela API is not secure', [
                'https' => strcasecmp($instance->getScheme(), 'https') === 0,
                'verify_ssl' => $verifySsl,
            ]);
        }
        $handler = HandlerStack::create();
        // Guzzle removes the Authorization header once a redirect leaves the origin, but not X-AUTH-TOKEN.
        // Every hop is built from the request that still carries the token, so the hops share a state object:
        // the outer middleware creates it, and the one inside allow_redirects sees every hop and, once a hop
        // has left the instance, keeps the token off the rest of the chain.
        $handler->before('allow_redirects', static function (callable $next): callable {
            return static function (RequestInterface $request, array $options) use ($next) {
                $options[self::LEFT_INSTANCE_OPTION] = new \stdClass();

                return $next($request, $options);
            };
        }, 'track_redirects_leaving_instance');
        $handler->after('allow_redirects', static function (callable $next) use ($instance): callable {
            return static function (RequestInterface $request, array $options) use ($next, $instance) {
                $state = $options[self::LEFT_INSTANCE_OPTION] ?? new \stdClass();
                if (InstanceUrl::leavesInstance($instance, $request->getUri())) {
                    $state->left = true;
                }
                if (!empty($state->left)) {
                    $request = $request->withoutHeader('X-AUTH-TOKEN');
                }

                return $next($request, $options);
            };
        }, 'drop_token_outside_instance');

        return new Client([
            'base_uri' => $instance,
            'timeout' => $requestTimeout,
            'verify' => $verifySsl,
            'handler' => $handler,
        ]);
    }
}
