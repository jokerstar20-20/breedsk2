<?php declare(strict_types=1);

namespace Brunko\Importer\Service;

/**
 * Parovanie parametrov Autronicu na vlastnosti v Shopware: ako sa skupina vlastnosti vola v e-shope,
 * ako sa zobrazuje (farebne kruzky) a akym hex kodom sa zobrazi konkretna farba.
 *
 * Kluc skupiny (ID vlastnosti) ostava podla povodneho nazvu parametra z feedu, preto sa existujuce
 * produkty nerozbiju, meni sa len zobrazovany nazov a typ zobrazenia.
 */
final class PropertyMap
{
    /** Parameter z feedu => nazov skupiny v e-shope a typ zobrazenia. */
    private const GROUPS = [
        'Farba primárna' => ['label' => 'Farba', 'displayType' => 'color'],
        // farba v konfiguratore variantov (Zelená 2, Zelená 3 ...): rovnaky nazov ako Farba, ale nefiltruje sa
        'Farba variantu' => ['label' => 'Farba', 'displayType' => 'color'],
        'Veľkosť' => ['label' => 'Veľkosť', 'displayType' => 'text'],
    ];

    /** Farba (male pismena, slovensky) => hex. Neznama farba sa zobrazi ako text. */
    private const COLORS = [
        'biela' => '#ffffff',
        'čierna' => '#000000',
        'sivá' => '#808080',
        'šedá' => '#808080',
        'strieborná' => '#c0c0c0',
        'zlatá' => '#d4af37',
        'červená' => '#d9232d',
        'modrá' => '#1f5fbf',
        'zelená' => '#2e9e4f',
        'žltá' => '#f4d03f',
        'oranžová' => '#f28c28',
        'ružová' => '#f08cb4',
        'fialová' => '#7d3c98',
        'hnedá' => '#7b4a2d',
        'béžová' => '#d9c7a3',
        'krémová' => '#f3ead3',
        'tyrkysová' => '#2ec4b6',
        'bordová' => '#800020',
        'antracitová' => '#2f3640',
        'grafitová' => '#383e42',
        'medená' => '#b87333',
        'bronzová' => '#cd7f32',
        'prírodná' => '#D0A67D', // natural (drevo)
        'svetlosivá' => '#c4c4c4',
        'tmavosivá' => '#555555',
        'lanýžová' => '#b8a58a',
        'kapučínová' => '#a67b5b',
        'dub' => '#b8935a',
        'lososová' => '#fa8072',
        'rubínová' => '#9b111e',
        'šampanská' => '#f7e7ce',
        'terakota' => '#c1613e',
        'medová' => '#d9a441',
        'hrdzavá' => '#b7410e',
        'mentolová' => '#a8e6cf',
        'staré drevo' => '#8a6f55',
        'artisan' => '#a98c68',
        'wotan' => '#9c8468',
        'sonoma' => '#c9a97a',
        'kávová' => '#6f4e37',
        'buk' => '#c9a06c',
        'čerešňa' => '#9a4a2e',
        'kašmírová' => '#bfae98',
        'jilm' => '#6e4b34',
        'chróm' => '#b9bcc0',
        'orech' => '#6b4a32',
        'číra' => '#e8f1f5',
        'priehľadná' => '#e8f1f5',
        'transparentná' => '#e8f1f5',
    ];

    /** Hodnoty z feedu, ktore patria pod inu farbu (male pismena => nazov). */
    private const ALIASES = [
        'vzor' => 'Viacfarebná',
        'viacfarebné' => 'Viacfarebná',
        'viacfarebný' => 'Viacfarebná',
    ];

    /** Zobrazovany nazov skupiny. */
    public static function groupLabel(string $param): string
    {
        return self::GROUPS[$param]['label'] ?? $param;
    }

    /** Typ zobrazenia skupiny (text / color) alebo null = nemenit. */
    public static function displayType(string $param): ?string
    {
        return self::GROUPS[$param]['displayType'] ?? null;
    }

    /**
     * Zobrazovany nazov hodnoty: farby sa zjednotia na zenský rod ("Prírodné"/"Prírodný" => "Prírodná"),
     * aby sa v e-shope nezobrazovali dve varianty tej istej farby. Ostatne hodnoty sa nemenia.
     */
    public static function normalizeValue(string $param, string $value): string
    {
        if (self::displayType($param) !== 'color') {
            return $value;
        }
        [$base, $num] = self::splitNumber($value);
        if (isset(self::ALIASES[mb_strtolower($base)])) {
            return self::ALIASES[mb_strtolower($base)] . $num;
        }
        $canonical = self::canonicalColor($base);

        return $canonical !== null ? mb_strtoupper(mb_substr($canonical, 0, 1)) . mb_substr($canonical, 1) . $num : $value;
    }

    /**
     * Zakladne farby pre filter: "Zelená 2" => ["Zelená"], "Sivá DK" => ["Sivá"], "Zelená / Biela" => ["Zelená", "Biela"].
     *
     * @return list<string>
     */
    public static function baseColors(string $value): array
    {
        $out = [];
        foreach (explode(' / ', trim($value)) as $part) {
            $base = self::splitNumber($part)[0];
            $base = self::ALIASES[mb_strtolower($base)] ?? $base;
            if ($base !== '') {
                $out[mb_strtolower($base)] = $base;
            }
        }

        return array_values($out);
    }

    /** Hex kod farby pre hodnotu, ak je skupina farebna a farbu poznáme. */
    public static function colorHex(string $param, string $value): ?string
    {
        if (self::displayType($param) !== 'color') {
            return null;
        }
        $canonical = self::canonicalColor(self::splitNumber($value)[0]);

        return $canonical !== null ? self::COLORS[$canonical] : null;
    }

    /** "Sivá DK" => ["Sivá", " DK"], "Zelená / Biela" => ["Zelená", " / Biela"] (odtien/velkost sa pri hex kode ignoruje). */
    private static function splitNumber(string $value): array
    {
        $v = trim($value);
        $base = ($pos = mb_strpos($v, ' / ')) !== false ? mb_substr($v, 0, $pos) : $v;
        while (preg_match('/^(.*\S)\s+(\d+|[A-Z]{1,6}|\([^)]*\))$/u', $base, $m)) {
            $base = $m[1];
        }

        return [$base, mb_substr($v, mb_strlen($base))];
    }

    /** Kluc v COLORS pre hodnotu (presne, alebo bez ohladu na rod: prirodne/prirodny/prirodna). */
    private static function canonicalColor(string $value): ?string
    {
        $v = mb_strtolower(trim($value));
        if (isset(self::COLORS[$v])) {
            return $v;
        }
        if (mb_strlen($v) >= 5) {
            $stem = mb_substr($v, 0, -1);
            foreach (array_keys(self::COLORS) as $name) {
                if (mb_strlen($name) >= 5 && mb_substr($name, 0, -1) === $stem) {
                    return $name;
                }
            }
        }

        return null;
    }
}
