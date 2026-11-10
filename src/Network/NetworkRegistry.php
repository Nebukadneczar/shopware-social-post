<?php declare(strict_types=1);

namespace SocialPost\Network;

final class NetworkRegistry
{
    /** @var array<string, NetworkInterface> */
    private array $networks = [];

    /**
     * @param iterable<NetworkInterface> $networks
     */
    public function __construct(iterable $networks)
    {
        foreach ($networks as $network) {
            $this->networks[$network->key()] = $network;
        }
    }

    /** @return array<string, NetworkInterface> */
    public function all(): array
    {
        return $this->networks;
    }

    public function get(string $key): ?NetworkInterface
    {
        return $this->networks[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->networks);
    }
}
