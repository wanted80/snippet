<?php

declare(strict_types=1);

namespace Snippet\Markdown;

/** A fenced code block stored as a source span with an optional language. */
final readonly class CodeBlock implements Block
{
    public function __construct(
        public int $offset,
        public int $length,
        public ?string $language,
    ) {}
}
