<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** A level 1-3 ATX heading whose inline events occupy one arena range. */
final readonly class Heading implements Block
{
    public function __construct(
        public int $level,
        public int $inlineOffset,
        public int $inlineCount,
    ) {}
}
