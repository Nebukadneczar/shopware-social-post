<?php declare(strict_types=1);

namespace SocialPost\Network;

/** Facebook-Seite über die Graph API (Seiten-Zugriffstoken). */
final class Facebook extends AbstractNetwork
{
    public function key(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook (Seite)';
    }

    public function limit(): int
    {
        return 5000;
    }

    public function fields(): array
    {
        return [
            ['key' => 'pageId', 'label' => 'Seiten-ID', 'secret' => false, 'hint' => 'Zahl der Facebook-Seite (Seite → Info → Seiten-Transparenz).', 'placeholder' => '1234567890', 'default' => ''],
            ['key' => 'accessToken', 'label' => 'Seiten-Zugriffstoken', 'secret' => true, 'hint' => 'Langlebiges Token der Seite mit dem Recht pages_manage_posts.', 'placeholder' => '', 'default' => ''],
            ['key' => 'graphVersion', 'label' => 'Graph-API-Version', 'secret' => false, 'hint' => 'Normalerweise nicht ändern.', 'placeholder' => 'v23.0', 'default' => 'v23.0'],
        ];
    }

    public function help(): string
    {
        return 'Auf developers.facebook.com eine App vom Typ "Business" anlegen, im Graph-API-Explorer ein Token mit pages_manage_posts, pages_read_engagement und pages_show_list für die Seite erzeugen und in ein langlebiges Seiten-Token umtauschen. Seiten-ID und Token hier eintragen. Bei Apps im Entwicklungsmodus kann nur die eigene Seite veröffentlicht werden; das genügt für den Shop-Betreiber.';
    }

    public function isConfigured(array $values): bool
    {
        return trim($values['pageId'] ?? '') !== '' && trim($values['accessToken'] ?? '') !== '';
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
        $this->need($values, 'pageId', 'accessToken');
        $r = $this->call('GET', $this->base($values) . '/' . rawurlencode(trim($values['pageId'])), [
            'headers' => $this->headers($values),
            'query' => ['fields' => 'name'],
        ]);

        return 'Verbunden mit der Seite „' . ($r['name'] ?? '?') . '“.';
    }

    public function composeText(Message $message): string
    {
        // der Link kommt als eigenes Feld und wird von Facebook als Vorschaukarte angezeigt
        return $this->fit($message->text, $this->limit());
    }

    public function publish(array $values, Message $message): array
    {
        $this->need($values, 'pageId', 'accessToken');
        $page = rawurlencode(trim($values['pageId']));
        $text = $this->composeText($message);

        if ($message->link !== null && $message->link !== '') {
            $body = ['message' => $text, 'link' => $message->link];
            $path = '/feed';
        } elseif ($message->imageUrl !== null && $message->imageUrl !== '') {
            $body = ['caption' => $text, 'url' => $message->imageUrl];
            $path = '/photos';
        } else {
            $body = ['message' => $text];
            $path = '/feed';
        }

        $r = $this->call('POST', $this->base($values) . '/' . $page . $path, [
            'headers' => $this->headers($values),
            'body' => $body,
        ]);
        $id = (string) ($r['post_id'] ?? $r['id'] ?? '');
        if ($id === '') {
            throw new NetworkException('Facebook hat keine Beitrags-ID zurückgegeben.');
        }

        return ['id' => $id, 'url' => 'https://www.facebook.com/' . $id];
    }
}
