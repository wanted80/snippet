<?php

declare(strict_types=1);

namespace Snippet\Content;

/** One discovered and verified article cover with derived intrinsic dimensions. */
final readonly class ArticleImage
{
    public function __construct(
        public string $path,
        public string $alt,
        public int $width,
        public int $height,
    ) {}
}
