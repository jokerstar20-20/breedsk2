<?php declare(strict_types=1);

namespace Brunko\Importer\Command;

use Brunko\Importer\Feed\AutronicFeedReader;
use Brunko\Importer\Feed\FeedDownloader;
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
 * Stiahne obrazky produktov, ktore ich este nemaju. Kazdy obrazok (podla URL) sa stiahne len raz.
 */
#[AsCommand(name: 'brunko:import:images', description: 'Stiahne chybajuce obrazky produktov z feedu Autronic')]
class ImportImagesCommand extends Command
{
    private const SUPPLIER = 'autronic';
    private const SKIP_PREFIXES = ['DE-'];

    public function __construct(
        private readonly AutronicFeedReader $reader,
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
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Lokalny XML subor namiesto stiahnutia z URL')
            ->addOption('no-download', null, InputOption::VALUE_NONE, 'Nesťahuj feed, pouzi existujuci var/feeds/autronic.xml')
            ->addOption('max-images', null, InputOption::VALUE_REQUIRED, 'Max obrazkov na produkt', '5')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Spracuj najviac N produktov (test)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nic nestahuj');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $max = max(1, (int) $input->getOption('max-images'));
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $dry = (bool) $input->getOption('dry-run');

        try {
            $file = $this->feeds->resolve($input->getOption('file'), (bool) $input->getOption('no-download'), $io);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return $this->process($io, $file, $max, $limit, $dry);
    }

    private function process(SymfonyStyle $io, string $file, int $max, ?int $limit, bool $dry): int
    {
        $known = [];
        foreach ($this->db->fetchAllAssociative('SELECT code, LOWER(HEX(product_id)) AS pid FROM brunko_import_state WHERE supplier = ?', [self::SUPPLIER]) as $r) {
            $known[$r['code']] = $r['pid'];
        }
        $withMedia = array_fill_keys($this->db->fetchFirstColumn('SELECT DISTINCT LOWER(HEX(product_id)) FROM product_media'), true);
        $folderId = $this->db->fetchOne("SELECT LOWER(HEX(mf.id)) FROM media_folder mf INNER JOIN media_default_folder mdf ON mf.default_folder_id = mdf.id WHERE mdf.entity = 'product' LIMIT 1") ?: null;
        $ctx = Context::createDefaultContext();

        $todo = 0;
        $ok = 0;
        $failed = 0;
        foreach ($this->reader->read($file) as $p) {
            $code = $p['code'];
            if (!isset($known[$code]) || isset($withMedia[$known[$code]]) || !$p['images']) {
                continue;
            }
            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($p['cat_code'], $prefix)) {
                    continue 2;
                }
            }
            if ($limit !== null && $todo >= $limit) {
                break;
            }
            $todo++;
            if ($dry) {
                continue;
            }

            $pid = $known[$code];
            $media = [];
            foreach (array_slice($p['images'], 0, $max) as $i => $url) {
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

        $parents = $dry ? 0 : $this->copyMediaToParents($ctx);

        $io->success(sprintf('%s: produktov bez obrazka %d, stiahnute obrazky %d, zlyhane %d, hlavne produkty variantov s obrazkami %d', $dry ? '[DRY-RUN]' : 'Hotovo', $todo, $ok, $failed, $parents));

        return Command::SUCCESS;
    }

    /**
     * Hlavny produkt variantov (v zozname sa zobrazuje namiesto variantov) nema vlastne obrazky,
     * preto dostane obrazky prveho variantu, ktory ich ma. Medialne subory sa nekopiruju, len sa prepoja.
     */
    private function copyMediaToParents(Context $ctx): int
    {
        $parents = $this->db->fetchFirstColumn(
            "SELECT LOWER(HEX(p.id)) FROM product p
             WHERE p.version_id = UNHEX(?) AND p.parent_id IS NULL
               AND EXISTS (SELECT 1 FROM brunko_import_state s WHERE s.supplier = ? AND s.product_id = p.id AND s.code LIKE 'PARENT:%')
               AND NOT EXISTS (SELECT 1 FROM product_media pm WHERE pm.product_id = p.id)",
            [\Shopware\Core\Defaults::LIVE_VERSION, self::SUPPLIER]
        );

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
            if (!$rows) {
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

    private function ensureMedia(string $mediaId, string $url, string $code, ?string $folderId, Context $ctx): void
    {
        $exists = $this->db->fetchOne('SELECT 1 FROM media WHERE id = UNHEX(?) AND file_name IS NOT NULL', [$mediaId]);
        if ($exists) {
            return;
        }
        $this->mediaRepository->upsert([['id' => $mediaId, 'mediaFolderId' => $folderId]], $ctx);

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'jpg';
        $name = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $code)), '-') . '-' . substr(md5($url), 0, 8);

        $mediaFile = $this->fetcher->fetchFromURL($url, $name, $ext);
        try {
            $this->saver->persistFileToMedia($mediaFile, $name, $mediaId, $ctx);
        } finally {
            $this->fetcher->cleanUpTempFile($mediaFile);
        }
    }
}
