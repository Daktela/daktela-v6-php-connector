<?php


namespace Daktela\DaktelaV6\Utils;


class Formatter
{

    /**
     * Private constructor so no instances are created.
     */
    private function __construct()
    {
    }

    /**
     * @param string|null $number Not normalized number in any format
     * @param bool $plusSign True if the number should be prefixed with "+" or false if the number should be prefixed with "00"
     * @return string|null normalized number i "00"/"+" format
     */
    public static function getNormalizedPhoneNumber(?string $number, bool $plusSign = false)
    {
        if (is_null($number)) {
            return null;
        }

        $number = str_replace(" ", "", $number);
        if (mb_substr($number, 0, 3) == 420 && mb_strlen($number) == 12) {
            $number = ($plusSign ? '+' : '00').$number;
        }
        if (mb_substr($number, 0, 1) != '+' && mb_substr($number, 0, 2) != '00') {
            $number = ($plusSign ? '+' : '00').'420'.$number;
        }
        if (mb_substr($number, 0, 1) == '+') {
            $number = ($plusSign ? '+' : '00').mb_substr($number, 1);
        }

        return $number;
    }
}