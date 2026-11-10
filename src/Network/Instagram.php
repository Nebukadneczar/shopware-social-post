<?php declare(strict_types=1);

namespace SocialPost\Network;

/** Instagram-Business-Konto über die Instagram Graph API (Bild-Beitrag im Feed). */
final class Instagram extends AbstractNetwork
{
    public function key(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram (Business-Konto)';
    }

    public function limit(): int
    {
        return 2200;
    }

    public function needsImage(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            ['key' => 'userId', 'label' => 'Instagram-Konto-ID', 'secret' => false, 'hint' => 'ID des Instagram-Business-Kontos (nicht der Benutzername).', 'placeholder' => '17841400000000000', 'default' => ''],
            ['key' => 'accessToken', 'label' => 'Zugriffstoken', 'secret' => true, 'hint' => 'Langlebiges Token mit instagram_basic und instagram_content_publish (meist das Token der verknüpften Facebook-Seite).', 'placeholder' => '', 'default' => ''],
            ['key' => 'graphVersion', 'label' => 'Graph-API-Version', 'secret' => false, 'hint' => 'Normalerweise nicht ändern.', 'placeholder' => 'v23.0', 'default' => 'v23.0'],
        ];
    }

    public function help(): string
    {
        return 'Das Instagram-Konto muss ein Business- oder Creator-Konto sein und mit einer Facebook-Seite verknüpft sein. In derselben Facebook-App wie bei Facebook die Rechte instagram_basic und instagram_content_publish anfordern, über die Seite die Instagram-Konto-ID auslesen (Feld instagram_business_account). Instagram nimmt für Feed-Beiträge nur JPEG-Bilder an, die öffentlich per Adresse erreichbar sind. Links in der Bildunterschrift sind nicht anklickbar.';
    }

    public function isConfigured(array $values): bool
    {
        return trim($values['userId'] ?? '') !== '' && trim($values['accessToken'] ?? '') !== '';
    }

    private function base(array $v): string
    {
        $ver = trim($v['graphVersion'] ?? '') ?: 'v23.0';

        return 'https://graph.facebook.com/' . preg_replace('~[^a-z0-9.]~i', '', $ver);
    }

    private function headers(array $v): array
    {
        return ['Authorization' => 'Bearer ' . trim($v['accessToken'])];
    }

    public function test(array $values): string
    {
        $this->need($values, 'userId', 'accessToken');
        $r = $this->call('GET', $this->base($values) . '/' . rawurlencode(trim($values['userId'])), [
            'headers' => $this->headers($values),
            'query' => ['fields' => 'username'],
        ]);

        return 'Verbunden mit dem Konto @' . ($r['username'] ?? '?') . '.';
    }

    public function publish(array $values, Message $message): array
    {
        $this->need($values, 'userId', 'accessToken');
        $image = $this->requireImage($message);
        $path = strtolower((string) parse_url($image, PHP_URL_PATH));
        if (!preg_match('~\.jpe?g$~', $path)) {
            throw new NetworkException('Instagram nimmt nur JPEG-Bilder an (Endung .jpg oder .jpeg). Bitte eine passende Bild-Adresse eintragen.');
        }

        $base = $this->base($values);
        $user = rawurlencode(trim($values['userId']));
        $headers = $this->headers($values);

        $container = $this->call('POST', $base . '/' . $user . '/media', [
            'headers' => $headers,
            'body' => ['image_url' => $image, 'caption' => $this->composeText($message)],
        ]);
        $creation = (string) ($container['id'] ?? '');
        if ($creation === '') {
            throw new NetworkException('Instagram hat den Beitrag nicht angenommen (keine Container-ID).');
        }

        // kurz warten, bis Instagram das Bild verarbeitet hat
        for ($i = 0; $i < 8; ++$i) {
            $s = $this->call('GET', $base . '/' . rawurlencode($creation), ['headers' => $headers, 'query' => ['fields' => 'status_code']]);
            $code = (string) ($s['status_code'] ?? '');
            if ($code === 'FINISHED' || $code === '') {
                break;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new NetworkException('Instagram konnte das Bild nicht verarbeiten (Status ' . $code . '). Bild-Adresse und Format prüfen.');
            }
            sleep(1);
        }

        $published = $this->call('POST', $base . '/' . $user . '/media_publish', [
            'headers' => $headers,
            'body' => ['creation_id' => $creation],
        ]);
        $id = (string) ($published['id'] ?? '');
        if ($id === '') {
            throw new NetworkException('Instagram hat keine Beitrags-ID zurückgegeben.');
        }

        $url = null;
        try {
            $p = $this->call('GET', $base . '/' . rawurlencode($id), ['headers' => $headers, 'query' => ['fields' => 'permalink']]);
            $url = isset($p['permalink']) ? (string) $p['permalink'] : null;
        } catch (\Throwable) {
        }

        return ['id' => $id, 'url' => $url];
    }
}
