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
        'COP' => 'Medená', 'OR' => 'Oranžová', 'GOLD' => 'Zlatá', 'BOR' => 'Bordová', 'LILA' => 'Fialová',
        'SAL' => 'Lososová', 'WH' => 'Biela', 'WHITE' => 'Biela', 'ANT' => 'Antracitová', 'RUBY' => 'Rubínová',
        'CHAM' => 'Šampanská', 'TER' => 'Terakota', 'HONEY' => 'Medová', 'RUST' => 'Hrdzavá', 'MENT' => 'Mentolová',
        'CLEAR' => 'Číra', 'COFFEE' => 'Kávová', 'ZELENA' => 'Zelená', 'SILVER' => 'Strieborná', 'CREAM' => 'Krémová',
        'BG' => 'Béžová',
    ];

    /** @return list<string> vsetky nazvy farieb (na odstranenie farby z nazvu hlavneho produktu) */
    public static function allNames(): array
    {
        return array_values(array_unique(self::TOKENS));
    }

    /** Pripona kodu za poslednou medzerou ("TV-915 GREY2" => "GREY2"). */
    public static function suffix(string $code): string
    {
        $pos = strrpos($code, ' ');

        return strtoupper($pos === false ? $code : substr($code, $pos + 1));
    }

    /** Farba z jednej casti pripony ("CRM2" => "Krémová 2"), alebo null. */
    private static function part(string $part): ?string
    {
        return preg_match('/^([A-Z]+)(\d*)$/', $part, $m) && isset(self::TOKENS[$m[1]])
            ? self::TOKENS[$m[1]] . ($m[2] === '' ? '' : ' ' . $m[2])
            : null;
    }

    /** Veľkosti v pripone kodu (DT3011 CRM-M). */
    private const SIZES = ['XS' => 0, 'S' => 1, 'M' => 2, 'L' => 3, 'XL' => 4];

    /** Cast pripony, ktora je velkostou (M) alebo priemerom (40), nie farbou. */
    private static function isSizePart(string $part): bool
    {
        return isset(self::SIZES[$part]) || ctype_digit($part);
    }

    /** Casti pripony za prvou pomlckou ("GRN-LT-M" => ["LT", "M"]). */
    private static function extraParts(string $code): array
    {
        $parts = explode('-', trim(self::suffix($code), '()'));
        array_shift($parts);

        return $parts;
    }

    /** Velkost z kodu: "DT3011 CRM-M" => "M", "OC4210 NAT-40" => "Priemer 40"; inak null. */
    public static function sizeOf(string $code): ?string
    {
        if (!str_contains($code, ' ')) {
            return null;
        }
        foreach (self::extraParts($code) as $part) {
            if (isset(self::SIZES[$part])) {
                return $part;
            }
            if (ctype_digit($part)) {
                return 'Priemer ' . $part;
            }
        }

        return null;
    }

    /** Poradie velkosti pre zoradenie hodnot (S < M < L < XL, priemery podla cisla). */
    public static function sizePosition(string $size): int
    {
        return self::SIZES[$size] ?? (int) preg_replace('/\D+/', '', $size);
    }

    /**
     * Nazov farby podla pripony kodu: CRM => Krémová, CRM2 => Krémová 2, GREY-DK => Sivá DK, GRN-WT => Zelená / Biela.
     * Velkost (M, L, 40) sa do nazvu farby nedava, je v samostatnom variante.
     * Null, ak kod nema priponu so znamou skratkou farby.
     */
    public static function name(string $code): ?string
    {
        if (!str_contains($code, ' ')) {
            return null;
        }
        $name = self::part(explode('-', trim(self::suffix($code), '()'))[0]);
        if ($name === null) {
            return null;
        }
        foreach (self::extraParts($code) as $part) {
            if (self::isSizePart($part)) {
                continue;
            }
            $name .= ($second = self::part($part)) !== null ? ' / ' . $second : ' ' . $part; // LT, DK, PEARL ...
        }

        return $name;
    }
}
