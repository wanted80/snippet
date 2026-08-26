<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** One flat-list item whose inline events occupy one arena range. */
final readonly class ListItem
{
    public function __construct(
        public int $inlineOffset,
        public int $inlineCount,
    ) {}
}
