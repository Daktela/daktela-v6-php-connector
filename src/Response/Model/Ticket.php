<?php

declare(strict_types=1);

namespace Daktela\Response\Model;

use Daktela\Type\Json;
use Exceptions\Data\NotFoundException;

/**
 * @author Petr Kalíšek <petr.kalisek@daktela.com>
 * @method string getTitle()
 */
class Ticket extends Json
{

    /**
     * @return string
     * @throws NotFoundException
     */
    public function getName(): string
    {
        return (string) parent::getName();
    }

}
