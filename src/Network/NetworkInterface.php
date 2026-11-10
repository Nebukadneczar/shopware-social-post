<?php declare(strict_types=1);

namespace SocialPost\Network;

interface NetworkInterface
{
    /** Technischer Schlüssel, z. B. "facebook". */
    public function key(): string;

    public function label(): string;

    /**
     * Eingabefelder der Einstellungen.
     *
     * @return list<array{key: string, label: string, secret: bool, hint: string, placeholder: string, default: string}>
     */
    public function fields(): array;

    /** Kurze Anleitung, woher die Zugangsdaten kommen (erscheint in der Hilfe). */
    public function help(): string;

    /** Maximale Textlänge des Netzwerks (nur für die Vorschau-Anzeige). */
    public function limit(): int;

    public function needsImage(): bool;

    /** @param array<string, string> $values */
    public function isConfigured(array $values): bool;

    /**
     * Prüft die Zugangsdaten, ohne etwas zu veröffentlichen.
     *
     * @param array<string, string> $values
     */
    public function test(array $values): string;

    /** Der Text, wie er tatsächlich gesendet wird (mit Link, auf die Länge gekürzt). */
    public function composeText(Message $message): string;

    /**
     * @param array<string, string> $values
     *
     * @return array{id: string, url: ?string}
     */
    public function publish(array $values, Message $message): array;
}
