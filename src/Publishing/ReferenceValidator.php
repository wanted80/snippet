<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use LogicException;
use Snippet\Content\Article;
use Snippet\Content\Catalog;
use Snippet\Content\Page;
use Snippet\Exception\ContentException;
use Snippet\Site\Config;
use Snippet\Support\UriReferenceParser;
use Uri\Rfc3986\Uri;

use function mb_ltrim;

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
                    // Equal offsets add zero newlines and reassign the same offset.
                    // @pest-mutate-ignore: SmallerToSmallerOrEqual
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
        $uri = UriReferenceParser::parse($target);
        if (!$uri instanceof Uri) {
            throw new LogicException('A parsed Markdown link target must be URL-parseable.');
        }

        $scheme = $uri->getScheme();
        if ($scheme !== null) {
            if (!$this->sameOrigin($uri, $scheme, $config->url)) {
                return null;
            }
            $path = '/' . mb_ltrim($uri->getRawPath(), '/');
            // A root deployment path would only enter this block and remove a zero-length prefix.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
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
            $path = $uri->getRawPath();
            $absolute = str_starts_with($path, '/');
        }

        $segments = $absolute ? [] : explode('/', mb_trim($currentRoute, '/'));
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

        // PublicationInventory canonicalizes every complete path before lookup as the matching trust boundary.
        // @pest-mutate-ignore: UnwrapArrayMap
        $resolved = '/' . implode('/', array_map(rawurlencode(...), $segments));
        if ($resolved !== '/' && str_ends_with($path, '/')) {
            $resolved .= '/';
        }

        return $resolved;
    }

    private function sameOrigin(Uri $target, string $targetScheme, string $siteOrigin): bool
    {
        $targetHost = $target->getHost();
        if ($targetHost === null || $targetHost === '') {
            throw new LogicException('A parsed Markdown link target must be URL-parseable.');
        }

        $site = Uri::parse($siteOrigin);
        if (!$site instanceof Uri) {
            throw new LogicException('A validated site origin must be URL-parseable.');
        }
        $siteScheme = $site->getScheme();
        if ($siteScheme === null) {
            throw new LogicException('A validated site origin must be URL-parseable.');
        }
        $siteHost = $site->getHost();
        if ($siteHost === null || $siteHost === '') {
            throw new LogicException('A validated site origin must be URL-parseable.');
        }

        $targetScheme = mb_strtolower($targetScheme);
        $siteScheme = mb_strtolower($siteScheme);
        $targetHost = mb_strtolower($targetHost);
        $siteHost = mb_strtolower($siteHost);
        if ($targetScheme !== $siteScheme || $targetHost !== $siteHost) {
            return false;
        }

        // ConfigLoader accepts only HTTPS origins, and the matching scheme above therefore is HTTPS too.
        $targetPort = $target->getPort() ?? 443;
        $sitePort = $site->getPort() ?? 443;

        return $targetPort === $sitePort;
    }
}
