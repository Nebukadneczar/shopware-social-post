<?php declare(strict_types=1);

namespace SocialPost\Network;

/** Mastodon (jede Instanz) über ein Zugriffstoken mit dem Recht write:statuses. */
final class Mastodon extends AbstractNetwork
{
    public function key(): string
    {
        return 'mastodon';
    }

    public function label(): string
    {
        return 'Mastodon';
    }

    public function limit(): int
    {
        return 500;
    }

    public function fields(): array
    {
        return [
            ['key' => 'instance', 'label' => 'Instanz-Adresse', 'secret' => false, 'hint' => 'Adresse deiner Mastodon-Instanz mit https://.', 'placeholder' => 'https://mastodon.social', 'default' => ''],
            ['key' => 'accessToken', 'label' => 'Zugriffstoken', 'secret' => true, 'hint' => 'Einstellungen → Entwicklung → Neue Anwendung, Recht write:statuses.', 'placeholder' => '', 'default' => ''],
        ];
    }

    public function help(): string
    {
        return 'In Mastodon unter Einstellungen → Entwicklung eine neue Anwendung anlegen (nur das Recht write:statuses anhaken) und das Zugriffstoken kopieren. Die Vorschau mit Bild erzeugt Mastodon selbst aus dem Link.';
    }

    public function isConfigured(array $values): bool
    {
        return trim($values['instance'] ?? '') !== '' && trim($values['accessToken'] ?? '') !== '';
    }

    private function base(array $v): string
    {
        $b = rtrim(trim($v['instance'] ?? ''), '/');
        if (!str_starts_with($b, 'https://')) {
            throw new NetworkException('Die Instanz-Adresse muss mit https:// beginnen.');
        }

        return $b;
    }

    private function headers(array $v): array
    {
        return ['Authorization' => 'Bearer ' . trim($v['accessToken'])];
    }

    public function test(array $values): string
    {
        $this->need($values, 'instance', 'accessToken');
        $r = $this->call('GET', $this->base($values) . '/api/v1/accounts/verify_credentials', ['headers' => $this->headers($values)]);

        return 'Verbunden als @' . ($r['acct'] ?? '?') . '.';
    }

    public function composeText(Message $message): string
    {
        // Mastodon zählt jede Adresse mit 23 Zeichen
        return $this->withLink($message->text, $message->link, $this->limit(), 23);
    }

    public function publish(array $values, Message $message): array
    {
        $this->need($values, 'instance', 'accessToken');
        $r = $this->call('POST', $this->base($values) . '/api/v1/statuses', [
            'headers' => $this->headers($values) + ['Idempotency-Key' => $message->key],
            'body' => ['status' => $this->composeText($message), 'visibility' => 'public'],
        ]);
        $id = (string) ($r['id'] ?? '');
        if ($id === '') {
            throw new NetworkException('Mastodon hat keine Beitrags-ID zurückgegeben.');
        }

        return ['id' => $id, 'url' => isset($r['url']) ? (string) $r['url'] : null];
    }
}
