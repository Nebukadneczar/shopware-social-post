<?php declare(strict_types=1);

namespace SocialPost\Network;

/** Telegram-Kanal oder -Gruppe über einen Bot. */
final class Telegram extends AbstractNetwork
{
    public function key(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return 'Telegram (Kanal)';
    }

    public function limit(): int
    {
        return 1024;
    }

    public function fields(): array
    {
        return [
            ['key' => 'botToken', 'label' => 'Bot-Token', 'secret' => true, 'hint' => 'Vom BotFather beim Anlegen des Bots.', 'placeholder' => '', 'default' => ''],
            ['key' => 'chatId', 'label' => 'Kanal', 'secret' => false, 'hint' => 'Öffentlicher Kanal als @name oder die numerische Chat-ID.', 'placeholder' => '@meinkanal', 'default' => ''],
        ];
    }

    public function help(): string
    {
        return 'In Telegram mit @BotFather einen Bot anlegen und das Token kopieren. Den Bot als Administrator mit dem Recht "Nachrichten posten" zum Kanal hinzufügen. Als Kanal den @namen des öffentlichen Kanals eintragen. Telegram ist der einfachste Weg zum Ausprobieren.';
    }

    public function isConfigured(array $values): bool
    {
        return trim($values['botToken'] ?? '') !== '' && trim($values['chatId'] ?? '') !== '';
    }

    private function url(array $v, string $method): string
    {
        return 'https://api.telegram.org/bot' . trim($v['botToken']) . '/' . $method;
    }

    public function test(array $values): string
    {
        $this->need($values, 'botToken', 'chatId');
        $me = $this->call('GET', $this->url($values, 'getMe'));
        $chat = $this->call('GET', $this->url($values, 'getChat'), ['query' => ['chat_id' => trim($values['chatId'])]]);

        return 'Bot @' . ($me['result']['username'] ?? '?') . ' verbunden mit „' . ($chat['result']['title'] ?? $chat['result']['username'] ?? '?') . '“.';
    }

    public function publish(array $values, Message $message): array
    {
        $this->need($values, 'botToken', 'chatId');
        $chat = trim($values['chatId']);
        $hasImage = $message->imageUrl !== null && $message->imageUrl !== '';

        if ($hasImage) {
            $r = $this->call('POST', $this->url($values, 'sendPhoto'), ['body' => [
                'chat_id' => $chat,
                'photo' => $message->imageUrl,
                'caption' => $this->composeText($message),
            ]]);
        } else {
            $r = $this->call('POST', $this->url($values, 'sendMessage'), ['body' => [
                'chat_id' => $chat,
                'text' => $this->withLink($message->text, $message->link, 4000),
            ]]);
        }

        $id = (string) ($r['result']['message_id'] ?? '');
        if ($id === '') {
            throw new NetworkException('Telegram hat keine Nachrichten-ID zurückgegeben.');
        }
        $url = str_starts_with($chat, '@') ? 'https://t.me/' . substr($chat, 1) . '/' . $id : null;

        return ['id' => $id, 'url' => $url];
    }
}
