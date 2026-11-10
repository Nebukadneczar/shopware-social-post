<?php declare(strict_types=1);

namespace SocialPost;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\Registry\TaskRegistry;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class SocialPost extends Plugin
{
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        // geplante Aufgabe „fällige Beiträge senden“ eintragen; schlägt das fehl, hilft: bin/console scheduled-task:register
        try {
            if ($this->container->has(TaskRegistry::class)) {
                $this->container->get(TaskRegistry::class)->registerTasks();
            }
        } catch (\Throwable) {
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Beiträge und Zugangsdaten nur löschen, wenn beim Deinstallieren „Daten löschen“ gewählt wurde
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `social_post`');
        $connection->executeStatement("DELETE FROM `system_config` WHERE `configuration_key` LIKE 'SocialPost.config.%'");
    }
}
