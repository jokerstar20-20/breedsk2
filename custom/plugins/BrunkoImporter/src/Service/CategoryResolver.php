<?php declare(strict_types=1);

namespace Brunko\Importer\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Z kodu DE-UMKV-REZANE vytvori strom DE > UMKV > REZANE.
 * Existujuce kategorie nikdy neprepisuje (ani nazov), vytvara len chybajuce.
 * Kategorie vznikaju bez cmsPageId, takze dostanu predvoleny layout s bocnym filtrom.
 */
class CategoryResolver
{
    /** @var array<string,true> */
    private array $existing = [];
    /** @var array<string,string> */
    private array $names;
    private string $rootId = '';
    private string $skId = '';
    private string $systemId = '';

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $categoryRepository,
    ) {
        $this->names = require __DIR__ . '/../Resources/config/category_names.php';
    }

    public function init(string $rootCategoryId, string $skLanguageId, string $systemLanguageId): void
    {
        $this->rootId = $rootCategoryId;
        $this->skId = $skLanguageId;
        $this->systemId = $systemLanguageId;
        $this->existing = array_fill_keys(
            $this->connection->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM category'),
            true
        );
    }

    public static function idFor(string $code): string
    {
        return md5('brunko:cat:' . $code);
    }

    /**
     * Spolocny strom kategorii (strom Kondela): cesta "Obyvacka > Pohovky". ID z cesty, takze viac dodavatelov
     * dostane tie iste kategorie. Existujuce sa neprepisuju, vytvaraju sa len chybajuce.
     */
    public function ensurePath(string $path, bool $dryRun = false): string
    {
        $parentId = $this->rootId;
        $acc = '';
        $toCreate = [];
        foreach (array_map('trim', explode('>', $path)) as $name) {
            $acc = $acc === '' ? $name : $acc . ' > ' . $name;
            $id = md5('brunko:cat:path:' . mb_strtolower($acc));
            if (!isset($this->existing[$id])) {
                $toCreate[] = [
                    'id' => $id,
                    'parentId' => $parentId,
                    'active' => true,
                    'visible' => true,
                    'type' => 'page',
                    'translations' => [
                        $this->systemId => ['name' => $name],
                        $this->skId => ['name' => $name],
                    ],
                ];
                $this->existing[$id] = true;
            }
            $parentId = $id;
        }
        if ($toCreate && !$dryRun) {
            $this->categoryRepository->create($toCreate, Context::createDefaultContext());
        }

        return $parentId;
    }

    /** Zaisti existenciu cele cesty a vrati id listovej kategorie. */
    public function ensure(string $code, string $leafName, bool $dryRun = false): string
    {
        $parts = explode('-', $code);
        $parentId = $this->rootId;
        $path = '';
        $toCreate = [];
        foreach ($parts as $i => $part) {
            $path = $path === '' ? $part : $path . '-' . $part;
            $id = self::idFor($path);
            if (!isset($this->existing[$id])) {
                $isLeaf = $i === count($parts) - 1;
                $name = $isLeaf && $leafName !== '' ? $leafName : ($this->names[$path] ?? $part);
                $toCreate[] = [
                    'id' => $id,
                    'parentId' => $parentId,
                    'active' => true,
                    'visible' => true,
                    'type' => 'page',
                    'translations' => [
                        $this->systemId => ['name' => $name],
                        $this->skId => ['name' => $name],
                    ],
                ];
                $this->existing[$id] = true;
            }
            $parentId = $id;
        }
        if ($toCreate && !$dryRun) {
            $this->categoryRepository->create($toCreate, Context::createDefaultContext());
        }

        return $parentId;
    }
}
