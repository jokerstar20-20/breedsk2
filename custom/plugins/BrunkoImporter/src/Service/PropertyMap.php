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
        'orech' => '#6b4a32',
        'číra' => '#e8f1f5',
        'priehľadná' => '#e8f1f5',
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
        $canonical = self::canonicalColor($value);

        return $canonical !== null ? mb_strtoupper(mb_substr($canonical, 0, 1)) . mb_substr($canonical, 1) : $value;
    }

    /** Hex kod farby pre hodnotu, ak je skupina farebna a farbu poznáme. */
    public static function colorHex(string $param, string $value): ?string
    {
        if (self::displayType($param) !== 'color') {
            return null;
        }
        $canonical = self::canonicalColor($value);

        return $canonical !== null ? self::COLORS[$canonical] : null;
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
