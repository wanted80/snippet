<?php

declare(strict_types=1);

namespace Snippet\Content;

/** One tag paired with its aggregate article usage count. */
final readonly class TagUsage
{
    public function __construct(
        public Tag $tag,
        public int $articles,
    ) {}
}
