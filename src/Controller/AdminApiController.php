<?php declare(strict_types=1);

namespace SocialPost\Controller;

use Shopware\Core\Framework\Uuid\Uuid;
use SocialPost\Network\NetworkRegistry;
use SocialPost\Service\PostRepository;
use SocialPost\Service\PostService;
use SocialPost\Service\SettingsService;
use SocialPost\Service\SourceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schnittstelle für das Admin-Modul „Social Media“.
 * Lesen braucht das Recht system_config:read, Ändern und Senden system_config:update.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class AdminApiController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostService $service,
        private readonly SettingsService $settings,
        private readonly SourceService $sources,
        private readonly NetworkRegistry $networks,
    ) {
    }

    #[Route(path: '/api/_action/social-post/overview', name: 'api.action.social-post.overview', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $status = (string) $request->query->get('status', 'all');
        $all = $this->settings->all();
        $nets = [];
        foreach ($this->networks->all() as $key => $net) {
            $n = $all['networks'][$key];
            $nets[] = [
                'key' => $key,
                'label' => $net->label(),
                'enabled' => $n['enabled'],
                'configured' => $net->isConfigured($n['values']),
                'needsImage' => $net->needsImage(),
                'limit' => $net->limit(),
            ];
        }

        return new JsonResponse([
            'posts' => $this->posts->list($status),
            'counts' => $this->posts->counts(),
            'networks' => $nets,
            'dryRun' => (bool) $all['dryRun'],
            'autoProduct' => $all['autoProduct'],
            'autoLanding' => $all['autoLanding'],
        ]);
    }

    #[Route(path: '/api/_action/social-post/post/{id}', name: 'api.action.social-post.post', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function post(string $id): JsonResponse
    {
        $p = $this->posts->find($id);

        return $p ? new JsonResponse($p) : new JsonResponse(['error' => 'Beitrag nicht gefunden.'], 404);
    }

    #[Route(path: '/api/_action/social-post/post', name: 'api.action.social-post.create', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->store(null, $request);
    }

    #[Route(path: '/api/_action/social-post/post/{id}', name: 'api.action.social-post.update', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->store($id, $request);
    }

    private function store(?string $id, Request $request): JsonResponse
    {
        $existing = null;
        if ($id !== null) {
            $existing = $this->posts->find($id);
            if ($existing === null) {
                return new JsonResponse(['error' => 'Beitrag nicht gefunden.'], 404);
            }
            if (\in_array($existing['status'], ['sending', 'sent'], true)) {
                return new JsonResponse(['error' => 'Dieser Beitrag ist schon gesendet. Bitte duplizieren.'], 409);
            }
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Ungültige Eingabe.'], 422);
        }
        $norm = $this->normalize($data);
        if (isset($norm['error'])) {
            return new JsonResponse(['error' => $norm['error']], 422);
        }
        $norm['origin'] = $existing['origin'] ?? 'manual';

        $schedule = ($data['action'] ?? 'draft') === 'schedule';
        if ($schedule) {
            if ($norm['scheduledAt'] === null) {
                return new JsonResponse(['error' => 'Bitte einen Zeitpunkt zum Senden wählen.'], 422);
            }
            if ($norm['networks'] === []) {
                return new JsonResponse(['error' => 'Bitte mindestens ein Netzwerk wählen.'], 422);
            }
            $norm['status'] = 'scheduled';
        } else {
            $norm['status'] = 'draft';
            $norm['scheduledAt'] = null;
        }

        $newId = $this->posts->save($norm, $id);

        return new JsonResponse($this->posts->find($newId));
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    private function normalize(array $d): array
    {
        $type = (string) ($d['sourceType'] ?? 'custom');
        if (!\in_array($type, ['custom', ...SourceService::TYPES], true)) {
            return ['error' => 'Unbekannte Quelle.'];
        }
        $sourceId = strtolower(trim((string) ($d['sourceId'] ?? '')));
        if ($type !== 'custom' && !preg_match('~^[0-9a-f]{32}$~', $sourceId)) {
            return ['error' => 'Bitte eine Quelle auswählen.'];
        }
        $title = trim((string) ($d['title'] ?? ''));
        $text = trim(str_replace("\r\n", "\n", (string) ($d['text'] ?? '')));
        if (mb_strlen($title) > 255) {
            return ['error' => 'Der Titel darf höchstens 255 Zeichen haben.'];
        }
        if (mb_strlen($text) > 5000) {
            return ['error' => 'Der Text darf höchstens 5000 Zeichen haben.'];
        }
        $link = trim((string) ($d['link'] ?? ''));
        $image = trim((string) ($d['imageUrl'] ?? ''));
        foreach (['Link' => $link, 'Bild-Adresse' => $image] as $label => $url) {
            if ($url !== '' && (!preg_match('~^https?://~i', $url) || mb_strlen($url) > 1000 || preg_match('~\s~', $url))) {
                return ['error' => $label . ' muss mit http:// oder https:// beginnen.'];
            }
        }

        $nets = [];
        foreach ((array) ($d['networks'] ?? []) as $k) {
            if (\is_string($k) && $this->networks->get($k) !== null) {
                $nets[] = $k;
            }
        }

        $at = null;
        if (!empty($d['scheduledAt'])) {
            try {
                $at = (new \DateTimeImmutable((string) $d['scheduledAt']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
            } catch (\Throwable) {
                return ['error' => 'Der Zeitpunkt ist ungültig.'];
            }
        }

        return [
            'sourceType' => $type,
            'sourceId' => $type === 'custom' ? null : $sourceId,
            'title' => $title,
            'text' => $text,
            'link' => $link,
            'imageUrl' => $image,
            'networks' => array_values(array_unique($nets)),
            'scheduledAt' => $at,
        ];
    }

    #[Route(path: '/api/_action/social-post/post/{id}', name: 'api.action.social-post.delete', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['system_config:update']], methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $p = $this->posts->find($id);
        if ($p === null) {
            return new JsonResponse(['error' => 'Beitrag nicht gefunden.'], 404);
        }
        if ($p['status'] === 'sending') {
            return new JsonResponse(['error' => 'Der Beitrag wird gerade gesendet.'], 409);
        }
        $this->posts->delete($id);

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/api/_action/social-post/post/{id}/duplicate', name: 'api.action.social-post.duplicate', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function duplicate(string $id): JsonResponse
    {
        $p = $this->posts->find($id);
        if ($p === null) {
            return new JsonResponse(['error' => 'Beitrag nicht gefunden.'], 404);
        }
        $p['status'] = 'draft';
        $p['origin'] = 'manual';
        $p['scheduledAt'] = null;
        $new = $this->posts->save($p);

        return new JsonResponse($this->posts->find($new));
    }

    #[Route(path: '/api/_action/social-post/post/{id}/send', name: 'api.action.social-post.send', requirements: ['id' => '[0-9a-f]{32}'], defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function send(string $id): JsonResponse
    {
        $p = $this->posts->find($id);
        if ($p === null) {
            return new JsonResponse(['error' => 'Beitrag nicht gefunden.'], 404);
        }
        if ($p['networks'] === []) {
            return new JsonResponse(['error' => 'Bitte mindestens ein Netzwerk wählen.'], 422);
        }
        if (trim((string) $p['text']) === '') {
            return new JsonResponse(['error' => 'Der Text ist leer.'], 422);
        }
        try {
            return new JsonResponse($this->service->send($id));
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }
    }

    #[Route(path: '/api/_action/social-post/preview', name: 'api.action.social-post.preview', defaults: ['_acl' => ['system_config:read']], methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Ungültige Eingabe.'], 422);
        }
        if (empty($data['sourceId'])) {
            $data['sourceType'] = 'custom';
        }
        $norm = $this->normalize($data);
        if (isset($norm['error'])) {
            return new JsonResponse(['items' => [], 'link' => '']);
        }

        return new JsonResponse(['items' => $this->service->preview($norm + ['id' => 'preview']), 'link' => $this->service->effectiveLink($norm)]);
    }

    #[Route(path: '/api/_action/social-post/search', name: 'api.action.social-post.search', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $type = (string) $request->query->get('type', 'product');

        return new JsonResponse(['items' => $this->sources->search($type, (string) $request->query->get('q', ''))]);
    }

    #[Route(path: '/api/_action/social-post/source', name: 'api.action.social-post.source', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function source(Request $request): JsonResponse
    {
        $type = (string) $request->query->get('type', '');
        $id = strtolower((string) $request->query->get('id', ''));
        if (!\in_array($type, SourceService::TYPES, true) || !Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Ungültige Quelle.'], 422);
        }
        $s = $this->sources->suggest($type, $id);

        return $s ? new JsonResponse($s) : new JsonResponse(['error' => 'Quelle nicht gefunden.'], 404);
    }

    #[Route(path: '/api/_action/social-post/settings', name: 'api.action.social-post.settings', defaults: ['_acl' => ['system_config:read']], methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        return new JsonResponse($this->settings->forAdmin());
    }

    #[Route(path: '/api/_action/social-post/settings', name: 'api.action.social-post.settings.save', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function saveSettings(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Ungültige Eingabe.'], 422);
        }
        $this->settings->save($data);

        return new JsonResponse($this->settings->forAdmin());
    }

    #[Route(path: '/api/_action/social-post/network-test/{key}', name: 'api.action.social-post.test', requirements: ['key' => '[a-z]{1,20}'], defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function test(string $key): JsonResponse
    {
        return new JsonResponse($this->service->testNetwork($key));
    }

    #[Route(path: '/api/_action/social-post/run-due', name: 'api.action.social-post.rundue', defaults: ['_acl' => ['system_config:update']], methods: ['POST'])]
    public function runDue(): JsonResponse
    {
        return new JsonResponse(['sent' => \count($this->service->runDue())]);
    }
}
