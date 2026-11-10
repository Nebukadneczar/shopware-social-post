<?php declare(strict_types=1);

namespace SocialPost\Service;

use Psr\Log\LoggerInterface;
use SocialPost\Network\Message;
use SocialPost\Network\NetworkRegistry;

/**
 * Herzstück: Beitrag je Netzwerk zusammenbauen, senden (oder im Testmodus nur simulieren), Ergebnisse speichern,
 * fällige Beiträge abarbeiten und Beiträge zu neuen Produkten/Blogartikeln automatisch anlegen.
 */
final class PostService
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly SettingsService $settings,
        private readonly NetworkRegistry $networks,
        private readonly SourceService $sources,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Adresse des Beitrags: eigener Link, sonst die der Quelle.
     *
     * @param array<string, mixed> $post
     */
    public function effectiveLink(array $post): string
    {
        $link = trim((string) ($post['link'] ?? ''));
        if ($link !== '') {
            return $link;
        }
        if (!empty($post['sourceId']) && \in_array($post['sourceType'] ?? '', SourceService::TYPES, true)) {
            return $this->sources->urlFor((string) $post['sourceType'], (string) $post['sourceId']);
        }

        return '';
    }

    private function tagLink(string $link, string $network, bool $utm): string
    {
        if ($link === '' || !$utm || str_contains($link, 'utm_source=')) {
            return $link;
        }
        $fragment = '';
        if (($pos = strpos($link, '#')) !== false) {
            $fragment = substr($link, $pos);
            $link = substr($link, 0, $pos);
        }
        $sep = str_contains($link, '?') ? '&' : '?';

        return $link . $sep . 'utm_source=' . rawurlencode($network) . '&utm_medium=social&utm_campaign=social-post' . $fragment;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function message(array $post, string $network, string $link, bool $utm): Message
    {
        $image = trim((string) ($post['imageUrl'] ?? ''));
        if ($image === '') {
            $image = (string) $this->settings->get('defaultImage');
        }
        $id = (string) ($post['id'] ?? 'preview');

        return new Message(
            (string) $post['text'],
            $this->tagLink($link, $network, $utm) ?: null,
            $image !== '' ? $image : null,
            (string) $post['title'],
            substr(hash('sha256', $id . '|' . $network), 0, 32),
        );
    }

    /**
     * Vorschau für den Editor: der Text, wie er je Netzwerk gesendet würde, mit Hinweisen.
     *
     * @param array<string, mixed> $post
     *
     * @return list<array<string, mixed>>
     */
    public function preview(array $post): array
    {
        $settings = $this->settings->all();
        $link = $this->effectiveLink($post);
        $out = [];
        foreach ($post['networks'] ?? [] as $key) {
            $net = $this->networks->get((string) $key);
            if ($net === null) {
                continue;
            }
            $cfg = $settings['networks'][$key];
            $msg = $this->message($post, $key, $link, (bool) $settings['utm']);
            $text = $net->composeText($msg);
            $warn = [];
            if (!$cfg['enabled'] || !$net->isConfigured($cfg['values'])) {
                $warn[] = 'Netzwerk ist nicht eingerichtet.';
            }
            if ($net->needsImage() && $msg->imageUrl === null) {
                $warn[] = 'Braucht ein Bild.';
            }
            if (trim((string) $post['text']) === '') {
                $warn[] = 'Der Text ist leer.';
            }
            $out[] = [
                'key' => $key,
                'label' => $net->label(),
                'text' => $text,
                'link' => $msg->link,
                'length' => mb_strlen($text),
                'limit' => $net->limit(),
                'cut' => trim((string) $post['text']) !== '' && !str_contains(preg_replace('~\s+~u', '', $text) ?? '', preg_replace('~\s+~u', '', trim((string) $post['text'])) ?? ''),
                'warnings' => $warn,
            ];
        }

        return $out;
    }

    /**
     * Sendet den Beitrag an die gewählten Netzwerke (bereits erfolgreiche werden übersprungen).
     *
     * @param list<string>|null $only
     *
     * @return array<string, mixed> der aktualisierte Beitrag
     */
    public function send(string $id, ?array $only = null): array
    {
        $post = $this->posts->find($id);
        if ($post === null) {
            throw new \RuntimeException('Beitrag nicht gefunden.');
        }
        if (!$this->posts->lock($id)) {
            throw new \RuntimeException('Der Beitrag wird gerade gesendet oder wurde schon gesendet.');
        }

        $settings = $this->settings->all();
        $dry = (bool) $settings['dryRun'];
        $results = $post['results'];
        $nets = $only ?? $post['networks'];

        try {
            // Blogartikel-Filter für automatische Beiträge
            if ($post['origin'] === 'auto' && $post['sourceType'] === 'landing_page' && $post['sourceId'] && ($prefix = (string) $settings['landingPrefix']) !== '') {
                $path = $this->sources->seoPath('landing_page', (string) $post['sourceId']);
                if (!str_starts_with($path, ltrim($prefix, '/'))) {
                    $this->posts->finish($id, 'skipped', ['_note' => ['status' => 'skipped', 'error' => 'Übersprungen: Die Adresse beginnt nicht mit "' . $prefix . '".']]);

                    return $this->posts->find($id) ?? $post;
                }
            }

            $link = $this->effectiveLink($post);
            foreach ($nets as $key) {
                $key = (string) $key;
                if (($results[$key]['status'] ?? '') === 'ok') {
                    continue;
                }
                $net = $this->networks->get($key);
                $cfg = $settings['networks'][$key] ?? null;
                $now = gmdate('Y-m-d H:i:s');
                if ($net === null || $cfg === null) {
                    $results[$key] = ['status' => 'failed', 'error' => 'Unbekanntes Netzwerk.', 'at' => $now];
                    continue;
                }
                if (!$cfg['enabled'] || !$net->isConfigured($cfg['values'])) {
                    $results[$key] = ['status' => 'failed', 'error' => 'Das Netzwerk ist nicht eingeschaltet oder nicht vollständig eingerichtet.', 'at' => $now];
                    continue;
                }

                $msg = $this->message($post, $key, $link, (bool) $settings['utm']);
                if ($dry) {
                    $results[$key] = ['status' => 'dry', 'at' => $now, 'text' => $net->composeText($msg)];
                    continue;
                }

                try {
                    $r = $net->publish($cfg['values'], $msg);
                    $results[$key] = ['status' => 'ok', 'id' => $r['id'], 'url' => $r['url'], 'at' => $now];
                } catch (\Throwable $e) {
                    $results[$key] = ['status' => 'failed', 'error' => $this->clean($e->getMessage(), $cfg['values']), 'at' => $now];
                    $this->logger->warning('SocialPost: Senden an ' . $key . ' fehlgeschlagen: ' . $this->clean($e->getMessage(), $cfg['values']));
                }
            }
        } catch (\Throwable $e) {
            $this->posts->finish($id, 'failed', $results + ['_error' => ['status' => 'failed', 'error' => $e->getMessage()]]);

            return $this->posts->find($id) ?? $post;
        }

        $this->posts->finish($id, $this->overall($results, $post['networks']), $results);

        return $this->posts->find($id) ?? $post;
    }

    /**
     * @param array<string, array<string, mixed>> $results
     * @param list<string> $networks
     */
    private function overall(array $results, array $networks): string
    {
        $ok = $failed = $dry = 0;
        foreach ($networks as $key) {
            match ($results[$key]['status'] ?? '') {
                'ok' => ++$ok,
                'dry' => ++$dry,
                default => ++$failed,
            };
        }
        if ($failed === 0 && $dry > 0) {
            return 'tested';
        }
        if ($failed === 0) {
            return 'sent';
        }

        return $ok > 0 ? 'partial' : 'failed';
    }

    /** Entfernt Zugangsdaten aus Fehlermeldungen (Telegram hat das Token in der Adresse). */
    private function clean(string $message, array $values): string
    {
        foreach ($values as $v) {
            if (\is_string($v) && mb_strlen($v) >= 6) {
                $message = str_replace($v, '***', $message);
            }
        }

        return mb_substr($message, 0, 500);
    }

    /**
     * Prüft die Zugangsdaten eines Netzwerks, ohne zu veröffentlichen.
     *
     * @return array{ok: bool, message: string}
     */
    public function testNetwork(string $key): array
    {
        $net = $this->networks->get($key);
        if ($net === null) {
            return ['ok' => false, 'message' => 'Unbekanntes Netzwerk.'];
        }
        $values = $this->settings->all()['networks'][$key]['values'];
        try {
            return ['ok' => true, 'message' => $net->test($values)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->clean($e->getMessage(), $values)];
        }
    }

    /**
     * Sendet fällige geplante Beiträge (automatische Beiträge höchstens so viele am Tag, wie eingestellt).
     *
     * @return list<array<string, mixed>>
     */
    public function runDue(int $max = 5): array
    {
        $this->posts->releaseStale();
        $limit = (int) $this->settings->get('autoMaxPerDay');
        $sentAuto = $this->posts->autoSentToday();
        $done = [];
        foreach ($this->posts->due($max * 4) as $post) {
            if (\count($done) >= $max) {
                break;
            }
            if ($post['origin'] === 'auto') {
                if ($sentAuto >= $limit) {
                    continue;
                }
                ++$sentAuto;
            }
            try {
                $done[] = $this->send((string) $post['id']);
            } catch (\Throwable $e) {
                $this->logger->warning('SocialPost: ' . $e->getMessage());
            }
        }

        return $done;
    }

    /**
     * Legt zu einem neuen Produkt/Blogartikel automatisch einen Beitrag an (Entwurf oder geplant).
     * Schutz vor Massenanlage: höchstens 10 automatische Beiträge pro Stunde, nur einmal je Quelle.
     */
    public function createAuto(string $type, string $sourceId, string $mode): ?string
    {
        if (!\in_array($mode, ['draft', 'send'], true)) {
            return null;
        }
        if ($this->posts->existsForSource($type, $sourceId)) {
            return null;
        }
        if ($this->posts->autoCreatedSince(new \DateTimeImmutable('-1 hour')) >= 10) {
            $this->logger->warning('SocialPost: Mehr als 10 automatische Beiträge in einer Stunde, weitere werden übersprungen.');

            return null;
        }
        $s = $this->sources->suggest($type, $sourceId);
        if ($s === null || !$s['active'] || trim($s['title']) === '') {
            return null;
        }

        $networks = array_values(array_intersect((array) $this->settings->get('autoNetworks'), $this->settings->readyNetworks()));
        if ($networks === [] && $mode === 'send') {
            $this->logger->info('SocialPost: Automatisches Senden ist aktiv, aber kein Netzwerk für automatische Beiträge gewählt.');

            return null;
        }

        $delay = (int) $this->settings->get('autoDelay');
        $at = $mode === 'send' ? (new \DateTimeImmutable('+' . $delay . ' minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v') : null;

        return $this->posts->save([
            'sourceType' => $type,
            'sourceId' => $sourceId,
            'title' => $s['title'],
            'text' => $s['text'],
            'link' => null,
            'imageUrl' => $s['imageUrl'],
            'networks' => $networks,
            'status' => $mode === 'send' ? 'scheduled' : 'draft',
            'origin' => 'auto',
            'scheduledAt' => $at,
        ]);
    }
}
