<?php

namespace Daktela;

/**
 *
 * @author Petr Kalíšek <petr.kalisek@daktela.com>
 */
interface IConfig
{
    public function getInstanceUri(): string;

    public function getAccessToken(): string;
}
