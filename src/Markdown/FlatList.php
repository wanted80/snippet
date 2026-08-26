<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** A non-nested ordered or unordered list. */
final readonly class FlatList implements Block
{
    public function __construct(
        public bool $ordered,
        public int $itemOffset,
        public int $itemCount,
    ) {}
}
