<?php

declare(strict_types = 1);

namespace Daktela\Response;

use Daktela\Response\Model\User as UserModel;
use Daktela\Type\Json;
use Exceptions\Data\NotFoundException;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
class Response extends Json
{

    /**
     * @var string|null
     */
    private $entityDataClass = null;

    public static function create(object $data, ?string $entityDataClass = null): self
    {
        $object = parent::create($data);
        $object->setDataEntityClass($entityDataClass);

        return $object;
    }

    public function setDataEntityClass($entityDataClass): Response
    {
        $this->entityDataClass = $entityDataClass;

        return $this;
    }

    /**
     * @return Result
     * @throws NotFoundException
     */
    public function getResult(): Result
    {
        return Result::create(parent::getResult(), $this->entityDataClass);
    }

    /**
     * @return UserModel
     * @throws NotFoundException
     */
    public function getUser(): UserModel
    {
        return UserModel::create(parent::getResult());
    }

}
