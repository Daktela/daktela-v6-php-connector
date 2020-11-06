<?php

declare(strict_types = 1);

namespace Daktela\Request;

use InvalidArgumentException;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
class Factory
{

    private const REQUEST_NAMESPACE = 'App\Lib\Daktela\Request';

    public static function createRequest(string $apiPoint): IRequest
    {
        $apiPoint = \ucfirst($apiPoint);
        $apiClass = self::REQUEST_NAMESPACE . '\\' . $apiPoint;

        if (!\class_exists($apiClass)) {
            throw new InvalidArgumentException(\sprintf('Class %s for api point %s not exists', $apiClass, $apiPoint));
        }

        return new $apiClass();
    }
}
