<?php

declare(strict_types = 1);

namespace Daktela\Request;

use Daktela\BaseDaktela;
use Daktela\Response\Model\Ticket;
use Exceptions\Data\NotFoundException;
use UnexpectedValueException;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
class Tickets extends BaseDaktela
{

    public const METHOD = 'tickets';
    public const STAGE_OPEN = 'OPEN';
    public const PRIORITY_LOW = 'LOW';
    public const GET_PATH_MASK = 'tickets/%s';

    public function getMethod(): string
    {
        return self::METHOD;
    }

    public function getPathMask(): string
    {
        return self::GET_PATH_MASK;
    }

    protected function getModelClass(): ?string
    {
        return Ticket::class;
    }

    /**
     * @deprecated To refactor, move to another place
     * @param array $daktelaTicketData
     * @return DaktelaTicket
     * @throws UnexpectedValueException
    public static function createTicket(array $daktelaTicketData): ?DaktelaTicket
    {
        try {
            $ticketData = parent::postData((string)self::METHOD, $daktelaTicketData);
            $ticketResponse = TicketModel::create($ticketData->getResult());

            $daktelaTicket = self::saveTicketToDb($ticketResponse);
            if ($ticketResponse->getParentTicket() && $ticketResponse->getParentTicket()->getName()) {
                self::getTicket($ticketResponse->getParentTicket()->getName());
            }

            return $daktelaTicket;
        } catch (NotFoundException | UnexpectedValueException $ex) {
            throw new UnexpectedValueException($ex->getMessage(), $ex->getCode());
        }
    }
     */
    /**
     * @deprecated To refactor, move to another place
     * @param int $daktelaTicketId
     * @param array $daktelaTicketData
     * @return DaktelaTicket
     * @throws UnexpectedValueException

    public static function updateTicket(int $daktelaTicketId, array $daktelaTicketData): DaktelaTicket
    {
        try {
            parent::putData((string)self::METHOD . '/' . $daktelaTicketId, $daktelaTicketData);

            return self::getTicket($daktelaTicketId);
        } catch (NotFoundException | UnexpectedValueException $ex) {
            throw new UnexpectedValueException($ex->getMessage(), $ex->getCode());
        }
    }*/
    /**
     * @deprecated To refactor, move to another place
     * @param int $daktelaTicketId
     * @return DaktelaTicket
     * @throws UnexpectedValueException
    public static function getTicket(int $daktelaTicketId): ?DaktelaTicket
    {
        try {
            $ticketData = parent::getJsonData((string)self::METHOD . '/' . $daktelaTicketId);
            $ticketResponse = TicketModel::create($ticketData);

            $daktelaTicket = self::saveTicketToDb($ticketResponse);
            if ($ticketResponse->getParentTicket() && $ticketResponse->getParentTicket()->getName()) {
                self::getTicket($ticketResponse->getParentTicket()->getName());
            }

            return $daktelaTicket;
        } catch (NotFoundException | UnexpectedValueException $ex) {
            throw new UnexpectedValueException($ex->getMessage(), $ex->getCode());
        }
    }
   */
    /**
     * @deprecated To refactor, move to another place
     * @param TicketModel $responseTicket
     * @throws PersistenceFailedException
     * @throws NotFoundException
     * @return DaktelaTicket
    public static function saveTicketToDb(TicketModel $responseTicket): DaktelaTicket
    {
        $repo = TableRegistry::getTableLocator()->get('DaktelaTicket');
        $daktelaTicket = $repo->find()->where(['iddaktelaticket' => $responseTicket->getName()])->first() ?: $repo->newEntity();
        if (!($daktelaTicket instanceof DaktelaTicket)) {
            throw new InvalidArgumentException('Bad SQL return instance');
        }
        if ($daktelaTicket->isNew() === false) {
            $daktelaTicket->updated = new FrozenTime();
        }
        $daktelaTicket->iddaktelaticket = $responseTicket->getName();
        $daktelaTicket->data = $responseTicket->toObject();
        $daktelaTicket = $repo->saveOrFail($daktelaTicket);
        return $daktelaTicket;
    }
   */

}
