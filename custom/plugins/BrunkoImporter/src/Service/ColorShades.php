<?php declare(strict_types=1);

namespace Brunko\Importer\Service;

/**
 * Spresnenie nazvu farby Autronicu podla pripony kodu produktu (80001-01 BK, TV-915 GREY2, TV-915 LAN2):
 * pismena = farba (GREY sivá, CRM kremová, LAN lanyzova ...), cislo = odtien (GREY2 svetlosiva, GREY3 tmavosiva).
 * Pouziva sa len tam, kde by sa v skupine variantov inak opakovala ta ista farba z feedu.
 */
final class ColorShades
{
    /** Pismena v pripone => nazov farby. */
    private const TOKENS = [
        'GREY' => 'Sivá', 'SIL' => 'Strieborná', 'CRM' => 'Krémová', 'LAN' => 'Lanýžová', 'CAP' => 'Kapučínová',
        'BK' => 'Čierna', 'WT' => 'Biela', 'BR' => 'Hnedá', 'BRN' => 'Hnedá', 'BGE' => 'Béžová', 'BEI' => 'Béžová',
        'GRN' => 'Zelená', 'BLUE' => 'Modrá', 'YEL' => 'Žltá', 'PINK' => 'Ružová', 'RED' => 'Červená',
        'ORA' => 'Oranžová', 'PUR' => 'Fialová', 'NAT' => 'Prírodná',
    ];

    /** Pismena + cislo => nazov (odtiene, ktore poznáme). */
    private const SHADES = [
        'GREY2' => 'Svetlosivá',
        'GREY3' => 'Tmavosivá',
    ];

    /** Pripona kodu za poslednou medzerou ("TV-915 GREY2" => "GREY2"). */
    public static function suffix(string $code): string
    {
        $pos = strrpos($code, ' ');

        return strtoupper($pos === false ? $code : substr($code, $pos + 1));
    }

    public static function refine(string $code, string $feedColour): string
    {
        $suffix = self::suffix($code);
        if (isset(self::SHADES[$suffix])) {
            return self::SHADES[$suffix];
        }
        if (preg_match('/^([A-Z]+)(\d*)/', $suffix, $m) && isset(self::TOKENS[$m[1]])) {
            $name = self::TOKENS[$m[1]];

            return in_array($m[2], ['', '1', '2'], true) ? $name : $name . ' ' . $m[2];
        }

        return $feedColour . ' (' . $suffix . ')';
    }
}
