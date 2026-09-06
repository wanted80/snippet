<?php

declare(strict_types=1);

namespace Snippet\Rendering;

/** Open Graph document types emitted by Snippet's supported page kinds. */
enum OpenGraphType: string
{
    case Website = 'website';
    case Article = 'article';
}
