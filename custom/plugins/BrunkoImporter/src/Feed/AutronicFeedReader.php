<?php declare(strict_types=1);

namespace Brunko\Importer\Feed;

use Generator;
use SimpleXMLElement;
use XMLReader;

/**
 * Streamovy citatel feedu Autronic. Cita po jednom <Product>, nenacita cely subor do pamate.
 */
class AutronicFeedReader
{
    /** @return Generator<array<string,mixed>> */
    public function read(string $file): Generator
    {
        $reader = new XMLReader();
        if (!$reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Feed sa nepodarilo otvorit: ' . $file);
        }

        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'Product') {
                $xml = simplexml_load_string($reader->readOuterXml());
                if ($xml !== false) {
                    yield $this->normalize($xml);
                }
                $reader->next();
            }
        }
        $reader->close();
    }

    private function attr(?SimpleXMLElement $node, string $name): ?string
    {
        return $node !== null && isset($node[$name]) ? (string) $node[$name] : null;
    }

    /** @return array<string,mixed> */
    private function normalize(SimpleXMLElement $p): array
    {
        $desc = [];
        foreach ($p->Descriptions->Description ?? [] as $d) {
            $desc[(string) $d['format']] = trim((string) $d);
        }

        $params = [];
        foreach ($p->Parameters->Parameter ?? [] as $pa) {
            $value = (string) $pa['type'] === 'Numeric' ? (string) $pa->NumericValue : (string) $pa->TextValue;
            $params[(string) $pa->Name] = ['value' => trim($value), 'unit' => ((string) $pa->Unit) ?: null];
        }

        $images = [];
        foreach ($p->Images->Image ?? [] as $i) {
            $url = (string) $i['largeSizeUrl'] ?: (string) $i['mediumSizeUrl'];
            if ($url !== '') {
                $images[] = $url;
            }
        }

        $dims = [];
        foreach ($p->Dimensions->Dimension ?? [] as $d) {
            $dims[(string) $d['type']] = (float) $d['value'];
        }

        // Farebne varianty: zoznam surodencov (kod => farba). Feed ho uvadza opakovane, preto kluc = kod.
        $colorVariants = [];
        foreach ($p->ColorVariants->Product ?? [] as $cv) {
            $cvCode = trim((string) $cv->ProductCode);
            if ($cvCode !== '') {
                $colorVariants[$cvCode] = trim((string) $cv->Color);
            }
        }

        $pr = $p->Prices;
        $level = $p->QuantityLevels->QuantityLevel[0] ?? null;

        return [
            'code'         => trim((string) $p->ProductCode),
            'name'         => trim((string) $p->ProductName),
            'ean'          => ((string) $p->Ean) ?: null,
            'cat_name'     => trim((string) $p->ProductCategory->CategoryName),
            'cat_code'     => trim((string) $p->ProductCategory->CategoryShortName),
            'wholesale'    => (float) $this->attr($pr->WholesalePriceWithoutVat ?? null, 'value'),
            'retail'       => (float) $this->attr($pr->RetailPriceIncludingVat ?? null, 'value'),
            'retail_promo' => (float) $this->attr($pr->RetailPromotionalPriceIncludingVat ?? null, 'value'),
            'vat'          => (float) $this->attr($pr->VatRate ?? null, 'value') ?: 23.0,
            'stock'        => (int) $this->attr($p->Availability->StockAvailabilityTotal ?? null, 'Quantity'),
            'min_qty'      => max(1, (int) $this->attr($level, 'quantity')),
            'desc_html'    => isset($desc['html']) ? html_entity_decode($desc['html'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
            'desc_text'    => $desc['plaintext'] ?? null,
            'params'       => $params,
            'color'          => $params['Farba primárna']['value'] ?? null,
            'color_variants' => $colorVariants,
            'images'       => $images,
            'width_mm'     => isset($dims['Šírka (cm)']) ? $dims['Šírka (cm)'] * 10 : null,
            'height_mm'    => isset($dims['Výška (cm)']) ? $dims['Výška (cm)'] * 10 : null,
            'length_mm'    => isset($dims['Hĺbka (cm)']) ? $dims['Hĺbka (cm)'] * 10 : null,
        ];
    }
}
