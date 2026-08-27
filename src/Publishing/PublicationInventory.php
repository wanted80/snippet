<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Snippet\Content\Catalog;
use Snippet\Site\Config;

/** A deterministic set of URL paths produced by one validated publication. */
final readonly class PublicationInventory
{
    /** @var array<string, true> */
    private array $targets;

    public function __construct(Config $config, Catalog $catalog)
    {
        $targets = [];
        foreach (['/', '/index.html', '/llms.txt', '/favicon.svg', '/articles/', '/articles/index.html', '/pages/', '/pages/index.html', '/tags/', '/tags/index.html', '/assets/site.css', '/assets/theme.js'] as $target) {
            $targets[$target] = true;
        }
        if ($config->hasTheme) {
            $targets['/assets/theme.css'] = true;
        }
        foreach ($config->assets as $asset) {
            $targets[$this->canonical('/assets/site/' . $asset)] = true;
        }
        foreach ([$catalog->articles, $catalog->pages] as $items) {
            foreach ($items as $item) {
                $route = $item->url();
                $targets[$route] = true;
                $targets[$route . 'index.html'] = true;
                foreach ($item->assets as $asset) {
                    $targets[$this->canonical($route . $asset->path)] = true;
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
        return isset($this->targets[$this->canonical($path)]);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->targets);
    }

    private function canonical(string $path): string
    {
        return implode('/', array_map(
            static fn(string $segment): string => $segment === '' ? '' : rawurlencode(rawurldecode($segment)),
            explode('/', $path),
        ));
    }
}
