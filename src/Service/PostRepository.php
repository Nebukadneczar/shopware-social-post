<?php declare(strict_types=1);

namespace SocialPost\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/** Zugriff auf die Tabelle social_post. IDs gehen nach außen immer als 32-stelliger Hex-Wert. */
final class PostRepository
{
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'partial', 'failed', 'tested', 'skipped'];

    public function __construct(private readonly Connection $db)
    {
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $r): array
    {
        $networks = json_decode((string) $r['networks'], true);
        $results = json_decode((string) ($r['results'] ?? ''), true);

        return [
            'id' => Uuid::fromBytesToHex($r['id']),
            'sourceType' => $r['source_type'],
            'sourceId' => $r['source_id'] !== null ? Uuid::fromBytesToHex($r['source_id']) : null,
            'title' => $r['title'],
            'text' => $r['text'],
            'link' => $r['link'],
            'imageUrl' => $r['image_url'],
            'networks' => \is_array($networks) ? array_values($networks) : [],
            'status' => $r['status'],
            'origin' => $r['origin'],
            'scheduledAt' => $this->trimDate($r['scheduled_at']),
            'sentAt' => $this->trimDate($r['sent_at']),
            'results' => \is_array($results) ? $results : [],
            'createdAt' => $this->trimDate($r['created_at']),
        ];
    }

    private function trimDate(?string $d): ?string
    {
        return $d !== null ? substr($d, 0, 19) : null;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $r = $this->db->fetchAssociative('SELECT * FROM social_post WHERE id = :id', ['id' => Uuid::fromHexToBytes($id)]);

        return $r ? $this->hydrate($r) : null;
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status, int $limit = 200): array
    {
        $sql = 'SELECT * FROM social_post';
        $params = [];
        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' WHERE status = :s';
            $params['s'] = $status;
        }
        $sql .= ' ORDER BY COALESCE(scheduled_at, created_at) DESC LIMIT ' . max(1, min(500, $limit));

        return array_map(fn (array $r) => $this->hydrate($r), $this->db->fetchAllAssociative($sql, $params));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->fetchAllAssociative('SELECT status, COUNT(*) c FROM social_post GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['c'];
        }

        return $out;
    }

    /**
     * Legt an oder aktualisiert; gibt die ID zurück.
     *
     * @param array<string, mixed> $d
     */
    public function save(array $d, ?string $id = null): string
    {
        $now = self::now();
        $row = [
            'source_type' => $d['sourceType'] ?? 'custom',
            'source_id' => !empty($d['sourceId']) ? Uuid::fromHexToBytes($d['sourceId']) : null,
            'title' => (string) ($d['title'] ?? ''),
            'text' => (string) ($d['text'] ?? ''),
            'link' => ($d['link'] ?? '') !== '' ? $d['link'] : null,
            'image_url' => ($d['imageUrl'] ?? '') !== '' ? $d['imageUrl'] : null,
            'networks' => json_encode(array_values($d['networks'] ?? [])),
            'status' => $d['status'] ?? 'draft',
            'origin' => $d['origin'] ?? 'manual',
            'scheduled_at' => $d['scheduledAt'] ?? null,
            'updated_at' => $now,
        ];

        if ($id === null) {
            $id = Uuid::randomHex();
            $this->db->insert('social_post', $row + ['id' => Uuid::fromHexToBytes($id), 'created_at' => $now]);
        } else {
            $this->db->update('social_post', $row, ['id' => Uuid::fromHexToBytes($id)]);
        }

        return $id;
    }

    /** @param array<string, mixed> $results */
    public function finish(string $id, string $status, array $results): void
    {
        $now = self::now();
        $this->db->update('social_post', [
            'status' => $status,
            'results' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sent_at' => \in_array($status, ['sent', 'partial'], true) ? $now : null,
            'updated_at' => $now,
        ], ['id' => Uuid::fromHexToBytes($id)]);
    }

    public function delete(string $id): void
    {
        $this->db->delete('social_post', ['id' => Uuid::fromHexToBytes($id)]);
    }

    /** Sperrt den Beitrag für das Senden; false, wenn ihn schon jemand anderes sendet oder er fertig ist. */
    public function lock(string $id): bool
    {
        $n = $this->db->executeStatement(
            "UPDATE social_post SET status = 'sending', updated_at = :now WHERE id = :id AND status IN ('draft','scheduled','failed','partial','tested')",
            ['now' => self::now(), 'id' => Uuid::fromHexToBytes($id)]
        );

        return $n === 1;
    }

    /** Beiträge, die länger als 15 Minuten im Status „sending“ hängen, als fehlgeschlagen markieren. */
    public function releaseStale(): void
    {
        $this->db->executeStatement(
            "UPDATE social_post SET status = 'failed' WHERE status = 'sending' AND updated_at < :t",
            ['t' => (new \DateTimeImmutable('-15 minutes', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
        );
    }

    /** @return list<array<string, mixed>> */
    public function due(int $limit): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM social_post WHERE status = 'scheduled' AND scheduled_at <= :now ORDER BY scheduled_at ASC LIMIT " . max(1, $limit),
            ['now' => self::now()]
        );

        return array_map(fn (array $r) => $this->hydrate($r), $rows);
    }

    public function existsForSource(string $type, string $sourceId): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM social_post WHERE source_type = :t AND source_id = :s LIMIT 1',
            ['t' => $type, 's' => Uuid::fromHexToBytes($sourceId)]
        );
    }

    public function autoSentToday(): int
    {
        $start = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM social_post WHERE origin = 'auto' AND status IN ('sent','partial') AND sent_at >= :t",
            ['t' => $start]
        );
    }

    public function autoCreatedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM social_post WHERE origin = 'auto' AND created_at >= :t",
            ['t' => $since->setTimezone(new \DateTimeZone('UTC'))->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
        );
    }
}
