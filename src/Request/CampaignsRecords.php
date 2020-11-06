<?php

declare(strict_types = 1);

namespace Daktela\Request;

use Daktela\BaseDaktela;

/** @author Petr Kalíšek <petr.kalisek@daktela.com> */
class CampaignsRecords extends BaseDaktela
{

    public const METHOD = 'campaignsRecords';

    public function getMethod(): string
    {
        return self::METHOD;
    }

    public function getPathMask(): string
    {
        return 'NOT_READY';
    }

    /**
     * @todo missing model CampaignsRecords
     * @return string|null
     */
    protected function getModelClass(): ?string
    {
        return null;
    }

    /**
     * @param array $daktelaActivitiesData
     * @return DaktelaTicket|null
     * @throws UnexpectedValueException

    public static function createCampaignsRecord(array $daktelaActivitiesData): ?DaktelaTicket
    {
        try {
            $activitiesData = parent::postData((string)self::METHOD, $daktelaActivitiesData);
            $ticketResponse = $activitiesData->getResult()->getTicket();
            if ($ticketResponse === null) {
                return null;
            }

            return Tickets::getTicket($ticketResponse->getName());
        } catch (NotFoundException | UnexpectedValueException $ex) {
            throw new UnexpectedValueException($ex->getMessage(), $ex->getCode());
        }
    }
     */
    /**
     * @param string $message
     * @param string $recipient
     * @param string $queue
     * @return DaktelaTicket|null
     * @throws UnexpectedValueException

    public static function callCampaignsRecord(string $message, string $recipient, string $queue, int $daktelaTicketId = null): ?DaktelaTicket
    {

        // {"queue": "2368", "number": "773794604", "customFields": {"tts_jazyk": ["CZ"], "texttospeech": ["TEXT"]}}

        return self::createCampaignsRecord([
                    'queue' => $queue,
                    'number' => $recipient,
                    'customFields' => [
                        'tts_jazyk' => 'CZ',
                        'texttospeech' => $message,
                    ],
                    'ticket' => $daktelaTicketId,
        ]);

        /*
          return self::createCampaignsRecord([
          'record_type' => $queue,
          'customFields' => [
          'tts_jazyk' => 'CZ',
          'texttospeech' => $message,
          '_system_number_to_cf_migration' => $recipient,
          ],
          'ticket' => $daktelaTicketId,
          ]); */
    //}

}
