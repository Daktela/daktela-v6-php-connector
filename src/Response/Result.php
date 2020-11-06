<?php
declare(strict_types=1);

namespace Daktela\Response;

use Daktela\Response\Model\Ticket as TicketModel;
use Daktela\Type\Json;
use Exceptions\Data\NotFoundException;
use InvalidArgumentException;

/**
 * @author Petr Kalíšek <petr.kalisek@daktela.com>
 */
class Result extends Json
{

    /**
     * @var string|null
     */
    private $entityDataClass = null;

    public static function create(object $data, string $entityDataClass = null): self
    {
        $object = parent::create($data);
        $object->setDataEntityClass($entityDataClass);
        return $object;
    }

    public function setDataEntityClass(string $entityDataClass): Result
    {
        $this->entityDataClass = $entityDataClass;
        return $this;
    }

    
    public function getData(): array
    {
        $data = parent::getData();
        if (!is_array($data)) {
            throw new InvalidArgumentException(sprintf('Data in %s are not array', __CLASS__));
        }

        if ($this->entityDataClass === null) {
            return $data;
        }

        return array_map(function ($o) {
            $entityDataClass = $this->entityDataClass;
            return $entityDataClass::create($o);
        }, $data);
    }

    /**
     * @return TicketModel|null
     */
    public function getTicket(): ?TicketModel
    {
        try {
            return TicketModel::create(parent::getTicket());
        } catch (NotFoundException $ex) {
            return null;
        }
    }


}
