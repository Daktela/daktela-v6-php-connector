<?php

declare(strict_types=1);

namespace Daktela\Response;

use Daktela\Response\Response;
use Daktela\Response\Result;
use Daktela\Type\Json;
use Daktela\Response\Model\User as UserModel;
use Daktela\Response\Model\Ticket as TicketModel;
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

    /** @throws NotFoundException */
    public function getResult(): Result
    {
        return Result::create(parent::getResult(), $this->entityDataClass);
    }

    /** @throws NotFoundException */
    public function getUser(): UserModel
    {
        return UserModel::create(parent::getResult());
    }

    /** @throws NotFoundException */
    public function getTicket(): TicketModel
    {
        return TicketModel::create(parent::getResult());
    }

}
