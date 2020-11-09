<?php
declare(strict_types=1);

namespace Daktela;

use Exceptions\Data\NotFoundException;

/**
 * @author Petr Kalíšek <petr.kalisek@daktela.com>
 */
class Config implements IConfig
{

    const TRANFER_PROTOCOL = 'https';
    const API_PATH_MASK = '/api/v6/%s.json?';

    /**
     * @return string
     * @throws NotFoundException
     */
    public function getInstanceUri(): string
    {
        $instance = null;
        if (getenv('DAKTELA_INSTANCE') !== false) {
            $instance = getenv('DAKTELA_INSTANCE');
        }
        if ($instance === null) {
            throw new NotFoundException('Daktela instance not found in env settings, DAKTELA_INSTANCE missing');
        }

        return (string)self::TRANFER_PROTOCOL . '://' . $instance;
    }

    /**
     * @return string
     * @throws NotFoundException
     */
    public function getAccessToken(): string
    {
        if (getenv('DAKTELA_ACCESSTOKEN') !== false) {
            return (string)getenv('DAKTELA_ACCESSTOKEN');
        }

        throw new NotFoundException('Daktela accesstoken not found in env settings, DAKTELA_ACCESSTOKEN missing');
    }

}
