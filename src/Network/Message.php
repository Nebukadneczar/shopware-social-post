<?php declare(strict_types=1);

namespace SocialPost\Network;

/** Der Beitrag, wie er an ein Netzwerk geht (Link ist bereits mit Kampagnenparametern versehen). */
final class Message
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $link,
        public readonly ?string $imageUrl,
        public readonly string $title,
        public readonly string $key,
    ) {
    }
}
