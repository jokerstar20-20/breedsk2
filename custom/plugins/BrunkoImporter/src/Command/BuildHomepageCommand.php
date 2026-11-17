<?php declare(strict_types=1);

namespace Brunko\Importer\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vytvori layout "Uvodna stranka" (Shopping Experience) a priradi ho LEN vstupnej kategorii obchodu.
 * Idempotentne (deterministicke ID), vsetko cez DAL repozitare. Texty sk-SK + systemovy jazyk.
 * Styly (.brunko-home-*) su v BrunkoNova/base.scss.
 */
#[AsCommand(name: 'brunko:homepage:build', description: 'Vytvori/aktualizuje layout Uvodna stranka a priradi ho vstupnej kategorii')]
class BuildHomepageCommand extends Command
{
    private const SK_LANGUAGE = '01a1d9c3caca71e6bc800a9c35b578ea';
    private const STOREFRONT_TYPE = '8a243080f92e4c719546314b577cf82b';
    private const TILES = 8;

    public function __construct(
        private readonly Connection $db,
        private readonly EntityRepository $cmsPageRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $productStreamRepository,
        private readonly EntityRepository $mediaRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Len vypise co by sa stalo, nic nezapise');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $ctx = Context::createDefaultContext();

        $sc = $this->db->fetchAssociative(
            'SELECT LOWER(HEX(id)) id, LOWER(HEX(navigation_category_id)) nav FROM sales_channel WHERE type_id = UNHEX(?) AND active = 1 ORDER BY created_at LIMIT 1',
            [self::STOREFRONT_TYPE]
        );
        if (!$sc) {
            $io->error('Storefront sales channel sa nenasiel.');
            return Command::FAILURE;
        }
        $navId = $sc['nav'];
        $io->writeln('Vstupna kategoria: ' . $navId);

        $tiles = $this->loadTiles($navId, $sc['id'], $ctx);
        if (\count($tiles) < 3) {
            $io->error('Najdenych menej ako 3 kategorie s produktmi.');
            return Command::FAILURE;
        }
        $io->section('Dlazdice kategorii');
        foreach ($tiles as $t) {
            $io->writeln(sprintf('  %-28s %5d produktov  %s  %s', $t['name'], $t['count'], $t['link'], $t['img'] ?: '(bez obrazka)'));
        }

        $pageId = md5('brunko:home:page');
        $streamId = md5('brunko:home:stream:novinky');
        $heroSection = md5('brunko:home:section:hero');
        $mainSection = md5('brunko:home:section:main');

        $deals = $this->pickDiscounted();
        $io->section('Akciove produkty (' . \count($deals) . ')');
        foreach ($deals as $d) {
            $io->writeln(sprintf('  %-22s -%s %%  %s', $d['number'], $d['pct'], $d['name']));
        }
        $first = $tiles[0];
        $sections = [
            [
                'id' => $heroSection, 'position' => 0, 'type' => 'default', 'sizingMode' => 'full_width',
                'blocks' => [$this->htmlBlock('hero', 0, $this->heroHtml($first['link']), 'full_width', '0')],
            ],
            [
                'id' => $mainSection, 'position' => 1, 'type' => 'default', 'sizingMode' => 'boxed',
                'blocks' => [
                    $this->htmlBlock('benefits', 0, $this->benefitsHtml()),
                    $this->htmlBlock('categories', 1, $this->categoriesHtml($tiles)),
                    ...($deals ? [$this->sliderBlock('akcie', 2, 'static', array_column($deals, 'id'), 'Akciové produkty')] : []),
                    $this->sliderBlock('novinky', 3, 'product_stream', $streamId, 'Novinky v ponuke'),
                    $this->htmlBlock('about', 4, $this->aboutHtml()),
                ],
            ],
        ];

        $page = [
            'id' => $pageId,
            'type' => 'landingpage',
            'locked' => false,
            'translations' => $this->tr(['name' => 'Úvodná stránka']),
            'sections' => $sections,
        ];
        $stream = [
            'id' => $streamId,
            'translations' => $this->tr(['name' => 'Novinky (úvodná stránka)', 'description' => 'Aktívne hlavné produkty, radené podľa dátumu pridania']),
            'filters' => [[
                'id' => md5('brunko:home:stream:f0'), 'type' => 'multi', 'operator' => 'AND', 'position' => 0,
                'queries' => [
                    ['id' => md5('brunko:home:stream:f1'), 'type' => 'equals', 'field' => 'active', 'value' => '1', 'position' => 0],
                    ['id' => md5('brunko:home:stream:f2'), 'type' => 'equals', 'field' => 'parentId', 'value' => null, 'position' => 1],
                ],
            ]],
        ];

        $io->section('Layout');
        $io->writeln(sprintf('cms_page %s: 2 sekcie, hero, vyhody, kategorie, slider Akciove, slider Novinky, o obchode', $pageId));
        $io->writeln('product_stream ' . $streamId . ' (active=1, parentId IS NULL; radenie createdAt DESC, limit 12)');
        $io->writeln(sprintf('Pocet produktov v kategorii (pred): %d', $this->countProducts($navId)));

        if ($dry) {
            $io->note('--dry-run: nic sa nezapisalo.');
            return Command::SUCCESS;
        }

        // Stream musi existovat pred pouzitim; potom stranka; nakoniec priradenie kategorii.
        $this->productStreamRepository->upsert([$stream], $ctx);
        $this->cmsPageRepository->upsert([$page], $ctx);
        $this->categoryRepository->update([['id' => $navId, 'cmsPageId' => $pageId]], $ctx);

        $io->success('Layout "Úvodná stránka" vytvoreny a priradeny vstupnej kategorii.');
        $io->writeln('Dalej: bin/console cache:clear a cache:clear:http (a theme:compile, ak sa menil scss).');
        return Command::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function tr(array $fields): array
    {
        return [Defaults::LANGUAGE_SYSTEM => $fields, self::SK_LANGUAGE => $fields];
    }

    /** @return array<string,mixed> */
    private function htmlBlock(string $key, int $pos, string $html, string $sizing = 'boxed', string $margin = '20px'): array
    {
        $blockId = md5('brunko:home:block:' . $key);
        return [
            'id' => $blockId, 'position' => $pos, 'type' => 'html', 'name' => 'Brunko ' . $key,
            'sizingMode' => $sizing, 'marginTop' => $margin === '0' ? '0px' : $margin, 'marginBottom' => $margin === '0' ? '0px' : $margin,
            'slots' => [[
                'id' => md5('brunko:home:slot:' . $key), 'type' => 'html', 'slot' => 'content',
                'translations' => $this->tr(['config' => ['content' => ['source' => 'static', 'value' => $html]]]),
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function sliderBlock(string $key, int $pos, string $source, string|array $value, string $title): array
    {
        $cfg = [
            'products' => ['source' => $source, 'value' => $value],
            'title' => ['source' => 'static', 'value' => $title],
            'displayMode' => ['source' => 'static', 'value' => 'standard'],
            'boxLayout' => ['source' => 'static', 'value' => 'standard'],
            'navigationArrows' => ['source' => 'static', 'value' => 'outside'],
            'rotate' => ['source' => 'static', 'value' => false],
            'autoplayTimeout' => ['source' => 'static', 'value' => 5000],
            'speed' => ['source' => 'static', 'value' => 300],
            'border' => ['source' => 'static', 'value' => false],
            'elMinWidth' => ['source' => 'static', 'value' => '250px'],
            'verticalAlign' => ['source' => 'static', 'value' => null],
            'productStreamSorting' => ['source' => 'static', 'value' => 'createdAt:DESC'],
            'productStreamLimit' => ['source' => 'static', 'value' => 12],
        ];
        return [
            'id' => md5('brunko:home:block:' . $key), 'position' => $pos, 'type' => 'product-slider', 'name' => 'Brunko ' . $key,
            'sizingMode' => 'boxed', 'marginTop' => '20px', 'marginBottom' => '20px',
            'slots' => [[
                'id' => md5('brunko:home:slot:' . $key), 'type' => 'product-slider', 'slot' => 'productSlider',
                'translations' => $this->tr(['config' => $cfg]),
            ]],
        ];
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function heroHtml(string $link): string
    {
        return '<section class="brunko-home-hero">' . "\n"
            . '  <div class="container">' . "\n"
            . '    <h1 class="brunko-home-hero-title">Zariaďte si domov podľa seba</h1>' . "\n"
            . '    <p class="brunko-home-hero-text">Nábytok a bytové doplnky do každej miestnosti. Kuchyňa, obývačka, spálňa, kancelária aj záhrada na jednom mieste.</p>' . "\n"
            . '    <a class="btn btn-primary brunko-home-hero-btn" href="' . $this->e($link) . '">Nakupovať</a>' . "\n"
            . '  </div>' . "\n"
            . '</section>';
    }

    private function benefitsHtml(): string
    {
        $items = [
            ['truck', 'Doprava GLS / SPS', 'Doručenie kuriérom po Slovensku'],
            ['card', 'Platba na dobierku', 'Zaplatíte pri prevzatí tovaru'],
            ['return', 'Vrátenie do 14 dní', 'Bez udania dôvodu'],
            ['headset', 'Rýchle odoslanie', 'Tovar odosielame zo skladu'],
        ];
        $h = '<div class="brunko-home-benefits"><div class="row text-center">' . "\n";
        foreach ($items as [$icon, $t, $d]) {
            $h .= '  <div class="col-6 col-md-3 brunko-benefit"><span class="brunko-benefit-icon brunko-benefit-icon-' . $icon . '"></span>'
                . '<strong class="brunko-benefit-title">' . $this->e($t) . '</strong><span class="brunko-benefit-text">' . $this->e($d) . '</span></div>' . "\n";
        }
        return $h . '</div></div>';
    }

    /** @param list<array{name:string,link:string,img:string,count:int}> $tiles */
    private function categoriesHtml(array $tiles): string
    {
        $h = '<section class="brunko-home-cats">' . "\n" . '  <h2 class="brunko-home-heading">Nakupujte podľa kategórií</h2>' . "\n" . '  <div class="brunko-home-cat-grid">' . "\n";
        foreach ($tiles as $t) {
            $img = $t['img'] !== '' ? '<img src="' . $this->e($t['img']) . '" alt="' . $this->e($t['name']) . '" loading="lazy">' : '';
            $h .= '    <a class="brunko-home-cat" href="' . $this->e($t['link']) . '"><span class="brunko-home-cat-img">' . $img . '</span>'
                . '<span class="brunko-home-cat-name">' . $this->e($t['name']) . '</span></a>' . "\n";
        }
        return $h . '  </div>' . "\n" . '</section>';
    }

    private function aboutHtml(): string
    {
        return '<section class="brunko-home-about">' . "\n"
            . '  <h2 class="brunko-home-heading">O obchode breed.sk</h2>' . "\n"
            . '  <p>breed.sk je internetový obchod s nábytkom a bytovými doplnkami pre celú domácnosť. V ponuke nájdete tisíce produktov pre kuchyňu, obývačku, spálňu, kanceláriu aj záhradu. '
            . 'Tovar odosielame zo skladu a doručujeme kuriérom, platiť môžete aj na dobierku.</p>' . "\n"
            . '</section>';
    }

    /**
     * 12 aktivnych dostupnych hlavnych produktov s najvyssou zlavou (listPrice), s obrazkom; max 2 z jednej kategorie najvyssej urovne.
     * Vyber je staticky (listPrice sa v product streame filtrovat neda), obnovi ho opakovane spustenie prikazu.
     *
     * @return list<array{id:string,number:string,name:string,pct:string}>
     */
    private function pickDiscounted(): array
    {
        $pctPath = '$.c' . Defaults::CURRENCY . '.percentage.gross';
        $rows = $this->db->fetchAllAssociative(
            "SELECT LOWER(HEX(p.id)) id, p.product_number number, pt.name name,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(p.price, ?)) AS DECIMAL(6,2)) pct,
                    (SELECT LOWER(HEX(c.parent_id)) FROM product_category_tree pc JOIN category c ON c.id = pc.category_id
                      WHERE pc.product_id = p.id AND pc.product_version_id = p.version_id AND c.level = 3 LIMIT 1) top
               FROM product p
               JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = UNHEX(?)
              WHERE p.active = 1 AND p.available = 1 AND p.parent_id IS NULL AND p.product_media_id IS NOT NULL
                AND JSON_EXTRACT(p.price, ?) IS NOT NULL
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(p.price, ?)) AS DECIMAL(6,2)) BETWEEN 10 AND 60
              ORDER BY pct DESC, p.product_number LIMIT 400",
            [$pctPath, self::SK_LANGUAGE, $pctPath, $pctPath]
        );
        $out = [];
        $perTop = [];
        foreach ($rows as $r) {
            $top = (string) ($r['top'] ?? '');
            if (($perTop[$top] ?? 0) >= 2) {
                continue;
            }
            $perTop[$top] = ($perTop[$top] ?? 0) + 1;
            $out[] = ['id' => $r['id'], 'number' => (string) $r['number'], 'name' => mb_substr((string) $r['name'], 0, 60), 'pct' => (string) $r['pct']];
            if (\count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    private function countProducts(string $navId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT pc.product_id) FROM product_category_tree pc JOIN category c ON c.id = pc.category_id WHERE c.path LIKE CONCAT('%|', ?, '|%') OR c.id = UNHEX(?)",
            [$navId, $navId]
        );
    }

    /** @return list<array{name:string,link:string,img:string,count:int}> */
    private function loadTiles(string $navId, string $scId, Context $ctx): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT LOWER(HEX(c.id)) id, ct.name,
                    (SELECT COUNT(DISTINCT pc.product_id) FROM product_category_tree pc
                       JOIN product p ON p.id = pc.product_id AND p.version_id = pc.product_version_id
                      WHERE pc.category_id = c.id AND p.active = 1) cnt
               FROM category c JOIN category_translation ct ON ct.category_id = c.id AND ct.language_id = UNHEX(?)
              WHERE c.parent_id = UNHEX(?) AND c.active = 1 AND c.type = \'page\' AND ct.name IS NOT NULL
              ORDER BY cnt DESC LIMIT ' . self::TILES,
            [self::SK_LANGUAGE, $navId]
        );
        $tiles = [];
        foreach ($rows as $r) {
            if ((int) $r['cnt'] < 1) {
                continue;
            }
            $path = $this->db->fetchOne(
                "SELECT seo_path_info FROM seo_url WHERE foreign_key = UNHEX(?) AND route_name = 'frontend.navigation.page' AND is_canonical = 1 AND is_deleted = 0 AND sales_channel_id = UNHEX(?) AND language_id = UNHEX(?) LIMIT 1",
                [$r['id'], $scId, self::SK_LANGUAGE]
            );
            $mediaId = $this->db->fetchOne(
                'SELECT LOWER(HEX(pm.media_id)) FROM product_category_tree pc
                   JOIN product p ON p.id = pc.product_id AND p.version_id = pc.product_version_id
                   JOIN product_media pm ON pm.id = p.product_media_id AND pm.version_id = p.product_media_version_id
                  WHERE pc.category_id = UNHEX(?) AND p.active = 1 AND p.parent_id IS NULL
                  ORDER BY p.product_number LIMIT 1',
                [$r['id']]
            );
            $tiles[] = [
                'name' => (string) $r['name'],
                'link' => $path ? '/' . ltrim((string) $path, '/') : '/navigation/' . $r['id'],
                'img' => $mediaId ? $this->mediaUrl($mediaId, $ctx) : '',
                'count' => (int) $r['cnt'],
            ];
        }
        return $tiles;
    }

    /** Relativna URL miniatury (>=400px, najmensia), inak povodny obrazok. */
    private function mediaUrl(string $mediaId, Context $ctx): string
    {
        $criteria = new Criteria([$mediaId]);
        $criteria->addAssociation('thumbnails');
        /** @var MediaEntity|null $media */
        $media = $this->mediaRepository->search($criteria, $ctx)->first();
        if (!$media) {
            return '';
        }
        $url = $media->getUrl();
        $best = null;
        foreach ($media->getThumbnails() ?? [] as $t) {
            if ($t->getWidth() >= 400 && ($best === null || $t->getWidth() < $best->getWidth())) {
                $best = $t;
            }
        }
        if ($best) {
            $url = $best->getUrl();
        }
        $p = parse_url($url);
        return ($p['path'] ?? '') !== '' ? $p['path'] : '';
    }
}
