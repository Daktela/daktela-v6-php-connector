<?php

declare(strict_types=1);

namespace Daktela\Response\Model;

use Daktela\Type\Json;
use Exceptions\Data\NotFoundException;

/**
 * @author Petr Kalíšek <petr.kalisek@daktela.com>
 *
 * @method string getTitle()
 */
class Ticket extends Json
{

    /**
     *
     * @return int
     * @throws NotFoundException
     */
    public function getName(): int
    {
        return (int)parent::getName();
    }
}
