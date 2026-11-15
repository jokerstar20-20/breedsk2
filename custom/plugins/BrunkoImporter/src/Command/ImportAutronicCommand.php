<?php declare(strict_types=1);

namespace Brunko\Importer\Command;

use Brunko\Importer\Feed\AutronicFeedReader;
use Brunko\Importer\Feed\FeedDownloader;
use Brunko\Importer\Service\CategoryResolver;
use Brunko\Importer\Service\ColorShades;
use Brunko\Importer\Service\PropertyMap;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'brunko:import:autronic', description: 'Import produktov z feedu Autronic (ceny, sklad, kategorie)')]
class ImportAutronicCommand extends Command
{
    /** Kategorie, ktorych kod zacina tymto prefixom, zatial preskakujeme. */
    private const SKIP_PREFIXES = ['DE-'];
    /** Parametre z feedu, z ktorych vzniknu vlastnosti (filtre v storefronte). */
    private const PROPERTY_PARAMS = ['Farba primárna', 'Materiál', 'Štýl'];
    /** Vlastnost, podla ktorej sa robia varianty (feed: ColorVariants). */
    private const VARIANT_PARAM = 'Farba variantu'; // farba v konfiguratore (Zelená 2); nefiltruje sa
    private const FILTER_PARAM = 'Farba primárna';  // zakladna farba (Zelená) pre filter a vlastnosti produktu
    private const SIZE_PARAM = 'Veľkosť';
    private const BATCH = 200;
    private const MANUFACTURER = 'Autronic';

    /** ID vyrobcu Autronic (nastavuje sa v doImport). */
    private string $manufacturerId = '';

    /** @var array<string,string>|null ID hodnoty vlastnosti => ID obrazka (farby bez hex kodu, napr. "Viacfarebna", s obrazkom nastavenym v admine) */
    private ?array $optionMedia = null;

    public function __construct(
        private readonly AutronicFeedReader $reader,
        protected readonly FeedDownloader $feeds,
        protected readonly CategoryResolver $categories,
        protected readonly Connection $db,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $taxRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Lokalny XML subor namiesto stiahnutia z URL')
            ->addOption('no-download', null, InputOption::VALUE_NONE, 'Nesťahuj feed, pouzi existujuci var/feeds/autronic.xml')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nic nezapisuj, len vypis co by sa stalo')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Spracuj najviac N produktov (na test)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignoruj poistku proti prazdnemu/neuplnemu feedu');
    }

    protected ?SymfonyStyle $io = null;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $started = microtime(true);

        // --- 1. feed (var/feeds/autronic.xml; stiahne sa len ak nie je --file / --no-download) ---
        try {
            $file = $this->resolveFeed($input->getOption('file'), (bool) $input->getOption('no-download'), $io);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return $this->doImport($io, $file, $dry, $limit, (bool) $input->getOption('force'), $started);
    }

    private function doImport(SymfonyStyle $io, string $file, bool $dry, ?int $limit, bool $force, float $started): int
    {
        // --- 2. precitaj a prefiltruj feed ---
        $items = [];
        $skipped = 0;
        $dupes = 0;
        $noPrice = 0;
        $named = 0;
        foreach ($this->readItems($file) as $p) {
            if ($p['code'] === '' || $p['cat_code'] === '') {
                continue;
            }
            // Bez ceny sa produkt neimportuje (aby sa nepredaval zadarmo); ak uz v e-shope je, skryje sa ako vypadnuty z feedu.
            if ($p['retail'] <= 0 && $p['retail_promo'] <= 0) {
                $noPrice++;
                continue;
            }
            if ($p['name'] === '') {
                $named++; // nazov sa zostavi pri zapise: "<vyrobca> + kod"
            }
            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($p['cat_code'], $prefix)) {
                    $skipped++;
                    continue 2;
                }
            }
            if (isset($items[$p['code']])) {
                $dupes++;
                $old = $items[$p['code']];
                $oldAvail = $old['stock'] >= $old['min_qty'];
                $newAvail = $p['stock'] >= $p['min_qty'];
                $oldPrice = $old['retail_promo'] ?: $old['retail'];
                $newPrice = $p['retail_promo'] ?: $p['retail'];
                if (($newAvail && !$oldAvail) || ($newAvail === $oldAvail && $newPrice < $oldPrice)) {
                    $items[$p['code']] = $p;
                }
                continue;
            }
            $items[$p['code']] = $p;
            if ($limit !== null && count($items) >= $limit) {
                break;
            }
        }
        $io->writeln(sprintf('Feed: %d produktov na import, %d preskocenych (DE-), %d duplicit, %d bez ceny (preskocene), %d bez nazvu (nazov = Autronic + kod)', count($items), $skipped, $dupes, $noPrice, $named));

        // --- 3. poistka ---
        $state = [];
        foreach ($this->db->fetchAllAssociative('SELECT code, LOWER(HEX(product_id)) AS pid, hash FROM brunko_import_state WHERE supplier = ?', [$this->supplier()]) as $r) {
            $state[$r['code']] = $r;
        }
        if (!$items) {
            $io->error('Feed neobsahuje ziadne produkty. Koncim bez zmien.');
            return Command::FAILURE;
        }
        if ($limit === null && !$force && count($state) > 50 && count($items) < count($state) * 0.8) {
            $io->error(sprintf('Feed ma len %d produktov, v DB je %d. Vyzera neuplny, koncim bez zmien (--force to obide).', count($items), count($state)));
            $this->logger->error('Brunko import: neuplny feed', ['feed' => count($items), 'db' => count($state)]);
            return Command::FAILURE;
        }

        // --- 4. zakladne ID ---
        $systemLang = Defaults::LANGUAGE_SYSTEM;
        $skLang = $this->db->fetchOne("SELECT LOWER(HEX(language.id)) FROM language INNER JOIN locale ON language.translation_code_id = locale.id WHERE locale.code = 'sk-SK'");
        if (!$skLang) {
            $io->error('Jazyk sk-SK nie je v systeme (bin/console translation:install --locales=sk-SK).');
            return Command::FAILURE;
        }
        $sc = $this->db->fetchAssociative('SELECT LOWER(HEX(id)) AS id, LOWER(HEX(navigation_category_id)) AS nav FROM sales_channel WHERE type_id = UNHEX(?) LIMIT 1', [Defaults::SALES_CHANNEL_TYPE_STOREFRONT]);
        if (!$sc) {
            $io->error('Storefront sales channel nenajdeny.');
            return Command::FAILURE;
        }
        $taxId = $this->taxId(23.0, $dry);
        $this->manufacturerId = $this->ensureManufacturer($systemLang, $skLang, $dry);
        $this->categories->init($sc['nav'], $skLang, $systemLang);
        $ctx = Context::createDefaultContext();

        // --- 5. diff ---
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
        $stats = ['new' => 0, 'changed' => 0, 'same' => 0, 'hidden' => 0, 'failed' => 0, 'groups' => 0];
        $batch = [];
        $batchMeta = [];

        $flush = function () use (&$batch, &$batchMeta, &$stats, $ctx, $dry, $now): void {
            if (!$batch) {
                return;
            }
            if (!$dry) {
                $failed = [];
                try {
                    $this->productRepository->upsert($batch, $ctx);
                } catch (\Throwable $e) {
                    $this->logger->warning('Brunko import: davka zlyhala, skusam po jednom', ['err' => $e->getMessage()]);
                    foreach ($batch as $i => $row) {
                        try {
                            $this->productRepository->upsert([$row], $ctx);
                        } catch (\Throwable $e2) {
                            $failed[$i] = true;
                            $stats['failed']++;
                            $this->logger->error('Brunko import: produkt zlyhal', ['code' => $row['productNumber'], 'err' => $e2->getMessage()]);
                        }
                    }
                }
                foreach ($batchMeta as $i => $m) {
                    if (isset($failed[$i])) {
                        continue;
                    }
                    $this->db->executeStatement(
                        'INSERT INTO brunko_import_state (supplier, code, product_id, hash, updated_at) VALUES (?, ?, UNHEX(?), ?, ?)
                         ON DUPLICATE KEY UPDATE product_id = VALUES(product_id), hash = VALUES(hash), updated_at = VALUES(updated_at)',
                        [$this->supplier(), $m['code'], $m['pid'], $m['hash'], $now]
                    );
                }
            }
            $batch = [];
            $batchMeta = [];
        };

        // --- 5-. stare hodnoty farieb (iny rod, napr. "Prirodne" => "Prirodna"): zrusia sa, aby produkty nemali dve varianty tej istej farby ---
        $oldOptionIds = [];
        foreach ($items as $p) {
            foreach (self::PROPERTY_PARAMS as $param) {
                $values = [$p['params'][$param]['value'] ?? ''];
                if ($param === self::FILTER_PARAM) {
                    $values = array_merge($values, array_values($p['color_variants'] ?? []));
                }
                foreach ($values as $v) {
                    if ($v !== '' && mb_strtolower(PropertyMap::normalizeValue($param, $v)) !== mb_strtolower($v)) {
                        $oldOptionIds[md5('brunko:po:' . $param . ':' . mb_strtolower($v))] = true;
                    }
                }
            }
        }
        if ($oldOptionIds) {
            $io->writeln(sprintf('Stare hodnoty farieb na zlucenie: %d', count($oldOptionIds)), OutputInterface::VERBOSITY_VERBOSE);
            if (!$dry) {
                foreach (array_keys($oldOptionIds) as $oldId) {
                    // konfigurator hlavnych produktov nema kaskadu, vazby product_property / product_option ano; produkty sa v tomto behu prepisu
                    $this->db->executeStatement('DELETE FROM product_configurator_setting WHERE property_group_option_id = UNHEX(?)', [$oldId]);
                    $this->db->executeStatement('DELETE FROM property_group_option WHERE id = UNHEX(?)', [$oldId]);
                }
            }
        }

        // --- 5a. farebne varianty (feed: ColorVariants) -> jeden hlavny produkt + varianty ---
        $groups = $this->buildGroups($items, $io);
        $grouped = [];
        foreach ($groups as $g) {
            foreach ($g['codes'] as $c) {
                $grouped[$c] = true;
            }
        }
        $seenParents = [];

        // --- 5b. samostatne produkty ---
        // Produkt, ktory bol varianta, ale uz nie je v skupine (surodenec zo feedu zmizol), sa odpoji od hlavneho produktu,
        // inak by ostal skryty spolu s nim.
        $wasChild = array_fill_keys($this->db->fetchFirstColumn(
            'SELECT s.code FROM brunko_import_state s INNER JOIN product p ON p.id = s.product_id AND p.version_id = UNHEX(?)
             WHERE s.supplier = ? AND p.parent_id IS NOT NULL AND s.code NOT LIKE ?',
            [Defaults::LIVE_VERSION, $this->supplier(), 'PARENT:%']
        ), true);

        foreach ($items as $code => $p) {
            if (isset($grouped[$code])) {
                continue;
            }
            $detach = isset($wasChild[$code]);
            $hash = md5(json_encode($this->hashBase($p), JSON_UNESCAPED_UNICODE) . ($detach ? ':detach' : ''));
            $isNew = !isset($state[$code]);
            if (!$isNew && $state[$code]['hash'] === $hash) {
                $stats['same']++;
                continue;
            }
            $isNew ? $stats['new']++ : $stats['changed']++;

            $catId = $this->categoryId($p, $dry);
            $row = $this->buildProduct($p, $catId, $taxId, $systemLang, $skLang, $sc['id'], $isNew);
            $this->cleanLinks($row['id'], $isNew, $dry);
            if ($detach) {
                $row['parentId'] = null;
            }
            $batchMeta[count($batch)] = ['code' => $code, 'pid' => $row['id'], 'hash' => $hash];
            $batch[] = $row;
            if (count($batch) >= self::BATCH) {
                $flush();
            }
        }

        // --- 5c. skupiny variantov: hlavny produkt (parent) + varianty (children) ---
        foreach ($groups as $g) {
            $codes = $g['codes'];
            $colors = $g['colors'];
            $sizes = $g['sizes'] ?? [];
            $members = [];
            foreach ($codes as $c) {
                $members[$c] = $items[$c];
            }
            $first = reset($members);

            $parentId = $this->parentId($codes);
            $parentNumber = $this->parentNumber($codes, $parentId);
            $parentName = $this->parentName($members, $colors, $parentNumber, (bool) $sizes);
            $parentKey = 'PARENT:' . $parentId;
            $seenParents[$parentKey] = true;
            $stats['groups']++;

            $io->writeln(sprintf('  varianty: %s "%s" <- %s', $parentNumber, $parentName, implode(', ', array_map(fn ($c) => $c . ' (' . $colors[$c] . ')', $codes))), OutputInterface::VERBOSITY_VERBOSE);

            // V konfiguratore hlavneho produktu (prepinac farieb v zozname aj vyber farby v detaile) su len varianty skladom.
            // Vypredane varianty ostavaju neaktivne (nie su v zozname, detaile ani vo vyhladavani). Ak nie je skladom ziadny, ostanu vsetky
            // (hlavny produkt je vtedy neaktivny).
            $inStock = array_filter($members, fn (array $m): bool => $m['stock'] >= $m['min_qty']);
            $settingColors = $inStock ? array_intersect_key($colors, $inStock) : $colors;
            $settingSizes = array_values(array_unique($sizes ? ($inStock ? array_intersect_key($sizes, $inStock) : $sizes) : []));

            $memberHashes = [];
            foreach ($members as $c => $m) {
                $memberHashes[$c] = md5(json_encode($this->hashBase($m), JSON_UNESCAPED_UNICODE) . $parentId . ':settings2' . ($sizes[$c] ?? ''));
            }
            $mediaMarks = [];
            foreach ($settingColors as $color) {
                $oid = md5('brunko:po:' . self::VARIANT_PARAM . ':' . mb_strtolower(PropertyMap::normalizeValue(self::VARIANT_PARAM, $color)));
                if (($mid = $this->colorMedia($color)) !== null) {
                    $mediaMarks[$oid] = $mid;
                }
            }
            $groupHash = md5(json_encode([$parentNumber, $parentName, $memberHashes, $colors, $sizes, $mediaMarks, 'nm6'], JSON_UNESCAPED_UNICODE));
            $parentIsNew = !isset($state[$parentKey]);
            $allSame = !$parentIsNew && $state[$parentKey]['hash'] === $groupHash;
            foreach ($codes as $c) {
                if (!isset($state[$c])) {
                    $allSame = false;
                }
            }
            if ($allSame) {
                $stats['same'] += count($codes);
                continue;
            }

            $catId = $this->categoryId($first, $dry);
            $parentRow = $this->buildParent($parentId, $parentNumber, $parentName, $members, $settingColors, $settingSizes, $catId, $taxId, $systemLang, $skLang, $sc['id'], $parentIsNew);
            $parentIsNew ? $stats['new']++ : $stats['changed']++;
            $batchMeta[count($batch)] = ['code' => $parentKey, 'pid' => $parentId, 'hash' => $groupHash];
            $batch[] = $parentRow; // hlavny produkt musi byt v davke pred variantmi

            if (!$dry && !$parentIsNew) {
                // upsert konfiguratora stare farby neodstrani, preto sa zmazu nastavenia farieb, ktore uz nie su skladom
                $keepIds = array_map(
                    fn (string $color): string => md5('brunko:po:' . self::VARIANT_PARAM . ':' . mb_strtolower(PropertyMap::normalizeValue(self::VARIANT_PARAM, $color))),
                    array_values($settingColors)
                );
                foreach ($settingSizes as $size) {
                    $keepIds[] = md5('brunko:po:' . self::SIZE_PARAM . ':' . mb_strtolower($size));
                }
                $this->db->executeStatement(
                    'DELETE FROM product_configurator_setting WHERE product_id = UNHEX(?) AND property_group_option_id NOT IN (' . implode(',', array_fill(0, count($keepIds), 'UNHEX(?)')) . ')',
                    array_merge([$parentId], $keepIds)
                );
            }

            foreach ($members as $c => $m) {
                $isNew = !isset($state[$c]);
                $isNew ? $stats['new']++ : $stats['changed']++;
                $mCat = $this->categoryId($m, $dry);
                $row = $this->buildProduct($m, $mCat, $taxId, $systemLang, $skLang, $sc['id'], $isNew);
                $this->cleanLinks($row['id'], $isNew, $dry);
                $row['parentId'] = $parentId;
                $row['options'] = [$this->optionRow(self::VARIANT_PARAM, $colors[$c], $systemLang, $skLang)];
                if (!empty($sizes[$c])) {
                    $row['options'][] = $this->optionRow(self::SIZE_PARAM, $sizes[$c], $systemLang, $skLang);
                }
                $batchMeta[count($batch)] = ['code' => $c, 'pid' => $row['id'], 'hash' => $memberHashes[$c]];
                $batch[] = $row;
            }
            if (count($batch) >= self::BATCH) {
                $flush();
            }
        }
        $flush();

        if (!$dry) {
            $this->retireLegacyCategories();
        }

        // --- 6. produkty, ktore zo feedu zmizli, skryjeme ---
        if ($limit === null) {
            $gone = array_diff_key($state, $items); // 'PARENT:*' kluce nie su v $items, riesi sa nizsie
            $hide = [];
            foreach ($gone as $code => $r) {
                // hlavny produkt skupiny skryjeme len ak skupina uz vo feede nie je
                if (str_starts_with((string) $code, 'PARENT:') && isset($seenParents[$code])) {
                    continue;
                }
                $hide[] = ['id' => $r['pid'], 'active' => false, 'stock' => 0];
            }
            $stats['hidden'] = count($hide);
            if (!$dry) {
                foreach (array_chunk($hide, self::BATCH) as $chunk) {
                    $this->productRepository->update($chunk, $ctx);
                }
            }
        }

        $io->success(sprintf(
            '%s hotovo za %.1fs: nove %d, zmenene %d, bez zmeny %d, skryte %d, zlyhane %d, skupiny variantov %d',
            $dry ? '[DRY-RUN]' : 'Import',
            microtime(true) - $started,
            $stats['new'], $stats['changed'], $stats['same'], $stats['hidden'], $stats['failed'], $stats['groups']
        ));
        $this->logger->info('Brunko import autronic', $stats);

        return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** Pri zmene farby DAL stare vazby (options, properties) neodstrani, preto sa pred zapisom zmazu. */
    private function cleanLinks(string $productId, bool $isNew, bool $dry): void
    {
        if ($isNew || $dry) {
            return;
        }
        $this->db->executeStatement('DELETE FROM product_option WHERE product_id = UNHEX(?)', [$productId]);
        $this->db->executeStatement('DELETE FROM product_property WHERE product_id = UNHEX(?)', [$productId]);
    }

    /** @param array<string,mixed> $p */
    private function buildProduct(array $p, string $catId, string $taxId, string $systemLang, string $skLang, string $scId, bool $isNew): array
    {
        $vatFactor = 1 + $p['vat'] / 100;
        $price = $this->priceStruct($p);

        $id = md5('brunko:product:' . $this->supplier() . ':' . $p['code']);
        $name = $this->displayName($p['name'], $p['code']);
        $desc = $p['desc_html'] ?? ($p['desc_text'] !== null ? nl2br(htmlspecialchars($p['desc_text'])) : null);
        $tr = ['name' => $name, 'description' => $desc];

        $row = [
            'id' => $id,
            'productNumber' => $p['code'],
            'ean' => $p['ean'],
            'taxId' => $taxId,
            'manufacturerId' => $this->manufacturerId,
            'price' => [$price],
            'purchasePrices' => [[
                'currencyId' => Defaults::CURRENCY,
                'net' => $p['wholesale'],
                'gross' => round($p['wholesale'] * $vatFactor, 4),
                'linked' => true,
            ]],
            'stock' => $p['stock'],
            'active' => $p['stock'] >= $p['min_qty'],
            'isCloseout' => false,
            'minPurchase' => $p['min_qty'],
            'purchaseSteps' => $p['min_qty'],
            'categories' => [['id' => $catId]],
            'width' => $p['width_mm'],
            'height' => $p['height_mm'],
            'length' => $p['length_mm'],
            'weight' => $this->weightKg($p['params']),
            'translations' => [$systemLang => $tr, $skLang => $tr],
        ];

        $props = [];
        foreach (self::PROPERTY_PARAMS as $param) {
            $v = $p['params'][$param]['value'] ?? '';
            if ($v === '') {
                continue;
            }
            foreach ($param === self::FILTER_PARAM ? PropertyMap::baseColors($v) : [$v] as $value) {
                $props[] = $this->optionRow($param, $value, $systemLang, $skLang);
            }
        }
        if ($props) {
            $row['properties'] = $props;
        }

        if ($isNew) {
            // viditelnost v storefronte len pri vytvoreni (deterministicke ID, ziadne duplicity)
            $row['visibilities'] = [[
                'id' => md5('brunko:vis:' . $id . ':' . $scId),
                'salesChannelId' => $scId,
                'visibility' => 30,
            ]];
        }

        return $row;
    }

    /** @param array<string,mixed> $p */
    private function priceStruct(array $p): array
    {
        $vatFactor = 1 + $p['vat'] / 100;
        $sale = $p['retail_promo'] > 0 && $p['retail_promo'] < $p['retail'] ? $p['retail_promo'] : $p['retail'];
        $price = [
            'currencyId' => Defaults::CURRENCY,
            'gross' => $sale,
            'net' => round($sale / $vatFactor, 4),
            'linked' => true,
        ];
        if ($sale < $p['retail']) {
            $price['listPrice'] = [
                'currencyId' => Defaults::CURRENCY,
                'gross' => $p['retail'],
                'net' => round($p['retail'] / $vatFactor, 4),
                'linked' => true,
            ];
        }

        return $price;
    }

    /** Hash produktu bez poli, ktore pribudli pre varianty (aby sa bezne produkty neprepisovali zbytocne). */
    private function hashBase(array $p): array
    {
        $hasColor = ($p['color'] ?? '') !== '';
        unset($p['color'], $p['color_variants']);
        $p['_mf'] = 6; // (3 = vyrobca, tvar nazvu, spolocny strom kategorii)   // vyrobca Autronic => jednorazovo prepise existujuce produkty
        if ($hasColor) {
            $p['_map'] = 4; // parovanie farby (nazov skupiny, farebne kruzky) => jednorazovo prepise produkty s farbou
        }

        return $p;
    }

    /**
     * Vlastnost (skupina + hodnota). ID podla povodneho nazvu parametra z feedu, zobrazovany nazov, typ zobrazenia
     * a hex farby podla PropertyMap (parovanie "Farba primarna" -> "Farba" s farebnymi kruzkami).
     */
    private function optionRow(string $param, string $value, string $systemLang, string $skLang): array
    {
        $value = PropertyMap::normalizeValue($param, $value);
        $label = PropertyMap::groupLabel($param);
        $group = [
            'id' => md5('brunko:pg:' . $param),
            'translations' => [$systemLang => ['name' => $label], $skLang => ['name' => $label]],
        ];
        if (($type = PropertyMap::displayType($param)) !== null) {
            $group['displayType'] = $type;
        }
        if ($param === self::SIZE_PARAM) {
            $group['sortingType'] = 'position';
        }
        if ($param === self::VARIANT_PARAM) {
            $group['filterable'] = false;
        }

        $option = [
            'id' => md5('brunko:po:' . $param . ':' . mb_strtolower($value)),
            'group' => $group,
            'translations' => [$systemLang => ['name' => $value], $skLang => ['name' => $value]],
        ];
        if (($hex = PropertyMap::colorHex($param, $value)) !== null) {
            $option['colorHexCode'] = $hex;
        }
        if ($param === self::SIZE_PARAM) {
            $option['position'] = ColorShades::sizePosition($value);
        }

        return $option;
    }

    /**
     * Zoskupi produkty podla ColorVariants (spojite komponenty). Skupina musi mat >= 2 produkty,
     * kazdy s unikatnou neprazdnou farbou, inak sa nezoskupuje.
     *
     * @param array<string,array<string,mixed>> $items
     * @return list<array{codes:list<string>,colors:array<string,string>}>
     */
    private function buildGroups(array $items, SymfonyStyle $io): array
    {
        $adj = [];
        foreach ($items as $code => $p) {
            foreach (array_keys($p['color_variants'] ?? []) as $other) {
                $other = (string) $other;
                if ($other !== (string) $code && isset($items[$other])) {
                    $adj[$code][$other] = true;
                    $adj[$other][$code] = true;
                }
            }
        }

        $groups = [];
        $seen = [];
        foreach (array_keys($adj) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $component = [];
            $queue = [$start];
            $seen[$start] = true;
            while ($queue) {
                $cur = array_shift($queue);
                $component[] = (string) $cur;
                foreach (array_keys($adj[$cur] ?? []) as $next) {
                    if (!isset($seen[$next])) {
                        $seen[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
            sort($component);

            $colors = [];
            foreach ($component as $c) {
                $color = trim((string) ($items[$c]['color'] ?? ''));
                if ($color === '') {
                    // farba z odkazu v inom produkte skupiny
                    foreach ($component as $other) {
                        if (!empty($items[$other]['color_variants'][$c])) {
                            $color = $items[$other]['color_variants'][$c];
                            break;
                        }
                    }
                }
                $colors[$c] = $color;
            }

            // druha os: velkost / priemer z pripony kodu (len ak sa medzi clenmi lisi)
            $sizes = [];
            foreach ($component as $c) {
                $sizes[$c] = (string) ($items[$c]['size'] ?? '');
            }
            $distinct = array_unique($sizes);
            if (count($distinct) === 1 && reset($distinct) !== '') {
                $sizes = []; // vsetci rovnaka velkost => os nema zmysel
            } elseif (count($distinct) > 1 && in_array('', $sizes, true)) {
                $io->warning(sprintf('Skupina %s sa nezoskupuje (cast produktov nema velkost).', implode(', ', $component)));
                continue;
            } elseif (count($distinct) === 1) {
                $sizes = [];
            }

            $keys = [];
            foreach ($colors as $c => $col) {
                $keys[$c] = mb_strtolower($col) . '|' . ($sizes[$c] ?? '');
            }
            if (in_array('', array_map('mb_strtolower', $colors), true) || count(array_unique($keys)) !== count($keys)) {
                $io->warning(sprintf('Skupina %s sa nezoskupuje (chybajuca alebo opakujuca sa farba), ostanu samostatne produkty.', implode(', ', $component)));
                continue;
            }
            $groups[] = ['codes' => $component, 'colors' => $colors, 'sizes' => $sizes];
        }

        return $groups;
    }

    /** ID hlavneho produktu: ak uz niektory clen skupiny ma parenta, pouzije sa ten (stabilne ID), inak sa odvodi z kodu. */
    private function parentId(array $codes): string
    {
        $existing = $this->db->fetchOne(
            'SELECT LOWER(HEX(parent_id)) FROM product WHERE product_number IN (?) AND parent_id IS NOT NULL AND version_id = UNHEX(?) LIMIT 1',
            [$codes, Defaults::LIVE_VERSION],
            [ArrayParameterType::STRING, ParameterType::STRING]
        );

        return $existing ?: md5('brunko:parent:' . $this->supplier() . ':' . $codes[0]);
    }

    /** Cislo hlavneho produktu = spolocny zaciatok kodov (80001-01 BK + 80001-01 WT => 80001-01). */
    private function parentNumber(array $codes, string $parentId): string
    {
        if (!$this->commonPrefixParentNumber()) {
            return $codes[0] . '-M';
        }
        $prefix = $codes[0];
        foreach ($codes as $c) {
            while ($prefix !== '' && !str_starts_with($c, $prefix)) {
                $prefix = substr($prefix, 0, -1);
            }
        }
        $prefix = rtrim($prefix, " -_/.");

        $taken = $prefix !== '' ? $this->db->fetchOne(
            'SELECT LOWER(HEX(id)) FROM product WHERE product_number = ? AND version_id = UNHEX(?) LIMIT 1',
            [$prefix, Defaults::LIVE_VERSION]
        ) : null;

        if (strlen($prefix) < 3 || in_array($prefix, $codes, true) || ($taken && $taken !== $parentId)) {
            return $codes[0] . '-M';
        }

        return $prefix;
    }

    /** Nazov hlavneho produktu: nazov prveho clena bez farby a s cislom modelu namiesto kodu variantu. */
    private function parentName(array $members, array $colors, string $parentNumber, bool $hasSizes = false): string
    {
        $first = reset($members);
        $fullName = $hasSizes ? preg_replace('/\s*\d+(?:[.,]\d+)?(?:\s*[x×]\s*\d+(?:[.,]\d+)?)+\s*cm\b/iu', '', $first['name']) : $first['name'];
        $parts = array_map('trim', explode(',', $fullName));
        if (count($parts) < 2) {
            return $first['name'];
        }
        $stems = [];
        foreach ($colors as $color) {
            $l = mb_strtolower($color);
            $stems[] = mb_substr($l, 0, max(4, mb_strlen($l) - 2));
        }
        $generic = array_map(fn (string $n): string => mb_substr(mb_strtolower($n), 0, max(3, mb_strlen($n) - 2)), ColorShades::allNames());
        $out = [];
        foreach ($parts as $i => $part) {
            $l = mb_strtolower($part);
            if ($i > 0 && mb_substr_count($part, ' ') <= 1) {
                foreach ($generic as $stem) {
                    if (str_starts_with($l, $stem)) {
                        continue 2; // segment s farbou z feedu (napr. "hnedá" pri variante inej farby)
                    }
                }
            }
            if (isset($members[$part])) {
                $out[] = $parentNumber; // segment s kodom variantu
                continue;
            }
            foreach ($stems as $stem) {
                if ($stem !== '' && str_starts_with($l, $stem)) {
                    continue 2; // segment s farbou
                }
            }
            $out[] = $part;
        }

        return implode(', ', $out) ?: $first['name'];
    }

    /**
     * Hlavny produkt: konfigurator farby, v zozname sa zobrazuje namiesto variantov (displayParent).
     * Sklad = sucet variantov, cena = najnizsia cena variantu.
     *
     * @param array<string,array<string,mixed>> $members
     * @param array<string,string> $colors
     */
    private function buildParent(string $parentId, string $parentNumber, string $parentName, array $members, array $colors, array $sizes, string $catId, string $taxId, string $systemLang, string $skLang, string $scId, bool $isNew): array
    {
        $first = reset($members);
        $stock = 0;
        $minQty = PHP_INT_MAX;
        $active = false;
        $cheapest = null;
        $cheapestEff = null;
        foreach ($members as $m) {
            $stock += $m['stock'];
            $minQty = min($minQty, $m['min_qty']);
            $active = $active || $m['stock'] >= $m['min_qty'];
            $eff = $m['retail_promo'] > 0 && $m['retail_promo'] < $m['retail'] ? $m['retail_promo'] : $m['retail'];
            if ($cheapest === null || $eff < $cheapestEff) {
                $cheapest = $m;
                $cheapestEff = $eff;
            }
        }

        // Hlavny variant: dostupny, potom najlacnejsi, potom podla kodu (pri vypadku sa automaticky prepne na dalsi).
        $ranked = $members;
        uasort($ranked, function (array $a, array $b): int {
            $availA = $a['stock'] >= $a['min_qty'] ? 0 : 1;
            $availB = $b['stock'] >= $b['min_qty'] ? 0 : 1;
            $effA = $a['retail_promo'] > 0 && $a['retail_promo'] < $a['retail'] ? $a['retail_promo'] : $a['retail'];
            $effB = $b['retail_promo'] > 0 && $b['retail_promo'] < $b['retail'] ? $b['retail_promo'] : $b['retail'];

            return [$availA, $effA, $a['code']] <=> [$availB, $effB, $b['code']];
        });
        $mainVariantId = md5('brunko:product:' . $this->supplier() . ':' . array_key_first($ranked));

        $desc = $first['desc_html'] ?? ($first['desc_text'] !== null ? nl2br(htmlspecialchars($first['desc_text'])) : null);
        $tr = ['name' => $this->displayName($parentName, $parentNumber), 'description' => $desc];

        $settings = [];
        foreach ($sizes as $size) {
            $opt = $this->optionRow(self::SIZE_PARAM, $size, $systemLang, $skLang);
            $settings[] = ['id' => md5('brunko:cs:' . $parentId . ':' . $opt['id']), 'option' => $opt];
        }
        $seenColor = [];
        foreach ($colors as $color) {
            if (isset($seenColor[mb_strtolower($color)])) {
                continue;
            }
            $seenColor[mb_strtolower($color)] = true;
            $opt = $this->optionRow(self::VARIANT_PARAM, $color, $systemLang, $skLang);
            $setting = ['id' => md5('brunko:cs:' . $parentId . ':' . $opt['id']), 'option' => $opt];
            // Farba bez hex kodu, ale s obrazkom (napr. "Viacfarebna"): obrazok sa zobrazi v kruzku namiesto textu
            $mediaId = $this->colorMedia($color);
            if ($mediaId !== null && empty($opt['colorHexCode'])) {
                $setting['mediaId'] = $mediaId;
            }
            $settings[] = $setting;
        }

        $row = [
            'id' => $parentId,
            'productNumber' => $parentNumber,
            'taxId' => $taxId,
            'manufacturerId' => $this->manufacturerId,
            'price' => [$this->priceStruct($cheapest)],
            'stock' => $stock,
            'active' => $active,
            'isCloseout' => false,
            'minPurchase' => $minQty,
            'purchaseSteps' => $minQty,
            'categories' => [['id' => $catId]],
            'width' => $first['width_mm'],
            'height' => $first['height_mm'],
            'length' => $first['length_mm'],
            'weight' => $this->weightKg($first['params']),
            'translations' => [$systemLang => $tr, $skLang => $tr],
            // V zozname sa zobrazuje hlavny variant (nie hlavny produkt) a RHWebFoundation k nemu zobrazi prepinac farieb.
            'variantListingConfig' => [
                'displayParent' => false,
                'mainVariantId' => $mainVariantId,
                'configuratorGroupConfig' => array_merge(
                    [[
                        'id' => md5('brunko:pg:' . self::VARIANT_PARAM),
                        'representation' => 'box',
                        'expressionForListings' => false,
                    ]],
                    $sizes ? [[
                        'id' => md5('brunko:pg:' . self::SIZE_PARAM),
                        'representation' => 'box',
                        'expressionForListings' => false,
                    ]] : []
                ),
            ],
            'configuratorSettings' => $settings,
        ];

        if ($isNew) {
            $row['visibilities'] = [[
                'id' => md5('brunko:vis:' . $parentId . ':' . $scId),
                'salesChannelId' => $scId,
                'visibility' => 30,
            ]];
        }

        return $row;
    }

    /**
     * Pôvodný strom kategórií podľa kódov Autronicu (BD, BD-BO, NA-KRE ...) nahradil spoločný strom. Odpoja sa z neho produkty
     * (upsert pridá novú kategóriu, ale starú neodoberie) a kategórie sa deaktivujú. Produkty ani kategórie sa nemažú.
     */
    protected function retireLegacyCategories(): void
    {
        $map = require __DIR__ . '/../Resources/config/category_map_autronic.php';
        $ids = [];
        foreach (array_keys($map) as $code) {
            $path = '';
            foreach (explode('-', $code) as $part) {
                $path = $path === '' ? $part : $path . '-' . $part;
                $ids[CategoryResolver::idFor($path)] = true;
            }
        }
        $in = implode(',', array_fill(0, count($ids), 'UNHEX(?)'));
        $params = array_keys($ids);
        $this->db->executeStatement('DELETE FROM product_category WHERE category_id IN (' . $in . ')', $params);
        $this->db->executeStatement('DELETE FROM product_category_tree WHERE category_id IN (' . $in . ')', $params);
        $this->db->executeStatement('UPDATE category SET active = 0 WHERE id IN (' . $in . ')', $params);
    }

    // ---- body, v ktorych sa dodavatelia lisia (ImportKondelaCommand ich prepisuje) ----

    protected function supplier(): string
    {
        return 'autronic';
    }

    protected function manufacturerName(): string
    {
        return 'Autronic';
    }

    /** Cislo hlavneho produktu zo spolocneho zaciatku kodov (Autronic: 80001-01 BK + WT => 80001-01). */
    protected function commonPrefixParentNumber(): bool
    {
        return true;
    }

    protected function resolveFeed(?string $file, bool $noDownload, SymfonyStyle $io): string
    {
        return $this->feeds->resolve($file, $noDownload, $io);
    }

    /**
     * Polozky feedu. Ak sa v skupine farebnych variantov opakuje nazov farby (TV-915 GREY2 a SIL2 su obe "Siva"),
     * pozna sa rozdiel z pripony kodu (GREY2 = svetlosiva, SIL = strieborna ...) a farba sa pre tie varianty spresni.
     *
     * @return iterable<array<string,mixed>>
     */
    protected function readItems(string $file): iterable
    {
        // 1. prechod: len male udaje (kod, farba, odkazy), aby sa pamat nezvysovala
        $own = [];   // kod => farba produktu
        $ref = [];   // kod => farba z odkazu v inom produkte
        $adj = [];
        foreach ($this->reader->read($file) as $p) {
            $c = (string) $p['code'];
            $own[$c] = trim((string) ($p['color'] ?? ''));
            foreach (($p['color_variants'] ?? []) as $other => $colour) {
                $other = (string) $other;
                if ($colour !== '') {
                    $ref[$other] ??= $colour;
                }
                if ($other !== $c) {
                    $adj[$c][$other] = true;
                    $adj[$other][$c] = true;
                }
            }
        }
        // farba vzdy podla pripony kodu (CRM => Krémová, CRM2 => Krémová 2); inak ostava z feedu
        $refined = [];
        foreach (array_keys($own) as $code) {
            if (($n = ColorShades::name((string) $code)) !== null) {
                $refined[(string) $code] = $n;
            }
        }
        $seen = [];
        foreach (array_keys($adj) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $component = [];
            $queue = [(string) $start];
            $seen[$start] = true;
            while ($queue) {
                $cur = array_shift($queue);
                if (isset($own[$cur])) {
                    $component[] = $cur;
                }
                foreach (array_keys($adj[$cur] ?? []) as $next) {
                    if (!isset($seen[$next])) {
                        $seen[$next] = true;
                        $queue[] = (string) $next;
                    }
                }
            }
            $cv = [];
            foreach ($component as $code) {
                $cv[$code] = $refined[$code] ?? ($own[$code] !== '' ? $own[$code] : ($ref[$code] ?? ''));
            }
            // ak su dve farby (pri rovnakej velkosti) v skupine rovnake (napr. OAK a DUB), rozlisi ich pripona kodu
            $names = $cv;
            $keys = [];
            foreach ($cv as $code => $v) {
                $keys[$code] = mb_strtolower($v) . '|' . ColorShades::sizeOf((string) $code);
            }
            foreach (array_count_values($keys) as $key => $n) {
                if ($n > 1) {
                    foreach ($cv as $code => $v) {
                        if ($keys[$code] === $key) {
                            $names[$code] = $v . ' (' . ColorShades::suffix((string) $code) . ')';
                        }
                    }
                }
            }
            foreach ($names as $code => $name) {
                $refined[$code] = $name;
            }
        }

        // 2. prechod: polozky s upravenymi farbami
        foreach ($this->reader->read($file) as $p) {
            $p['size'] = ColorShades::sizeOf((string) $p['code']);
            if ($refined) {
                foreach ($p['color_variants'] ?? [] as $code => $colour) {
                    if (isset($refined[$code])) {
                        $p['color_variants'][$code] = $refined[$code];
                    }
                }
                if (isset($refined[$p['code']])) {
                    $p['color'] = $refined[$p['code']];
                    $p['params']['Farba primárna']['value'] = $refined[$p['code']];
                }
            }
            yield $p;
        }
    }

    /** Kategoria produktu: Autronic kod => cesta v spolocnom strome (strom Kondela), vid category_map_autronic.php. */
    protected function categoryId(array $p, bool $dry): string
    {
        static $map = null;
        $map ??= require __DIR__ . '/../Resources/config/category_map_autronic.php';

        $path = $map[$p['cat_code']] ?? null;
        if ($path === null) {
            return $this->categories->ensure($p['cat_code'], $p['cat_name'], $dry); // nezmapovane (DE-*): strom podla kodu
        }
        if (in_array($p['cat_code'], ['BD-VES-KOV', 'BD-VES-MAS'], true)) {
            $text = mb_strtolower($p['name'] . ' ' . ($p['desc_text'] ?? '') . ' ' . strip_tags((string) ($p['desc_html'] ?? '')));
            $path = 'Predsieň a chodba > Vešiaky > ' . (str_contains($text, 'stojan') ? 'Stojanové vešiaky' : 'Vešiaky na stenu');
        }

        return $this->categories->ensurePath($path, $dry);
    }

    /**
     * Nazov v e-shope: "Autronic KOD Typ, material". Feed uvadza kod na konci nazvu ("Zrkadlo, zrkadlo, AZR-V5015"),
     * ten sa z nazvu vyhodi (je uz na zaciatku). Bez nazvu vo feede ostane len "Autronic KOD".
     */
    protected function displayName(string $name, string $code): string
    {
        $parts = array_filter(
            array_map('trim', explode(',', $name)),
            fn (string $part): bool => $part !== '' && mb_strtolower($part) !== mb_strtolower($code)
        );

        return trim($this->manufacturerName() . ' ' . $code . ' ' . implode(', ', $parts));
    }

    /** @return array<string,string> */
    /** Obrazok farby (napr. Viacfarebna) je nastaveny na zakladnej farbe vo filtri; pouzije sa aj v konfiguratore variantov. */
    private function colorMedia(string $color): ?string
    {
        $base = PropertyMap::baseColors($color)[0] ?? $color;
        $id = md5('brunko:po:' . self::FILTER_PARAM . ':' . mb_strtolower(PropertyMap::normalizeValue(self::FILTER_PARAM, $base)));

        return $this->optionMedia()[$id] ?? null;
    }

    private function optionMedia(): array
    {
        if ($this->optionMedia === null) {
            $this->optionMedia = [];
            foreach ($this->db->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, LOWER(HEX(media_id)) AS media_id FROM property_group_option WHERE media_id IS NOT NULL') as $r) {
                $this->optionMedia[$r['id']] = $r['media_id'];
            }
        }

        return $this->optionMedia;
    }

    /** Vyrobca Autronic: pouzije sa existujuci (podla nazvu), inak sa vytvori. */
    private function ensureManufacturer(string $systemLang, string $skLang, bool $dry): string
    {
        $existing = $this->db->fetchOne(
            'SELECT LOWER(HEX(m.id)) FROM product_manufacturer m INNER JOIN product_manufacturer_translation t ON t.product_manufacturer_id = m.id
             WHERE t.name = ? AND m.version_id = UNHEX(?) LIMIT 1',
            [$this->manufacturerName(), Defaults::LIVE_VERSION]
        );
        if ($existing) {
            return $existing;
        }
        $id = md5('brunko:manufacturer:' . $this->supplier());
        if (!$dry) {
            $this->manufacturerRepository->upsert([[
                'id' => $id,
                'translations' => [$systemLang => ['name' => $this->manufacturerName()], $skLang => ['name' => $this->manufacturerName()]],
            ]], Context::createDefaultContext());
        }

        return $id;
    }

    /** @param array<string,array{value:string,unit:?string}> $params */
    private function weightKg(array $params): ?float
    {
        foreach (['Hmotnosť - brutto (kg)', 'Hmotnosť - netto (kg)'] as $k) {
            if (isset($params[$k]) && (float) $params[$k]['value'] > 0) {
                return (float) $params[$k]['value'];
            }
        }
        if (isset($params['Hmotnosť (g)']) && (float) $params['Hmotnosť (g)']['value'] > 0) {
            return (float) $params['Hmotnosť (g)']['value'] / 1000;
        }

        return null;
    }

    private function taxId(float $rate, bool $dry): string
    {
        $id = $this->db->fetchOne('SELECT LOWER(HEX(id)) FROM tax WHERE tax_rate = ? LIMIT 1', [$rate]);
        if ($id) {
            return $id;
        }
        $id = Uuid::randomHex();
        if (!$dry) {
            $this->taxRepository->create([['id' => $id, 'name' => 'DPH ' . (int) $rate . ' %', 'taxRate' => $rate]], Context::createDefaultContext());
        }

        return $id;
    }
}
