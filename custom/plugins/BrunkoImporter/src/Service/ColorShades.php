<?php declare(strict_types=1);

namespace Brunko\Importer\Service;

/**
 * Spresnenie nazvu farby Autronicu podla pripony kodu produktu (80001-01 BK, TV-915 GREY2, TV-915 LAN2):
 * pismena = farba (GREY Sivá, CRM Krémová, LAN Lanýžová ...), cislo = odtien (CRM2 => Krémová 2). Hex kod je pre vsetky odtiene rovnaky.
 */
final class ColorShades
{
    /** Pismena v pripone => nazov farby. */
    private const TOKENS = [
        'GREY' => 'Sivá', 'SIL' => 'Strieborná', 'CRM' => 'Krémová', 'LAN' => 'Lanýžová', 'CAP' => 'Kapučínová',
        'BK' => 'Čierna', 'WT' => 'Biela', 'BR' => 'Hnedá', 'BRN' => 'Hnedá', 'BGE' => 'Béžová', 'BEI' => 'Béžová',
        'GRN' => 'Zelená', 'BLUE' => 'Modrá', 'YEL' => 'Žltá', 'PINK' => 'Ružová', 'RED' => 'Červená',
        'ORA' => 'Oranžová', 'PUR' => 'Fialová', 'NAT' => 'Prírodná',
        'OAK' => 'Dub', 'DUB' => 'Dub', 'OLW' => 'Staré drevo', 'WAL' => 'Orech', 'ART' => 'Artisan',
        'WOT' => 'Wotan', 'SON' => 'Sonoma', 'COF' => 'Kávová', 'BUK' => 'Buk', 'TRV' => 'Čerešňa',
        'CSM' => 'Kašmírová', 'ELM' => 'Jilm', 'CR' => 'Chróm',
    ];

    /** Pripona kodu za poslednou medzerou ("TV-915 GREY2" => "GREY2"). */
    public static function suffix(string $code): string
    {
        $pos = strrpos($code, ' ');

        return strtoupper($pos === false ? $code : substr($code, $pos + 1));
    }

    /**
     * Nazov farby podla pripony kodu: CRM => Krémová, CRM2 => Krémová 2, CRM3 => Krémová 3.
     * Null, ak kod nema priponu so znamou skratkou farby.
     */
    public static function name(string $code): ?string
    {
        if (!str_contains($code, ' ')) {
            return null;
        }
        if (preg_match('/^([A-Z]+)(\d*)$/', self::suffix($code), $m) && isset(self::TOKENS[$m[1]])) {
            return $m[2] === '' ? self::TOKENS[$m[1]] : self::TOKENS[$m[1]] . ' ' . $m[2];
        }

        return null;
    }
}
