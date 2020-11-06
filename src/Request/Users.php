<?php

declare(strict_types = 1);

namespace Daktela\Request;

use Daktela\BaseDaktela;
use Daktela\Response\Model\User as UserModel;
use UnexpectedValueException;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
class Users extends BaseDaktela
{

    public const METHOD = 'users';

    public const GET_PATH_MASK = 'users/%s';

    public function getMethod(): string
    {
        return self::METHOD;
    }

    public function getPathMask(): string
    {
        return self::GET_PATH_MASK;
    }

    protected function getModelClass(): string
    {
        return UserModel::class;
    }

    /**
     * @param string $name
     * @return UserModel|null
     * @throws UnexpectedValueException

    public static function getUser(string $name): ?UserModel
    {
        try {
            $result = parent::getJsonData((string)self::METHOD . '/' . $name);

            return UserModel::create($result);
        } catch (NotFoundException | UnexpectedValueException $ex) {
            throw new UnexpectedValueException($ex->getMessage(), $ex->getCode());
        }
    }*/
/**
     * @param Filter $filter
     * @return UserModel
     * @throws UnexpectedValueException

    public static function findUser(Filter $filter): UserModel
    {
        try {
            $result = parent::getJsonData((string)self::METHOD, $filter->toArray());

            return UserModel::create($result);
        } catch (NotFoundException | UnexpectedValueException $ex) {
            throw new UnexpectedValueException($ex->getMessage(), $ex->getCode());
        }
    }
 */

}
