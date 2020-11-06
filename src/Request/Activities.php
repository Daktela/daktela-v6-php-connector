<?php

declare(strict_types=1);

namespace Daktela\Request;

use Daktela\BaseDaktela;
use Daktela\Response\Model\Activity;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
class Activities extends BaseDaktela
{

    public const METHOD = 'activities';
    public const TYPE_EMAIL = 'EMAIL';
    public const TYPE_SMS = 'SMS';
    public const ACTION_OPEN = 'OPEN';
    public const ACTION_CLOSE = 'CLOSE';

    public function getMethod(): string
    {
        return self::METHOD;
    }

    /**
     * @todo not complete
     * @return string
     */
    public function getPathMask(): string
    {
        return "NOT_READY";
    }

    protected function getModelClass(): ?string
    {
        return Activity::class;
    }

    /**
     * @param array $daktelaActivitiesData
     * @return \App\Model\Entity\DaktelaTicket|null
     * @throws UnexpectedValueException public static function createActivity(array $daktelaActivitiesData): ?DaktelaTicket
     *     {
     *         try {
     *             $activitiesData = parent::postData((string)self::METHOD, $daktelaActivitiesData);
     *             $ticketResponse = $activitiesData->getResult()->getTicket();
     *             if ($ticketResponse === null) {
     *                 return null;
     *             }
     * 
     *             return Tickets::getTicket($ticketResponse->getName());
     *         } catch (NotFoundException | UnexpectedValueException $ex) {
     *             throw new UnexpectedValueException($ex->getMessage(), $ex->getCode());
     *         }
     *     }
     */
    /**
     * @param int $daktelaActivitiesId
     * @return \App\Model\Entity\DaktelaTicket
     * @throws UnexpectedValueException public static function getActivity(int $daktelaActivitiesId): DaktelaTicket
     *     {
     *         try {
     *             parent::getJsonData((string)self::METHOD . '/' . $daktelaActivitiesId);
     * 
     *             return $daktelaTicket = Tickets::getTicket($daktelaActivitiesId);
     *         } catch (NotFoundException | UnexpectedValueException $ex) {
     *             throw new UnexpectedValueException($ex->getMessage(), $ex->getCode());
     *         }
     *     }
     */
    /**
     * @param string $subject
     * @param string $message
     * @param string $recipients
     * @param string $queue
     * @param int $daktelaTicketId
     * @return \App\Model\Entity\DaktelaTicket|null
     * @throws UnexpectedValueException public static function sendEmail(string $subject, string $message, string $recipients, string $queue, int $daktelaTicketId = null): ?DaktelaTicket
     *     {
     *         return self::createActivity([
     *                     'type' => self::TYPE_EMAIL,
     *                     'subject' => $subject,
     *                     'message' => $message,
     *                     'to' => trim($recipients),
     *                     'queue' => $queue,
     *                     'sendMail' => true,
     *                     'action' => self::ACTION_CLOSE,
     *                     'ticket' => $daktelaTicketId,
     *         ]);
     *     } */
    /**
     * @param string $message
     * @param string $recipient
     * @param string $queue
     * @return \App\Model\Entity\DaktelaTicket|null
     * @throws UnexpectedValueException public static function sendSms(string $message, string $recipient, string $queue, int $daktelaTicketId = null): ?DaktelaTicket
     *     {
     *         return self::createActivity([
     *                     'type' => self::TYPE_SMS,
     *                     'number' => trim($recipient),
     *                     'text' => $message,
     *                     'queue' => $queue,
     *                     'ticket' => $daktelaTicketId,
     *         ]);
     *     } */
}
