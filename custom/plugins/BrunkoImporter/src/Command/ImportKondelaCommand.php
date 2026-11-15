<?php declare(strict_types=1);

namespace Brunko\Importer\Command;

use Brunko\Importer\Feed\AutronicFeedReader;
use Brunko\Importer\Feed\FeedDownloader;
use Brunko\Importer\Feed\KondelaFeedReader;
use Brunko\Importer\Service\CategoryResolver;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import Kondela: rovnaky postup ako Autronic (diff podla hashu, varianty, skrytie vypadnutych), len ine citanie feedu,
 * vyrobca a kategorie (cesta z feedu = spolocny strom).
 */
#[AsCommand(name: 'brunko:import:kondela', description: 'Import produktov z feedu Kondela (ceny, sklad, kategorie, varianty)')]
class ImportKondelaCommand extends ImportAutronicCommand
{
    public function __construct(
        AutronicFeedReader $reader,
        private readonly KondelaFeedReader $kondela,
        FeedDownloader $feeds,
        CategoryResolver $categories,
        Connection $db,
        EntityRepository $productRepository,
        EntityRepository $taxRepository,
        EntityRepository $manufacturerRepository,
        LoggerInterface $logger,
    ) {
        parent::__construct($reader, $feeds, $categories, $db, $productRepository, $taxRepository, $manufacturerRepository, $logger);
    }

    protected function supplier(): string
    {
        return 'kondela';
    }

    protected function manufacturerName(): string
    {
        return 'Kondela';
    }

    // Kody su ciselne (0000085802), spolocny zaciatok nema vyznam: cislo hlavneho produktu = prvy kod + "-M"
    protected function commonPrefixParentNumber(): bool
    {
        return false;
    }

    protected function resolveFeed(?string $file, bool $noDownload, SymfonyStyle $io): string
    {
        return $this->feeds->resolve($file, $noDownload, $io, 'kondela', 'PRODUKT', 1);
    }

    protected function readItems(string $file): iterable
    {
        $items = $this->kondela->read($file);
        $skipped = $this->kondela->skipped();
        $this->io?->writeln(sprintf(
            'Kondela: vynechane bez ceny %d, na objednavku %d, bez skladu %d',
            $skipped['bez ceny'],
            $skipped['na objednavku'],
            $skipped['bez skladu']
        ));

        return $items;
    }

    protected function categoryId(array $p, bool $dry): string
    {
        return $this->categories->ensurePath($p['cat_path'], $dry);
    }

    protected function displayName(string $name, string $code): string
    {
        return $name !== '' ? $name : 'Kondela ' . $code;
    }

    // Spolocny strom ostava, nic sa neodpaja
    protected function retireLegacyCategories(): void
    {
    }
}
