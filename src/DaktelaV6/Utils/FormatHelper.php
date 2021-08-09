<?php


namespace Daktela\DaktelaV6\Utils;

class FormatHelper
{

    /**
     * Private constructor so no instances are created.
     */
    private function __construct()
    {
    }

    /**
     * @param string|null $number Not normalized number in any format
     * @param bool $plusSign True if the number should be prefixed with "+" or false if the number should be prefixed
     * with "00"
     * @param string $intlPrefix Default internation prefix (e.g. "420" for the Czech Republic)
     * @param int $intlLength Threshold length of number to be considered as international if the start of the number is
     * the international prefix (e.g. "420773794604")
     * @return string|null normalized number i "00"/"+" format
     */
    public static function getNormalizedPhoneNumber(
        ?string $number,
        bool $plusSign = false,
        string $intlPrefix = '420',
        int $intlLength = 12
    ): ?string {
        if (is_null($number)) {
            return null;
        }

        $number = str_replace(" ", "", $number);
        if (mb_substr($number, 0, mb_strlen($intlPrefix)) == $intlPrefix && mb_strlen($number) >= $intlLength) {
            $number = ($plusSign ? '+' : '00') . $number;
        }
        if (mb_substr($number, 0, 1) != '+' && mb_substr($number, 0, 2) != '00') {
            $number = ($plusSign ? '+' : '00') . $intlPrefix . $number;
        }
        if (mb_substr($number, 0, 1) == '+') {
            $number = ($plusSign ? '+' : '00') . mb_substr($number, 1);
        }
        if (mb_substr($number, 0, 2) == '00') {
            $number = ($plusSign ? '+' : '00') . mb_substr($number, 2);
        }

        return $number;
    }
}
