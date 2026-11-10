<?php declare(strict_types=1);

namespace SocialPost\Network;

/** Pinterest: legt einen Pin mit Bild und Link auf einer Pinnwand an (API v5). */
final class Pinterest extends AbstractNetwork
{
    public function key(): string
    {
        return 'pinterest';
    }

    public function label(): string
    {
        return 'Pinterest';
    }

    public function limit(): int
    {
        return 800;
    }

    public function needsImage(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            ['key' => 'boardId', 'label' => 'Pinnwand-ID', 'secret' => false, 'hint' => 'ID der Pinnwand, auf der die Pins landen.', 'placeholder' => '1234567890123456789', 'default' => ''],
            ['key' => 'accessToken', 'label' => 'Zugriffstoken', 'secret' => true, 'hint' => 'OAuth-Token mit den Rechten boards:read und pins:write.', 'placeholder' => '', 'default' => ''],
            ['key' => 'apiBase', 'label' => 'API-Adresse', 'secret' => false, 'hint' => 'Für Tests mit Testzugang: https://api-sandbox.pinterest.com/v5', 'placeholder' => 'https://api.pinterest.com/v5', 'default' => 'https://api.pinterest.com/v5'],
        ];
    }

    public function help(): string
    {
        return 'Auf developers.pinterest.com eine App anlegen und den Zugang für Pins beantragen. Mit dem OAuth-Ablauf ein Token mit boards:read und pins:write erzeugen. Die Pinnwand-ID liefert der Aufruf GET /v5/boards. Neue Apps haben zunächst nur den Testzugang (Sandbox), dafür die API-Adresse auf die Sandbox stellen.';
    }

    public function isConfigured(array $values): bool
    {
        return trim($values['boardId'] ?? '') !== '' && trim($values['accessToken'] ?? '') !== '';
    }

    private function base(array $v): string
    {
        $b = rtrim(trim($v['apiBase'] ?? '') ?: 'https://api.pinterest.com/v5', '/');
        if (!str_starts_with($b, 'https://')) {
            throw new NetworkException('Die API-Adresse muss mit https:// beginnen.');
        }

        return $b;
    }

    private function headers(array $v): array
    {
        return ['Authorization' => 'Bearer ' . trim($v['accessToken']), 'Accept' => 'application/json'];
    }

    public function test(array $values): string
    {
        $this->need($values, 'boardId', 'accessToken');
        $r = $this->call('GET', $this->base($values) . '/boards/' . rawurlencode(trim($values['boardId'])), ['headers' => $this->headers($values)]);

        return 'Verbunden mit der Pinnwand „' . ($r['name'] ?? '?') . '“.';
    }

    public function composeText(Message $message): string
    {
        return $this->fit($message->text, $this->limit());
    }

    public function publish(array $values, Message $message): array
    {
        $this->need($values, 'boardId', 'accessToken');
        $image = $this->requireImage($message);

        $body = [
            'board_id' => trim($values['boardId']),
            'title' => $this->fit($message->title, 100),
            'description' => $this->composeText($message),
            'media_source' => ['source_type' => 'image_url', 'url' => $image],
        ];
        if ($message->link !== null && $message->link !== '') {
            $body['link'] = $message->link;
        }

        $r = $this->call('POST', $this->base($values) . '/pins', [
            'headers' => $this->headers($values) + ['Content-Type' => 'application/json'],
            'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $id = (string) ($r['id'] ?? '');
        if ($id === '') {
            throw new NetworkException('Pinterest hat keine Pin-ID zurückgegeben.');
        }

        return ['id' => $id, 'url' => 'https://www.pinterest.com/pin/' . $id . '/'];
    }
}
