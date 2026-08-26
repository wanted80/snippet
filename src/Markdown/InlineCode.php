<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** Literal inline code stored as a span of the document source. */
final readonly class InlineCode implements Inline
{
    public function __construct(
        public int $offset,
        public int $length,
    ) {}
}
