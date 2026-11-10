<?php declare(strict_types=1);

namespace SocialPost\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Legt die Tabelle social_post an: eine Zeile je Beitrag (Entwurf, geplant, gesendet) mit den Ergebnissen je Netzwerk.
 */
class Migration1794500000CreateTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794500000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `social_post` (
    `id` BINARY(16) NOT NULL,
    `source_type` VARCHAR(16) NOT NULL DEFAULT 'custom',
    `source_id` BINARY(16) NULL,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `text` TEXT NOT NULL,
    `link` VARCHAR(1000) NULL,
    `image_url` VARCHAR(1000) NULL,
    `networks` VARCHAR(500) NOT NULL DEFAULT '[]',
    `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
    `origin` VARCHAR(8) NOT NULL DEFAULT 'manual',
    `scheduled_at` DATETIME(3) NULL,
    `sent_at` DATETIME(3) NULL,
    `results` TEXT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    KEY `idx.social_post.status` (`status`, `scheduled_at`),
    KEY `idx.social_post.source` (`source_type`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }
}
