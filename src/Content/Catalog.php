<?php

declare(strict_types=1);

namespace Snippet\Content;

use Snippet\Exception\ContentException;

use function strnatcasecmp;

/** An immutable, route-safe collection of validated articles and pages. */
final readonly class Catalog
{
    /** @var array<string, list<Article>> */
    private array $articlesByTag;

    /** @var list<TagUsage> */
    private array $tagUsages;

    /** @var list<Tag> */
    private array $tags;

    /**
     * @param list<Article> $articles
     * @param list<Page> $pages
     */
    public function __construct(public array $articles, public array $pages)
    {
        $routes = [];
        foreach ([$articles, $pages] as $items) {
            foreach ($items as $item) {
                $url = $item->url();
                if (isset($routes[$url])) {
                    throw new ContentException(sprintf("Route collision at %s between '%s' and '%s'.", $url, $routes[$url], $item->slug));
                }

                $routes[$url] = $item->slug;
            }
        }

        /** @var array<string, list<Article>> $articlesByTag */
        $articlesByTag = [];
        /** @var array<string, Tag> $tags */
        $tags = [];
        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($articles as $article) {
            foreach ($article->tags as $tag) {
                $tags[$tag->slug] ??= $tag;
                $counts[$tag->slug] = ($counts[$tag->slug] ?? 0) + 1;
                $articlesByTag[$tag->slug] ??= [];
                $articlesByTag[$tag->slug][] = $article;
            }
        }

        $usages = [];
        foreach ($tags as $slug => $tag) {
            $usages[] = new TagUsage($tag, $counts[$slug]);
        }
        usort($usages, static function (TagUsage $left, TagUsage $right): int {
            $count = $right->articles <=> $left->articles;
            if ($count !== 0) {
                return $count;
            }

            $label = strnatcasecmp($left->tag->label, $right->tag->label);
            return $label !== 0 ? $label : strcmp($left->tag->slug, $right->tag->slug);
        });

        $this->articlesByTag = $articlesByTag;
        $this->tagUsages = $usages;
        $this->tags = array_values($tags);
    }

    /** Return the total number of content items across both types. */
    public function count(): int
    {
        return count($this->articles) + count($this->pages);
    }

    /**
     * Return tag usages by article count descending, natural label ascending, then slug ascending.
     *
     * @return list<TagUsage>
     */
    public function tagUsages(): array
    {
        return $this->tagUsages;
    }

    /**
     * Return tag definitions in first catalog occurrence order.
     *
     * @return list<Tag>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return list<Article> articles in catalog order */
    public function articlesForTag(Tag $tag): array
    {
        return $this->articlesByTag[$tag->slug] ?? [];
    }
}
