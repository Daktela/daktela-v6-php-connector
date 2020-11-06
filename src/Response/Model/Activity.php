<?php

declare(strict_types=1);

namespace Daktela\Response\Model;

use Daktela\Response\Model\Ticket;
use Daktela\Type\Json;
use Exceptions\Data\NotFoundException;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
class Activity extends Json
{

    /** @throws NotFoundException */
    public function getTicket(): Ticket
    {
        return Ticket::create(parent::getTicket());
    }

}
