<?php

declare(strict_types=1);

namespace Snippet\Content;

use Override;
use Snippet\Markdown\Document;

/** A validated dated article with its parsed body and asset inventory. */
final readonly class Article implements ContentItem
{
    /**
     * @param list<Tag> $tags
     * @param list<Asset> $assets
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public string $date,
        public array $tags,
        public Document $document,
        public array $assets,
        public ?ArticleImage $image = null,
    ) {}

    #[Override]
    public function type(): ContentType
    {
        return ContentType::Article;
    }

    #[Override]
    public function url(): string
    {
        return $this->type()->url($this->slug);
    }
}
