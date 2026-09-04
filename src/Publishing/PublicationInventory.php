<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Snippet\Content\Catalog;
use Snippet\Rendering\AssetPaths;
use Snippet\Site\Config;

/** A deterministic set of URL paths produced by one validated publication. */
final readonly class PublicationInventory
{
    /** @var array<string, true> */
    private array $targets;

    public function __construct(Config $config, Catalog $catalog, AssetPaths $assets)
    {
        $targets = [];
        foreach (['/', '/index.html', '/404.html', '/llms.txt', '/favicon.svg', '/articles/', '/articles/index.html', '/pages/', '/pages/index.html', '/tags/', '/tags/index.html', $assets->themeStylesheet, $assets->themeScript] as $target) {
            $targets[$target] = true;
        }
        if ($assets->siteStylesheet !== null) {
            $targets[$assets->siteStylesheet] = true;
        }
        if ($assets->siteScript !== null) {
            $targets[$assets->siteScript] = true;
        }
        foreach ($config->assets as $asset) {
            $targets['/assets/site/' . $this->encodeAssetPath($asset)] = true;
        }
        foreach ([$catalog->articles, $catalog->pages] as $items) {
            foreach ($items as $item) {
                $route = $item->url();
                $targets[$route] = true;
                $targets[$route . 'index.html'] = true;
                foreach ($item->assets as $asset) {
                    $targets[$route . $this->encodeAssetPath($asset->path)] = true;
                }
            }
        }
        foreach ($catalog->tags() as $tag) {
            $route = $tag->url();
            $targets[$route] = true;
            $targets[$route . 'index.html'] = true;
        }

        ksort($targets, SORT_STRING);
        $this->targets = $targets;
    }

    public function contains(string $path): bool
    {
        return $this->targets[$this->canonical($path)] ?? false;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->targets);
    }

    private function canonical(string $path): string
    {
        return implode('/', array_map(
            static fn(string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path),
        ));
    }

    /** Filesystem names contain literal bytes, never pre-encoded URL segments. */
    private function encodeAssetPath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
