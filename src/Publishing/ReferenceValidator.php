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
                        $line += mb_substr_count(mb_substr($item->document->source, $lineOffset, $link->offset - $lineOffset, '8bit'), "\n", '8bit');
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
        $baseSegments = [];
        if ($scheme !== null) {
            if (!$this->sameOrigin($uri, $scheme, $config->url)) {
                return null;
            }
            $path = $uri->getRawPath();
            if ($config->basePath !== '') {
                $baseSegments = array_map(rawurldecode(...), explode('/', mb_ltrim($config->basePath, '/', '8bit')));
            }
            $absolute = true;
        } else {
            $path = $uri->getRawPath();
            $absolute = str_starts_with($path, '/');
        }

        $segments = $absolute ? [] : explode('/', mb_trim($currentRoute, '/', '8bit'));
        $components = explode('/', $path);
        foreach ($components as $component) {
            $component = rawurldecode($component);
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                // Once inside this publication, traversal cannot escape its deployment root.
                if ($segments === [] || $segments === $baseSegments) {
                    throw new ContentException("Internal link target '{$target}' in '{$source}' at line {$line} traverses above the site root.");
                }
                array_pop($segments);
                continue;
            }

            $segments[] = $component;
        }

        // Normalize the complete absolute path before deciding whether it belongs to this publication.
        if ($baseSegments !== []) {
            if (array_slice($segments, 0, count($baseSegments)) !== $baseSegments) {
                return null;
            }
            $segments = array_slice($segments, count($baseSegments));
        }

        // PublicationInventory canonicalizes every complete path before lookup as the matching trust boundary.
        // @pest-mutate-ignore: UnwrapArrayMap
        $resolved = '/' . implode('/', array_map(rawurlencode(...), $segments));
        if ($resolved !== '/' && in_array(rawurldecode(array_last($components)), ['', '.', '..'], true)) {
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

        $targetScheme = mb_strtolower($targetScheme, 'UTF-8');
        $siteScheme = mb_strtolower($siteScheme, 'UTF-8');
        $targetHost = mb_strtolower($targetHost, 'UTF-8');
        $siteHost = mb_strtolower($siteHost, 'UTF-8');
        if ($targetScheme !== $siteScheme || $targetHost !== $siteHost) {
            return false;
        }

        // ConfigLoader accepts only HTTPS origins, and the matching scheme above therefore is HTTPS too.
        $targetPort = $target->getPort() ?? 443;
        $sitePort = $site->getPort() ?? 443;

        return $targetPort === $sitePort;
    }
}
