<?php

declare(strict_types=1);

namespace Snippet\Content;

use Override;
use Snippet\Markdown\Document;

/** A validated timeless page with its parsed article and asset inventory. */
final readonly class Page implements ContentItem
{
    /** @param list<Asset> $assets */
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public Document $document,
        public array $assets,
        public ?int $menuOrder = null,
    ) {}

    #[Override]
    public function type(): ContentType
    {
        return ContentType::Page;
    }

    #[Override]
    public function url(): string
    {
        return $this->type()->url($this->slug);
    }
}
