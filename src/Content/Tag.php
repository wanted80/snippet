<?php

declare(strict_types=1);

namespace Snippet\Content;

use Snippet\Support\Slug;

/** A validated display label and route-safe tag identifier. */
final readonly class Tag
{
    public function __construct(
        public string $label,
        public string $slug,
    ) {}

    public function url(): string
    {
        return '/tags/' . Slug::toUriSegment($this->slug) . '/';
    }
}
