<?php

declare(strict_types=1);

namespace Daktela\Request;

use Daktela\Config;
use Daktela\IConfig;
use Daktela\Request\IRequest;
use InvalidArgumentException;
use function class_exists;
use function sprintf;
use function ucfirst;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
class Factory
{

    private const REQUEST_NAMESPACE = 'Daktela\Request';

    /**
     * @var IRequest[]
     */
    private static $instances = [];

    public static function createRequest(string $apiPoint, ?IConfig $config = null): IRequest
    {
        $apiPoint = ucfirst($apiPoint);
        $apiClass = self::REQUEST_NAMESPACE . '\\' . $apiPoint;

        if (!class_exists($apiClass)) {
            throw new InvalidArgumentException(sprintf('Class %s for api point %s not exists', $apiClass, $apiPoint));
        }

        if ($config === null) {
            $config = new Config();
        }

        if (!isset(self::$instances[$apiClass])) {
            self::$instances[$apiClass] = new $apiClass($config);
        }

        return self::$instances[$apiClass];
    }

}
