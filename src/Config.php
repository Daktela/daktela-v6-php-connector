<?php
declare(strict_types=1);

namespace Daktela;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Exceptions\Data\NotFoundException;

/**
 * @author Petr Kalíšek <petr.kalisek@daktela.com>
 */
class Config
{

    const TRANFER_PROTOCOL = 'https';
    const API_PATH_MASK = '/api/v6/%s.json?';
    const TICKETS_PATH_MASK = '/tickets/update/%s';

    /**
     * @return string
     * @throws NotFoundException
     */
    public static function getInstanceUri(): string
    {
        $instance = null;
        if (getenv('DAKTELA_INSTANCE') !== false) {
            $instance = getenv('DAKTELA_INSTANCE');
        }
        if ($instance === null) {
            throw new NotFoundException('Daktela instance not found in settings');
        }

        return (string)self::TRANFER_PROTOCOL . '://' . $instance;
    }

    /**
     * @return string
     * @throws NotFoundException
     */
    public static function getAccessToken(): string
    {
        if (getenv('DAKTELA_ACCESSTOKEN') !== false) {
            return (string)getenv('DAKTELA_ACCESSTOKEN');
        }

        throw new NotFoundException('Daktela accesstoken not found in settings');
    }

}
