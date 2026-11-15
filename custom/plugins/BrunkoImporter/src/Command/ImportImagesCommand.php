<?php declare(strict_types=1);

namespace Brunko\Importer\Command;

use Brunko\Importer\Feed\AutronicFeedReader;
use Brunko\Importer\Feed\FeedDownloader;
use Brunko\Importer\Feed\KondelaFeedReader;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Media\File\FileFetcher;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Stiahne obrazky produktov (Autronic aj Kondela). Kazdy obrazok (podla URL) sa stiahne len raz.
 * Doplna: produkt, ktory ma menej obrazkov nez --max-images (a feed ich ma viac), dostane chybajuce. Nic sa nemaze ani neprepisuje,
 * takze je mozne najprv pustit napr. --max-images=3 a neskor --max-images=10.
 */
#[AsCommand(name: 'brunko:import:images', description: 'Stiahne chybajuce obrazky produktov z feedov Autronic a Kondela')]
class ImportImagesCommand extends Command
{
    private const SKIP_PREFIXES = ['DE-'];

    public function __construct(
        private readonly AutronicFeedReader $reader,
        private readonly KondelaFeedReader $kondela,
        private readonly FeedDownloader $feeds,
        private readonly Connection $db,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly FileFetcher $fetcher,
        private readonly FileSaver $saver,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('supplier', null, InputOption::VALUE_REQUIRED, 'autronic, kondela alebo all', 'all')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Lokalny XML subor namiesto stiahnutia z URL (len s --supplier=autronic|kondela)')
            ->addOption('no-download', null, InputOption::VALUE_NONE, 'Nesťahuj feed, pouzi existujuci var/feeds/<dodavatel>.xml')
            ->addOption('max-images', null, InputOption::VALUE_REQUIRED, 'Max obrazkov na produkt', '5')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Spracuj najviac N produktov na dodavatela (test)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nic nestahuj');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $max = max(1, (int) $input->getOption('max-images'));
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $dry = (bool) $input->getOption('dry-run');
        $supplier = strtolower((string) $input->getOption('supplier'));
        $suppliers = $supplier === 'all' ? ['autronic', 'kondela'] : [$supplier];
        foreach ($suppliers as $s) {
            if (!in_array($s, ['autronic', 'kondela'], true)) {
                $io->error('Neznamy dodavatel: ' . $s);

                return Command::INVALID;
            }
        }
        if ($input->getOption('file') && count($suppliers) !== 1) {
            $io->error('--file sa da pouzit len spolu s --supplier=autronic alebo kondela.');

            return Command::INVALID;
        }

        $rc = Command::SUCCESS;
        foreach ($suppliers as $s) {
            $io->section(ucfirst($s));
            try {
                $file = $s === 'kondela'
                    ? $this->feeds->resolve($input->getOption('file'), (bool) $input->getOption('no-download'), $io, 'kondela', 'PRODUKT', 1)
                    : $this->feeds->resolve($input->getOption('file'), (bool) $input->getOption('no-download'), $io);
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());
                $rc = Command::FAILURE;
                continue;
            }
            $this->process($io, $s, $file, $max, $limit, $dry);
        }

        return $rc;
    }

    private function process(SymfonyStyle $io, string $supplier, string $file, int $max, ?int $limit, bool $dry): void
    {
        $known = [];
        foreach ($this->db->fetchAllAssociative('SELECT code, LOWER(HEX(product_id)) AS pid FROM brunko_import_state WHERE supplier = ?', [$supplier]) as $r) {
            $known[$r['code']] = $r['pid'];
        }
        $have = [];
        foreach ($this->db->fetchAllAssociative('SELECT LOWER(HEX(product_id)) AS pid, COUNT(*) AS n FROM product_media GROUP BY product_id') as $r) {
            $have[$r['pid']] = (int) $r['n'];
        }
        $folderId = $this->db->fetchOne("SELECT LOWER(HEX(mf.id)) FROM media_folder mf INNER JOIN media_default_folder mdf ON mf.default_folder_id = mdf.id WHERE mdf.entity = 'product' LIMIT 1") ?: null;
        $ctx = Context::createDefaultContext();
        $items = $supplier === 'kondela' ? $this->kondela->read($file) : $this->reader->read($file);

        $todo = 0;
        $ok = 0;
        $failed = 0;
        foreach ($items as $p) {
            $code = $p['code'];
            if (!isset($known[$code]) || !$p['images']) {
                continue;
            }
            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with((string) $p['cat_code'], $prefix)) {
                    continue 2;
                }
            }
            $pid = $known[$code];
            $urls = array_slice($p['images'], 0, $max);
            if (($have[$pid] ?? 0) >= count($urls)) {
                continue; // uz ma dost obrazkov
            }
            if ($limit !== null && $todo >= $limit) {
                break;
            }
            $todo++;
            if ($dry) {
                continue;
            }

            $media = [];
            foreach ($urls as $i => $url) {
                $mediaId = md5('brunko:media:' . $url);
                try {
                    $this->ensureMedia($mediaId, $url, $code, $folderId, $ctx);
                    $media[] = ['id' => md5('brunko:pm:' . $pid . ':' . $mediaId), 'mediaId' => $mediaId, 'position' => $i];
                    $ok++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->logger->warning('Brunko obrazky: obrazok zlyhal', ['code' => $code, 'url' => $url, 'err' => $e->getMessage()]);
                }
            }
            if ($media) {
                $this->productRepository->update([[
                    'id' => $pid,
                    'media' => $media,
                    'coverId' => $media[0]['id'],
                ]], $ctx);
            }
            if ($todo % 50 === 0) {
                $io->writeln(sprintf('... %d produktov spracovanych', $todo));
            }
        }

        $parents = $dry ? 0 : $this->copyMediaToParents($supplier, $ctx);

        $io->success(sprintf('%s: produktov na doplnenie %d, spracovane obrazky %d, zlyhane %d, hlavne produkty variantov aktualizovane %d', $dry ? '[DRY-RUN]' : 'Hotovo', $todo, $ok, $failed, $parents));
    }

    /**
     * Hlavny produkt variantov (v zozname sa zobrazuje namiesto variantov) nema vlastne obrazky,
     * preto dostane obrazky prveho variantu, ktory ich ma (vratane doplnenia, ked ich variant dostal viac).
     * Medialne subory sa nekopiruju, len sa prepoja.
     */
    private function copyMediaToParents(string $supplier, Context $ctx): int
    {
        $parents = $this->db->fetchFirstColumn(
            "SELECT LOWER(HEX(p.id)) FROM product p
             WHERE p.version_id = UNHEX(?) AND p.parent_id IS NULL
               AND EXISTS (SELECT 1 FROM brunko_import_state s WHERE s.supplier = ? AND s.product_id = p.id AND s.code LIKE 'PARENT:%')",
            [\Shopware\Core\Defaults::LIVE_VERSION, $supplier]
        );
        $have = [];
        foreach ($this->db->fetchAllAssociative('SELECT LOWER(HEX(product_id)) AS pid, COUNT(*) AS n FROM product_media GROUP BY product_id') as $r) {
            $have[$r['pid']] = (int) $r['n'];
        }

        $done = 0;
        foreach ($parents as $parentId) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT LOWER(HEX(pm.media_id)) AS media_id, pm.position
                 FROM product_media pm
                 INNER JOIN product c ON c.id = pm.product_id AND c.version_id = pm.product_version_id
                 WHERE c.parent_id = UNHEX(?) AND c.version_id = UNHEX(?)
                   AND pm.product_id = (
                       SELECT pm2.product_id FROM product_media pm2
                       INNER JOIN product c2 ON c2.id = pm2.product_id AND c2.version_id = pm2.product_version_id
                       WHERE c2.parent_id = UNHEX(?) AND c2.version_id = UNHEX(?)
                       ORDER BY c2.product_number LIMIT 1
                   )
                 ORDER BY pm.position',
                [$parentId, \Shopware\Core\Defaults::LIVE_VERSION, $parentId, \Shopware\Core\Defaults::LIVE_VERSION]
            );
            if (!$rows || ($have[$parentId] ?? 0) >= count($rows)) {
                continue;
            }
            $media = [];
            foreach ($rows as $i => $r) {
                $media[] = ['id' => md5('brunko:pm:' . $parentId . ':' . $r['media_id']), 'mediaId' => $r['media_id'], 'position' => $i];
            }
            $this->productRepository->update([['id' => $parentId, 'media' => $media, 'coverId' => $media[0]['id']]], $ctx);
            $done++;
        }

        return $done;
    }

    /** Kondela ma v nazvoch suborov medzery a diakritiku (posteľ, béžová): cesta sa zakoduje po castiach, uz zakodovane znaky ostavaju. */
    private function encodeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'], $parts['path'])) {
            return str_replace(' ', '%20', $url);
        }
        $path = implode('/', array_map(static fn (string $seg) => rawurlencode(rawurldecode($seg)), explode('/', $parts['path'])));

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function ensureMedia(string $mediaId, string $url, string $code, ?string $folderId, Context $ctx): void
    {
        $exists = $this->db->fetchOne('SELECT 1 FROM media WHERE id = UNHEX(?) AND file_name IS NOT NULL', [$mediaId]);
        if ($exists) {
            return;
        }
        $this->mediaRepository->upsert([['id' => $mediaId, 'mediaFolderId' => $folderId]], $ctx);

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'jpg';
        $name = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $code)), '-') . '-' . substr(md5($url), 0, 8);

        $mediaFile = $this->fetcher->fetchFromURL($this->encodeUrl($url), $name, $ext);
        try {
            $this->saver->persistFileToMedia($mediaFile, $name, $mediaId, $ctx);
        } finally {
            $this->fetcher->cleanUpTempFile($mediaFile);
        }
    }
}
