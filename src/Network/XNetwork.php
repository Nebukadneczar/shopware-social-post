<?php declare(strict_types=1);

namespace SocialPost\Network;

/** X (ehemals Twitter) über die API v2 mit OAuth 1.0a (Benutzer-Zugriff). Veröffentlicht Text mit Link. */
final class XNetwork extends AbstractNetwork
{
    private const ENDPOINT = 'https://api.x.com/2';

    public function key(): string
    {
        return 'x';
    }

    public function label(): string
    {
        return 'X (Twitter)';
    }

    public function limit(): int
    {
        return 280;
    }

    public function fields(): array
    {
        return [
            ['key' => 'apiKey', 'label' => 'API-Schlüssel (Consumer Key)', 'secret' => false, 'hint' => '', 'placeholder' => '', 'default' => ''],
            ['key' => 'apiSecret', 'label' => 'API-Geheimnis (Consumer Secret)', 'secret' => true, 'hint' => '', 'placeholder' => '', 'default' => ''],
            ['key' => 'accessToken', 'label' => 'Zugriffstoken (Access Token)', 'secret' => false, 'hint' => 'Muss Lese- und Schreibrechte haben.', 'placeholder' => '', 'default' => ''],
            ['key' => 'accessSecret', 'label' => 'Zugriffs-Geheimnis (Access Token Secret)', 'secret' => true, 'hint' => '', 'placeholder' => '', 'default' => ''],
        ];
    }

    public function help(): string
    {
        return 'Im X-Entwicklerportal ein Projekt mit App anlegen, in den App-Einstellungen "Lesen und Schreiben" aktivieren und danach Zugriffstoken samt Geheimnis erzeugen (die Rechte müssen vor dem Erzeugen der Tokens gesetzt sein). Für das Veröffentlichen verlangt X einen API-Zugang, der je nach Tarif Geld kosten kann; bitte die aktuellen Bedingungen bei X prüfen. Der Beitrag ist reiner Text mit Link, die Vorschau erzeugt X aus dem Link.';
    }

    public function isConfigured(array $values): bool
    {
        foreach (['apiKey', 'apiSecret', 'accessToken', 'accessSecret'] as $k) {
            if (trim($values[$k] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $v */
    private function authorization(string $method, string $url, array $v): string
    {
        $oauth = [
            'oauth_consumer_key' => trim($v['apiKey']),
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => trim($v['accessToken']),
            'oauth_version' => '1.0',
        ];

        // die Signatur umfasst nur die OAuth-Parameter (bei JSON-Inhalt zählt der Body nicht)
        $sorted = [];
        foreach ($oauth as $k => $val) {
            $sorted[rawurlencode($k)] = rawurlencode($val);
        }
        ksort($sorted);
        $pairs = [];
        foreach ($sorted as $k => $val) {
            $pairs[] = $k . '=' . $val;
        }
        $base = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode(implode('&', $pairs));
        $key = rawurlencode(trim($v['apiSecret'])) . '&' . rawurlencode(trim($v['accessSecret']));
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        $parts = [];
        foreach ($oauth as $k => $val) {
            $parts[] = rawurlencode($k) . '="' . rawurlencode($val) . '"';
        }

        return 'OAuth ' . implode(', ', $parts);
    }

    public function test(array $values): string
    {
        $url = self::ENDPOINT . '/users/me';
        $r = $this->call('GET', $url, ['headers' => ['Authorization' => $this->authorization('GET', $url, $values)]]);

        return 'Verbunden als @' . ($r['data']['username'] ?? '?') . '.';
    }

    public function composeText(Message $message): string
    {
        // X zählt jede Adresse mit 23 Zeichen; kleine Reserve wegen Sonderzeichen und Emojis
        return $this->withLink($message->text, $message->link, 275, 23);
    }

    public function publish(array $values, Message $message): array
    {
        if (!$this->isConfigured($values)) {
            throw new NetworkException('Zugangsdaten unvollständig.');
        }
        $url = self::ENDPOINT . '/tweets';
        $r = $this->call('POST', $url, [
            'headers' => [
                'Authorization' => $this->authorization('POST', $url, $values),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['text' => $this->composeText($message)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $id = (string) ($r['data']['id'] ?? '');
        if ($id === '') {
            throw new NetworkException('X hat keine Beitrags-ID zurückgegeben.');
        }

        return ['id' => $id, 'url' => 'https://x.com/i/status/' . $id];
    }
}
