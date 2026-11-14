<?php declare(strict_types=1);

namespace Brunko\Importer\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1763100000CreateImportState extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1763100000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `brunko_import_state` (
                `supplier`   VARCHAR(32)  NOT NULL,
                `code`       VARCHAR(190) NOT NULL,
                `product_id` BINARY(16)   NOT NULL,
                `hash`       CHAR(32)     NOT NULL,
                `updated_at` DATETIME(3)  NOT NULL,
                PRIMARY KEY (`supplier`, `code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
