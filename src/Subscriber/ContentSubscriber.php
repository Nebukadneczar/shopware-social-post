<?php declare(strict_types=1);

namespace SocialPost\Subscriber;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\Uuid\Uuid;
use SocialPost\Service\PostService;
use SocialPost\Service\SettingsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Neues aktives Produkt oder neuer aktiver Blogartikel (Landingpage) → je nach Einstellung ein Entwurf
 * oder ein geplanter Beitrag. Fehler werden nur protokolliert und stören nie das Speichern im Admin.
 */
class ContentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly PostService $posts,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'product.written' => 'onProduct',
            'landing_page.written' => 'onLandingPage',
        ];
    }

    public function onProduct(EntityWrittenEvent $event): void
    {
        $this->handle('product', 'autoProduct', $event);
    }

    public function onLandingPage(EntityWrittenEvent $event): void
    {
        $this->handle('landing_page', 'autoLanding', $event);
    }

    private function handle(string $type, string $settingKey, EntityWrittenEvent $event): void
    {
        try {
            if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
                return;
            }
            $mode = (string) $this->settings->get($settingKey);
            if ($mode === 'off') {
                return;
            }

            foreach ($event->getWriteResults() as $result) {
                if ($result->getOperation() === EntityWriteResult::OPERATION_DELETE) {
                    continue;
                }
                $id = $result->getPrimaryKey();
                if (\is_array($id)) {
                    $id = $id['id'] ?? null;
                }
                if (!\is_string($id) || !Uuid::isValid($id)) {
                    continue;
                }
                $payload = $result->getPayload();
                $isInsert = $result->getOperation() === EntityWriteResult::OPERATION_INSERT;
                // Kandidat: neu angelegt oder das Feld "active" wurde auf wahr gesetzt
                if (!$isInsert && empty($payload['active'])) {
                    continue;
                }
                if (!$this->eligible($type, $id)) {
                    continue;
                }
                $this->posts->createAuto($type, $id, $mode);
            }
        } catch (\Throwable $e) {
            $this->logger->error('SocialPost: automatischer Beitrag fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /** Aktiv, kein Variantenprodukt und nicht älter als die eingestellte Anzahl Tage (schützt vor Beiträgen nach Importen). */
    private function eligible(string $type, string $id): bool
    {
        $row = $this->db->fetchAssociative(
            'SELECT active, created_at' . ($type === 'product' ? ', parent_id' : '') . ' FROM `' . $type . '` WHERE id = :id AND version_id = :v',
            ['id' => Uuid::fromHexToBytes($id), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        if (!$row || !$row['active'] || ($row['parent_id'] ?? null) !== null) {
            return false;
        }
        $days = max(1, (int) $this->settings->get('autoMaxAgeDays'));
        $created = new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC'));

        return $created >= new \DateTimeImmutable('-' . $days . ' days');
    }
}
