<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** Literal inline text stored as a span of the document source. */
final readonly class Text implements Inline
{
    public function __construct(
        public int $offset,
        public int $length,
    ) {}
}
