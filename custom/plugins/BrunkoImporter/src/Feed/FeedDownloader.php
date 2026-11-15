<?php declare(strict_types=1);

namespace Brunko\Importer\Feed;

use Symfony\Component\Console\Style\SymfonyStyle;
use XMLReader;

/**
 * Stiahne feed Autronic do var/feeds/<dodavatel>.xml. Najprv do autronic.xml.tmp a az po uspesnom stiahnuti
 * a overeni (validne XML s aspon jednym <Product>) ho premenuje, takze pri zlyhani ostane povodny subor.
 * Subor sa po behu nemaze.
 */
class FeedDownloader
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function path(string $supplier = 'autronic'): string
    {
        return $this->projectDir . '/var/feeds/' . $supplier . '.xml';
    }

    /**
     * Vrati cestu k feedu, ktory sa ma spracovat.
     *  - --file: pouzije sa presne tento subor
     *  - --no-download: pouzije sa existujuci var/feeds/autronic.xml
     *  - inak sa stiahne z BRUNKO_FEED_AUTRONIC_URL
     *
     * @throws \RuntimeException pri akejkolvek chybe (pri zlyhani stiahnutia ostava povodny subor nedotknuty)
     */
    public function resolve(?string $file, bool $noDownload, SymfonyStyle $io, string $supplier = 'autronic', string $element = 'Product', int $depth = 2): string
    {
        if ($file) {
            if (!is_file($file)) {
                throw new \RuntimeException('Subor feedu neexistuje: ' . $file);
            }

            return $file;
        }

        $target = $this->path($supplier);
        if ($noDownload) {
            if (!is_file($target)) {
                throw new \RuntimeException('Neexistuje ' . $target . ' (spusti bez --no-download, alebo pouzi --file=...).');
            }
            $io->writeln(sprintf('Pouzivam existujuci feed %s (%s)', $target, date('Y-m-d H:i:s', (int) filemtime($target))));

            return $target;
        }

        $this->download($target, $io, $supplier, $element, $depth);

        return $target;
    }

    private function download(string $target, SymfonyStyle $io, string $supplier, string $element, int $depth): void
    {
        $env = 'BRUNKO_FEED_' . strtoupper($supplier) . '_URL';
        $url = $_SERVER[$env] ?? $_ENV[$env] ?? getenv($env);
        if (!$url) {
            throw new \RuntimeException('Chyba ' . $env . ' v .env (alebo pouzi --file=... / --no-download).');
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nepodarilo sa vytvorit adresar ' . $dir);
        }

        $tmp = $target . '.tmp';
        $ctx = stream_context_create(['http' => ['timeout' => 120], 'https' => ['timeout' => 120]]);
        if (!@copy($url, $tmp, $ctx)) {
            @unlink($tmp);
            throw new \RuntimeException('Stiahnutie feedu zlyhalo' . (is_file($target) ? ', povodny ' . basename($target) . ' ostal nezmeneny.' : '.'));
        }

        $products = $this->countProducts($tmp, $element, $depth);
        if ($products < 1) {
            @unlink($tmp);
            throw new \RuntimeException('Stiahnuty subor nie je validne XML s aspon jednym <' . $element . '>' . (is_file($target) ? ', povodny ' . basename($target) . ' ostal nezmeneny.' : '.'));
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('Nepodarilo sa premenovat ' . $tmp . ' na ' . $target);
        }

        $io->writeln(sprintf('Feed stiahnuty: %s (%d produktov, %.1f MB)', $target, $products, filesize($target) / 1048576));
    }

    /** Pocet <Product> v koreni (ProductFeed/Products/Product); 0 ak subor nie je validne XML. */
    private function countProducts(string $file, string $element, int $depth): int
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $count = 0;
        $reader = new XMLReader();
        try {
            if (!$reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT)) {
                return 0;
            }
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === $element && $reader->depth === $depth) {
                    $count++;
                    $reader->next();
                }
            }
            foreach (libxml_get_errors() as $e) {
                if ($e->level >= LIBXML_ERR_ERROR) {
                    return 0;
                }
            }

            return $count;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }
}
