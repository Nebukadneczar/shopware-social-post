<?php declare(strict_types=1);

namespace SocialPost\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use SocialPost\Network\NetworkRegistry;

/**
 * Alle Einstellungen liegen als ein Block in der Systemkonfiguration (SocialPost.config.data).
 * Geheime Felder (Tokens) werden nie an den Browser zurückgegeben; leer lassen beim Speichern = unverändert.
 */
final class SettingsService
{
    public const KEY = 'SocialPost.config.data';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly NetworkRegistry $networks,
    ) {
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'dryRun' => true,
            'utm' => true,
            'hashtags' => '',
            'defaultImage' => '',
            'salesChannelId' => '',
            'autoLanding' => 'off',
            'autoProduct' => 'off',
            'autoDelay' => 15,
            'autoMaxPerDay' => 3,
            'autoMaxAgeDays' => 30,
            'autoNetworks' => [],
            'landingPrefix' => '',
            'templateProduct' => "Neu im Shop: {name}\n\n{description}\n\n{hashtags}",
            'templateLanding' => "Neu im Blog: {name}\n\n{description}\n\n{hashtags}",
            'networks' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = $this->config->get(self::KEY);
        $stored = \is_array($stored) ? $stored : [];
        $all = array_replace($this->defaults(), $stored);
        $nets = [];
        foreach ($this->networks->all() as $key => $net) {
            $n = \is_array($all['networks'][$key] ?? null) ? $all['networks'][$key] : [];
            $values = \is_array($n['values'] ?? null) ? $n['values'] : [];
            foreach ($net->fields() as $f) {
                if (!isset($values[$f['key']]) || $values[$f['key']] === '') {
                    $values[$f['key']] = $f['default'];
                }
            }
            $nets[$key] = ['enabled' => !empty($n['enabled']), 'values' => array_map('strval', $values)];
        }
        $all['networks'] = $nets;

        return $all;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Netzwerke, die eingeschaltet und vollständig eingerichtet sind.
     *
     * @return list<string>
     */
    public function readyNetworks(): array
    {
        $out = [];
        $all = $this->all();
        foreach ($this->networks->all() as $key => $net) {
            $n = $all['networks'][$key];
            if ($n['enabled'] && $net->isConfigured($n['values'])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Einstellungen für den Admin, ohne geheime Werte.
     *
     * @return array<string, mixed>
     */
    public function forAdmin(): array
    {
        $all = $this->all();
        $nets = [];
        foreach ($this->networks->all() as $key => $net) {
            $n = $all['networks'][$key];
            $values = [];
            $has = [];
            $fields = [];
            foreach ($net->fields() as $f) {
                $v = $n['values'][$f['key']] ?? '';
                $has[$f['key']] = $v !== '';
                $values[$f['key']] = $f['secret'] ? '' : $v;
                $fields[] = $f;
            }
            $nets[] = [
                'key' => $key,
                'label' => $net->label(),
                'enabled' => $n['enabled'],
                'configured' => $net->isConfigured($n['values']),
                'needsImage' => $net->needsImage(),
                'limit' => $net->limit(),
                'help' => $net->help(),
                'fields' => $fields,
                'values' => $values,
                'has' => $has,
            ];
        }
        $all['networks'] = $nets;

        return $all;
    }

    /**
     * @param array<string, mixed> $in
     */
    public function save(array $in): void
    {
        $old = $this->all();
        $new = $old;

        $new['dryRun'] = !empty($in['dryRun']);
        $new['utm'] = !empty($in['utm']);
        $new['hashtags'] = $this->str($in['hashtags'] ?? '', 500);
        $new['defaultImage'] = $this->url($in['defaultImage'] ?? '');
        $sc = strtolower(trim((string) ($in['salesChannelId'] ?? '')));
        $new['salesChannelId'] = preg_match('~^[0-9a-f]{32}$~', $sc) ? $sc : '';
        foreach (['autoLanding', 'autoProduct'] as $k) {
            $mode = (string) ($in[$k] ?? 'off');
            $new[$k] = \in_array($mode, ['off', 'draft', 'send'], true) ? $mode : 'off';
        }
        $new['autoDelay'] = $this->int($in['autoDelay'] ?? 15, 0, 10080);
        $new['autoMaxPerDay'] = $this->int($in['autoMaxPerDay'] ?? 3, 1, 50);
        $new['autoMaxAgeDays'] = $this->int($in['autoMaxAgeDays'] ?? 30, 1, 3650);
        $new['landingPrefix'] = $this->str($in['landingPrefix'] ?? '', 100);
        $new['templateProduct'] = $this->str($in['templateProduct'] ?? '', 2000, true);
        $new['templateLanding'] = $this->str($in['templateLanding'] ?? '', 2000, true);

        $auto = [];
        foreach ((array) ($in['autoNetworks'] ?? []) as $k) {
            if (\is_string($k) && $this->networks->get($k) !== null) {
                $auto[] = $k;
            }
        }
        $new['autoNetworks'] = array_values(array_unique($auto));

        $inNets = [];
        foreach ((array) ($in['networks'] ?? []) as $n) {
            if (\is_array($n) && isset($n['key'])) {
                $inNets[(string) $n['key']] = $n;
            }
        }
        foreach ($this->networks->all() as $key => $net) {
            $n = $inNets[$key] ?? null;
            if ($n === null) {
                continue;
            }
            $values = $old['networks'][$key]['values'];
            foreach ($net->fields() as $f) {
                $given = (string) ($n['values'][$f['key']] ?? '');
                if ($f['secret'] && $given === '') {
                    continue; // unverändert lassen
                }
                $values[$f['key']] = $this->str($given, 500);
            }
            $new['networks'][$key] = ['enabled' => !empty($n['enabled']), 'values' => $values];
        }

        $this->config->set(self::KEY, $new);
    }

    private function str(mixed $v, int $max, bool $multiline = false): string
    {
        $s = (string) $v;
        $s = $multiline ? preg_replace('~[^\P{C}\n]+~u', '', $s) : preg_replace('~\p{C}+~u', '', $s);

        return mb_substr(trim((string) $s), 0, $max);
    }

    private function int(mixed $v, int $min, int $max): int
    {
        return max($min, min($max, (int) $v));
    }

    private function url(mixed $v): string
    {
        $s = trim((string) $v);

        return preg_match('~^https?://~i', $s) ? mb_substr($s, 0, 1000) : '';
    }
}
