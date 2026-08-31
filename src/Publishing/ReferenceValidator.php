<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use LogicException;
use Snippet\Content\Article;
use Snippet\Content\Catalog;
use Snippet\Content\Page;
use Snippet\Exception\ContentException;
use Snippet\Site\Config;

/** Checks authored Markdown references against one generated-path inventory. */
final readonly class ReferenceValidator
{
    public function validate(string $root, Config $config, Catalog $catalog, PublicationInventory $inventory): void
    {
        foreach ([$catalog->articles, $catalog->pages] as $items) {
            foreach ($items as $item) {
                $source = $this->sourcePath($root, $item);
                $line = 1;
                $lineOffset = 0;
                foreach ($item->document->links() as $link) {
                    if ($lineOffset < $link->offset) {
                        $line += mb_substr_count(mb_substr($item->document->source, $lineOffset, $link->offset - $lineOffset, '8bit'), "\n");
                        $lineOffset = $link->offset;
                    }

                    $target = $item->document->text($link);
                    $path = $this->resolve($target, $item->url(), $config, $source, $line);
                    if ($path !== null && !$inventory->contains($path)) {
                        throw new ContentException("Internal link target '{$target}' in '{$source}' at line {$line} does not exist in the generated site.");
                    }
                }
            }
        }
    }

    private function sourcePath(string $root, Article|Page $item): string
    {
        $type = $item->type();
        $path = $root . '/content/' . $type->collection();
        if ($item instanceof Article) {
            $path .= '/' . str_replace('-', '/', $item->date);
        }

        return $path . '/' . $item->slug . '/' . $type->sourceFilename();
    }

    private function resolve(string $target, string $currentRoute, Config $config, string $source, int $line): ?string
    {
        $parts = parse_url($target);
        if ($parts === false) {
            throw new LogicException('A parsed Markdown link target must be URL-parseable.');
        }

        if (isset($parts['scheme'])) {
            if (!$this->sameOrigin($parts, $config->url)) {
                return null;
            }
            $path = $parts['path'] ?? '/';
            if ($config->basePath !== '') {
                if ($path === $config->basePath) {
                    $path = '/';
                } elseif (str_starts_with($path, $config->basePath . '/')) {
                    $path = mb_substr($path, mb_strlen($config->basePath));
                } else {
                    return null;
                }
            }
            $absolute = true;
        } else {
            $path = $parts['path'] ?? '';
            $absolute = str_starts_with($path, '/');
        }

        $segments = $absolute ? [] : array_values(array_filter(explode('/', mb_trim($currentRoute, '/')), static fn(string $segment): bool => $segment !== ''));
        if ($path === '') {
            return $currentRoute;
        }

        foreach (explode('/', $path) as $component) {
            $component = rawurldecode($component);
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                if ($segments === []) {
                    throw new ContentException("Internal link target '{$target}' in '{$source}' at line {$line} traverses above the site root.");
                }
                array_pop($segments);
                continue;
            }

            $segments[] = $component;
        }

        $resolved = '/' . implode('/', array_map(rawurlencode(...), $segments));
        if ($resolved !== '/' && str_ends_with($path, '/')) {
            $resolved .= '/';
        }

        return $resolved;
    }

    /** @param array<string, int|string> $target */
    private function sameOrigin(array $target, string $siteOrigin): bool
    {
        $site = parse_url($siteOrigin);
        if ($site === false) {
            throw new LogicException('A validated site origin must be URL-parseable.');
        }

        $targetScheme = mb_strtolower((string) ($target['scheme'] ?? ''));
        $siteScheme = mb_strtolower($site['scheme'] ?? '');
        $targetHost = mb_strtolower((string) ($target['host'] ?? ''));
        $siteHost = mb_strtolower($site['host'] ?? '');
        $targetPort = $target['port'] ?? ($targetScheme === 'https' ? 443 : 80);
        $sitePort = $site['port'] ?? ($siteScheme === 'https' ? 443 : 80);

        return $targetScheme === $siteScheme && $targetHost === $siteHost && $targetPort === $sitePort;
    }
}
