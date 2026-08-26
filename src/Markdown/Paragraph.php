<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** A paragraph whose inline events occupy one range in the document arena. */
final readonly class Paragraph implements Block
{
    public function __construct(
        public int $inlineOffset,
        public int $inlineCount,
    ) {}
}
