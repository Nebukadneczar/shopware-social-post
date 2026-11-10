<?php declare(strict_types=1);

namespace SocialPost\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Produkte und Landingpages (Blogartikel): suchen, Beitragsvorschlag erzeugen, Adresse und Bild ermitteln.
 */
final class SourceService
{
    public const TYPES = ['product', 'landing_page'];

    private const STOREFRONT_TYPE = '8a243080f92e4c719546314b577cf82b';

    /**
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection> $productRepository
     * @param EntityRepository<\Shopware\Core\Content\LandingPage\LandingPageCollection> $landingPageRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $landingPageRepository,
        private readonly Connection $db,
        private readonly SettingsService $settings,
    ) {
    }

    private function context(): Context
    {
        $c = Context::createDefaultContext();
        $c->setConsiderInheritance(true);

        return $c;
    }

    /**
     * @return list<array{id: string, label: string, active: bool}>
     */
    public function search(string $type, string $q): array
    {
        $q = trim($q);
        $criteria = new Criteria();
        $criteria->setLimit(12);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        if ($type === 'product') {
            $criteria->addFilter(new EqualsFilter('parentId', null));
            if ($q !== '') {
                $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                    new ContainsFilter('name', $q),
                    new ContainsFilter('productNumber', $q),
                ]));
            }
            $out = [];
            foreach ($this->productRepository->search($criteria, $this->context())->getEntities() as $p) {
                /** @var ProductEntity $p */
                $out[] = ['id' => $p->getId(), 'label' => trim(($p->getTranslation('name') ?? '') . ' (' . $p->getProductNumber() . ')'), 'active' => (bool) $p->getActive()];
            }

            return $out;
        }

        if ($type === 'landing_page') {
            if ($q !== '') {
                $criteria->addFilter(new ContainsFilter('name', $q));
            }
            $out = [];
            foreach ($this->landingPageRepository->search($criteria, $this->context())->getEntities() as $l) {
                /** @var LandingPageEntity $l */
                $out[] = ['id' => $l->getId(), 'label' => (string) ($l->getTranslation('name') ?? $l->getName()), 'active' => $l->isActive()];
            }

            return $out;
        }

        return [];
    }

    /**
     * Beitragsvorschlag aus der Quelle.
     *
     * @return array{title: string, text: string, imageUrl: string, link: string, active: bool}|null
     */
    public function suggest(string $type, string $id): ?array
    {
        $title = '';
        $desc = '';
        $image = '';
        $active = true;

        if ($type === 'product') {
            $criteria = new Criteria([$id]);
            $criteria->addAssociation('cover.media');
            /** @var ProductEntity|null $p */
            $p = $this->productRepository->search($criteria, $this->context())->first();
            if ($p === null) {
                return null;
            }
            $title = (string) ($p->getTranslation('name') ?? '');
            $desc = $this->plain((string) ($p->getTranslation('description') ?? ''), 300);
            $image = (string) ($p->getCover()?->getMedia()?->getUrl() ?? '');
            $active = (bool) $p->getActive();
            $template = (string) $this->settings->get('templateProduct');
        } elseif ($type === 'landing_page') {
            $criteria = new Criteria([$id]);
            /** @var LandingPageEntity|null $l */
            $l = $this->landingPageRepository->search($criteria, $this->context())->first();
            if ($l === null) {
                return null;
            }
            $title = (string) ($l->getTranslation('name') ?? $l->getName() ?? '');
            $desc = $this->plain((string) ($l->getTranslation('metaDescription') ?? ''), 300);
            $active = $l->isActive();
            $template = (string) $this->settings->get('templateLanding');
        } else {
            return null;
        }

        if ($image === '') {
            $image = (string) $this->settings->get('defaultImage');
        }

        return [
            'title' => $title,
            'text' => $this->fill($template, ['name' => $title, 'description' => $desc, 'hashtags' => (string) $this->settings->get('hashtags')]),
            'imageUrl' => $image,
            'link' => $this->urlFor($type, $id),
            'active' => $active,
        ];
    }

    /** @param array<string, string> $vars */
    private function fill(string $template, array $vars): string
    {
        $text = $template;
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', $v, $text);
        }
        // leere Platzhalter hinterlassen keine doppelten Leerzeilen
        $text = preg_replace("~\n{3,}~", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function plain(string $html, int $max): string
    {
        $t = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags((string) preg_replace('~<(?:br|/p|/li|/div|/h[1-6]|/tr|/td)\b[^>]*>~i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($t) <= $max) {
            return $t;
        }
        $cut = mb_substr($t, 0, $max);
        $sp = mb_strrpos($cut, ' ');

        return rtrim($sp !== false && $sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " .,;:-") . '…';
    }

    /**
     * Öffentliche Adresse der Seite im Shop (SEO-Adresse, sonst die technische Adresse).
     */
    public function urlFor(string $type, string $id): string
    {
        $domain = $this->domain();
        if ($domain === null) {
            return '';
        }
        $path = $this->seoPath($type, $id);
        if ($path !== '') {
            return $domain['url'] . '/' . $path;
        }

        return $domain['url'] . ($type === 'product' ? '/detail/' : '/landingPage/') . $id;
    }

    /** SEO-Pfad der Seite ohne Domain (für den Adress-Filter bei Blogartikeln). */
    public function seoPath(string $type, string $id): string
    {
        $domain = $this->domain();
        if ($domain === null) {
            return '';
        }
        $route = $type === 'product' ? 'frontend.detail.page' : 'frontend.landing.page';
        $path = $this->db->fetchOne(
            'SELECT seo_path_info FROM seo_url WHERE foreign_key = :id AND route_name = :r AND is_canonical = 1 AND is_deleted = 0
             AND sales_channel_id = :sc AND language_id = :lang LIMIT 1',
            ['id' => Uuid::fromHexToBytes($id), 'r' => $route, 'sc' => $domain['sc'], 'lang' => $domain['lang']]
        );

        return \is_string($path) ? ltrim($path, '/') : '';
    }

    /** @return array{url: string, sc: string, lang: string}|null */
    private function domain(): ?array
    {
        $sql = 'SELECT d.url, d.sales_channel_id sc, d.language_id lang FROM sales_channel_domain d
                JOIN sales_channel s ON s.id = d.sales_channel_id
                WHERE s.active = 1 AND s.type_id = :t';
        $params = ['t' => hex2bin(self::STOREFRONT_TYPE)];
        $sc = (string) $this->settings->get('salesChannelId');
        if ($sc !== '') {
            $sql .= ' AND d.sales_channel_id = :sc';
            $params['sc'] = Uuid::fromHexToBytes($sc);
        }
        $row = $this->db->fetchAssociative($sql . ' ORDER BY d.created_at ASC LIMIT 1', $params);
        if (!$row) {
            return null;
        }

        return ['url' => rtrim((string) $row['url'], '/'), 'sc' => $row['sc'], 'lang' => $row['lang']];
    }
}
