<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** Opens a link event whose validated target is stored as a source span. */
final readonly class Link implements Inline
{
    public function __construct(
        public int $offset,
        public int $length,
    ) {}
}
