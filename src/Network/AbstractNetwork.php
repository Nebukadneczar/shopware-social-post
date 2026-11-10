<?php declare(strict_types=1);

namespace SocialPost\Network;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractNetwork implements NetworkInterface
{
    public function __construct(protected readonly HttpClientInterface $http)
    {
    }

    public function needsImage(): bool
    {
        return false;
    }

    public function composeText(Message $message): string
    {
        return $this->withLink($message->text, $message->link, $this->limit());
    }

    /**
     * Führt einen Aufruf aus und gibt die JSON-Antwort als Array zurück.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function call(string $method, string $url, array $options = []): array
    {
        $options += ['timeout' => 20, 'max_duration' => 45];

        try {
            $response = $this->http->request($method, $url, $options);
            $code = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new NetworkException('Keine Verbindung zum Netzwerk: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode($body, true);
        if ($code >= 400) {
            throw new NetworkException($this->errorText($code, \is_array($data) ? $data : [], $body));
        }

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function errorText(int $code, array $data, string $body): string
    {
        $text = null;
        foreach (['error', 'message', 'description', 'detail', 'title'] as $k) {
            $v = $data[$k] ?? null;
            if (\is_array($v)) {
                $v = $v['message'] ?? $v['error_user_msg'] ?? null;
            }
            if (\is_string($v) && $v !== '') {
                $text = $v;
                break;
            }
        }
        if ($text === null && isset($data['errors'][0]['message']) && \is_string($data['errors'][0]['message'])) {
            $text = $data['errors'][0]['message'];
        }
        if ($text === null) {
            $text = mb_substr(trim(strip_tags($body)), 0, 200);
        }

        return 'Fehler ' . $code . ': ' . $text;
    }

    protected function need(array $values, string ...$keys): void
    {
        foreach ($keys as $k) {
            if (trim((string) ($values[$k] ?? '')) === '') {
                throw new NetworkException('Zugangsdaten unvollständig (Feld "' . $k . '" fehlt).');
            }
        }
    }

    /** Kürzt an einer Wortgrenze und hängt "…" an. */
    protected function fit(string $text, int $max): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($max <= 0 || mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, max(1, $max - 1));
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \n.,;:-") . '…';
    }

    /** Hängt den Link ans Ende; der Text wird so gekürzt, dass beides in die Grenze passt. */
    protected function withLink(string $text, ?string $link, int $limit, ?int $linkLength = null): string
    {
        if ($link === null || $link === '') {
            return $this->fit($text, $limit);
        }
        $room = $limit - ($linkLength ?? mb_strlen($link)) - 2;

        return $this->fit($text, max(10, $room)) . "\n\n" . $link;
    }

    protected function requireImage(Message $m): string
    {
        if ($m->imageUrl === null || $m->imageUrl === '') {
            throw new NetworkException($this->label() . ' braucht ein Bild. Bitte im Beitrag eine Bild-Adresse eintragen.');
        }

        return $m->imageUrl;
    }
}
