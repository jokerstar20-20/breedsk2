<?php declare(strict_types=1);

namespace Brunko\Importer\Feed;

use XMLReader;
use SimpleXMLElement;

/**
 * Feed Kondela (<PRODUKTY><PRODUKT>) prevedie na rovnaky tvar poloziek ako AutronicFeedReader, aby ich spracoval ten isty import.
 *
 *  - MOC_SK = predajna cena s DPH 23 %, VOC_SK = nakupna cena bez DPH
 *  - vynechane su produkty "Na objednavku" a bez skladu
 *  - sklad: cislo, alebo text "Na sklade viac ako 5 kusov" => STOCK_MANY (zakaznikovi sa nezobrazuju presne cisla)
 *  - varianty: produkty s rovnakym modelom, typom, rozmermi a materialom, ktore sa lisia len farbou (FARBA musi byt v skupine unikatna)
 */
class KondelaFeedReader
{
    /** Sklad pre "Na sklade viac ako 5 kusov". */
    public const STOCK_MANY = 50;
    private const VAT = 23.0;

    /** @var array<string,int> */
    private array $skipped = [];

    /** Dovody vynechania produktov v poslednom citani (pre vypis). @return array<string,int> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return list<array<string,mixed>> */
    public function read(string $file): array
    {
        $this->skipped = ['bez ceny' => 0, 'na objednavku' => 0, 'bez skladu' => 0];
        $items = [];
        $reader = new XMLReader();
        $reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'PRODUKT') {
                $node = simplexml_load_string($reader->readOuterXml(), SimpleXMLElement::class, LIBXML_NOCDATA);
                $reader->next();
                if ($node === false) {
                    continue;
                }
                $item = $this->map($node);
                if ($item !== null) {
                    $items[$item['code']] = $item;
                }
            }
        }
        $reader->close();

        return array_values($this->withVariants($items));
    }

    /** @return array<string,mixed>|null */
    private function map(SimpleXMLElement $p): ?array
    {
        $code = trim((string) $p->KOD_TOVARU);
        $retail = $this->num((string) $p->MOC_SK);
        if ($code === '' || $retail <= 0) {
            $this->skipped['bez ceny']++;

            return null;
        }
        if (stripos((string) $p->DOSTUPNOST, 'objedn') !== false) {
            $this->skipped['na objednavku']++;

            return null;
        }
        $stock = $this->stock(trim((string) $p->SKLADOVA_ZASOBA));
        if ($stock <= 0) {
            $this->skipped['bez skladu']++;

            return null;
        }

        $path = array_map('trim', explode('>', trim((string) $p->CENOVA_KATEGORIA)));
        $images = [];
        foreach ([(string) $p->URL_OBRAZOK, ...array_map('strval', iterator_to_array($p->OBRAZKY->OBRAZOK ?? [], false))] as $url) {
            $url = trim($url);
            if ($url !== '' && !in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        $params = [];
        foreach (['Farba primárna' => 'FARBA', 'Materiál' => 'MATERIAL', 'Štýl' => 'PREVEDENIE'] as $param => $tag) {
            $v = trim((string) $p->{$tag});
            if ($v !== '') {
                $params[$param] = ['value' => $v, 'unit' => null];
            }
        }
        $weight = $this->num((string) $p->HMOTNOST);
        if ($weight > 0) {
            $params['Hmotnosť - brutto (kg)'] = ['value' => (string) $weight, 'unit' => 'kg'];
        }

        $mm = fn (string $tag): ?float => ($v = $this->num((string) $p->{$tag})) > 0 ? $v * 10 : null;

        return [
            'code'         => $code,
            'name'         => trim((string) $p->H1),
            'ean'          => trim((string) $p->GTIN) ?: null,
            'cat_name'     => end($path) ?: '',
            'cat_code'     => implode(' > ', $path),
            'cat_path'     => implode(' > ', $path),
            'wholesale'    => $this->num((string) $p->VOC_SK),
            'retail'       => $retail,
            'retail_promo' => 0.0,
            'vat'          => self::VAT,
            'stock'        => $stock,
            'min_qty'      => 1,
            'desc_html'    => null,
            'desc_text'    => trim((string) $p->POPIS_TOVARU) ?: null,
            'params'       => $params,
            'color'        => $params['Farba primárna']['value'] ?? null,
            'color_variants' => [],
            'images'       => $images,
            'width_mm'     => $mm('SIRKA'),
            'height_mm'    => $mm('VYSKA'),
            'length_mm'    => $mm('HLBKA'),
            '_model'       => trim((string) $p->NAZOV_MODEL),
            '_type'        => trim((string) $p->TYP_NABYTKU),
        ];
    }

    private function num(string $v): float
    {
        return (float) str_replace(',', '.', trim($v));
    }

    private function stock(string $v): int
    {
        if (is_numeric($v)) {
            return (int) $v;
        }

        return stripos($v, 'viac ako') !== false ? self::STOCK_MANY : 0;
    }

    /**
     * @param array<string,array<string,mixed>> $items
     * @return array<string,array<string,mixed>>
     */
    private function withVariants(array $items): array
    {
        $groups = [];
        foreach ($items as $code => $p) {
            if ($p['_model'] === '' || ($p['color'] ?? '') === '') {
                continue;
            }
            $key = implode('|', [$p['_model'], $p['_type'], $p['width_mm'], $p['height_mm'], $p['length_mm'], $p['params']['Materiál']['value'] ?? '']);
            $groups[$key][$code] = $p['color'];
        }
        foreach ($groups as $members) {
            // varianty len ak su aspon 2 a kazda farba je v skupine jedinecna
            if (count($members) < 2 || count(array_unique(array_map('mb_strtolower', $members))) !== count($members)) {
                continue;
            }
            foreach (array_keys($members) as $code) {
                $items[$code]['color_variants'] = $members;
            }
        }
        foreach ($items as &$p) {
            unset($p['_model'], $p['_type']);
        }

        return $items;
    }
}
